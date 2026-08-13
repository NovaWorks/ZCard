<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\VisitLog;
use App\Models\Withdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 仪表盘数据聚合(比 dujiao-next/acg-faka 更全面)。
 * 提供:概览统计 + 按天趋势 + 排行 + 告警。
 */
class DashboardController extends Controller
{
    /**
     * 概览统计(总览卡片数据)。
     * GET /api/admin/dashboard/overview?days=7
     */
    public function overview(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->input('days', 7)));
        $since = now()->subDays($days);

        $orderQuery = Order::where('created_at', '>=', $since);
        $paidQuery = (clone $orderQuery)->where('status', 'paid');

        $totalOrders = (clone $orderQuery)->count();
        $paidOrders = (clone $paidQuery)->count();
        $paidAmount = (clone $paidQuery)->sum('amount');
        $totalCost = (clone $paidQuery)->sum('cost');
        $profit = $paidAmount - $totalCost;
        $profitMargin = $paidAmount > 0 ? round($profit / $paidAmount * 100, 1) : 0;
        $pendingAmount = (clone $orderQuery)->where('status', 'pending')->sum('amount');
        // 支付成功率仅统计订单支付(排除充值支付流水 order_id=null)
        $paymentSuccess = Payment::where('created_at', '>=', $since)
            ->where('status', 'success')->whereNotNull('order_id')->count();
        $paymentFailed = Payment::where('created_at', '>=', $since)
            ->where('status', 'failed')->whereNotNull('order_id')->count();
        $paymentRate = ($paymentSuccess + $paymentFailed) > 0
            ? round($paymentSuccess / ($paymentSuccess + $paymentFailed) * 100, 1) : 0;

        $newUsers = User::where('created_at', '>=', $since)->count();
        $totalProducts = Product::count();
        $lowStock = Product::where('status', true)
            ->whereDoesntHave('cards', fn ($q) => $q->where('status', 'unused'))
            ->count();
        $totalStock = Card::where('status', 'unused')->count();

        $pendingWithdrawals = Withdrawal::where('status', 'pending')->count();

        // 实时在线人数:database session 驱动下,sessions 表 5 分钟内活跃的会话数
        $onlineUsers = 0;
        try {
            $onlineUsers = (int) DB::table('sessions')
                ->where('last_activity', '>=', now()->subMinutes(5)->getTimestamp())
                ->count();
        } catch (\Throwable $e) {
            // session 驱动非 database 时忽略(返回 0)
        }

        return response()->json([
            'online_users' => $onlineUsers,
            'total_orders' => $totalOrders,
            'paid_orders' => $paidOrders,
            'paid_amount' => (int) $paidAmount,
            'total_cost' => (int) $totalCost,
            'profit' => (int) $profit,
            'profit_margin' => $profitMargin,
            'pending_amount' => (int) $pendingAmount,
            'payment_success' => $paymentSuccess,
            'payment_failed' => $paymentFailed,
            'payment_rate' => $paymentRate,
            'new_users' => $newUsers,
            'total_products' => $totalProducts,
            'low_stock_products' => $lowStock,
            'total_stock' => $totalStock,
            'pending_withdrawals' => $pendingWithdrawals,
        ]);
    }

    /**
     * 按天趋势(订单数/收入/利润,近 N 天)。
     * GET /api/admin/dashboard/trends?days=7
     */
    public function trends(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->input('days', 7)));

        // 单条 GROUP BY 查询(避免 acg-faka 的 N+1 风格)
        $rows = Order::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as order_count'),
            DB::raw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count"),
            DB::raw("SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as paid_amount"),
            DB::raw("SUM(CASE WHEN status = 'paid' THEN cost ELSE 0 END) as paid_cost"),
            DB::raw("SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded_count")
        )
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // 填充缺失日期(确保连续)
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $row = $rows->get($date);
            $paidAmount = $row ? (int) $row->paid_amount : 0;
            $paidCost = $row ? (int) $row->paid_cost : 0;
            $refundedCount = $row ? (int) $row->refunded_count : 0;
            $paidCount = $row ? (int) $row->paid_count : 0;
            $result[] = [
                'date' => $date,
                'order_count' => $row ? (int) $row->order_count : 0,
                'paid_count' => $paidCount,
                'paid_amount' => $paidAmount,
                'paid_cost' => $paidCost,
                'profit' => $paidAmount - $paidCost,
                'refunded_count' => $refundedCount,
                'refund_rate' => $paidCount > 0 ? round($refundedCount / $paidCount * 100, 1) : 0,
            ];
        }

        return response()->json($result);
    }

    /**
     * 热销商品排行(按已付订单数/金额)。
     * GET /api/admin/dashboard/top-products?days=7&limit=10
     */
    /**
     * 前台流量走势(PV/UV,近 N 天)。
     * GET /api/admin/dashboard/traffic?days=7
     */
    public function traffic(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->input('days', 7)));

        $rows = VisitLog::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as pv'),
            DB::raw('COUNT(DISTINCT ip) as uv')
        )
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $row = $rows->get($date);
            $result[] = [
                'date' => $date,
                'pv' => $row ? (int) $row->pv : 0,
                'uv' => $row ? (int) $row->uv : 0,
            ];
        }

        return response()->json($result);
    }

    public function topProducts(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->input('days', 7)));
        $limit = min(50, max(1, (int) $request->input('limit', 10)));

        $rows = Order::select(
            'product_id',
            DB::raw('COUNT(*) as order_count'),
            DB::raw("SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as paid_amount"),
            DB::raw("SUM(CASE WHEN status = 'paid' THEN cost ELSE 0 END) as paid_cost")
        )
            ->with('product:id,name,slug')
            ->where('created_at', '>=', now()->subDays($days))
            ->where('status', 'paid')
            ->groupBy('product_id')
            ->orderByDesc('paid_amount')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'product_name' => $r->product?->name ?? '已删除',
                'order_count' => (int) $r->order_count,
                'paid_amount' => (int) $r->paid_amount,
                'profit' => (int) $r->paid_amount - (int) $r->paid_cost,
            ]);

        return response()->json($rows);
    }

    /**
     * 支付通道排行(成功率/金额)。
     * GET /api/admin/dashboard/top-channels?days=7
     */
    public function topChannels(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->input('days', 7)));

        $rows = Payment::select(
            'channel',
            DB::raw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count"),
            DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count"),
            DB::raw('COUNT(*) as total_count')
        )
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('channel')
            ->orderByDesc('success_count')
            ->get()
            ->map(fn ($r) => [
                'channel' => $r->channel,
                'success_count' => (int) $r->success_count,
                'failed_count' => (int) $r->failed_count,
                'total_count' => (int) $r->total_count,
                'success_rate' => $r->total_count > 0
                    ? round($r->success_count / $r->total_count * 100, 1) : 0,
            ]);

        return response()->json($rows);
    }
}
