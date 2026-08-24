<?php

namespace EvolutionCMS\aIMage\Gateway;

use RuntimeException;

/**
 * A gateway call that did not produce a usable answer.
 *
 * `retryable` is the distinction the worker acts on: a 429 or a 502 is worth
 * another attempt, a 400 or a 403 will fail identically every time and burning
 * four attempts on it only delays the error the manager needs to see.
 */
class GatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly bool $retryable = false,
        public readonly ?string $errorCode = null,
        public readonly ?array $body = null
    ) {
        parent::__construct($message, $status);
    }

    public static function fromStatus(int $status, string $message, ?array $body = null): self
    {
        // 408/409/425/429 and the whole 5xx range are transient by contract.
        $retryable = $status === 0
            || $status === 408
            || $status === 409
            || $status === 425
            || $status === 429
            || $status >= 500;

        $code = null;
        if (is_array($body)) {
            $code = $body['error']['code'] ?? $body['error']['type'] ?? $body['code'] ?? null;
            $code = is_string($code) ? $code : null;
        }

        return new self($message, $status, $retryable, $code, $body);
    }

    /** A key the gateway refused. Never retried, and surfaced to the manager verbatim. */
    public function isAuthFailure(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }
}
