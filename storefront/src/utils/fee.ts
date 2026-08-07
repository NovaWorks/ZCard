/**
 * 支付手续费计算(与后端 PaymentService::calcFee 保持一致)。
 *
 * 输入金额为「展示货币分」,返回 [手续费(分), 应付金额(分)]。
 * - fee_type = fixed → 手续费 = fee × 100(fee 为元)
 * - fee_type = percent → 手续费 = 金额 × fee ÷ 100(fee=5 表示 5%)
 * - fee_bearer = customer → 应付 = 金额 + 手续费(加到用户付款额)
 * - fee_bearer = merchant → 应付不变(手续费从商户实收扣,前端不显示)
 */
export function calcChannelFee(
  amountFen: number,
  channel?: { fee?: number; fee_type?: string; fee_bearer?: string } | null,
): { feeFen: number; payFen: number } {
  const fee = Number(channel?.fee ?? 0)
  if (!channel || fee <= 0) {
    return { feeFen: 0, payFen: amountFen }
  }
  const isPercent = (channel.fee_type || 'percent') === 'percent'
  const feeFen = isPercent
    ? Math.round((amountFen * fee) / 100)
    : Math.round(fee * 100)
  const isCustomer = (channel.fee_bearer || 'merchant') === 'customer'
  return {
    feeFen,
    payFen: isCustomer ? amountFen + feeFen : amountFen,
  }
}
