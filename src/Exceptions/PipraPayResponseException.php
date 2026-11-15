<?php

namespace Imran1oss\PipraPay\Exceptions;

use Throwable;

class PipraPayResponseException extends PipraPayException
{
    /**
     * @param array<string, mixed> $response
     */
    public function __construct(
        string $message,
        public readonly array $response = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
