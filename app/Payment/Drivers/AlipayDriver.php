<?php

namespace App\Payment\Drivers;

use App\Payment\AbstractPaymentDriver;
use App\Payment\Contracts\Payable;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;

/**
 * 支付宝原生驱动(公钥模式 RSA2,参考 dujiao-next / acg-faka)。
 *
 * 加签方式为「密钥」:只需要 应用 APPID + 应用私钥 + 支付宝公钥 三项,
 * 无需证书(应用公钥证书/支付宝根证书)。签名与验签口径:
 * - 下单:公共参数 + biz_content → 剔除空值与 sign → ksort → k=v& → RSA2(SHA256) → base64
 * - 回调:剔除 sign/sign_type 与空值 → ksort → k=v& → 支付宝公钥 openssl_verify
 *
 * 支持支付方式(通道配置 pay_type):
 * - scan 当面付扫码 alipay.trade.precreate → 二维码
 * - web  电脑网站支付 alipay.trade.page.pay → 自动提交表单
 * - h5   手机网站支付 alipay.trade.wap.pay → 自动提交表单
 * - pos  刷卡支付 alipay.trade.pay
 */
class AlipayDriver extends AbstractPaymentDriver
{
    private const GATEWAY = 'https://openapi.alipay.com/gateway.do';

    private const GATEWAY_SANDBOX = 'https://openapi-sandbox.dl.alipaydev.com/gateway.do';

    public function pay(Payable $order, array $config): PaymentResult
    {
        $payType = $config['pay_type'] ?? 'scan';
        $method = match ($payType) {
            'web' => 'alipay.trade.page.pay',
            'h5' => 'alipay.trade.wap.pay',
            'pos' => 'alipay.trade.pay',
            default => 'alipay.trade.precreate',
        };

        $biz = [
            'out_trade_no' => $order->getPayableKey(),
            'total_amount' => bcdiv((string) $order->getPayableAmount(), '100', 2),
            'subject' => $order->getPayableKey(),
        ];
        if ($payType === 'h5') {
            $biz['quit_url'] = rtrim(config('app.url'), '/').'/pay/result?order_no='.$order->getPayableKey();
            $biz['product_code'] = 'QUICK_WAP_WAY';
        }

        $params = [
            'app_id' => $config['app_id'] ?? '',
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $this->namedUrl('payment.notify', ['channel' => 'alipay'], $config),
            'return_url' => $this->namedUrl('payment.return', ['code' => 'alipay'], $config).'?order_no='.$order->getPayableKey(),
            'biz_content' => json_encode($biz, JSON_UNESCAPED_UNICODE),
        ];
        $params['sign'] = $this->rsaSign($params, $config['private_key'] ?? '');

        // 当面付扫码:POST 网关解析 qr_code
        if ($method === 'alipay.trade.precreate') {
            $resp = $this->postGateway($this->gatewayUrl($config), $params);
            $body = $resp['alipay_trade_precreate_response'] ?? [];
            if (($body['code'] ?? '') !== '10000' || empty($body['qr_code'])) {
                $subCode = $body['sub_code'] ?? '';
                $subMsg = $body['sub_msg'] ?? ($body['msg'] ?? '未知错误');
                // 常见:isv.products-not-open-error=未开通当面付产品;
                //      isv.invalid-signature=密钥/签名错误
                $hint = match ($subCode) {
                    'isv.products-not-open-error' => '(未开通当面付产品,请到开放平台产品中心开通)',
                    'isv.invalid-signature', 'isv.insufficient-isv-permissions' => '(签名或权限错误,请核对应用私钥/支付宝公钥)',
                    default => '',
                };
                throw new \RuntimeException("支付宝当面付下单失败: {$subMsg} [{$subCode}] {$hint}");
            }

            return PaymentResult::qrcode((string) $body['qr_code']);
        }

        // 其余方式:返回自动提交表单(浏览器 POST 到网关)
        return PaymentResult::form($this->buildAutoSubmitForm($this->gatewayUrl($config), $params));
    }

    /** 签名内容:剔除 sign 与空值,按 key 升序拼接 k=v& */
    private function buildSignContent(array $params): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $v === '' || $v === null) {
                continue;
            }
            $parts[] = $k.'='.$v;
        }

        return implode('&', $parts);
    }

    /** RSA2 签名(应用私钥),支持 PKCS8/PKCS1、带/不带 PEM 头 */
    private function rsaSign(array $params, string $privateKeyRaw): string
    {
        $key = $this->normalizePem($privateKeyRaw, 'PRIVATE KEY');
        $content = $this->buildSignContent($params);
        if (! openssl_sign($content, $sign, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('支付宝签名失败: 请检查应用私钥是否正确');
        }

        return base64_encode($sign);
    }

    /** 回调验签:剔除 sign/sign_type/空值 → ksort → 支付宝公钥 RSA2 校验 */
    private function verifySign(array $data, string $publicKeyRaw): bool
    {
        $sign = $data['sign'] ?? null;
        unset($data['sign'], $data['sign_type']);
        if (! $sign) {
            return false;
        }
        $pubKey = $this->normalizePem($publicKeyRaw, 'PUBLIC KEY');
        $content = $this->buildSignContent($data);

        return openssl_verify($content, base64_decode((string) $sign), $pubKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /** 私钥/公钥自动补 PEM 头(兼容开放平台复制内容不带头) */
    private function normalizePem(string $raw, string $type): string
    {
        $raw = str_replace('\\n', "\n", trim($raw));
        if ($raw === '') {
            throw new \RuntimeException('密钥为空');
        }
        if (! str_contains($raw, 'BEGIN')) {
            $raw = "-----BEGIN {$type}-----\n".$raw."\n-----END {$type}-----";
        }

        return $raw;
    }

    private function gatewayUrl(array $config): string
    {
        return ($config['mode'] ?? 'normal') === 'sandbox' ? self::GATEWAY_SANDBOX : self::GATEWAY;
    }

    /** POST 支付宝网关(application/x-www-form-urlencoded) */
    private function postGateway(string $url, array $params): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException('支付宝网关请求失败: '.$err);
        }
        $json = json_decode((string) $body, true);
        if (! is_array($json)) {
            throw new \RuntimeException('支付宝响应解析失败: '.mb_substr((string) $body, 0, 200));
        }

        return $json;
    }

    /** 构造自动提交表单 HTML(web/h5/pos 跳转网关) */
    private function buildAutoSubmitForm(string $url, array $params): string
    {
        $inputs = '';
        foreach ($params as $k => $v) {
            $inputs .= '<input type="hidden" name="'.htmlspecialchars((string) $k).'" value="'.htmlspecialchars((string) $v).'"/>';
        }

        return '<form id="alipay_submit" name="alipay_submit" action="'.htmlspecialchars($url).'" method="POST">'
            .$inputs
            .'<input type="submit" value="立即支付" style="display:none;"/>'
            .'</form><script>document.forms["alipay_submit"].submit();</script>';
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $data = $request->all();
        if (! $this->verifySign($data, $config['alipay_public_key'] ?? '')) {
            return null;
        }

        $tradeStatus = $data['trade_status'] ?? null;
        if (! in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return null;
        }

        return [
            'channel_order_no' => $data['trade_no'] ?? null,
            'out_trade_no' => $data['out_trade_no'] ?? null,
            'amount' => (int) round(bcmul((string) ($data['total_amount'] ?? 0), '100', 3)), // 元→分
            'raw' => $data,
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'app_id' => [
                'label' => '应用 APPID',
                'type' => 'text',
                'required' => true,
            ],
            'private_key' => [
                'label' => '应用私钥(密钥管理 → 应用私钥)',
                'type' => 'textarea',
                'required' => true,
                'help' => '支付宝开放平台 → 密钥管理 → 应用私钥,复制完整内容(支持带或不带 -----BEGIN PRIVATE KEY----- 头)',
            ],
            'alipay_public_key' => [
                'label' => '支付宝公钥(密钥管理 → 支付宝公钥)',
                'type' => 'textarea',
                'required' => true,
                'help' => '密钥管理 → 查看支付宝公钥,复制完整内容(支持带或不带 -----BEGIN PUBLIC KEY----- 头)',
            ],
            'pay_type' => [
                'label' => '支付方式',
                'type' => 'select',
                'options' => [
                    'scan' => '当面付扫码(生成二维码)',
                    'web' => '电脑网站支付(PC 页面)',
                    'h5' => '手机网站支付(H5)',
                    'pos' => '刷卡支付(付款码)',
                ],
                'required' => true,
                'default' => 'scan',
            ],
            'mode' => [
                'label' => '运行模式',
                'type' => 'select',
                'options' => ['normal' => '正式环境', 'sandbox' => '沙箱环境'],
                'required' => true,
                'default' => 'normal',
            ],
            'target_currency' => [
                'label' => '收款货币',
                'type' => 'text',
                'required' => false,
                'default' => 'CNY',
            ],
            'exchange_rate' => [
                'label' => '汇率(基础货币→收款货币)',
                'type' => 'text',
                'required' => false,
                'default' => '1',
            ],
        ];
    }

    public function getInfo(): array
    {
        return [
            'name' => '支付宝',
            'icon' => 'alipay',
        ];
    }

    public function getPayTypes(array $config): array
    {
        return [$config['pay_type'] ?? 'scan'];
    }

    public function getSupportedCurrencies(): array
    {
        return ['CNY'];
    }
}
