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
        $product = Product::with('skus')->findOrFail($productId);
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
            $unitPrice = $skuId
                ? ($product->skus->firstWhere('id', $skuId)?->price ?? $product->price)
                : $product->price;
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

                return true;
            });

            if ($closed) {
                $count++;
            }
        }

        return $count;
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
     * @return array{orders: array<int, array>, matched: int} orders=已授权订单;matched=关键字命中的订单总数(含未授权,供上层做爆破计数)
     */
    public function searchOrders(
        string $keyword,
        ?string $password = null,
        ?string $accessToken = null,
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

    /** 校验订单对象级访问权限：本人登录态、随机访问凭证或订单查询密码三选一。 */
    public function canAccessOrder(
        Order $order,
        ?int $userId = null,
        ?string $accessToken = null,
        ?string $password = null,
    ): bool {
        if ($userId !== null && $order->user_id !== null && (int) $order->user_id === $userId) {
            return true;
        }

        $extra = is_array($order->extra) ? $order->extra : [];
        $storedTokenHash = (string) ($extra['access_token_hash'] ?? '');
        if ($storedTokenHash !== '' && $accessToken !== null && $accessToken !== '') {
            $providedTokenHash = hash('sha256', $accessToken);
            if (hash_equals($storedTokenHash, $providedTokenHash)) {
                return true;
            }
        }

        $storedPasswordHash = (string) ($extra['query_password'] ?? '');

        return $storedPasswordHash !== ''
            && $password !== null
            && $password !== ''
            && Hash::check($password, $storedPasswordHash);
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
            'extra' => $order->extra,
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
