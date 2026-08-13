<?php

namespace App\Supply\Exceptions;

use RuntimeException;

/**
 * 供货 API 业务异常(携带 error_code 供控制器映射 HTTP 状态码)。
 */
class SupplyApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message ?: $errorCode);
    }

    public static function insufficientBalance(): self
    {
        return new self('insufficient_balance', __('messages.supply_api.insufficient_balance'), 402);
    }

    public static function insufficientStock(): self
    {
        return new self('insufficient_stock', __('messages.supply_api.insufficient_stock'), 409);
    }

    public static function productUnavailable(): self
    {
        return new self('product_unavailable', __('messages.supply_api.product_unavailable'), 404);
    }

    public static function priceNotConfigured(): self
    {
        return new self('price_not_configured', __('messages.supply_api.price_not_configured'), 400);
    }
}
