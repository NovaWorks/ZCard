<?php

return [
    'guest_only' => '当前仅限会员下单,请先登录',
    'captcha_error' => '验证码错误',
    'order_not_found' => '未找到相关订单',
    'insufficient_stock' => '库存不足,需要 :need 张,仅剩 :have 张',

    'auth.register_closed' => '注册功能已关闭',
    'auth.invalid_credentials' => '邮箱或密码错误',
    'auth.account_disabled' => '账号已被禁用',
    'auth.logout_done' => '已退出',
    'auth.captcha_error' => '图形验证码错误',
    'auth.reset_code_throttle' => '验证码已发送,请 60 秒后重试',
    'auth.mail_send_failed' => '邮件发送失败,请检查邮箱设置或联系客服',
    'auth.reset_code_sent' => '验证码已发送至邮箱',
    'auth.reset_code_invalid' => '验证码错误或已过期',
    'auth.password_reset' => '密码重置成功',

    'order.status_abnormal' => '订单状态异常',

    'review.order_not_found' => '订单不存在或未支付',
    'review.already_reviewed' => '该订单已评价',
    'review.disabled' => '当前已关闭用户评价功能',

    'coupon.not_found' => '优惠券不存在',
    'coupon.invalid' => '优惠券已失效',
    'coupon.expired' => '优惠券已过期',
    'coupon.not_for_product' => '该优惠券不适用于此商品',
    'coupon.not_for_category' => '该优惠券不适用于此分类',
    'coupon.below_min' => '未达到优惠券最低消费金额',
    'coupon.exceeds_amount' => '优惠券面值不能大于订单金额',

    'withdrawal.amount_invalid' => '提现金额必须大于 0',
    'withdrawal.below_min' => '最低提现金额为 :min 分',
    'withdrawal.method_disabled' => '该提现方式未开启',
    'withdrawal.insufficient_balance' => '余额不足',

    'payment.channel_disabled' => '该支付通道未启用',

    'mail.disabled' => '邮件功能未开启',

    'insufficient_stock_short' => '库存不足',
];
