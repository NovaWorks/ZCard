<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSupplySourceProducts;
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

    /** POST /api/admin/supply-sources/{source}/sync 触发商品同步(同步执行,不走队列) */
    public function sync(Request $request, SupplySource $supplySource): JsonResponse
    {
        $mode = in_array($request->input('mode'), ['full', 'incremental']) ? $request->input('mode') : 'incremental';
        try {
            // 同步执行(原为异步 dispatch,但生产环境常无 queue:worker 导致任务永不执行,
            // 用户点同步无反应。改为同步执行,立即返回结果)。
            $job = new SyncSupplySourceProducts($supplySource->id, $mode);
            $job->handle(app(SupplyManager::class), app(\App\Supply\SupplySyncService::class));
            return response()->json(['ok' => true, 'message' => '同步完成', 'mode' => $mode]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage(), 'mode' => $mode], 500);
        }
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

    /**
     * GET /api/admin/supply-sources/{source}/products/preview
     * 实时拉取上游商品(同步,不走队列),按分类组织成树供后台勾选导入。
     * 返回结构:[{category_code, category_name, products:[{code,name,price,cover,stock,already_imported}]}]
     */
    public function previewProducts(SupplySource $supplySource): JsonResponse
    {
        try {
            $driver = app(SupplyManager::class)->driver($supplySource);
            $result = $driver->listProducts(null, 1);
            $items = $result['items'] ?? [];

            // 已导入本地的商品 code 集合(判断 already_imported)
            $importedCodes = \App\Models\Product::where('upstream_source_id', $supplySource->id)
                ->pluck('upstream_product_code')->toArray();

            // 按上游分类聚合(没有分类的归到"未分类")
            $tree = [];
            $bucket = [];
            foreach ($items as $p) {
                $catCode = $p->categoryCode ?? '_uncategorized';
                if (! isset($bucket[$catCode])) {
                    $bucket[$catCode] = [];
                }
                $bucket[$catCode][] = [
                    'code' => $p->code,
                    'name' => $p->name,
                    'price' => $p->price,            // 分
                    'factory_price' => $p->factoryPrice, // 分
                    'cover' => $p->cover,
                    'stock' => $p->stockQuantity,
                    'already_imported' => in_array($p->code, $importedCodes, true),
                ];
            }
            foreach ($bucket as $catCode => $products) {
                $tree[] = [
                    'category_code' => $catCode === '_uncategorized' ? null : $catCode,
                    'category_name' => $catCode === '_uncategorized' ? '未分类' : ('分类 #' . $catCode),
                    'products' => $products,
                ];
            }

            return response()->json([
                'ok' => true,
                'total' => count($items),
                'categories' => $tree,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/admin/supply-sources/{source}/products/import
     * 勾选导入:接收商品 code 列表,实时 upsert 到本地。
     * body: { codes: ['CODE1','CODE2',...] }
     */
    public function importProducts(Request $request, SupplySource $supplySource): JsonResponse
    {
        $data = $request->validate([
            'codes' => 'required|array|min:1',
            'codes.*' => 'string',
        ]);

        try {
            $driver = app(SupplyManager::class)->driver($supplySource);
            $sync = app(\App\Supply\SupplySyncService::class);
            $imported = 0;
            $skipped = 0;

            // 拉取上游全部商品,按 code 索引(只拉一次,避免逐个 getProduct 打多次请求)
            $result = $driver->listProducts(null, 1);
            $map = collect($result['items'] ?? [])->keyBy('code');

            foreach ($data['codes'] as $code) {
                $dto = $map->get($code);
                if (! $dto) {
                    $skipped++;
                    continue;
                }
                $sync->upsertProduct($supplySource, $dto);
                $imported++;
            }

            $supplySource->update(['last_synced_at' => now(), 'last_error' => null]);

            return response()->json([
                'ok' => true,
                'imported' => $imported,
                'skipped' => $skipped,
                'message' => "成功导入 {$imported} 个商品" . ($skipped > 0 ? "(跳过 {$skipped} 个)" : ''),
            ]);
        } catch (\Throwable $e) {
            $supplySource->update(['last_error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
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
