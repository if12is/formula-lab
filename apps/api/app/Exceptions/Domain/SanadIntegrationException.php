<?php

namespace App\Exceptions\Domain;

/**
 * Thrown by SanadClient when Sanad (LAN direct mode) refuses or cannot be reached.
 * The message is translated; what Sanad said is kept in $remoteMessage, $errors and $extra.
 */
class SanadIntegrationException extends BusinessRuleViolationException
{
    /**
     * @param  array<string, list<string>>  $errors  field => messages (422)
     * @param  array<string, mixed>  $extra  other response keys, e.g. shortfalls
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $remoteMessage = null,
        public readonly array $errors = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(__('exceptions.sanad_not_configured'));
    }

    public static function network(string $detail): self
    {
        return new self(__('exceptions.sanad_network', ['detail' => $detail]));
    }

    public static function floatNotAllowed(string $path): self
    {
        return new self(__('exceptions.sanad_float_not_allowed', ['path' => $path]));
    }

    public static function unexpectedResponse(int $status): self
    {
        return new self(__('exceptions.sanad_unexpected_response'), $status);
    }

    /**
     * Maps a non-2xx contract response (section 0) to a typed exception.
     *
     * @param  array<string, mixed>  $body  decoded JSON body, [] when not JSON
     */
    public static function fromStatus(int $status, array $body): self
    {
        $remote = isset($body['message']) && is_string($body['message']) ? $body['message'] : null;
        $errors = isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : [];
        $extra = array_diff_key($body, ['message' => 1, 'errors' => 1]);

        $message = match (true) {
            $status === 400 => __('exceptions.sanad_malformed'),
            $status === 401 => __('exceptions.sanad_unauthorized'),
            $status === 403 => __('exceptions.sanad_forbidden'),
            $status === 404 => __('exceptions.sanad_not_found'),
            $status === 409 => __('exceptions.sanad_conflict'),
            $status === 422 => __('exceptions.sanad_validation'),
            $status === 429 => __('exceptions.sanad_throttled'),
            $status >= 500 => __('exceptions.sanad_unavailable'),
            default => __('exceptions.sanad_http_error', ['status' => $status]),
        };

        return new self($message, $status, $remote, $errors, $extra);
    }

    /** @return list<array<string, mixed>> Insufficient-stock rows Sanad returned with a 422 (contract 4.3). */
    public function shortfalls(): array
    {
        $rows = $this->extra['shortfalls'] ?? [];

        return is_array($rows) ? array_values($rows) : [];
    }
}
