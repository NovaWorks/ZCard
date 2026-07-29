<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(string $message = '库存不足', int $code = 0)
    {
        parent::__construct($message, $code);
    }
}
