<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentChannel;
use App\Payment\Contracts\PaymentDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台支付通道管理。列表(含 driver 信息)、保存 config、切换 enabled,
 * 以及为前端 modal 拉取该 driver 的 config 字段定义。
 */
class PaymentChannelController extends Controller
{
    public function index(): JsonResponse
    {
        $channels = PaymentChannel::orderBy('sort')
            ->orderBy('id')
            ->get();

        // 附加 driver 元信息(name 是否可实例化)
        $channels->transform(function (PaymentChannel $channel) {
            $driverClass = $channel->driver;
            $channelArray = $channel->toArray();
            $channelArray['driver_label'] = class_exists($driverClass)
                ? (new \ReflectionClass($driverClass))->getShortName()
                : null;
            return $channelArray;
        });

        return response()->json($channels);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $channel = PaymentChannel::findOrFail($id);

        $data = $request->validate([
            'config'  => 'sometimes|array',
            'enabled' => 'sometimes|boolean',
            'name'    => 'sometimes|string|max:100',
            'fee'     => 'sometimes|numeric|min:0',
            'fee_type' => 'sometimes|string',
            'sort'    => 'sometimes|integer',
        ]);

        $channel->update($data);

        return response()->json($channel->fresh());
    }

    /**
     * 给前端 modal 渲染 config 表单:实例化 driver,取它的 getConfigFields()。
     */
    public function configFields(int $id): JsonResponse
    {
        $channel = PaymentChannel::findOrFail($id);

        $driverClass = $channel->driver;
        if (! class_exists($driverClass)) {
            return response()->json([
                'fields' => [],
                'error'  => "支付 Driver 不存在: {$driverClass}",
            ], 422);
        }

        $driver = new $driverClass();
        /** @var PaymentDriver $driver */

        return response()->json([
            'channel_id' => $channel->id,
            'driver'     => $channel->driver,
            'fields'     => $driver->getConfigFields(),
        ]);
    }
}
