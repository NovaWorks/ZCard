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
];
