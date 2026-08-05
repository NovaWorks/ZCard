<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Support\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $coupons = $this->baseQuery($request)->orderByDesc('id')->paginate($request->integer('pageSize', 15));
        return response()->json($coupons);
    }

    /**
     * 导出筛选后的优惠券为 CSV(流式输出)。
     * 参照 CardController::export,字段金额换算成元展示。
     */
    public function export(Request $request): StreamedResponse
    {
        $limit = (int) ($request->integer('limit') ?: 50000);
        $coupons = $this->baseQuery($request)->orderByDesc('id')->limit($limit)->get();

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="coupons-export-' . date('Ymd-His') . '.csv"',
            'X-Accel-Buffering'   => 'no',
        ];

        return response()->stream(function () use ($coupons) {
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['ID', '券码', '类型', '面值', '状态', '适用范围', '最低消费(元)', '过期时间', '核销时间', '核销用户ID', '关联订单ID', '备注', '创建时间']);

            foreach ($coupons as $c) {
                // 面值:fixed=分→元; percent=百分比原值
                $valueText = $c->type === Coupon::TYPE_PERCENT
                    ? $c->value . '%'
                    : '¥' . number_format($c->value / 100, 2);
                // 适用范围
                $scope = '全场';
                if ($c->product) $scope = '商品:' . $c->product->name;
                elseif ($c->category) $scope = '分类:' . $c->category->name;
                // 状态中文化
                $statusMap = ['active' => '可用', 'used' => '已使用', 'disabled' => '已禁用'];
                $statusText = $statusMap[$c->status] ?? $c->status;

                fputcsv($out, [
                    $c->id,
                    $c->code,
                    $c->type === Coupon::TYPE_PERCENT ? '百分比' : '固定金额',
                    $valueText,
                    $statusText,
                    $scope,
                    $c->min_amount > 0 ? number_format($c->min_amount / 100, 2) : '',
                    $c->expires_at ?? '',
                    $c->used_at ?? '',
                    $c->used_by ?? '',
                    $c->order_id ?? '',
                    $c->note,
                    $c->created_at,
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }

    /**
     * 优惠券基础查询(index 和 export 复用)。
     */
    private function baseQuery(Request $request)
    {
        $query = Coupon::query()->with(['product:id,name', 'category:id,name']);

        if ($keyword = $request->input('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->where('code', 'like', "%{$keyword}%")->orWhere('note', 'like', "%{$keyword}%");
            });
        }
        if ($request->has('status') && $request->input('status') !== '') {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('type') && $request->input('type') !== '') {
            $query->where('type', $request->input('type'));
        }
        if ($startDate = $request->input('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        return $query;
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'active_count' => Coupon::where('status', 'active')->count(),
            'used_count' => Coupon::where('status', 'used')->count(),
            'disabled_count' => Coupon::where('status', 'disabled')->count(),
            'total_count' => Coupon::count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'count' => 'required|integer|min:1|max:1000',
            'type' => 'required|string|in:fixed,percent',
            'value' => 'required|integer|min:1',
            'product_id' => 'nullable|integer|exists:products,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'min_amount' => 'nullable|numeric|min:0',
            'expires_at' => 'nullable|date',
            'note' => 'nullable|string|max:100',
        ]);

        $couponData = [
            'type' => $data['type'],
            'value' => $data['value'],
            'product_id' => $data['product_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'min_amount' => isset($data['min_amount']) ? (int) round($data['min_amount'] * 100) : 0,
            'expires_at' => $data['expires_at'] ?? null,
            'note' => $data['note'] ?? '',
        ];

        try {
            $coupons = CouponService::generate($data['count'], $couponData);
            return response()->json(['count' => count($coupons), 'codes' => array_map(fn ($c) => $c->code, $coupons)], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function toggle(int $id): JsonResponse
    {
        try {
            return response()->json(CouponService::toggle($id));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $coupon = Coupon::findOrFail($id);
        if ($coupon->status === Coupon::STATUS_USED) {
            return response()->json(['message' => '已使用的优惠券不能删除'], 422);
        }
        $coupon->delete();
        return response()->json(null, 204);
    }

    /** 批量删除(已使用的跳过) */
    public function batchDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $coupons = Coupon::whereIn('id', $validated['ids'])->get();
        $skipped = 0;
        foreach ($coupons as $coupon) {
            if ($coupon->status === Coupon::STATUS_USED) {
                $skipped++;
                continue;
            }
            $coupon->delete();
        }

        return response()->json([
            'deleted' => $coupons->count() - $skipped,
            'skipped' => $skipped,
        ]);
    }
}
