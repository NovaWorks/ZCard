<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupplySource;
use App\Supply\SupplyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 货源对接设置(spec §6) —— admin.role 保护
 */
class SupplySourceController extends Controller
{
    /** GET /api/admin/supply-sources/drivers 返回各驱动 label+configSchema */
    public function drivers(): JsonResponse
    {
        return response()->json(['drivers' => SupplyManager::allDriversMeta()]);
    }

    /** GET /api/admin/supply-sources */
    public function index(Request $request): JsonResponse
    {
        $sources = SupplySource::query()
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')->paginate($request->integer('per_page', 20));

        $sources->getCollection()->transform(fn ($s) => $this->maskCredentials($s));
        return response()->json($sources);
    }

    /** POST /api/admin/supply-sources */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateSource($request);
        $source = SupplySource::create([
            'name' => $data['name'],
            'driver' => $data['driver'],
            'base_url' => $data['base_url'],
            'credentials' => $data['credentials'], // cast encrypted:array 自动加密
            'status' => $data['status'] ?? 'active',
            'settings' => $data['settings'] ?? null,
        ]);
        return response()->json($this->maskCredentials($source), 201);
    }

    /** GET /api/admin/supply-sources/{source} */
    public function show(SupplySource $supplySource): JsonResponse
    {
        return response()->json($this->maskCredentials($supplySource));
    }

    /** PUT /api/admin/supply-sources/{source} */
    public function update(Request $request, SupplySource $supplySource): JsonResponse
    {
        $data = $this->validateSource($request, $supplySource);
        $update = collect($data)->except('credentials')->toArray();
        // credentials:secret 类字段留空=不修改,只合并实际传入的非空值
        if (isset($data['credentials'])) {
            $existing = $supplySource->credentials ?? [];
            $merged = array_merge($existing, array_filter($data['credentials'], fn ($v) => $v !== '' && $v !== null));
            $update['credentials'] = $merged;
        }
        $supplySource->update($update);
        return response()->json($this->maskCredentials($supplySource));
    }

    /** DELETE /api/admin/supply-sources/{source} */
    public function destroy(SupplySource $supplySource): JsonResponse
    {
        $supplySource->delete();
        return response()->json(null, 204);
    }

    /** POST /api/admin/supply-sources/{source}/test 测试连通(调 ping) */
    public function test(SupplySource $supplySource): JsonResponse
    {
        try {
            $driver = app(SupplyManager::class)->driver($supplySource);
            $result = $driver->ping();

            if ($result['connected'] ?? false) {
                $supplySource->update([
                    'balance_cache' => $result['balance'] ?? null,
                    'last_error' => null,
                ]);
            } else {
                $supplySource->update(['last_error' => $result['error'] ?? '连接失败']);
            }
            return response()->json($result);
        } catch (\Throwable $e) {
            $supplySource->update(['last_error' => $e->getMessage()]);
            return response()->json(['connected' => false, 'error' => $e->getMessage()]);
        }
    }

    /** POST /api/admin/supply-sources/{source}/sync 触发商品同步 */
    public function sync(Request $request, SupplySource $supplySource): JsonResponse
    {
        $mode = in_array($request->input('mode'), ['full', 'incremental']) ? $request->input('mode') : 'incremental';
        // Task 4 接入 SyncSupplySourceProducts Job
        return response()->json(['ok' => true, 'message' => '同步任务已派发(待 Task4 实现)', 'mode' => $mode]);
    }

    /** GET /api/admin/supply-sources/{source}/sync-status */
    public function syncStatus(SupplySource $supplySource): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'last_synced_at' => $supplySource->last_synced_at,
            'last_error' => $supplySource->last_error,
        ]);
    }

    private function validateSource(Request $request, ?SupplySource $existing = null): array
    {
        return $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'driver' => ['sometimes', 'required', Rule::in(array_keys(SupplyManager::DRIVERS))],
            'base_url' => 'sometimes|required|url|max:255',
            'credentials' => 'sometimes|array',
            'status' => 'sometimes|in:active,disabled',
            'settings' => 'sometimes|nullable|array',
        ]);
    }

    /** 凭证脱敏:secret 类字段只留末4位 */
    private function maskCredentials(SupplySource $source): SupplySource
    {
        $creds = $source->credentials ?? [];
        foreach ($creds as $key => $val) {
            if (is_string($val) && strlen($val) > 4 && (str_contains(strtolower($key), 'secret') || strtolower($key) === 'app_key')) {
                $creds[$key] = '••••••••' . substr($val, -4);
            }
        }
        $source->credentials = $creds;
        return $source;
    }
}
