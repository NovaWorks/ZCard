<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\QueueHeartbeatJob;
use App\Jobs\SyncSupplySourceProducts;
use App\Models\Product;
use App\Models\SupplySource;
use App\Models\SupplySyncTask;
use App\Supply\SupplyManager;
use App\Supply\SupplySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
        // 变更前的定价设置(update 后再读就是新值了)
        $oldSettings = $supplySource->settings ?? [];
        $supplySource->update($update);

        // 定价设置(加价比例/模式)变化 → 立即派发全量同步,让商品售价按新规则自动重算
        // (客户反馈:改 600% 加价后价格仍是旧的,因为此前要等每小时定时同步才生效)
        $this->maybeDispatchReprice($supplySource, $oldSettings);

        return response()->json($this->maskCredentials($supplySource));
    }

    /**
     * 检测定价设置是否变化;变化时派发全量同步任务(异步,立即执行,防重)。
     * 全量同步会按新加价规则重算所有未手动改价商品的售价,并统计价格更新数。
     */
    private function maybeDispatchReprice(SupplySource $supplySource, array $oldSettings): void
    {
        $pricingKeys = ['default_pricing_mode', 'default_markup_percent', 'default_markup_amount'];
        $newSettings = $supplySource->settings ?? [];

        $changed = false;
        foreach ($pricingKeys as $key) {
            $old = is_array($oldSettings) ? ($oldSettings[$key] ?? null) : null;
            $new = $newSettings[$key] ?? null;
            if ((string) $old !== (string) $new) {
                $changed = true;
                break;
            }
        }
        if (! $changed) {
            return;
        }

        // 防重:已有排队/运行中任务则跳过(定时/手动同步会覆盖)
        if (SupplySyncTask::where('supply_source_id', $supplySource->id)
            ->whereIn('status', [SupplySyncTask::STATUS_QUEUED, SupplySyncTask::STATUS_RUNNING])
            ->exists()) {
            return;
        }

        $task = SupplySyncTask::create([
            'supply_source_id' => $supplySource->id,
            'mode' => 'full',
            'status' => SupplySyncTask::STATUS_QUEUED,
        ]);
        SyncSupplySourceProducts::dispatch($supplySource->id, 'full', $task->id);
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

    /**
     * POST /api/admin/supply-sources/{source}/sync 触发商品同步(异步任务)。
     * 返回任务记录,前端轮询状态/进度;同货源有进行中任务时拒绝重复派发(409)。
     */
    public function sync(Request $request, SupplySource $supplySource): JsonResponse
    {
        $mode = in_array($request->input('mode'), ['full', 'incremental']) ? $request->input('mode') : 'incremental';

        // 防重:同一货源已有排队/运行中任务时拒绝
        if (SupplySyncTask::where('supply_source_id', $supplySource->id)
            ->whereIn('status', [SupplySyncTask::STATUS_QUEUED, SupplySyncTask::STATUS_RUNNING])
            ->exists()) {
            return response()->json([
                'ok' => false,
                'message' => '该货源已有同步任务进行中,请等待完成或先取消',
            ], 409);
        }

        $task = SupplySyncTask::create([
            'supply_source_id' => $supplySource->id,
            'mode' => $mode,
            'status' => SupplySyncTask::STATUS_QUEUED,
        ]);

        SyncSupplySourceProducts::dispatch($supplySource->id, $mode, $task->id);

        return response()->json(['ok' => true, 'task' => $task]);
    }

    /**
     * GET /api/admin/supply-sources/{source}/sync-tasks 同步任务列表(最新优先,含进行中)。
     */
    public function syncTasks(SupplySource $supplySource): JsonResponse
    {
        $tasks = SupplySyncTask::where('supply_source_id', $supplySource->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json(['ok' => true, 'tasks' => $tasks]);
    }

    /**
     * POST /api/admin/supply-sources/{source}/sync-cancel 取消进行中的同步任务。
     * 置 cancelled 标记,Job 感知后立即停止(无需强杀进程)。
     */
    public function syncCancel(Request $request, SupplySource $supplySource): JsonResponse
    {
        $task = SupplySyncTask::where('supply_source_id', $supplySource->id)
            ->whereIn('status', [SupplySyncTask::STATUS_QUEUED, SupplySyncTask::STATUS_RUNNING])
            ->latest('id')
            ->first();

        if (! $task) {
            return response()->json(['ok' => false, 'message' => '没有进行中的同步任务'], 404);
        }

        $task->update(['status' => SupplySyncTask::STATUS_CANCELLED]);

        return response()->json(['ok' => true, 'task' => $task]);
    }

    /**
     * GET /api/admin/supply-sources/sync-tasks 全部货源同步任务(含货源名,最新优先)。
     */
    public function allSyncTasks(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->input('limit', 50)));
        $tasks = SupplySyncTask::with('source:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($t) => array_merge($t->toArray(), [
                'source_name' => $t->source?->name ?? '已删除货源',
            ]));

        return response()->json(['ok' => true, 'tasks' => $tasks]);
    }

    /**
     * POST /api/admin/supply-sources/sync-queue-probe 派发队列探针。
     * worker 正常时心跳立即刷新;未运行则心跳停留在旧值。
     */
    public function probeQueue(): JsonResponse
    {
        QueueHeartbeatJob::dispatch();

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/admin/supply-sources/sync-queue-status 队列心跳状态。
     * heartbeat_at 为最近一次 worker 执行探针的时间戳(秒);null 表示从未执行过。
     */
    public function queueStatus(): JsonResponse
    {
        $heartbeat = Cache::get('queue:heartbeat');
        $connection = config('queue.default');

        return response()->json([
            'ok' => true,
            'heartbeat_at' => $heartbeat !== null ? (int) $heartbeat : null,
            'connection' => $connection,
            'healthy' => $heartbeat !== null && (now()->timestamp - (int) $heartbeat) <= 20,
        ]);
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
        // 预览结果缓存 60 秒(仅成功结果缓存):上游商品多时实时拉全量可能超过前端
        // 超时,重复点击直接命中缓存;导入成功后自动失效(见 importProducts)。
        $cacheKey = 'supply:preview:'.$supplySource->id;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached);
        }

        try {
            $driver = app(SupplyManager::class)->driver($supplySource);
            $items = $this->listAllProducts($driver);

            // 上游分类名映射 code → name:
            // 1) 驱动 listCategories() 返回的分类列表(dujiao-next / ZCard 供货 API)
            // 2) 商品内嵌的 categoryName(acg-faka 的 items 里 cat.name)
            $catNames = [];
            try {
                foreach ($driver->listCategories() as $cat) {
                    $catNames[$cat->code] = $cat->name;
                }
            } catch (\Throwable $e) {
                // 分类接口失败不阻塞商品预览,回退到"分类 #code"占位
            }
            foreach ($items as $p) {
                if ($p->categoryCode !== null && $p->categoryName !== null) {
                    $catNames[$p->categoryCode] = $p->categoryName;
                }
            }

            // 已导入本地的商品 code 集合(判断 already_imported)。
            // 用非严格比较:上游 code 可能是数字/字符串,本地存储也可能有类型差异,
            // 严格 in_array 会漏判导致"已导入商品仍显示新货源"。
            $importedCodes = collect(
                Product::where('upstream_source_id', $supplySource->id)
                    ->pluck('upstream_product_code')->toArray()
            )->map(fn ($c) => (string) $c)->all();

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
                    'already_imported' => in_array((string) $p->code, $importedCodes, true),
                ];
            }
            foreach ($bucket as $catCode => $products) {
                $tree[] = [
                    'category_code' => $catCode === '_uncategorized' ? null : $catCode,
                    'category_name' => $catCode === '_uncategorized'
                        ? '未分类'
                        : ($catNames[$catCode] ?? ('分类 #'.$catCode)),
                    'products' => $products,
                ];
            }

            $data = [
                'ok' => true,
                'total' => count($items),
                'categories' => $tree,
            ];
            Cache::put($cacheKey, $data, 60);

            return response()->json($data);
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
            'pricing' => 'nullable|array',
            'pricing.mode' => 'sometimes|in:percent,fixed,equal,pending',
            'pricing.markup_percent' => 'sometimes|numeric|min:0',
            'pricing.markup_amount' => 'sometimes|numeric|min:0',
            'save_default' => 'sometimes|boolean',
            'category_map' => 'nullable|array',
            'category_map.*' => 'nullable|integer',
        ]);

        try {
            // 显式定价策略(勾选导入时前端选择),null 则沿用货源默认
            $pricing = $data['pricing'] ?? null;
            if ($pricing !== null) {
                $pricing = array_intersect_key($pricing, array_flip(['mode', 'markup_percent', 'markup_amount']));
            }

            // 保存为货源默认定价设置(下次打开弹窗预填/后续同步生效)
            if (! empty($data['save_default']) && $pricing !== null) {
                $settings = $supplySource->settings ?? [];
                $settings['default_pricing_mode'] = $pricing['mode'] ?? $settings['default_pricing_mode'] ?? 'percent';
                if (isset($pricing['markup_percent'])) {
                    $settings['default_markup_percent'] = (int) $pricing['markup_percent'];
                }
                if (isset($pricing['markup_amount'])) {
                    $settings['default_markup_amount'] = (float) $pricing['markup_amount'];
                }
                $supplySource->update(['settings' => $settings]);
            }

            $driver = app(SupplyManager::class)->driver($supplySource);
            $sync = app(SupplySyncService::class);
            $imported = 0;
            $skipped = 0;

            // 拉取上游全部商品(循环分页,不能只取第 1 页),按 code 索引。
            // 批量命中是主路径;个别 code 因上游分页/响应不一致未命中时,
            // 逐个 getProduct 精确拉取兜底 —— 保证勾选的商品一定入库,
            // 否则客户会看到"导入成功但重新拉取仍显示新货源"。
            $map = collect($this->listAllProducts($driver))->keyBy('code');

            foreach ($data['codes'] as $code) {
                $dto = $map->get($code);
                if (! $dto) {
                    try {
                        $dto = $driver->getProduct((string) $code);
                    } catch (\Throwable $e) {
                        $dto = null;
                    }
                }
                if (! $dto) {
                    $skipped++;

                    continue;
                }
                $sync->upsertProduct($supplySource, $dto, $pricing, $data['category_map'] ?? null);
                $imported++;
            }

            $supplySource->update(['last_synced_at' => now(), 'last_error' => null]);
            // 导入成功:失效预览缓存,下次预览重新拉取(已导入标记更新)
            Cache::forget('supply:preview:'.$supplySource->id);

            return response()->json([
                'ok' => true,
                'imported' => $imported,
                'skipped' => $skipped,
                'message' => "成功导入 {$imported} 个商品".($skipped > 0 ? "(跳过 {$skipped} 个)" : ''),
            ]);
        } catch (\Throwable $e) {
            $supplySource->update(['last_error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/admin/supply-sources/{source}/products/debug
     * 调试用:直接发起 items 请求,返回上游原始响应(含 HTTP 状态码、响应头、响应体),
     * 供排查"测试连通成功但拉取商品失败"的问题。
     */
    public function debugProducts(SupplySource $supplySource): JsonResponse
    {
        $creds = $supplySource->credentials ?? [];
        $baseUrl = rtrim($supplySource->base_url, '/');
        $appId = $creds['app_id'] ?? '';
        $appKey = $creds['app_key'] ?? '';

        // 构造与 AcgFakaDriver 完全一致的签名
        $params = ['app_id' => $appId, 'app_key' => $appKey];
        unset($params['sign']);
        ksort($params);
        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);
        $sign = md5(urldecode(http_build_query($params)).'&key='.$appKey);
        $params['sign'] = $sign;

        $url = $baseUrl.'/shared/commodity/items';
        $resp = Http::asForm()->timeout(30)->post($url, $params);

        return response()->json([
            'request' => [
                'url' => $url,
                'method' => 'POST (application/x-www-form-urlencoded)',
                'app_id' => $appId,
                'app_key_masked' => $appKey ? ('••••'.substr($appKey, -4)) : '(空)',
                'sign' => $sign,
            ],
            'response' => [
                'http_status' => $resp->status(),
                'content_type' => $resp->header('Content-Type'),
                'body_preview' => mb_substr($resp->body(), 0, 2000),
                'json' => $resp->json(),
            ],
            'hint' => '如果 http_status=404:上游未配置伪静态/URL重写。'
                .'如果 json.code!=200:看 json.msg(如"密钥错误"=凭证问题,"商户ID不存在"=app_id 错)。'
                .'如果 json.data 为空数组:商品未开启 API 上架(api_status=1)。',
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
                $creds[$key] = '••••••••'.substr($val, -4);
            }
        }
        $source->credentials = $creds;

        return $source;
    }

    /** 拉取上游全部商品(循环分页;上限 20 页防上游异常死循环) */
    private function listAllProducts(mixed $driver): array
    {
        $all = [];
        $page = 1;
        do {
            $result = $driver->listProducts(null, $page);
            $items = $result['items'] ?? [];
            $all = array_merge($all, $items);
            $hasMore = ! empty($result['has_more']);
            $page++;
        } while ($hasMore && $page <= 20);

        return $all;
    }
}
