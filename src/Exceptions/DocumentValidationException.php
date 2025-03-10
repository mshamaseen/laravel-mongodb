<?php

namespace MongoDB\Laravel\Exceptions;

use Throwable;

class DocumentValidationException extends \Exception
{
    public function __construct(string $message, public array $values, public array $required ,int $code = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
