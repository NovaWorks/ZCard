<?php

return [
    'guest_only' => 'Only members can checkout. Please log in first.',
    'captcha_error' => 'Invalid captcha.',
    'order_not_found' => 'No matching orders found.',
    'insufficient_stock' => 'Insufficient stock: :need needed, only :have left.',

    'auth.register_closed' => 'Registration is currently closed.',
    'auth.invalid_credentials' => 'Invalid email or password.',
    'auth.account_disabled' => 'This account has been disabled.',
    'auth.logout_done' => 'Logged out.',
    'auth.captcha_error' => 'Invalid captcha.',
    'auth.reset_code_throttle' => 'Verification code already sent. Please try again in 60 seconds.',
    'auth.mail_send_failed' => 'Failed to send email. Please check your mailbox settings or contact support.',
    'auth.reset_code_sent' => 'Verification code has been sent to your email.',
    'auth.reset_code_invalid' => 'Verification code is invalid or expired.',
    'auth.password_reset' => 'Your password has been reset successfully.',

    'order.status_abnormal' => 'Order status is abnormal.',

    'review.order_not_found' => 'Order does not exist or is unpaid.',
    'review.already_reviewed' => 'This order has already been reviewed.',
    'review.disabled' => 'Product reviews are currently disabled.',

    'coupon.not_found' => 'Coupon does not exist.',
    'coupon.invalid' => 'Coupon is no longer valid.',
    'coupon.expired' => 'Coupon has expired.',
    'coupon.not_for_product' => 'This coupon is not applicable to this product.',
    'coupon.not_for_category' => 'This coupon is not applicable to this category.',
    'coupon.below_min' => 'Order amount does not meet the coupon minimum spend.',
    'coupon.exceeds_amount' => 'Coupon value cannot exceed the order amount.',

    'withdrawal.amount_invalid' => 'Withdrawal amount must be greater than 0.',
    'withdrawal.below_min' => 'Minimum withdrawal amount is :min.',
    'withdrawal.method_disabled' => 'This withdrawal method is not enabled.',
    'withdrawal.insufficient_balance' => 'Insufficient balance.',

    'payment.channel_disabled' => 'This payment channel is not enabled.',

    'mail.disabled' => 'Mail service is not enabled.',

    'insufficient_stock_short' => 'Insufficient stock.',
    'currency_base_undeletable' => 'Base currency cannot be deleted.',

    // ===== Supply Integration (spec §8.1) =====
    'supply.driver_dujiao_next' => 'Dujiao-next',
    'supply.driver_acg_faka' => 'ACG Faka',
    'supply.driver_zcard' => 'ZCard',
    'supply.field_base_url' => 'Site URL',
    'supply.field_api_key' => 'API Key',
    'supply.field_api_secret' => 'API Secret',
    'supply.field_app_id' => 'App ID',
    'supply.field_app_key' => 'App Key',
    'supply.stock_mode_realtime' => 'Realtime query',
    'supply.stock_mode_realtime_help' => 'Stock shown to customers is queried from upstream in real time. Most accurate, no oversell; adds one upstream request per storefront view.',
    'supply.stock_mode_synced' => 'Local cached sync',
    'supply.stock_mode_synced_help' => 'Upstream stock is periodically copied to local cache. Faster; has oversell risk (upstream may sell out between syncs), re-checked at order time as fallback.',
    'supply.failure_manual' => 'Manual intervention',
    'supply.failure_auto_refund' => 'Auto refund',
    'supply.pricing_fixed_markup' => 'Fixed markup',
    'supply.pricing_percent_markup' => 'Percent markup',
    'supply.pricing_equal_cost' => 'At cost',
    'supply.pricing_pending' => 'Leave blank',
    'supply.secret_show_once_warning' => 'Copy and save the API Secret now. It cannot be viewed again after closing.',
    'supply.balance_low_warning' => 'Low prepaid balance',

    'supply_api.insufficient_balance' => 'Insufficient balance',
    'supply_api.insufficient_stock' => 'Insufficient stock',
    'supply_api.product_unavailable' => 'Product unavailable',
    'supply_api.order_not_cancelable' => 'Order cannot be canceled',
    'supply_api.bad_request' => 'Bad request',
    'supply_api.timestamp_expired' => 'Request expired',
    'supply_api.invalid_signature' => 'Invalid signature',
    'supply_api.nonce_reused' => 'Nonce already used',
    'supply_api.unauthorized' => 'Unauthorized',
];
