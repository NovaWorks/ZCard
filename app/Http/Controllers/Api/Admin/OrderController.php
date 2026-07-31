<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'total_count'    => (clone $baseQuery)->count(),
            'pending_amount' => (clone $baseQuery)->where('status', 'pending')->sum('amount'),
            'total_amount'   => (clone $baseQuery)->sum('amount'),
            'paid_amount'    => (clone $baseQuery)->where('status', 'paid')->sum('amount'),
            'refunded_amount'=> (clone $baseQuery)->where('status', 'refunded')->sum('amount'),
            'total_cost'     => (clone $baseQuery)->sum('cost'),
        ]);
    }

    /**
     * 订单详情(含卡密发货)。
     */
    public function show(int $id): JsonResponse
    {
        $order = Order::with(['product:id,name', 'orderDeliveries:id,order_id,card_content,delivered_mode,delivered_at'])
            ->findOrFail($id);

        // 把 orderDeliveries 映射成 deliveries(前端期望的字段名)
        $data = $order->toArray();
        $data['deliveries'] = $data['order_deliveries'] ?? [];

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

    /**
     * 导出筛选订单为 CSV。
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->buildQuery($request)->orderByDesc('id')->limit(5000);
        $orders = $query->get();

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="orders_' . date('Ymd_His') . '.csv"',
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
                fputcsv($fh, [
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
                ]);
            }
            fclose($fh);
        }, 200, $headers);
    }

    /**
     * 清理无用订单(超时未支付的 pending 订单物理删除)。
     */
    public function clear(Request $request): JsonResponse
    {
        $minutes = (int) (app(\App\Support\StorefrontConfig::class)::get('order_close_minutes') ?? 15);
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
