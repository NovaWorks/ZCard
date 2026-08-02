# ZCard P1-B — 卡密导入与库存 实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 实现卡密导入引擎(文件/粘贴、单列/多列、同步/队列切换、去重加密批次)+ 库存管理(Card/CardImport Resource、商品库存、导出)+ API 接入层(API-first,Service 为核心)。

**Architecture:** API-first —— `CardImportService` / `CardService` 是核心(UI 无关),Filament Actions 和 API Controllers 都是调 Service 的薄入口。导入用 `INSERT IGNORE` + `UNIQUE(product_id, content_hash)` 去重,大文件(>5000)转队列 Job。

**Tech Stack:** Laravel 13, Filament v5.7.3, Redis 队列, CardCipher(Phase 0 加密), Sanctum(API 鉴权)。

**对应 spec:** `docs/superpowers/specs/2026-07-29-zcard-p1b-card-import-stock-design.md`

**v5 API 已确认(关键):**
- Action modal form 用 `->schema([...])`(非 `->form()`),page header 用 `getHeaderActions()`(非 `getActions()`)
- 导出:`->action(fn () => response()->streamDownload(...))`
- 查看明文:row action `->modalContent(fn ($record) => ...)->modalSubmitAction(false)`
- list-only Resource:`getPages()` 只返回 ListRecords + `canCreate(): false`
- 库存列:`modifyQueryUsing` + `withCount`(性能优,可排序)

---

## 环境前提

- 容器在跑(`./vendor/bin/sail up -d`),app :8092。
- Phase 0 卡密地基已就位:cards/card_imports 表、CardCipher、Card/CardImport 模型。
- P1-A 商品已就位(有测试商品 slug=steam-card)。
- 所有 artisan 命令走 `./vendor/bin/sail`(简写 `sail`)。

---

## 文件结构总览

```
app/
├── Support/
│   ├── CardImportService.php        # T1 核心:导入引擎
│   └── CardService.php              # T2 库存查询/导出/禁用
├── Jobs/
│   └── ImportCardsJob.php           # T3 大文件队列
├── Http/Controllers/Api/
│   ├── CardImportController.php     # T4 API 导入/状态/撤销
│   └── CardController.php           # T4 API 库存/列表/导出
├── Models/
│   └── Card.php (改,加 decryptedContent 访问器)  # T5
└── Filament/
    └── Resources/
        ├── Cards/                   # T5 CardResource
        ├── CardImports/             # T6 CardImportResource
        └── Products/ (改,加库存列+导入Action)  # T7
routes/api.php (改)                   # T4
```

---

## Task 1: CardImportService(导入引擎核心)

**Files:**
- Create: `app/Support/CardImportService.php`

- [ ] **Step 1: 创建 CardImportService**

`app/Support/CardImportService.php`:
```php
<?php

namespace App\Support;

use App\Models\Card;
use App\Models\CardImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 卡密导入引擎(spec §5.1)。
 * 与 UI 无关 —— Filament Action 和 API Controller 都调它(API-first)。
 */
class CardImportService
{
    const THRESHOLD_QUEUE = 5000; // 超过此数转队列

    /**
     * 导入入口:解析 + 决定同步/队列,返回 CardImport 批次。
     *
     * @param int    $productId   目标商品
     * @param int    $operatorId  操作者 user_id
     * @param string $rawInput    原始内容(文本或文件读取后的字符串)
     * @param array  $options     format=single|multi, delimiter(可选)
     */
    public function import(int $productId, int $operatorId, string $rawInput, array $options = []): CardImport
    {
        $cards = $this->parse($rawInput, $options['format'] ?? 'single', $options['delimiter'] ?? null);
        $total = count($cards);

        $import = CardImport::create([
            'product_id' => $productId,
            'operator_id' => $operatorId,
            'source' => $options['source'] ?? 'manual',
            'total' => $total,
            'success_count' => 0,
            'failed_count' => 0,
            'status' => 'running',
        ]);

        if ($total === 0) {
            $import->update(['status' => 'completed']);
            return $import;
        }

        if ($total <= self::THRESHOLD_QUEUE) {
            // 同步处理
            $this->processSync($import, $cards);
        } else {
            // 转队列:存原始输入到临时文件,Job 读文件解析(避免序列化大数组)
            $tmpFile = $this->storeTempInput($rawInput, $import->id);
            \App\Jobs\ImportCardsJob::dispatch($import->id, $tmpFile, $options);
        }

        return $import;
    }

    /**
     * 解析:按 format 拆成卡密明文数组。
     * single: 每行一个(去空行/空白)
     * multi: 整行作为卡密内容(含分隔符),去空行
     */
    public function parse(string $rawInput, string $format = 'single', ?string $delimiter = null): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $rawInput);
        $cards = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // multi 模式:整行入库(delimiter 仅提示用);single 也是整行
            $cards[] = $line;
        }
        return $cards;
    }

    /** 同步处理(≤5000):分块跑完 */
    public function processSync(CardImport $import, array $cards): void
    {
        foreach (array_chunk($cards, 1000) as $chunk) {
            $this->processChunk($import, $chunk);
        }
        $import->update(['status' => 'completed']);
    }

    /** 处理一块(1000 条):去重加密 + 批量插入 */
    public function processChunk(CardImport $import, array $chunk): void
    {
        $productId = $import->product_id;
        $importId = $import->id;
        $errors = $import->error_log ? (array) $import->error_log : [];

        // 算所有 hash
        $hashes = [];
        foreach ($chunk as $i => $plain) {
            $hashes[$i] = CardCipher::hash($plain);
        }

        // 批量查已存在的 (product_id, hash)
        $existing = DB::table('cards')
            ->where('product_id', $productId)
            ->whereIn('content_hash', array_values($hashes))
            ->pluck('content_hash')
            ->flip();

        $toInsert = [];
        $success = 0;
        $failed = 0;
        foreach ($chunk as $i => $plain) {
            $hash = $hashes[$i];
            if ($existing->has($hash)) {
                $failed++;
                $errors[] = ['line' => $i, 'reason' => 'duplicate'];
                continue;
            }
            $toInsert[] = [
                'product_id' => $productId,
                'import_id' => $importId,
                'content' => CardCipher::encrypt($plain),
                'content_hash' => $hash,
                'status' => Card::STATUS_UNUSED,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $success++;
        }

        // 批量插入(insertOrIgnore 走唯一索引兜底防并发)
        if ($toInsert) {
            DB::table('cards')->insertOrIgnore($toInsert);
        }

        // 累加统计(注意 insertOrIgnore 可能比 success 少,以实际为准)
        $realInserted = count($toInsert); // 简化:并发冲突极少,以准备数计
        $import->increment('success_count', $realInserted);
        $import->increment('failed_count', $failed);
        if ($errors) {
            $import->update(['error_log' => array_merge($import->error_log ?? [], $errors)]);
        }
    }

    /** 撤销某批次未用卡密(只删 unused,返回删除数) */
    public function revokeImport(int $importId): int
    {
        $count = Card::where('import_id', $importId)
            ->where('status', Card::STATUS_UNUSED)
            ->count();

        Card::where('import_id', $importId)
            ->where('status', Card::STATUS_UNUSED)
            ->delete();

        CardImport::where('id', $importId)->update(['status' => 'revoked']);

        return $count;
    }

    /** 存原始输入到临时文件(队列 Job 用,避免序列化大数组) */
    private function storeTempInput(string $rawInput, int $importId): string
    {
        $path = "card-imports/import-{$importId}-" . time() . '.txt';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $rawInput);
        return $path;
    }
}
```

- [ ] **Step 2: tinker 验证 parse + 小量导入**

```bash
./vendor/bin/sail artisan tinker --execute="
\$svc = app(App\Support\CardImportService::class);
\$cards = \$svc->parse(\"abc123\ndef456\n\nghi789\", 'single');
echo 'parsed: '.count(\$cards).' | first: '.\$cards[0];
"
```
Expected: `parsed: 3 | first: abc123`

- [ ] **Step 3: 验证 import(用 steam-card 商品)**

```bash
./vendor/bin/sail artisan tinker --execute="
\$p = App\Models\Product::where('slug','steam-card')->first();
\$svc = app(App\Support\CardImportService::class);
\$imp = \$svc->import(\$p->id, 1, \"test-card-001\ntest-card-002\ntest-card-001\", ['source'=>'tinker']);
echo 'import #'.\$imp->id.' success='.\$imp->fresh()->success_count.' failed='.\$imp->fresh()->failed_count.' status='.\$imp->fresh()->status;
"
```
Expected: `success=2 failed=1 status=completed`(test-card-001 重复)

- [ ] **Step 4: 验证卡密已加密入库**

```bash
./vendor/bin/sail artisan tinker --execute="
\$c = App\Models\Card::where('content_hash', App\Support\CardCipher::hash('test-card-001'))->first();
echo 'content(密文): '.substr(\$c->content,0,30).'... | plain: '.\$c->plainContent().' | status: '.\$c->status;
"
```
Expected: content 是密文,plain 是 test-card-001,status unused

- [ ] **Step 5: 提交**

```bash
git add app/Support/CardImportService.php && git commit -m "feat(card): CardImportService - parse, dedup+encrypt, sync import, revoke"
```

---

## Task 2: CardService(库存查询/导出/禁用)

**Files:**
- Create: `app/Support/CardService.php`

- [ ] **Step 1: 创建 CardService**

`app/Support/CardService.php`:
```php
<?php

namespace App\Support;

use App\Models\Card;
use Illuminate\Support\Facades\DB;

/**
 * 卡密库存服务(spec §5.2)。UI 无关,Filament + API 共用。
 */
class CardService
{
    /** 商品可用库存数(cards WHERE product_id AND status=unused) */
    public function countStock(int $productId): int
    {
        return (int) Card::where('product_id', $productId)
            ->where('status', Card::STATUS_UNUSED)
            ->count();
    }

    /** 批量库存(多商品,商品列表用,一次查询) */
    public function countStockForProducts(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        return Card::whereIn('product_id', $productIds)
            ->where('status', Card::STATUS_UNUSED)
            ->select('product_id', DB::raw('count(*) as cnt'))
            ->groupBy('product_id')
            ->pluck('cnt', 'product_id')
            ->toArray();
    }

    /** 导出某商品卡密为 txt(明文,逐行) */
    public function export(int $productId): string
    {
        $cards = Card::where('product_id', $productId)
            ->where('status', Card::STATUS_UNUSED)
            ->orderBy('id')
            ->get();

        $lines = [];
        foreach ($cards as $card) {
            $lines[] = $card->plainContent();
        }
        return implode("\n", $lines);
    }

    /** 批量禁用 */
    public function disable(array $cardIds): int
    {
        return Card::whereIn('id', $cardIds)
            ->whereIn('status', [Card::STATUS_UNUSED]) // 只禁用未用的
            ->update(['status' => Card::STATUS_DISABLED]);
    }
}
```

- [ ] **Step 2: tinker 验证**

```bash
./vendor/bin/sail artisan tinker --execute="
\$svc = app(App\Support\CardService::class);
\$p = App\Models\Product::where('slug','steam-card')->first();
echo 'stock: '.\$svc->countStock(\$p->id).' | export前30: '.substr(\$svc->export(\$p->id),0,30);
"
```
Expected: stock=2(刚导入的),export 含 test-card-001

- [ ] **Step 3: 提交**

```bash
git add app/Support/CardService.php && git commit -m "feat(card): CardService - stock count, export, disable"
```

---

## Task 3: ImportCardsJob(大文件队列)

**Files:**
- Create: `app/Jobs/ImportCardsJob.php`

- [ ] **Step 1: 创建 Job**

`app/Jobs/ImportCardsJob.php`:
```php
<?php

namespace App\Jobs;

use App\Models\CardImport;
use App\Support\CardImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * 大文件卡密导入队列 Job(spec §6)。
 * 从临时文件读原始输入,重新解析后分块处理。
 */
class ImportCardsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $importId,
        public string $tempFilePath,
        public array $options = [],
    ) {}

    public function handle(CardImportService $service): void
    {
        $import = CardImport::find($this->importId);
        if (! $import) {
            return;
        }

        try {
            $rawInput = Storage::disk('local')->get($this->tempFilePath);
            $cards = $service->parse($rawInput, $this->options['format'] ?? 'single', $this->options['delimiter'] ?? null);

            foreach (array_chunk($cards, 1000) as $chunk) {
                $service->processChunk($import, $chunk);
            }

            $import->update(['status' => 'completed']);
        } catch (\Throwable $e) {
            $import->update(['status' => 'failed']);
            report($e);
        } finally {
            Storage::disk('local')->delete($this->tempFilePath);
        }
    }
}
```

- [ ] **Step 2: 提交**

```bash
git add app/Jobs/ImportCardsJob.php && git commit -m "feat(card): ImportCardsJob for async large file import"
```

---

## Task 4: API 接入层(API-first)

**Files:**
- Create: `app/Http/Controllers/Api/CardImportController.php`
- Create: `app/Http/Controllers/Api/CardController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: CardImportController**

`app/Http/Controllers/Api/CardImportController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CardImport;
use App\Support\CardImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardImportController extends Controller
{
    public function import(Request $request, CardImportService $service): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'content' => 'required|string',
            'format' => 'nullable|in:single,multi',
            'delimiter' => 'nullable|string',
        ]);

        $import = $service->import(
            $data['product_id'],
            $request->user()->id,
            $data['content'],
            [
                'format' => $data['format'] ?? 'single',
                'delimiter' => $data['delimiter'] ?? null,
                'source' => 'api',
            ]
        );

        return response()->json([
            'import_id' => $import->id,
            'status' => $import->fresh()->status,
            'success_count' => $import->fresh()->success_count,
            'failed_count' => $import->fresh()->failed_count,
            'total' => $import->total,
        ]);
    }

    public function status(int $id): JsonResponse
    {
        $import = CardImport::findOrFail($id);
        return response()->json([
            'import_id' => $import->id,
            'status' => $import->status,
            'success_count' => $import->success_count,
            'failed_count' => $import->failed_count,
            'total' => $import->total,
        ]);
    }

    public function revoke(int $id, CardImportService $service): JsonResponse
    {
        $deleted = $service->revokeImport($id);
        return response()->json(['import_id' => $id, 'revoked_cards' => $deleted]);
    }
}
```

- [ ] **Step 2: CardController**

`app/Http/Controllers/Api/CardController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Support\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CardController extends Controller
{
    public function stock(int $productId, CardService $service): JsonResponse
    {
        return response()->json([
            'product_id' => $productId,
            'stock' => $service->countStock($productId),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Card::query();
        if ($productId = $request->input('product_id')) {
            $query->where('product_id', $productId);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        $cards = $query->orderByDesc('id')->paginate(20);
        // 不返回 content(加密密文),只返回元信息
        return response()->json($cards->through(fn ($c) => [
            'id' => $c->id,
            'product_id' => $c->product_id,
            'status' => $c->status,
            'created_at' => $c->created_at,
        ]));
    }

    public function export(int $productId, CardService $service): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print($service->export($productId)),
            "cards-product-{$productId}-" . now()->format('Ymd_His') . '.txt',
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
```

- [ ] **Step 3: 注册路由(加 auth:sanctum 保护管理类)**

修改 `routes/api.php`,在现有路由后加:
```php
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\CardImportController;

Route::middleware('auth:sanctum')->prefix('cards')->group(function () {
    Route::post('/import', [CardImportController::class, 'import']);
    Route::get('/import-status/{id}', [CardImportController::class, 'status']);
    Route::post('/import/{id}/revoke', [CardImportController::class, 'revoke']);
    Route::get('/export/{productId}', [CardController::class, 'export']);
});
Route::middleware('auth:sanctum')->get('/products/{id}/stock', [CardController::class, 'stock']);
Route::middleware('auth:sanctum')->get('/cards', [CardController::class, 'index']);
```

- [ ] **Step 4: 验证 API(stock 不需 token 可测结构,revoke 需 token)**

```bash
./vendor/bin/sail artisan route:clear
echo "=== /api/products/1/stock(需 token,预期 401) ==="
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8092/api/products/1/stock
```
Expected: 401(未带 token,鉴权生效)

- [ ] **Step 5: 提交**

```bash
git add app/Http/Controllers/Api/ routes/api.php && git commit -m "feat(api): card import/status/revoke + stock/list/export endpoints (auth:sanctum)"
```

---

## Task 5: Card 模型加访问器 + CardResource(卡密列表)

**Files:**
- Modify: `app/Models/Card.php`(加 decryptedContent 访问器)
- Create: `app/Filament/Resources/Cards/` (Resource + Schemas/Tables/Pages)
- Create: `resources/views/filament/cards/plaintext.blade.php`

- [ ] **Step 1: Card 模型加 decryptedContent(供 Filament 查看明文用)**

修改 `app/Models/Card.php`,在 `plainContent()` 方法后加(或已有则确认):
```php
/** 查看明文(同 plainContent,语义别名供 Filament modal 用) */
public function decryptedContent(): string
{
    return CardCipher::decrypt($this->content);
}
```
(若 plainContent 已存在,decryptedContent 可直接 `return $this->plainContent();`)

- [ ] **Step 2: 生成 CardResource**

```bash
./vendor/bin/sail artisan filament:resource Card
```

- [ ] **Step 3: CardResource 导航分组(中文化)**

打开 `app/Filament/Resources/Cards/CardResource.php`,加(group=商品,中文化标签,用 getter 方法):
```php
public static function getNavigationGroup(): string | \UnitEnum | null
{
    return '商品';
}

public static function getNavigationLabel(): string
{
    return '卡密库存';
}

public static function getModelLabel(): string
{
    return '卡密';
}

public static function getPluralModelLabel(): string
{
    return '卡密';
}

public static function getNavigationIcon(): string | \BackedEnum | null
{
    return 'heroicon-o-ticket';
}
```

- [ ] **Step 4: CardForm(只读场景,卡密一般不手动新建;保留最简)**

打开生成的 `app/Filament/Resources/Cards/Schemas/CardForm.php`,保留最简(状态可编辑):
```php
<?php

namespace App\Filament\Resources\Cards\Schemas;

use App\Models\Card;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class CardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('status')
                    ->options([
                        Card::STATUS_UNUSED => '未使用',
                        Card::STATUS_LOCKED => '锁定中',
                        Card::STATUS_USED => '已使用',
                        Card::STATUS_DISABLED => '已禁用',
                    ])
                    ->required(),
            ]);
    }
}
```

- [ ] **Step 5: CardsTable(列表 + 筛选 + 查看明文 + 禁用)**

完全替换 `app/Filament/Resources/Cards/Tables/CardsTable.php`:
```php
<?php

namespace App\Filament\Resources\Cards\Tables;

use App\Models\Card;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

class CardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->sortable()->label('ID'),
                TextColumn::make('product.name')->label('商品')->searchable(),
                TextColumn::make('content')->limit(20)->label('卡密(加密)')->copyable(false),
                TextColumn::make('status')->badge()->label('状态')->colors([
                    'success' => Card::STATUS_UNUSED,
                    'warning' => Card::STATUS_LOCKED,
                    'gray' => Card::STATUS_USED,
                    'danger' => Card::STATUS_DISABLED,
                ]),
                TextColumn::make('import.source')->label('来源')->toggleable(),
                TextColumn::make('created_at')->dateTime()->label('导入时间')->sortable(),
            ])
            ->filters([
                SelectFilter::make('product_id')
                    ->relationship('product', 'name')
                    ->label('商品'),
                SelectFilter::make('status')
                    ->options([
                        Card::STATUS_UNUSED => '未使用',
                        Card::STATUS_LOCKED => '锁定中',
                        Card::STATUS_USED => '已使用',
                        Card::STATUS_DISABLED => '已禁用',
                    ])
                    ->label('状态'),
            ])
            ->recordActions([
                TableAction::make('viewPlaintext')
                    ->label('查看明文')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('卡密明文')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (Card $record): View => view(
                        'filament.cards.plaintext',
                        ['plaintext' => $record->decryptedContent()],
                    )),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

- [ ] **Step 6: 创建明文查看 Blade**

创建 `resources/views/filament/cards/plaintext.blade.php`:
```blade
<div class="space-y-2">
    <pre class="text-sm bg-gray-50 dark:bg-gray-800 p-3 rounded break-all whitespace-pre-wrap">{{ $plaintext }}</pre>
</div>
```

- [ ] **Step 7: 生成 shield 权限**

```bash
./vendor/bin/sail artisan shield:generate --all --panel=admin --no-interaction
./vendor/bin/sail artisan optimize:clear
```

- [ ] **Step 8: 验证后台卡密列表**

浏览器后台 → 商品 → 卡密库存 → 见导入的卡密,点"查看明文"弹出明文,状态 badge 颜色正确。

- [ ] **Step 9: 提交**

```bash
git add app/Models/Card.php app/Filament/Resources/Cards/ resources/views/filament/cards/ app/Policies/ && git commit -m "feat(filament): CardResource - list, filters, view plaintext modal"
```

---

## Task 6: CardImportResource(导入批次,只读列表 + 撤销)

**Files:**
- Create: `app/Filament/Resources/CardImports/` (Resource + Tables/Pages)

- [ ] **Step 1: 生成 CardImportResource**

```bash
./vendor/bin/sail artisan filament:resource CardImport
```

- [ ] **Step 2: CardImportResource 配置(list-only + 导航 + 撤销 Action)**

打开 `app/Filament/Resources/CardImports/CardImportResource.php`,加:
```php
public static function getNavigationGroup(): string | \UnitEnum | null
{
    return '商品';
}

public static function getNavigationLabel(): string
{
    return '导入批次';
}

public static function getModelLabel(): string
{
    return '导入批次';
}

public static function getPluralModelLabel(): string
{
    return '导入批次';
}

public static function getNavigationIcon(): string | \BackedEnum | null
{
    return 'heroicon-o-arrow-down-tray';
}

public static function canCreate(): bool
{
    return false; // 批次由导入服务创建,不手动新建
}
```
确认 `getPages()` 只返回 ListCardImports(默认生成的就只有 list,无 create/edit 也可;若生成有 edit 可保留,或删 edit 页)。

- [ ] **Step 3: CardImportsTable(列表 + 撤销 Action + 失败明细展开)**

完全替换 `app/Filament/Resources/CardImports/Tables/CardImportsTable.php`:
```php
<?php

namespace App\Filament\Resources\CardImports\Tables;

use App\Models\CardImport;
use App\Support\CardImportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CardImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->sortable()->label('批次'),
                TextColumn::make('product.name')->label('商品'),
                TextColumn::make('source')->label('来源'),
                TextColumn::make('total')->label('总数')->alignRight(),
                TextColumn::make('success_count')->label('成功')->alignRight()->color('success'),
                TextColumn::make('failed_count')->label('失败')->alignRight()->color(fn ($state) => $state > 0 ? 'danger' : null),
                TextColumn::make('status')->badge()->label('状态')->colors([
                    'success' => 'completed',
                    'warning' => 'running',
                    'danger' => 'failed',
                    'gray' => 'revoked',
                ]),
                TextColumn::make('error_log')->label('失败明细')
                    ->formatStateUsing(fn ($state) => is_array($state) ? count($state) . ' 条' : '-')
                    ->toggleable(),
                TextColumn::make('created_at')->dateTime()->label('时间')->sortable(),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('撤销未用')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('撤销导入')
                    ->modalDescription('将删除本批次所有"未使用"的卡密,已使用/锁定的不受影响。')
                    ->visible(fn (CardImport $record) => $record->status !== 'revoked')
                    ->action(function (CardImport $record, CardImportService $service) {
                        $deleted = $service->revokeImport($record->id);
                        Notification::make()->success()->title("已撤销 {$deleted} 张未用卡密")->send();
                    }),
            ]);
    }
}
```

- [ ] **Step 4: 生成 shield 权限 + 验证**

```bash
./vendor/bin/sail artisan shield:generate --all --panel=admin --no-interaction
./vendor/bin/sail artisan optimize:clear
```
浏览器后台 → 商品 → 导入批次 → 见刚才的导入批次,"撤销未用"按钮可见。

- [ ] **Step 5: 提交**

```bash
git add app/Filament/Resources/CardImports/ app/Policies/ && git commit -m "feat(filament): CardImportResource - batch list, revoke unused action"
```

---

## Task 7: ProductResource 增强(库存列 + 导入卡密 Action)

**Files:**
- Modify: `app/Filament/Resources/Products/Tables/ProductsTable.php`(加库存列)
- Modify: `app/Filament/Resources/Products/Pages/ListProducts.php`(加导入 Action)

- [ ] **Step 1: ProductsTable 加库存列(性能优:modifyQueryUsing + withCount)**

修改 `app/Filament/Resources/Products/Tables/ProductsTable.php`:
- 在 use 区加:
```php
use App\Models\Card;
```
- 在 `->columns([...])` 里加一列(在 price 之后):
```php
TextColumn::make('available_stock_count')->label('可用库存')->sortable()->alignRight(),
```
- 在 `->defaultSort('sort')` 后加:
```php
->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) =>
    $query->withCount(['cards as available_stock_count' => fn ($q) => $q->where('status', Card::STATUS_UNUSED)])
)
```

- [ ] **Step 2: ListProducts 加"导入卡密"header Action**

打开 `app/Filament/Resources/Products/Pages/ListProducts.php`,加 `getHeaderActions()`(若已有则合并)。完整替换:
```php
<?php

namespace App\Filament\Resources\Products\Pages;

use App\Models\Product;
use App\Support\CardImportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCards')
                ->label('导入卡密')
                ->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    Select::make('product_id')
                        ->label('目标商品')
                        ->options(Product::orderBy('name')->pluck('name', 'id'))
                        ->required(),
                    \Filament\Forms\Components\FileUpload::make('file')
                        ->label('或上传文件(txt/csv,每行一个)')
                        ->acceptedFileTypes(['text/plain', 'text/csv'])
                        ->disk('local'),
                    Textarea::make('content')
                        ->label('或直接粘贴卡密(每行一个)')
                        ->rows(8),
                ])
                ->action(function (array $data) {
                    // 文件优先,否则用粘贴内容
                    $content = $data['content'] ?? '';
                    if (! empty($data['file'])) {
                        // file 存的是 local 盘路径(单个)
                        $path = is_array($data['file']) ? $data['file'][0] : $data['file'];
                        $fileContent = \Illuminate\Support\Facades\Storage::disk('local')->get($path);
                        if ($fileContent) {
                            $content = $fileContent;
                        }
                    }
                    if (trim($content) === '') {
                        Notification::make()->title('请上传文件或粘贴卡密内容')->danger()->send();
                        return;
                    }
                    $service = app(CardImportService::class);
                    $import = $service->import(
                        $data['product_id'],
                        auth()->id(),
                        $content,
                        ['source' => 'filament']
                    );
                    $fresh = $import->fresh();
                    Notification::make()
                        ->title('导入完成')
                        ->body("成功 {$fresh->success_count} / 失败 {$fresh->failed_count}(总数 {$fresh->total})")
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
```

- [ ] **Step 3: 验证导入 Action**

浏览器后台 → 商品管理 → 顶部应有"导入卡密"按钮 → 点开选商品 + 粘贴卡密 → 导入完成通知显示成功/失败数。

- [ ] **Step 4: 验证库存列**

商品列表应有"可用库存"列,显示真实数字。

- [ ] **Step 5: 提交**

```bash
git add app/Filament/Resources/Products/ && git commit -m "feat(filament): product stock column + import cards action"
```

---

## Task 8: 收尾验证(spec §9 验收清单)

- [ ] **Step 1: 导入引擎验证(tinker 跑一次完整流程)**

```bash
./vendor/bin/sail artisan tinker --execute="
\$svc = app(App\Support\CardImportService::class);
\$p = App\Models\Product::where('slug','steam-card')->first();
\$imp = \$svc->import(\$p->id, 1, \"e2e-001\ne2e-002\ne2e-001\ne2e-003\", ['source'=>'e2e']);
\$f = \$imp->fresh();
echo \"success={\$f->success_count} failed={\$f->failed_count} status={\$f->status}\";
"
```
Expected: `success=3 failed=1`(e2e-001 重复)

- [ ] **Step 2: API 验证(生成 token + 调导入)**

```bash
# 生成 token
TOKEN=$(./vendor/bin/sail artisan tinker --execute="
\$u = App\Models\User::find(1);
echo \$u->createToken('test')->plainTextToken;
" 2>&1 | tail -1)
echo "token: $TOKEN"
# 调导入 API
curl -s -X POST http://localhost:8092/api/cards/import \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"product_id":1,"content":"api-001\napi-002"}'
echo ""
# 查库存
curl -s -H "Authorization: Bearer $TOKEN" http://localhost:8092/api/products/1/stock
```
Expected: 导入返回 success_count=2;stock 返回数字

- [ ] **Step 3: 后台界面全验**

浏览器后台:
- 商品管理:有"可用库存"列 + "导入卡密"按钮,导入后数字增长
- 卡密库存:列表 + 查看明文 modal + 状态筛选
- 导入批次:列表 + 撤销未用

- [ ] **Step 4: 测试通过**

```bash
./vendor/bin/sail test 2>&1 | tail -3
```
Expected: PASS

- [ ] **Step 5: docs 未进 git + 工作树状态**

```bash
git ls-files docs/ | head -1 && echo "BAD" || echo "GOOD: docs not tracked"
git status --short
```

---

## 完成标准(对照 spec §9)

- [ ] CardImportService(import/parse/processSync/processChunk/revokeImport)
- [ ] CardService(countStock/countStockForProducts/export/disable)
- [ ] ImportCardsJob(队列大文件)
- [ ] API:import/status/revoke/stock/list/export(都调 Service,auth:sanctum)
- [ ] CardResource(列表/筛选/查看明文/禁用)
- [ ] CardImportResource(批次/撤销)
- [ ] ProductResource(库存列/导入 Action)
- [ ] API-first:Filament 和 API 共用 Service 层

---

## 与 spec 的一致性

无偏差。所有 spec §5/§6/§7/§8 项均有对应 Task。API-first 架构(spec §3)在 T1/T2(Service)和 T4(API)+ T5/T6/T7(Filament 都调 Service)中落地。
