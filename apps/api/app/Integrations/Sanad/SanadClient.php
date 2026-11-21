<?php

namespace App\Integrations\Sanad;

use App\Exceptions\Domain\SanadIntegrationException;
use App\Support\DTOs\SanadPage;
use App\Support\RequestFingerprint;
use App\Support\Sanad\NoFloats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LAN direct mode (contract section 4): Formula Lab calls Sanad Desktop at /api/v1/manufacturing/*.
 * Bearer token, `Idempotency-Key` on every write (derived from the request, so a repeated call is a
 * replay), deterministic exponential backoff on 429 / 503 / network errors, decimals only as strings.
 * Every failure surfaces as a SanadIntegrationException with a translated message.
 */
final class SanadClient
{
    private const BASE_PATH = '/api/v1/manufacturing';

    /**
     * @throws SanadIntegrationException
     */
    public function products(?string $cursor = null, ?string $kind = null, ?string $updatedSince = null): SanadPage
    {
        return $this->page('/products', ['cursor' => $cursor, 'kind' => $kind, 'updated_since' => $updatedSince]);
    }

    /**
     * @throws SanadIntegrationException
     */
    public function suppliers(?string $cursor = null, ?string $updatedSince = null): SanadPage
    {
        return $this->page('/suppliers', ['cursor' => $cursor, 'updated_since' => $updatedSince]);
    }

    /**
     * @throws SanadIntegrationException
     */
    public function purchases(?string $since = null, ?string $cursor = null): SanadPage
    {
        return $this->page('/purchases', ['cursor' => $cursor, 'updated_since' => $since]);
    }

    /**
     * @throws SanadIntegrationException
     */
    public function stock(?string $cursor = null, ?string $updatedSince = null, ?string $branch = null): SanadPage
    {
        return $this->page('/stock', ['cursor' => $cursor, 'updated_since' => $updatedSince, 'branch' => $branch]);
    }

    /**
     * @param  array<string, mixed>  $data  contract 4.2 body
     * @return array<string, mixed>
     *
     * @throws SanadIntegrationException
     */
    public function upsertProduct(string $uuid, array $data, ?string $idempotencyKey = null): array
    {
        return $this->write('PUT', "/products/{$uuid}", $data, $idempotencyKey);
    }

    /**
     * @param  list<array<string, mixed>>  $lines  `{product_uuid, quantity, uom}`
     * @param  array<string, mixed>  $options  other body keys: branch_code (null = Sanad's main branch), consumed_on, notes
     * @return array<string, mixed>
     *
     * @throws SanadIntegrationException With shortfalls() on a 422 when stock is insufficient.
     */
    public function consume(string $externalId, array $lines, array $options = [], ?string $idempotencyKey = null): array
    {
        return $this->write('POST', "/production-orders/{$externalId}/consume", ['lines' => $lines] + $options, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $data  contract 4.4 body
     * @return array<string, mixed>
     *
     * @throws SanadIntegrationException
     */
    public function receive(string $externalId, array $data, ?string $idempotencyKey = null): array
    {
        return $this->write('POST', "/production-orders/{$externalId}/receive", $data, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $data  contract 4.5 body
     * @return array<string, mixed>
     *
     * @throws SanadIntegrationException
     */
    public function wholesalePrices(string $uuid, array $data, ?string $idempotencyKey = null): array
    {
        return $this->write('PUT', "/products/{$uuid}/wholesale-prices", $data, $idempotencyKey);
    }

    /**
     * Delay before retry number $attempt (1 = after the first failure): base * 2^(attempt-1), capped.
     * A 429 `Retry-After` wins when it asks for longer, still capped.
     */
    public function backoffMilliseconds(int $attempt, ?int $retryAfterSeconds = null): int
    {
        $base = config('sanad.client.backoff_base_seconds');
        $cap = config('sanad.client.backoff_cap_seconds');
        $seconds = min($cap, $base * (2 ** max(0, $attempt - 1)));

        if ($retryAfterSeconds !== null) {
            $seconds = min($cap, max($seconds, $retryAfterSeconds));
        }

        return $seconds * 1000;
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws SanadIntegrationException
     */
    private function page(string $path, array $query): SanadPage
    {
        $body = $this->send('GET', $path, ['query' => array_filter($query, fn (mixed $value): bool => $value !== null && $value !== '')]);
        $next = $body['next_cursor'] ?? null;

        return new SanadPage(array_values((array) ($body['data'] ?? [])), is_string($next) && $next !== '' ? $next : null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws SanadIntegrationException
     */
    private function write(string $method, string $path, array $data, ?string $idempotencyKey): array
    {
        NoFloats::assert($data);

        return $this->send($method, $path, [
            'json' => $data,
            'headers' => ['Idempotency-Key' => $idempotencyKey ?? RequestFingerprint::hash([$method, $path, $data])],
        ]);
    }

    /**
     * @param  array{query?: array<string, mixed>, json?: array<string, mixed>, headers?: array<string, string>}  $options
     * @return array<string, mixed>
     *
     * @throws SanadIntegrationException
     */
    private function send(string $method, string $path, array $options): array
    {
        $request = $this->pending()->withHeaders($options['headers'] ?? []);

        try {
            $response = match ($method) {
                'GET' => $request->get(self::BASE_PATH.$path, $options['query'] ?? []),
                default => $request->send($method, self::BASE_PATH.$path, ['json' => $options['json'] ?? []]),
            };
        } catch (ConnectionException $exception) {
            Log::warning('Sanad is unreachable.', ['method' => $method, 'path' => $path]);

            throw SanadIntegrationException::network($exception->getMessage());
        }

        return $this->decode($response, $method, $path);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SanadIntegrationException
     */
    private function decode(Response $response, string $method, string $path): array
    {
        $body = $response->json();

        if ($response->successful()) {
            if (! is_array($body)) {
                throw SanadIntegrationException::unexpectedResponse($response->status());
            }

            return $body;
        }

        Log::warning('Sanad refused a request.', ['method' => $method, 'path' => $path, 'status' => $response->status()]);

        throw SanadIntegrationException::fromStatus($response->status(), is_array($body) ? $body : []);
    }

    /** @throws SanadIntegrationException When the base URL or token is not configured. */
    private function pending(): PendingRequest
    {
        $baseUrl = (string) config('sanad.client.base_url');
        $token = (string) config('sanad.client.token');
        if ($baseUrl === '' || $token === '') {
            throw SanadIntegrationException::notConfigured();
        }

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(config('sanad.client.timeout_seconds'))
            ->retry(
                config('sanad.client.max_attempts'),
                fn (int $attempt, Throwable $exception): int => $this->backoffMilliseconds($attempt, $this->retryAfter($exception)),
                fn (Throwable $exception): bool => $this->isRetryable($exception),
                throw: false,
            );
    }

    private function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && in_array($exception->response->status(), config('sanad.client.retry_statuses'), true);
    }

    private function retryAfter(Throwable $exception): ?int
    {
        if (! $exception instanceof RequestException) {
            return null;
        }

        $header = $exception->response->header('Retry-After');

        return ctype_digit($header) ? (int) $header : null;
    }
}
