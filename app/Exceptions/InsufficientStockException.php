<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(string $message = null, int $code = 0)
    {
        parent::__construct($message ?? __('messages.insufficient_stock_short'), $code);
    }
}
