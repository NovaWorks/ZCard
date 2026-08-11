<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentChannel;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentUrlGenerator;
use App\Support\PaymentService;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 后台支付通道管理。列表(含 driver 信息)、保存 config、切换 enabled,
 * 以及为前端 modal 拉取该 driver 的 config 字段定义。
 */
class PaymentChannelController extends Controller
{
    /**
     * 扫描系统支持的全部支付驱动(代码层面),供「添加支付渠道」弹窗勾选。
     * 返回每个驱动的 code/name/icon + 是否已在 payment_channels 表中存在(added)。
     */
    public function drivers(PaymentService $service): JsonResponse
    {
        $all = $service->discoverDrivers();
        // 标记哪些已添加到数据库(按 code 匹配)
        $existingCodes = PaymentChannel::pluck('code')->toArray();

        $list = array_map(fn ($d) => array_merge($d, [
            'added' => in_array($d['code'], $existingCodes, true),
        ]), $all);

        return response()->json($list);
    }

    /**
     * 添加支付渠道(按驱动 code 创建 payment_channels 记录,幂等)。
     * 用于「添加支付渠道」弹窗勾选后提交。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:50',
        ]);

        // 从驱动目录找到对应驱动(防止伪造不存在的 code)
        $service = app(PaymentService::class);
        $driver = collect($service->discoverDrivers())
            ->firstWhere('code', $data['code']);

        if (! $driver) {
            return response()->json(['message' => '未知的支付驱动: '.$data['code']], 422);
        }

        // 幂等:已存在同 code 渠道则直接返回(可能是之前被删除的,恢复显示)
        $channel = PaymentChannel::where('code', $data['code'])->first();
        if (! $channel) {
            $sort = (int) PaymentChannel::max('sort') + 1;
            $channel = PaymentChannel::create([
                'merchant_id' => 1,
                'code' => $driver['code'],
                'name' => $driver['name'],
                'driver' => $driver['driver'],
                'config' => null,
                'fee' => 0,
                'fee_type' => 'percent',
                'sort' => $sort,
                'enabled' => false,
            ]);
        }

        return response()->json($this->channelArray($channel), 201);
    }

    /**
     * 删除支付渠道(物理删除,页面即不再显示)。
     * 重新「添加」时按 code 用 firstOrCreate 恢复。
     */
    public function destroy(int $id): JsonResponse
    {
        $channel = PaymentChannel::findOrFail($id);
        $channel->delete();

        return response()->json(['message' => '已删除']);
    }

    public function index(): JsonResponse
    {
        $channels = PaymentChannel::orderBy('sort')
            ->orderBy('id')
            ->get();

        // 附加 driver 元信息(name 是否可实例化)
        $channels->transform(fn (PaymentChannel $channel) => $this->channelArray($channel));

        return response()->json($channels);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $channel = PaymentChannel::findOrFail($id);

        $data = $request->validate([
            'config' => 'sometimes|array',
            'enabled' => 'sometimes|boolean',
            'name' => 'sometimes|string|max:100',
            'fee' => 'sometimes|numeric|min:0',
            'fee_type' => 'sometimes|string',
            'fee_bearer' => 'sometimes|string|in:merchant,customer',
            'sort' => 'sometimes|integer',
        ]);

        // config 合并保存(而非覆盖):前端对敏感字段留空时不传,
        // 这里与旧值合并,实现"留空=保留旧值"
        if (isset($data['config'])) {
            $oldConfig = $channel->config ?? [];
            $submitted = array_filter(
                $data['config'],
                fn ($value) => $value !== StorefrontConfig::SECRET_MASK,
            );
            $data['config'] = array_merge($oldConfig, $submitted);
        }

        // 驱动可选提供配置归一/校验。易支付用此处统一 gateway_url、
        // submit.php 尾缀和 type 形态;启用前必须在服务端确认凭据完整。
        $driverClass = $channel->driver;
        $driver = class_exists($driverClass) ? new $driverClass : null;
        $effectiveConfig = $data['config'] ?? ($channel->config ?? []);
        try {
            if ($driver && method_exists($driver, 'normalizeConfig')) {
                $effectiveConfig = $driver->normalizeConfig($effectiveConfig);
                if (isset($data['config'])) {
                    $data['config'] = $effectiveConfig;
                }
            }
            $willEnable = (bool) ($data['enabled'] ?? $channel->enabled);
            if ($willEnable && $driver && method_exists($driver, 'validateConfig')) {
                $effectiveConfig = $driver->validateConfig($effectiveConfig);
                $data['config'] = $effectiveConfig;
            }
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['config' => $e->getMessage()]);
        }

        $channel->update($data);

        return response()->json($this->channelArray($channel->fresh()));
    }

    /**
     * 给前端 modal 渲染 config 表单:实例化 driver,取它的 getConfigFields()。
     * 驱动返回的是 [key => {label, type, ...}] 关联数组,
     * 这里转换成前端期望的扁平数组 [{key, label, type, ...}]。
     * 同时附加该通道的异步回调地址,供后台参考配置。
     */
    public function configFields(int $id): JsonResponse
    {
        $channel = PaymentChannel::findOrFail($id);

        $driverClass = $channel->driver;
        if (! class_exists($driverClass)) {
            return response()->json([
                'fields' => [],
                'error' => "支付 Driver 不存在: {$driverClass}",
            ], 422);
        }

        $driver = new $driverClass;
        /** @var PaymentDriver $driver */
        $rawFields = $driver->getConfigFields();
        $fields = [];
        foreach ($rawFields as $key => $field) {
            $f = is_array($field) ? $field : [];
            $f['key'] = $key;
            // select / multiselect 选项归一:驱动返回 ['value' => 'label'],前端要 [{value,label}]
            $type = $f['type'] ?? null;
            if (in_array($type, ['select', 'multiselect'], true) && isset($f['options']) && is_array($f['options'])) {
                $opts = [];
                foreach ($f['options'] as $val => $label) {
                    $opts[] = ['value' => $val, 'label' => $label];
                }
                $f['options'] = $opts;
            }
            $fields[] = $f;
        }

        // 与支付驱动提交给网关的 notify_url 共用同一真理源。
        $callbackUrl = app(PaymentUrlGenerator::class)->named(
            $channel->code === 'epay' ? 'api.payments.callback' : 'payment.notify',
            ['channel' => $channel->code],
            $channel->config ?? [],
        );

        return response()->json([
            'channel_id' => $channel->id,
            'driver' => $channel->driver,
            'fields' => $fields,
            'callback_url' => $callbackUrl,
        ]);
    }

    /** 后台仅返回“已配置”占位符，不回显支付密钥原文。 */
    private function channelArray(PaymentChannel $channel): array
    {
        $driverClass = $channel->driver;
        $array = $channel->toArray();
        $config = $channel->config ?? [];

        if (class_exists($driverClass)) {
            try {
                $fields = (new $driverClass)->getConfigFields();
                foreach ($fields as $key => $field) {
                    $sensitiveKey = (bool) preg_match(
                        '/(?:^|_)(?:key|secret|token|password|private|cert)(?:_|$)/i',
                        (string) $key,
                    );
                    if ((($field['type'] ?? null) === 'secret' || $sensitiveKey) && ! empty($config[$key])) {
                        $config[$key] = StorefrontConfig::SECRET_MASK;
                    }
                }
            } catch (\Throwable) {
                // 驱动异常时宁可不返回配置，也不泄露未知字段。
                $config = [];
            }
        } else {
            $config = [];
        }

        $array['config'] = $config;
        $array['driver_label'] = class_exists($driverClass)
            ? (new \ReflectionClass($driverClass))->getShortName()
            : null;

        return $array;
    }
}
