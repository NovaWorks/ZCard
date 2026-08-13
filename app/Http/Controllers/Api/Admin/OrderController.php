<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupplySource;
use App\Supply\UpstreamOrderService;
use App\Support\CsvSafe;
use App\Support\FulfillmentService;
use App\Support\OrderService;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 后台订单管理。
 * 列表(含统计) + 详情 + 手动关单 + 导出CSV + 清理无用订单。
 */
class OrderController extends Controller
{
    /**
     * 构建筛选查询(供 index/stats/export 复用)。
     */
    protected function buildQuery(Request $request)
    {
        $query = Order::query()
            ->with('product:id,name')
            ->withCount('orderDeliveries');

        // 关键字(订单号 / 联系方式 / 商品名)
        if ($keyword = $request->input('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->where('order_no', 'like', "%{$keyword}%")
                    ->orWhere('contact', 'like', "%{$keyword}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$keyword}%"));
            });
        }

        // 精确筛选
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($channel = $request->input('payment_channel')) {
            $query->where('payment_channel', $channel);
        }
        if ($productId = $request->input('product_id')) {
            $query->where('product_id', $productId);
        }
        // 发货状态
        if ($deliveryStatus = $request->input('delivery_status')) {
            $query->where('delivery_status', $deliveryStatus);
        }
        // 是否访客(guest=匿名访客/member=注册会员)
        if ($userType = $request->input('user_type')) {
            if ($userType === 'guest') {
                $query->whereNull('user_id');
            } elseif ($userType === 'member') {
                $query->whereNotNull('user_id');
            }
        }
        // 下单设备
        if ($device = $request->input('create_device')) {
            $query->where('create_device', $device);
        }
        // IP 地址
        if ($ip = $request->input('create_ip')) {
            $query->where('create_ip', 'like', "%{$ip}%");
        }

        // 分站筛选(G3):subsite_id 精确 / subsite_only=1 只看分站订单 / main_only=1 只看主站订单
        if ($subsiteId = $request->input('subsite_id')) {
            $query->where('subsite_id', $subsiteId);
        } elseif ($request->input('subsite_only')) {
            $query->whereNotNull('subsite_id');
        } elseif ($request->input('main_only')) {
            $query->whereNull('subsite_id');
        }

        // 时间范围
        if ($startDate = $request->input('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        return $query;
    }

    /**
     * 订单列表(分页)。
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->buildQuery($request);
        $orders = $query->orderByDesc('id')
            ->paginate($request->integer('pageSize', 15));

        return response()->json($orders);
    }

    /**
     * 统计卡片数据(基于当前筛选条件)。
     */
    public function stats(Request $request): JsonResponse
    {
        $baseQuery = $this->buildQuery($request);

        return response()->json([
            'total_count' => (clone $baseQuery)->count(),
            'pending_amount' => (clone $baseQuery)->where('status', 'pending')->sum('amount'),
            'total_amount' => (clone $baseQuery)->sum('amount'),
            'paid_amount' => (clone $baseQuery)->where('status', 'paid')->sum('amount'),
            'refunded_amount' => (clone $baseQuery)->where('status', 'refunded')->sum('amount'),
            'total_cost' => (clone $baseQuery)->sum('cost'),
        ]);
    }

    /**
     * 订单详情(含卡密发货)。
     */
    public function show(int $id): JsonResponse
    {
        $order = Order::with(['product:id,name,upstream_source_id,upstream_product_code,upstream_product_url', 'orderDeliveries:id,order_id,card_content,delivered_mode,delivered_at'])
            ->findOrFail($id);

        // 把 orderDeliveries 映射成 deliveries(前端期望的字段名)
        $data = $order->toArray();
        $data['deliveries'] = $data['order_deliveries'] ?? [];

        // 财务信息:单价/成本单价/利润/利润率(金额均为分)
        $qty = max(1, (int) $order->quantity);
        $data['unit_price'] = (int) $order->amount;
        $data['unit_cost'] = (int) $order->cost;
        $data['profit'] = (int) $order->amount - (int) $order->cost;
        $data['profit_rate'] = $order->amount > 0
            ? round(((int) $order->amount - (int) $order->cost) / (int) $order->amount * 100, 1) : 0;

        // 货源信息:货源名/上游商品代码/上游商品链接/上游订单号(拿货记录)
        $source = null;
        if ($order->upstream_source_id) {
            $source = SupplySource::find($order->upstream_source_id);
        } elseif ($order->product && $order->product->upstream_source_id) {
            $source = SupplySource::find($order->product->upstream_source_id);
        }
        if ($source) {
            $data['upstream_source_name'] = $source->name;
            $data['upstream_base_url'] = $source->base_url;
            $data['upstream_product_url'] = $source->productUrlFor(
                $order->product?->upstream_product_code,
                $order->product?->upstream_product_url,
            );
        }

        return response()->json($data);
    }

    /**
     * 手动关闭订单(仅 pending)。
     */
    public function close(int $id): JsonResponse
    {
        $order = app(OrderService::class)->closeOrder($id);

        return response()->json($order);
    }

    /** 已付款的人工履约订单由管理员提交一次性发货内容。 */
    public function fulfill(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'content' => 'required|string|max:10000',
        ]);
        $order = Order::with('product')->findOrFail($id);

        if ($order->status !== 'paid') {
            throw ValidationException::withMessages([
                'content' => '仅已支付订单可以人工发货',
            ]);
        }
        if (! in_array($order->fulfillment_type_snapshot, [
            Product::FULFILLMENT_MANUAL,
            // 上游商品拿货失败/上游人工发货时,允许本地手动兜底发货
            Product::FULFILLMENT_UPSTREAM,
        ], true)) {
            throw ValidationException::withMessages([
                'content' => '该订单不支持手动发货',
            ]);
        }
        if ($order->delivery_status === 'delivered') {
            return response()->json(['message' => '该订单已完成发货'], 409);
        }

        if (! app(FulfillmentService::class)->fulfill($order, [$data['content']], 'manual')) {
            return response()->json(['message' => '该订单已完成发货'], 409);
        }

        return $this->show($order->id);
    }

    /**
     * POST /api/admin/orders/{id}/refetch-upstream 手动重新拿货(自动拿货失败的兜底)。
     * 上游商品订单、已支付未发货时可用;成功/失败即时反馈(错误原因展示给管理员)。
     */
    public function refetchUpstream(int $id): JsonResponse
    {
        $order = Order::with('product')->findOrFail($id);

        if ($order->status !== 'paid') {
            return response()->json(['ok' => false, 'message' => '仅已支付订单可以拿货'], 422);
        }
        if ($order->delivery_status === 'delivered') {
            return response()->json(['ok' => false, 'message' => '该订单已发货,无需重复拿货'], 409);
        }
        if (! $order->product || ! $order->product->upstream_source_id) {
            return response()->json(['ok' => false, 'message' => '该订单不是上游货源商品'], 422);
        }

        $source = SupplySource::find($order->product->upstream_source_id);
        if (! $source || ! $source->isActive()) {
            return response()->json(['ok' => false, 'message' => '货源不存在或已停用,可改用手动发货'], 422);
        }

        try {
            app(UpstreamOrderService::class)->fetchFromUpstream($order, $source);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => '拿货失败: '.$e->getMessage().' (可改用手动发货兜底)',
            ], 500);
        }

        $order->refresh();
        if ($order->delivery_status === 'delivered') {
            return response()->json(['ok' => true, 'message' => '拿货成功,已自动发货', 'order' => $order]);
        }

        // 上游已接单但尚未发卡:返回状态,由上游回调/重试完成
        return response()->json([
            'ok' => true,
            'message' => '已向上游提交拿货请求,等待上游发货'.($order->upstream_order_id ? "(上游单号 {$order->upstream_order_id})" : ''),
            'order' => $order,
        ]);
    }

    /**
     * 导出筛选订单为 CSV。
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->buildQuery($request)->orderByDesc('id')->limit(5000);
        $orders = $query->get();

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="orders_'.date('Ymd_His').'.csv"',
        ];

        return response()->stream(function () use ($orders) {
            $fh = fopen('php://output', 'w');
            // UTF-8 BOM(Excel 兼容)
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, [
                '订单号', '商品名称', 'SKU', '数量', '金额(元)', '成本(元)', '佣金(元)',
                '支付方式', '支付状态', '联系方式', '卡密数量', '下单时间', '支付时间',
            ]);
            foreach ($orders as $o) {
                fputcsv($fh, CsvSafe::row([
                    $o->order_no,
                    $o->product?->name ?? '-',
                    $o->sku_name ?? '-',
                    $o->quantity,
                    number_format($o->amount / 100, 2),
                    number_format($o->cost / 100, 2),
                    number_format(($o->amount - $o->cost) / 100, 2),
                    $o->payment_channel ?? '-',
                    $this->statusText($o->status),
                    $o->contact ?? '-',
                    $o->order_deliveries_count ?? 0,
                    $o->created_at,
                    $o->paid_at,
                ]));
            }
            fclose($fh);
        }, 200, $headers);
    }

    /**
     * 清理无用订单(超时未支付的 pending 订单物理删除)。
     */
    public function clear(Request $request): JsonResponse
    {
        $minutes = (int) (app(StorefrontConfig::class)::get('order_close_minutes') ?? 15);
        $cutoff = now()->subMinutes($minutes);

        $count = Order::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->delete();

        return response()->json(['cleared' => $count]);
    }

    private function statusText(string $status): string
    {
        return match ($status) {
            'pending' => '待支付',
            'paid' => '已支付',
            'closed' => '已关闭',
            'refunded' => '已退款',
            default => $status,
        };
    }
}
