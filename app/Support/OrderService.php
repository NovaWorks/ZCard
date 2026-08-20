<?php

namespace App\Support;

use App\Events\OrderPaid;
use App\Exceptions\InsufficientStockException;
use App\Models\Card;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Review;
use App\Models\SubsiteOrderSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OrderService
{
    /** 单次查单最多比对的访问凭证数(浏览器一般只持有个位数订单凭证) */
    public const MAX_ACCESS_TOKENS = 20;

    /**
     * 创建订单:锁卡 → 建 order(pending)。
     *
     * @param  array  $customer  [contact, password?, extra?, card_id?]
     *                           card_id: 靓号自选模式下客户选定的具体卡密(锁该卡,按卡价计算)
     *
     * @throws InsufficientStockException
     */
    public function createOrder(int $productId, ?int $skuId, int $qty, array $customer, ?string $displayCurrency = null): Order
    {
        // 安全(H-1):上架/隐藏状态与购买约束必须服务端强制——此前仅在前台列表过滤,
        // 直接调 API 可购买下架/隐藏商品、游客购买会员商品、无视限购囤货。
        // 下架/隐藏与"不存在"同响应(404),不泄露商品存在性。
        $product = Product::with('skus')
            ->where('status', true)
            ->where('hide', false)
            ->findOrFail($productId);

        // 动态控件只接受商品声明过的字段，并在本站先完成必填/选项/正则校验。
        // 对接上游商品时，这些值会随订单快照传给货源驱动。
        $customer['extra'] = $this->validateControlValues(
            is_array($product->control_config) ? $product->control_config : [],
            is_array($customer['extra'] ?? null) ? $customer['extra'] : [],
        );

        $userId = $customer['user_id'] ?? null;
        if ($product->only_user && ! $userId) {
            throw new \RuntimeException(__('messages.order.member_only'));
        }

        $minOrder = max(1, (int) ($product->min_order ?? 1));
        if ($qty < $minOrder) {
            throw new \RuntimeException(__('messages.order.below_min_order', ['min' => $minOrder]));
        }
        $maxOrder = (int) ($product->max_order ?? 0);
        if ($maxOrder > 0 && $qty > $maxOrder) {
            throw new \RuntimeException(__('messages.order.above_max_order', ['max' => $maxOrder]));
        }
        $purchaseLimit = (int) ($product->purchase_limit ?? 0);
        if ($purchaseLimit > 0) {
            if (! $userId) {
                throw new \RuntimeException(__('messages.order.member_only'));
            }
            // 限购按「已支付 + 待支付」累计数量口径(待支付同样占用库存与购买名额)
            $bought = (int) Order::where('user_id', $userId)
                ->where('product_id', $productId)
                ->whereIn('status', ['paid', 'pending'])
                ->sum('quantity');
            if ($bought + $qty > $purchaseLimit) {
                throw new \RuntimeException(__('messages.order.purchase_limit_exceeded', ['limit' => $purchaseLimit]));
            }
        }

        $fulfillmentType = $product->resolvedFulfillmentType();
        $premium = $fulfillmentType === Product::FULFILLMENT_AUTO_CARD
            && ($product->pick_type ?? 'general') === 'premium';
        $cardId = $customer['card_id'] ?? null;

        // 靓号自选:单价来自所选卡密(第二段价格),qty 强制 1
        $unitPrice = null;
        if ($premium) {
            if (! $cardId) {
                throw new \RuntimeException('靓号自选商品必须选择具体靓号');
            }
            $qty = 1;
            $selectedCard = Card::where('product_id', $productId)
                ->where('id', $cardId)
                ->where('status', Card::STATUS_UNUSED)
                ->first();
            if (! $selectedCard) {
                throw new \RuntimeException('所选靓号不可用或已被购买');
            }
            $unitPrice = $selectedCard->price ?? (int) $product->price;
        } else {
            // 安全(H-1):SKU 必须归属本商品且启用;跨商品/禁用 SKU 直接拒绝,不再回退商品原价
            $sku = $skuId ? $product->skus->firstWhere('id', $skuId) : null;
            if ($skuId && (! $sku || ! $sku->status)) {
                throw new \RuntimeException(__('messages.order.sku_unavailable'));
            }
            $unitPrice = $sku ? (int) $sku->price : (int) $product->price;
        }
        $amount = $unitPrice * $qty;

        // 分站定价(spec §5):读 subsite,按分站加价。
        // 靓号自选:价格已由客户所选靓号决定,不再叠加分站加价。
        $subsite = request()->attributes->get('subsite');
        $subsiteId = null;
        $subsiteDomain = null;
        $baseUnitPrice = $unitPrice;
        $profitEligible = true;
        $profitBlockReason = null;
        if ($subsite && ! $premium) {
            $pricing = app(SubsitePricingService::class)
                ->resolveUnitPrice($product, $skuId ? $product->skus->firstWhere('id', $skuId) : null, $subsite);
            $unitPrice = $pricing['price'];
            $amount = $unitPrice * $qty; // 重算金额(加价后)
            $subsiteId = $subsite->id;
            $subsiteDomain = request()->host();
            // 防自购(spec §6)
            $buyerId = $customer['user_id'] ?? null;
            if ($buyerId && $buyerId == $subsite->user_id) {
                $profitEligible = false;
                $profitBlockReason = 'self_dealing_owner';
            } elseif ($buyerId) {
                $upline = User::find($buyerId);
                for ($i = 0; $i < 3 && $upline && $upline->pid; $i++) {
                    $upline = User::find($upline->pid);
                    if ($upline && $upline->id == $subsite->user_id) {
                        $profitEligible = false;
                        $profitBlockReason = 'self_dealing_upline';
                        break;
                    }
                }
            }
        }

        // 优惠券处理(购物车批量下单支持任意数量;仅非分站订单可用)
        $couponCode = $customer['coupon_code'] ?? null;
        $discountAmount = 0;
        $coupon = null;

        if ($couponCode && ! $subsite) {
            $result = CouponService::validate($couponCode, $productId, $amount);
            $discountAmount = $result['discount'];
            $coupon = $result['coupon'];
            $amount = max(0, $amount - $discountAmount);
        }

        return DB::transaction(function () use ($productId, $skuId, $qty, $customer, $product, $amount, $discountAmount, $couponCode, $coupon, $displayCurrency, $subsite, $subsiteId, $subsiteDomain, $baseUnitPrice, $unitPrice, $profitEligible, $profitBlockReason, $premium, $cardId, $fulfillmentType) {
            $cards = collect();

            if ($fulfillmentType === Product::FULFILLMENT_AUTO_CARD) {
                if ($premium) {
                    // 靓号自选:锁客户选定的那张卡(事务内再次校验未被占用)
                    $cards = Card::where('product_id', $productId)
                        ->where('id', $cardId)
                        ->where('status', Card::STATUS_UNUSED)
                        ->lockForUpdate()
                        ->limit(1)
                        ->get();
                    if ($cards->count() < 1) {
                        throw new \RuntimeException('所选靓号不可用或已被购买');
                    }
                } else {
                    // 常规:锁任意未使用卡(FOR UPDATE 防并发超卖)
                    $cards = Card::where('product_id', $productId)
                        ->where('status', Card::STATUS_UNUSED)
                        ->lockForUpdate()
                        ->limit($qty)
                        ->get();

                    if ($cards->count() < $qty) {
                        throw new InsufficientStockException(__('messages.insufficient_stock', ['need' => $qty, 'have' => $cards->count()]));
                    }
                }
            }

            $extra = array_merge(
                $skuId ? ['sku_id' => $skuId, 'sku_name' => $product->skus->firstWhere('id', $skuId)?->name] : [],
                $cardId ? ['card_id' => (int) $cardId] : [],
                ['control' => $customer['extra'] ?? []],
            );

            // 查询密码加密存
            if (! empty($customer['password'])) {
                $extra['query_password'] = Hash::make($customer['password']);
            }

            // 游客订单访问凭证：明文仅在创建响应中返回，数据库只保存 SHA-256。
            // 订单号和联系方式都可能出现在邮件、日志或聊天记录中，不能作为卡密读取权限。
            $accessToken = bin2hex(random_bytes(32));
            $extra['access_token_hash'] = hash('sha256', $accessToken);

            // 创建订单(含成本/SKU 快照)
            $skuName = $skuId ? ($product->skus->firstWhere('id', $skuId)?->name) : null;
            $unitCost = (int) $product->factory_price;

            // 货币快照(spec §3.5):下单瞬间锁定显示汇率
            $currencySvc = app(CurrencyService::class);
            $baseCur = $currencySvc->getBaseCurrency();
            $dispCur = $displayCurrency ?: $baseCur;
            $conv = $currencySvc->convert((int) $amount, $dispCur);

            $order = Order::create([
                'order_no' => $this->generateOrderNo(),
                'merchant_id' => $product->merchant_id,
                'user_id' => $customer['user_id'] ?? null,
                'product_id' => $productId,
                'quantity' => $qty,
                'amount' => $amount,
                'base_currency' => $baseCur,
                'display_currency' => $conv['currency'],
                'exchange_rate' => $conv['rate'],
                'amount_display' => $conv['amount'],
                'subsite_id' => $subsiteId,
                'subsite_domain' => $subsiteDomain,
                'subsite_profit' => $profitEligible ? (($unitPrice - $baseUnitPrice) * $qty) : 0,
                'coupon_code' => $couponCode,
                'discount_amount' => $discountAmount,
                'cost' => $unitCost * $qty,
                'sku_name' => $skuName,
                'status' => 'pending',
                'delivery_status' => 'pending',
                'fulfillment_type_snapshot' => $fulfillmentType,
                'contact' => $customer['contact'] ?? null,
                // 创建订单时锁定付款后说明，后续商品编辑不影响历史订单。
                'instructions_snapshot' => $product->leave_message ?: null,
                'delivery_message_snapshot' => $fulfillmentType === Product::FULFILLMENT_FIXED
                    ? ($product->delivery_message ?: null)
                    : null,
                'create_ip' => $customer['create_ip'] ?? null,
                'create_device' => $customer['create_device'] ?? null,
                'extra' => $extra,
            ]);

            // 核销优惠券
            if ($coupon) {
                CouponService::apply($coupon, $order->id, $customer['user_id'] ?? null);
            }

            // 分站订单定价快照(spec §5)
            if ($subsiteId) {
                SubsiteOrderSnapshot::create([
                    'order_id' => $order->id,
                    'merchant_id' => $subsiteId,
                    'domain' => $subsiteDomain,
                    'reseller_user_id' => $subsite->user_id,
                    'buyer_id' => $customer['user_id'] ?? null,
                    'base_amount' => $baseUnitPrice * $qty,
                    'reseller_amount' => $amount,
                    'profit_amount' => $profitEligible ? (($unitPrice - $baseUnitPrice) * $qty) : 0,
                    'profit_eligible' => $profitEligible,
                    'profit_block_reason' => $profitBlockReason,
                    'pricing_snapshot' => ['unit_base' => $baseUnitPrice, 'unit_reseller' => $unitPrice, 'qty' => $qty],
                    'risk_snapshot' => ['profit_eligible' => $profitEligible, 'profit_block_reason' => $profitBlockReason],
                ]);
            }

            // 仅自动卡密商品在下单时锁库存；其他类型在付款后按各自履约流程处理。
            if ($fulfillmentType === Product::FULFILLMENT_AUTO_CARD) {
                $cards->each->update([
                    'status' => Card::STATUS_LOCKED,
                    'locked_at' => now(),
                    'order_id' => $order->id,
                ]);
            }

            // 仅供本次 HTTP 响应读取，不会持久化明文访问凭证。
            $order->setAccessTokenForResponse($accessToken);

            return $order;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validateControlValues(array $fields, array $input): array
    {
        $clean = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $name = is_scalar($field['name'] ?? null) ? (string) $field['name'] : '';
            if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/D', $name)) {
                continue;
            }

            $label = is_scalar($field['label'] ?? null) && trim((string) $field['label']) !== ''
                ? trim((string) $field['label'])
                : $name;
            $value = $input[$name] ?? null;
            $isEmpty = $value === null || $value === '' || $value === [];
            if (! empty($field['required']) && $isEmpty) {
                throw new \RuntimeException("请填写{$label}");
            }
            if ($isEmpty) {
                continue;
            }

            if (is_array($value)) {
                if (count($value) > 50) {
                    throw new \RuntimeException("{$label}选择项过多");
                }
                $value = array_values(array_map(static function (mixed $item) use ($label): string {
                    if (! is_scalar($item) || mb_strlen((string) $item) > 500) {
                        throw new \RuntimeException("{$label}格式不正确");
                    }

                    return trim((string) $item);
                }, $value));
            } elseif (is_scalar($value)) {
                $value = trim((string) $value);
                if (mb_strlen($value) > 2000) {
                    throw new \RuntimeException("{$label}内容过长");
                }
            } else {
                throw new \RuntimeException("{$label}格式不正确");
            }

            $options = array_values(array_filter(
                is_array($field['options'] ?? null) ? $field['options'] : [],
                'is_scalar',
            ));
            if ($options !== []) {
                $selected = is_array($value) ? $value : [$value];
                foreach ($selected as $option) {
                    if (! in_array((string) $option, array_map('strval', $options), true)) {
                        throw new \RuntimeException("{$label}选项无效");
                    }
                }
            }

            $regex = is_scalar($field['regex'] ?? null) ? trim((string) $field['regex']) : '';
            if ($regex !== '' && is_string($value)) {
                $pattern = '#'.str_replace('#', '\\#', $regex).'#u';
                $matched = @preg_match($pattern, $value);
                if ($matched !== 1) {
                    $message = is_scalar($field['error'] ?? null) ? trim((string) $field['error']) : '';
                    throw new \RuntimeException($message !== '' ? $message : "{$label}格式不正确");
                }
            }

            $clean[$name] = $value;
        }

        return $clean;
    }

    /**
     * 购物车批量下单:一个事务内为多个商品各创建一张订单。
     *
     * @param  array  $items  [{product_id, sku_id?, qty}]
     * @param  array  $customer  [contact, password?, extra?, coupon_code?, user_id?, create_ip?, create_device?]
     * @return Collection<int, Order> 订单集合(与 items 顺序一致)
     *
     * @throws InsufficientStockException 任一商品库存不足时整体回滚
     */
    public function batchCreate(array $items, array $customer, ?string $displayCurrency = null): Collection
    {
        if (empty($items)) {
            throw new \InvalidArgumentException(__('messages.order.items_required'));
        }

        // 优惠券只作用于购物车中第一个适用商品(逐商品尝试验证)
        $couponCode = $customer['coupon_code'] ?? null;
        $couponUsed = false;

        return DB::transaction(function () use ($items, $customer, $displayCurrency, $couponCode, &$couponUsed) {
            $orders = [];
            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $skuId = isset($item['sku_id']) ? (int) $item['sku_id'] : null;
                $qty = max(1, (int) ($item['qty'] ?? 1));

                $itemCustomer = $customer;
                // 靓号自选:透传该商品项选定的卡密
                if (! empty($item['card_id'])) {
                    $itemCustomer['card_id'] = (int) $item['card_id'];
                }
                if ($couponCode && ! $couponUsed) {
                    // 让 createOrder 对该商品应用优惠券;不适用则跳过该商品继续尝试下一个
                    $itemCustomer['coupon_code'] = $couponCode;
                } else {
                    unset($itemCustomer['coupon_code']);
                }

                try {
                    $orders[] = $this->createOrder($productId, $skuId, $qty, $itemCustomer, $displayCurrency);
                    $couponUsed = true; // 创建成功即视为该商品适用(createOrder 内部已验证并核销)
                } catch (\RuntimeException $e) {
                    // 优惠券不适用当前商品:去掉券码重试(避免把非券错误吞掉)
                    if ($couponCode && ! $couponUsed && $this->isCouponError($e)) {
                        unset($itemCustomer['coupon_code']);
                        $orders[] = $this->createOrder($productId, $skuId, $qty, $itemCustomer, $displayCurrency);

                        continue;
                    }
                    throw $e;
                }
            }

            return collect($orders);
        });
    }

    /** 判断异常是否由优惠券校验引起(用于购物车批量下单时逐个商品尝试) */
    private function isCouponError(\RuntimeException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, __('messages.coupon.not_for_product'))
            || str_contains($msg, __('messages.coupon.not_for_category'))
            || str_contains($msg, __('messages.coupon.below_min'))
            || str_contains($msg, __('messages.coupon.exceeds_amount'));
    }

    /**
     * 标记支付成功(状态机 pending→paid)。
     * 发卡通过 OrderPaid 事件触发(同步监听),事件在事务内派发,
     * 因此发卡与状态变更同事务,保证一致性(参考 acg-faka orderSuccess)。
     * 若发卡失败,整个事务回滚,订单回到 pending,第三方会重试回调。
     */
    public function markPaid(string $orderNo): Order
    {
        $order = DB::transaction(function () use ($orderNo) {
            $order = Order::where('order_no', $orderNo)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'pending') {
                throw new \RuntimeException("订单状态异常: {$order->status},无法支付");
            }
            // 快照支付渠道(从 payments 关联取成功的渠道码)
            $paymentChannel = Payment::where('order_id', $order->id)
                ->where('status', 'success')->value('channel');
            $order->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_channel' => $paymentChannel ?? $order->payment_channel,
            ]);
            // 事件在事务内派发,监听器(DeliveryService)同步执行,
            // 确保发卡与状态变更原子一致
            event(new OrderPaid($order));

            return $order;
        });

        return $order;
    }

    /** 超时关单(Scheduler 调),返回关闭数 */
    public function closeExpired(): int
    {
        $minutes = (int) (StorefrontConfig::get('order_close_minutes') ?? 15);
        $cutoff = now()->subMinutes($minutes);

        $expired = Order::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->get();

        $count = 0;
        foreach ($expired as $order) {
            // 安全(H-8):慢支付通道(USDT 链上)到账常超过常规关单时间;存在未结慢通道
            // 支付流水且流水仍在宽限期内的订单顺延关闭,防止「用户已转账、订单已被关、
            // 卡已释放被他人买走 → 付款成功永不发货」的资损场景。
            if ($this->hasActiveSlowChannelPayment($order->id)) {
                continue;
            }

            $closed = DB::transaction(function () use ($order) {
                // 安全(M-7):行锁后复检状态——支付回调可能在关单瞬间把订单置为 paid,
                // 未复检会导致"已付款订单被翻成 closed、付款不发卡"。
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'pending') {
                    return false;
                }
                $locked->update(['status' => 'closed', 'closed_at' => now()]);
                Card::where('order_id', $locked->id)
                    ->where('status', Card::STATUS_LOCKED)
                    ->update([
                        'status' => Card::STATUS_UNUSED,
                        'locked_at' => null,
                        'order_id' => null,
                    ]);
                CouponService::release($locked->id);

                return true;
            });

            if ($closed) {
                $count++;
            }
        }

        return $count;
    }

    /** 慢支付通道列表(链上到账通常 >15 分钟) */
    public const SLOW_CHANNELS = ['usdt', 'okpay', 'tokenpay', 'epusdt', 'bepusdt'];

    /** 是否存在仍在宽限期内的未结慢通道支付流水 */
    private function hasActiveSlowChannelPayment(int $orderId): bool
    {
        $grace = (int) (StorefrontConfig::get('slow_channel_close_grace_minutes') ?: 60);

        return Payment::where('order_id', $orderId)
            ->whereIn('channel', self::SLOW_CHANNELS)
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subMinutes(max(5, $grace)))
            ->exists();
    }

    /** 后台手动关闭 */
    public function closeOrder(int $orderId): Order
    {
        $order = null;
        DB::transaction(function () use ($orderId, &$order) {
            // 安全(M-7):行锁 + 事务内复检状态,防止与支付回调竞态翻转已支付订单。
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'pending') {
                throw new \RuntimeException('仅待支付订单可关闭');
            }
            $order->update(['status' => 'closed', 'closed_at' => now()]);
            Card::where('order_id', $order->id)
                ->where('status', Card::STATUS_LOCKED)
                ->update(['status' => Card::STATUS_UNUSED, 'locked_at' => null, 'order_id' => null]);
            CouponService::release($order->id);
        });

        return $order->fresh();
    }

    /** 查询单笔订单：联系方式和订单号只是定位条件，仍须通过对象级授权。 */
    public function queryOrder(
        string $contact,
        string $orderNo,
        ?string $password = null,
        ?string $accessToken = null,
        ?int $userId = null,
    ): ?Order {
        $order = Order::where('order_no', $orderNo)
            ->where('contact', $contact)
            ->with('orderDeliveries')
            ->first();

        if (! $order) {
            return null;
        }

        if (! $this->canAccessOrder($order, $userId, $accessToken, $password)) {
            return null;
        }

        return $order;
    }

    /**
     * 搜索订单:单关键字智能匹配 order_no 或 contact,返回历史订单列表。
     * 每一笔结果都必须通过本人登录态、随机访问凭证或查询密码之一授权。
     *
     * @param  string|array<int, string>|null  $accessToken  单个凭证,或浏览器持有的一批凭证(按联系方式查单时会一次带多个)
     * @return array{orders: array<int, array>, matched: int} orders=已授权订单;matched=关键字命中的订单总数(含未授权,供上层做爆破计数)
     */
    public function searchOrders(
        string $keyword,
        ?string $password = null,
        string|array|null $accessToken = null,
        ?int $userId = null,
    ): array {
        $kw = trim($keyword);
        $query = Order::with(['product:id,name,cover', 'orderDeliveries:id,order_id,card_content'])
            ->where(fn ($q) => $q->where('order_no', $kw)->orWhere('contact', $kw))
            ->orderByDesc('id')
            ->limit(50);

        $orders = $query->get();
        $matched = $orders->count();

        // 对每一张订单做对象级授权，不能把“知道订单号/联系方式”当作读取卡密的权限。
        $orders = $orders->filter(fn (Order $order) => $this->canAccessOrder(
            $order,
            $userId,
            $accessToken,
            $password,
        ))->values();

        $mapped = $orders->map(fn ($o) => [
            'order_no' => $o->order_no,
            'product_name' => $o->product?->name,
            'product_cover' => $o->product?->cover,
            'quantity' => $o->quantity,
            'amount' => $o->amount,
            'amount_display' => $o->amount_display,
            'display_currency' => $o->display_currency,
            'exchange_rate' => $o->exchange_rate,
            'status' => $o->status,
            'delivery_status' => $o->delivery_status,
            'fulfillment_type' => $o->fulfillment_type_snapshot,
            'created_at' => $o->created_at?->toIso8601String(),
            'paid_at' => $o->paid_at?->toIso8601String(),
            'cards' => $o->status === 'paid'
                ? ($o->orderDeliveries?->map(fn ($d) => $d->card_content)->toArray() ?? [])
                : [],
            'instructions' => $o->status === 'paid' ? ($o->instructions_snapshot ?: null) : null,
        ])->toArray();

        return ['orders' => $mapped, 'matched' => $matched];
    }

    /**
     * 校验订单对象级访问权限：本人登录态、随机访问凭证或订单查询密码三选一。
     *
     * @param  string|array<int, string>|null  $accessToken  允许传入多个凭证:按联系方式查单时,
     *                                                       浏览器无法预知命中哪几笔订单,只能把本机持有的凭证一起提交,
     *                                                       由服务端逐笔比对(凭证本就属于提交者,不构成越权面)。
     */
    public function canAccessOrder(
        Order $order,
        ?int $userId = null,
        string|array|null $accessToken = null,
        ?string $password = null,
    ): bool {
        if ($userId !== null && $order->user_id !== null && (int) $order->user_id === $userId) {
            return true;
        }

        $extra = is_array($order->extra) ? $order->extra : [];
        $storedTokenHash = (string) ($extra['access_token_hash'] ?? '');
        if ($storedTokenHash !== '') {
            foreach ($this->normalizeAccessTokens($accessToken) as $token) {
                if (hash_equals($storedTokenHash, hash('sha256', $token))) {
                    return true;
                }
            }
        }

        $storedPasswordHash = (string) ($extra['query_password'] ?? '');

        return $storedPasswordHash !== ''
            && $password !== null
            && $password !== ''
            && Hash::check($password, $storedPasswordHash);
    }

    /**
     * 把凭证入参归一成非空字符串数组(去重),同时限制单次比对上限,避免被当成哈希计算放大器。
     *
     * @param  string|array<int, mixed>|null  $accessToken
     * @return array<int, string>
     */
    private function normalizeAccessTokens(string|array|null $accessToken): array
    {
        $tokens = is_array($accessToken) ? $accessToken : [$accessToken];

        return array_slice(array_values(array_unique(array_filter(
            array_map(fn ($token) => is_string($token) ? trim($token) : '', $tokens),
            fn ($token) => $token !== '',
        ))), 0, self::MAX_ACCESS_TOKENS);
    }

    /** 订单详情(含发货卡密) */
    public function getOrderDetail(Order $order): array
    {
        $order->load('orderDeliveries', 'product');

        $cards = $order->status === 'paid'
            ? $order->orderDeliveries->map(fn ($d) => $d->card_content)->toArray()
            : [];

        return [
            'order_no' => $order->order_no,
            'status' => $order->status,
            'delivery_status' => $order->delivery_status,
            'fulfillment_type' => $order->fulfillment_type_snapshot,
            'product_name' => $order->product?->name,
            'quantity' => $order->quantity,
            'amount' => $order->amount,
            'amount_display' => $order->amount_display,
            'display_currency' => $order->display_currency,
            'exchange_rate' => $order->exchange_rate,
            'created_at' => $order->created_at,
            'paid_at' => $order->paid_at,
            'cards' => $cards,
            'instructions' => $order->status === 'paid' ? ($order->instructions_snapshot ?: null) : null,
            // 安全(低危):extra 只回传控制字段,不含 query_password/access_token_hash
            // 两个哈希(常被截图转发,离线爆破弱查询密码后可绕过在线锁定读卡密)。
            'extra' => collect($order->extra ?? [])
                ->except(['query_password', 'access_token_hash'])
                ->all(),
        ];
    }

    /** 我的订单(登录用户的历史订单) */
    public function myOrders(int $userId): array
    {
        $orders = Order::where('user_id', $userId)
            ->with(['product:id,name,cover', 'orderDeliveries:id,order_id,card_content'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // 批量查已评价订单(供前端展示"评价"入口)
        $reviewedOrderIds = Review::whereIn('order_id', $orders->pluck('id'))
            ->pluck('order_id')
            ->all();

        return $orders->map(fn ($o) => [
            'id' => $o->id,
            'order_no' => $o->order_no,
            'product_id' => $o->product_id,
            'product_name' => $o->product?->name,
            'product_cover' => $o->product?->cover,
            'quantity' => $o->quantity,
            'amount' => $o->amount,
            'amount_display' => $o->amount_display,
            'display_currency' => $o->display_currency,
            'exchange_rate' => $o->exchange_rate,
            'status' => $o->status,
            'delivery_status' => $o->delivery_status,
            'fulfillment_type' => $o->fulfillment_type_snapshot,
            'created_at' => $o->created_at?->toIso8601String(),
            'paid_at' => $o->paid_at?->toIso8601String(),
            'reviewed' => in_array($o->id, $reviewedOrderIds, true),
            'cards' => $o->status === 'paid'
                ? ($o->orderDeliveries?->map(fn ($d) => $d->card_content)->toArray() ?? [])
                : [],
            'instructions' => $o->status === 'paid' ? ($o->instructions_snapshot ?: null) : null,
        ])->toArray();
    }

    private function generateOrderNo(): string
    {
        return 'ORD'.now()->format('YmdHis').strtoupper(Str::random(6));
    }
}
