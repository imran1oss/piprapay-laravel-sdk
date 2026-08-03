<?php

namespace Imran1oss\PipraPay\Exceptions;

class PipraPayResponseException extends PipraPayException
{
    /**
     * @param array<string, mixed> $response
     */
    public function __construct(
        string $message,
        public readonly array $response = [],
    ) {
        parent::__construct($message);
    }
}
