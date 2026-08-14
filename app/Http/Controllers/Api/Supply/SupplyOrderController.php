<?php

namespace App\Http\Controllers\Api\Supply;

use App\Http\Controllers\Controller;
use App\Models\SupplyOrder;
use App\Supply\CallbackUrlGuard;
use App\Supply\Exceptions\SupplyApiException;
use App\Supply\SupplyOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 供货 API 订单控制器(spec §4.4) —— 下游下单拿货
 */
class SupplyOrderController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'sku_id' => 'nullable|integer|exists:product_skus,id',
            'quantity' => 'required|integer|min:1|max:100',
            'downstream_order_no' => 'required|string|max:100',
            'contact' => 'nullable|string|max:200',
            'callback_url' => 'nullable|url|max:500',
        ]);

        // SSRF 校验 callback_url
        if (! empty($data['callback_url']) && ! app(CallbackUrlGuard::class)->isAllowed($data['callback_url'])) {
            return response()->json(['ok' => false, 'error_code' => 'bad_request', 'message' => __('messages.supply_api.bad_request')], 400);
        }

        $account = $request->attributes->get('supplier_account');
        try {
            $result = app(SupplyOrderService::class)->createOrder($account, $data, 'sync');

            return response()->json([
                'ok' => true,
                'supply_order_id' => $result['supply_order_id'],
                'order_id' => $result['order_id'],
                'amount' => $result['amount'],
                'fulfillment' => [
                    'type' => $result['fulfillment_type'],
                    'status' => $result['delivery_status'],
                    'cards' => $result['cards'],
                    'instructions' => $result['instructions'],
                ],
            ], 201);
        } catch (SupplyApiException $e) {
            return response()->json([
                'ok' => false,
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], $e->httpStatus);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $account = $request->attributes->get('supplier_account');
        $supplyOrder = SupplyOrder::where('supplier_account_id', $account->id)->find($id);
        if (! $supplyOrder) {
            return response()->json(['ok' => false, 'error_code' => 'order_not_found', 'message' => __('messages.supply_api.order_not_found')], 404);
        }

        $order = $supplyOrder->order;
        // 安全(低危):未发货订单不回传卡密内容(与 instructions 的 delivered 判定同口径)
        $cards = $order->delivery_status === 'delivered'
            ? $order->orderDeliveries()->pluck('card_content')->all()
            : [];

        return response()->json([
            'ok' => true,
            'supply_order_id' => $supplyOrder->id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'amount' => (int) $order->amount,
            'fulfillment' => [
                'type' => $order->fulfillment_type_snapshot,
                'status' => $order->delivery_status,
                'cards' => $cards,
                'instructions' => $order->delivery_status === 'delivered'
                    ? ($order->instructions_snapshot ?: null)
                    : null,
            ],
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $account = $request->attributes->get('supplier_account');
        $supplyOrder = SupplyOrder::where('supplier_account_id', $account->id)->find($id);
        if (! $supplyOrder) {
            return response()->json(['ok' => false, 'error_code' => 'order_not_found', 'message' => __('messages.supply_api.order_not_found')], 404);
        }

        // 真实取消(M-5):事务内关闭本地订单、释放锁定卡、退款入账 + 账本流水
        try {
            app(SupplyOrderService::class)->cancelOrder($account, $supplyOrder);
        } catch (SupplyApiException $e) {
            return response()->json([
                'ok' => false,
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], $e->httpStatus);
        }

        return response()->json(['ok' => true, 'supply_order_id' => $supplyOrder->id, 'status' => 'canceled']);
    }
}
