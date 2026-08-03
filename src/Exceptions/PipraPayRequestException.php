<?php

namespace Imran1oss\PipraPay\Exceptions;

use Throwable;

class PipraPayRequestException extends PipraPayException
{
    /**
     * @param array<string, mixed> $response
     */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $errorCode = null,
        public readonly array $response = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }
}
