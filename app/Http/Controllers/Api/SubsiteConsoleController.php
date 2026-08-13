<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\SubsiteDomain;
use App\Models\SubsiteLedgerEntry;
use App\Models\SubsiteProductSetting;
use App\Support\DomainVerificationService;
use App\Support\StorefrontConfig;
use App\Support\SubsiteWithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubsiteConsoleController extends Controller
{
    /** 我的分站 */
    public function mySubsite(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) {
            return response()->json(['message' => '您还没有分站'], 404);
        }

        return response()->json($merchant->load('domains'));
    }

    /** 分站财务概览 */
    public function finance(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) {
            return response()->json(['message' => '无分站'], 404);
        }

        $available = SubsiteLedgerEntry::where('merchant_id', $merchant->id)->where('status', 'available')->sum('amount');
        $pending = SubsiteLedgerEntry::where('merchant_id', $merchant->id)->where('status', 'pending')->sum('amount');
        $total = SubsiteLedgerEntry::where('merchant_id', $merchant->id)->whereIn('type', ['order_profit', 'refund_deduct'])->sum('amount');

        return response()->json([
            'total_profit' => (int) $total,
            'available' => (int) $available,
            'pending' => (int) $pending,
        ]);
    }

    /** 利润账本明细 */
    public function ledger(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) {
            return response()->json(['message' => '无分站'], 404);
        }

        return response()->json(
            SubsiteLedgerEntry::where('merchant_id', $merchant->id)->orderByDesc('id')->limit(100)->get()
        );
    }

    /** 域名绑定 */
    public function bindDomain(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) {
            return response()->json(['message' => '无分站'], 404);
        }
        $data = $request->validate([
            'domain' => [
                'required', 'string', 'max:253',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $domain = strtolower(rtrim(trim((string) $value), '.'));
                    if (! DomainVerificationService::isSafePublicDomain($domain)) {
                        $fail('请输入可解析的公网域名，不要包含协议、端口或路径');

                        return;
                    }

                    // 安全(H-1):禁止绑定主站自身域名(劫持主站流量/品牌/利润归属)。
                    $mainHosts = array_filter([
                        strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
                        strtolower((string) parse_url((string) StorefrontConfig::get('site_url'), PHP_URL_HOST)),
                        strtolower((string) $this->normalizeHost((string) request()->getHost())),
                    ]);
                    if (in_array($domain, $mainHosts, true)) {
                        $fail('不允许绑定主站域名');
                    }
                },
            ],
            'type' => 'required|in:subdomain,custom',
        ]);
        $domain = strtolower(rtrim(trim($data['domain']), '.'));

        // 安全(H-1):subdomain 类型同样必须完成归属验证(DNS TXT / HTTP well-known),
        // 不再免验证直写 verified/active;平台通配符场景校验子域后缀。
        $subdomainBase = strtolower(trim((string) StorefrontConfig::get('subsite_subdomain_base')));
        if ($data['type'] === 'subdomain' && $subdomainBase !== '') {
            $base = ltrim($subdomainBase, '.');
            if ($domain === $base || ! str_ends_with($domain, '.'.$base)) {
                return response()->json(['message' => '子域名必须属于平台域名: .'.$base], 422);
            }
        }

        $row = SubsiteDomain::create([
            'merchant_id' => $merchant->id,
            'domain' => $domain,
            'type' => $data['type'],
            'verification_token' => Str::random(32),
            'verification_status' => 'pending',
            'status' => 'pending_review',
            'verified_at' => null,
            'is_primary' => ! SubsiteDomain::where('merchant_id', $merchant->id)->exists(),
        ]);

        // verification_token 仅在本绑定响应中一次性返回(模型已 $hidden,防列表接口外泄);
        // 后续可通过 /domains/{id}/instructions 再次获取验证指引。
        $response = $row->toArray();
        $response['verification_token'] = $row->verification_token;

        return response()->json($response, 201);
    }

    /** Host 归一化(与 ResolveSubsite 一致,用于主站域名比对) */
    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        return rtrim((string) preg_replace('/^www\./', '', $host), '.');
    }

    /** 触发域名验证(DNS TXT + HTTP well-known 双查) */
    public function verifyDomain(Request $request, int $domainId): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) {
            return response()->json(['message' => '无分站'], 404);
        }

        $domain = SubsiteDomain::where('id', $domainId)->where('merchant_id', $merchant->id)->first();
        if (! $domain) {
            return response()->json(['message' => '域名不存在'], 404);
        }
        if ($domain->verification_status === 'verified') {
            return response()->json(['message' => '域名已验证', 'verified' => true]);
        }

        $result = DomainVerificationService::verify($domain);

        return response()->json($result, $result['verified'] ? 200 : 422);
    }

    /** 获取域名验证指引(供前端展示 DNS/HTTP 配置方法) */
    public function domainInstructions(Request $request, int $domainId): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) {
            return response()->json(['message' => '无分站'], 404);
        }

        $domain = SubsiteDomain::where('id', $domainId)->where('merchant_id', $merchant->id)->first();
        if (! $domain) {
            return response()->json(['message' => '域名不存在'], 404);
        }

        return response()->json(DomainVerificationService::getInstructions($domain));
    }

    /** 商品配置列表 */
    public function productSettings(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) {
            return response()->json(['message' => '无分站'], 404);
        }

        return response()->json(
            SubsiteProductSetting::where('merchant_id', $merchant->id)->with('product:id,name,slug,price')->get()
        );
    }

    /** 商品配置 upsert */
    public function upsertProductSetting(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) {
            return response()->json(['message' => '无分站'], 404);
        }
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'is_listed' => 'boolean',
            'pricing_mode' => 'sometimes|in:inherit,markup_percent,fixed_markup,fixed_price',
            'markup_percent' => 'nullable|numeric|min:0',
            'fixed_markup_amount' => 'nullable|integer|min:0',
            'fixed_price_amount' => 'nullable|integer|min:0',
        ]);
        $setting = SubsiteProductSetting::updateOrCreate(
            ['merchant_id' => $merchant->id, 'product_id' => $data['product_id'], 'sku_id' => 0],
            $data
        );

        return response()->json($setting, 201);
    }

    /** 发起提现 */
    public function requestWithdrawal(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) {
            return response()->json(['message' => '无分站'], 404);
        }
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:alipay,wechat,usdt',
            'account' => 'required|string|max:200',
            'account_name' => 'required|string|max:50',
        ]);
        try {
            $w = SubsiteWithdrawalService::request(
                $merchant->id, (int) round($data['amount'] * 100), $data['method'], $data['account'], $data['account_name']
            );

            return response()->json($w, 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /** 更新分站白标配置(站名/logo/公告) — G2 修复 */
    public function updateBranding(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) {
            return response()->json(['message' => '无分站'], 404);
        }
        $data = $request->validate([
            'site_name' => 'nullable|string|max:120',
            'logo' => 'nullable|string|max:500',
            'announcement' => 'nullable|string|max:1000',
        ]);
        $settings = $merchant->settings ?? [];
        $settings = array_merge($settings, array_filter($data, fn ($v) => $v !== null));
        $merchant->update(['settings' => $settings]);

        return response()->json($merchant);
    }

    /** 分站销售订单列表(#4) — 按当前用户分站的订单 */
    public function orders(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) {
            return response()->json(['message' => '无分站'], 404);
        }

        $orders = Order::where('subsite_id', $merchant->id)
            ->with('product:id,name,slug', 'buyer:id,username')
            ->select(['id', 'order_no', 'product_id', 'user_id', 'quantity', 'amount', 'subsite_profit', 'status', 'created_at', 'paid_at'])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'order_no' => $o->order_no,
                'product_name' => $o->product?->name,
                'buyer_name' => $o->buyer?->username ?? '游客',
                'quantity' => $o->quantity,
                'amount' => (int) $o->amount,
                'profit' => (int) $o->subsite_profit,
                'status' => $o->status,
                'created_at' => $o->created_at?->toDateTimeString(),
                'paid_at' => $o->paid_at?->toDateTimeString(),
            ]);

        return response()->json($orders);
    }

    private function getMySubsite(Request $request): ?Merchant
    {
        return Merchant::where('user_id', $request->user()->id)->where('settings->is_subsite', true)->first();
    }
}
