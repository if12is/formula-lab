<?php

namespace App\Http\Middleware;

use App\Support\RequestFingerprint;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Contract section 6. Requires `Idempotency-Key`; for a repeated (key, token, method, path):
 *  - same body: the stored status and body come back with `Idempotent-Replay: true`, nothing re-runs;
 *  - different body: 422;
 *  - still running: 409 + `Retry-After: 1`.
 * 5xx responses and exceptions are not stored, so the key can be retried.
 */
class EnsureIdempotency
{
    private const TABLE = 'idempotency_keys';

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '' || strlen($key) > config('sanad.idempotency.max_key_length')) {
            return response()->json(['message' => __('sanad.idempotency_key_required')], 400);
        }

        $scope = hash('sha256', implode('|', [(string) $request->bearerToken(), $request->method(), $request->path()]));
        $requestHash = RequestFingerprint::hash($request->all());

        if (! $this->claim($key, $scope, $requestHash) && ! $this->reclaimAbandoned($key, $scope, $requestHash)) {
            return $this->resolveExisting($key, $scope, $requestHash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->forget($key, $scope);

            throw $exception;
        }

        if ($response->getStatusCode() >= 500) {
            $this->forget($key, $scope);

            return $response;
        }

        DB::table(self::TABLE)->where('key', $key)->where('scope', $scope)->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => $response->getContent(),
            'updated_at' => now(),
        ]);

        return $response;
    }

    /** True when this request created the row. `insertOrIgnore` avoids poisoning an open PostgreSQL transaction. */
    private function claim(string $key, string $scope, string $requestHash): bool
    {
        return DB::table(self::TABLE)->insertOrIgnore([
            'key' => $key,
            'scope' => $scope,
            'request_hash' => $requestHash,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    /** A run that died mid-flight leaves a row without a response; after the timeout the key may be taken over. */
    private function reclaimAbandoned(string $key, string $scope, string $requestHash): bool
    {
        $deleted = DB::table(self::TABLE)
            ->where('key', $key)
            ->where('scope', $scope)
            ->whereNull('response_status')
            ->where('created_at', '<', now()->subSeconds(config('sanad.idempotency.in_flight_timeout_seconds')))
            ->delete();

        return $deleted > 0 && $this->claim($key, $scope, $requestHash);
    }

    private function resolveExisting(string $key, string $scope, string $requestHash): Response
    {
        $existing = DB::table(self::TABLE)->where('key', $key)->where('scope', $scope)->first();
        if ($existing === null) {
            return $this->inFlight();
        }

        if (! hash_equals((string) $existing->request_hash, $requestHash)) {
            return response()->json(['message' => __('sanad.idempotency_mismatch')], 422);
        }

        if ($existing->response_status === null) {
            return $this->inFlight();
        }

        $replay = JsonResponse::fromJsonString((string) $existing->response_body, (int) $existing->response_status);
        $replay->headers->set('Idempotent-Replay', 'true');

        return $replay;
    }

    private function inFlight(): Response
    {
        return response()->json(['message' => __('sanad.request_in_flight')], 409, ['Retry-After' => '1']);
    }

    private function forget(string $key, string $scope): void
    {
        DB::table(self::TABLE)->where('key', $key)->where('scope', $scope)->delete();
    }
}
