<?php

declare(strict_types=1);

namespace App\Pegasus;

/**
 * Outcome of a single Pegasus createOrder API call.
 */
final readonly class CreateOrderResult
{
    /**
     * @param string $responseBody Truncated raw response body from Pegasus (empty if no HTTP response).
     */
    public function __construct(
        public bool $success,
        public int $statusCode,
        public string $externalOrderId,
        public string $externalOrderNumber,
        public string $message,
        public string $responseBody,
    ) {
    }
}
