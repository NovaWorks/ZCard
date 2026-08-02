# ZCard P1-B — 卡密导入与库存 设计（Spec）

> Phase 1 第二个子项目。基于 Phase 0 卡密地基(cards/card_imports/CardCipher),实现卡密导入引擎 + 库存管理。
> 本文档不进 git（`.gitignore` 忽略整个 `docs/`）。

- **日期**:2026-07-29
- **范围**:P1-B(卡密导入引擎 + 库存管理后台 + API 接入层)
- **状态**:待实现
- **对应计划**:`acg-faka/开发计划.md` Phase 1 的"卡密/库存管理"部分

---

## 1. 定位与范围

### 1.1 P1-B 是什么

P1-B = **卡密导入与库存管理**。商品(P1-A)已就位,现在要把卡密(发卡系统的核心资产)导入进来,并管理库存。P1-B 完成后,店主能批量导入卡密、查看库存、管理卡密生命周期,P1-C 的发货才有卡密可发。

### 1.2 范围(最终确认)

**导入引擎(CardImportService):**
- 两种入口:文件上传(txt/csv) + 文本框粘贴
- 格式:默认每行一个卡密;可选多列模式(分隔符如 `----`/逗号,整行入库)
- 处理:小文件(≤5000 条)同步即时;大文件(>5000)转队列异步,页面轮询批次状态
- 流程:解析 → 分块(1000/批)→ 算 hash → 批量加密 → `insertOrIgnore` 去重(走 `UNIQUE(product_id, content_hash)`)→ 累加 success/failed → 写 card_imports 批次

**库存管理:**
- CardResource:卡密列表(按商品/状态筛选、查看明文需解密、批量禁用/删除)
- CardImportResource:导入批次列表(成功/失败数、来源、时间、失败明细、撤销未用卡密)
- ProductResource 增强:商品列表显示各商品可用库存数
- 卡密导出:导出某商品卡密为 txt

**API 接入层(API-first 架构,全局原则):**
- 所有 P1-B 业务能力同时提供 API 端点 + Filament 入口
- Filament 和 API 都调 Service 层,Service 是唯一真相源

### 1.3 不含

- 卡密发货逻辑(锁卡→发货→写 order_deliveries)→ P1-C
- 卡密与订单关联展示 → P1-C
- 按 SKU 隔离卡密(当前卡密归属 product_id)→ 评估后决定,暂不做
- API 鉴权细化(谁能调哪些管理 API)→ 后续,先用 Sanctum token

---

## 2. 决策记录(来自 brainstorming)

| # | 决策 | 选择 |
|---|---|---|
| D1 | 导入方式 | 文件上传 + 文本框粘贴 都支持 |
| D2 | 处理方式 | 同步(≤5000)+ 队列(>5000)自动切换 |
| D3 | 卡密格式 | 默认每行一个;可选多列+分隔符(整行入库) |
| D4 | 库存管理范围 | 卡密列表 + 导入批次 + 商品库存统计 + 卡密导出 |
| D5 | 架构原则 | **API-first**:Service 层为核心,Filament + API 都是薄入口,换管理端零业务逻辑重写 |

---

## 3. API-first 架构原则(全局)

ZCard 后端采用 **API-first 架构**:所有业务能力都通过 Service 层实现,Filament(服务端渲染)和 API(HTTP+JSON)都是调用 Service 的薄入口。

```
┌──────────────────────────────────────────────────┐
│  所有客户端层(互不绑定)                            │
│   ├─ 前台商城 Vue(走 /api/storefront/*)          │
│   ├─ 后台 Filament(调 Service,服务端渲染)        │
│   └─ 未来:移动端 / 独立管理端 / 第三方(走 API)    │
└───────────────┬──────────────────────────────────┘
        ┌───────┴───────┐
        ▼               ▼
   API 层(/api/*)   Filament(薄入口)
        │               │
        └───────┬───────┘
                ▼
        Service 层(核心业务,UI 无关)
        CardImportService / CardService ...
```

**P1-B 落地:**
- `CardImportService` / `CardService` 是核心,与 UI 无关
- Filament Action 和 API Controller 都调这两个 Service
- 未来换管理端 → 调同一批 API → 零业务逻辑重写

---

## 4. 数据模型(沿用 Phase 0,无变更)

P1-B 不改表结构,直接用 Phase 0 已建的:

- `cards`:id, product_id, import_id, content(加密), content_hash(sha256 去重), status(unused/locked/used/disabled), order_id, locked_at, used_at
  - `UNIQUE(product_id, content_hash)` 产品内去重
  - `INDEX(product_id, status)` 库存查询热路径
- `card_imports`:id, product_id, operator_id, source, total, success_count, failed_count, status(running/completed/failed), error_log(json)

> Phase 0 设计已为 P1-B 铺好路,无需 migration。

---

## 5. Service 层(核心)

### 5.1 CardImportService(`app/Support/CardImportService.php`)

```php
class CardImportService
{
    // 入口:解析 + 决定同步/队列
    public function import(int $productId, int $operatorId, string $rawInput, array $options = []): CardImport

    // 解析:按 format(single/multi) + delimiter 拆成卡密明文数组
    public function parse(string $rawInput, string $format = 'single', ?string $delimiter = null): array

    // 同步处理一批(≤5000)
    public function processSync(CardImport $import, array $cards): void

    // 队列 Job 内部调:处理一块(1000 条)
    public function processChunk(CardImport $import, array $chunk): void

    // 撤销某批次未用卡密
    public function revokeImport(int $importId): int  // 返回删除数
}
```

**解析规则:**
- `single`:按换行拆分,每行一个卡密,去空行/首尾空白
- `multi`:按换行拆分,整行作为卡密内容(含分隔符,如 `user001----pass001` 整段入库);店主可在选项指定分隔符(仅用于校验/提示,实际整行存)

**分块去重加密(processChunk):**
```
foreach chunk(1000):
  算每条 hash = CardCipher::hash(plain)
  批量查已存在的 (product_id, hash) → 标记重复
  新卡密: encrypt(plain) → content; insertOrIgnore([product_id, import_id, content, hash, status=unused])
  重复: failed_count++, error_log 记 {line, reason:'duplicate'}
更新 card_imports: success_count, failed_count, status
```

**同步/队列切换(import):**
```
$cards = parse(...)
$total = count($cards)
$import = CardImport::create([...status=running, total=$total])
if ($total <= 5000):
    processSync($import, $cards)   // 同步跑完
    $import->update(status=completed)
else:
    dispatch(new ImportCardsJob($import->id, $cards))  // 队列
    // Job 完成后更新 status
return $import
```

### 5.2 CardService(`app/Support/CardService.php`)

```php
class CardService
{
    // 商品可用库存数(cards WHERE product_id AND status=unused)
    public function countStock(int $productId): int

    // 批量库存(多商品,用于商品列表)
    public function countStockForProducts(array $productIds): array  // [product_id => count]

    // 导出某商品卡密为 txt(明文,逐行)
    public function export(int $productId): string  // 返回明文卡密文本

    // 批量禁用
    public function disable(array $cardIds): int
}
```

---

## 6. 队列 Job

`app/Jobs/ImportCardsJob.php`(实现 ShouldQueue):
- 构造接收 `CardImport $import`(或 id + cards 数据)
- handle() 内:分块调 `CardImportService::processChunk()`
- 完成后更新 `card_imports.status = completed`,失败 `failed`
- 用 Redis 队列(Phase 0 已配)

> 注意:大文件 cards 数据较大,Job 里存 cards 数组可能内存高。优化:Job 只存 import_id + 原始输入引用(文件路径或缓存),handle 时重新解析。P1-B 实现时按此处理。

---

## 7. API 接入层(routes/api.php)

```
# 卡密导入
POST /api/cards/import              导入卡密(body: product_id, content, format, delimiter)
GET  /api/cards/import-status/{id}  查询导入批次状态(队列大文件轮询)
POST /api/cards/import/{id}/revoke  撤销某批次未用卡密

# 库存
GET  /api/products/{id}/stock       查商品可用库存

# 卡密管理
GET  /api/cards                     卡密列表(参数:product_id, status, page)
GET  /api/cards/export              导出卡密(参数:product_id,返回 txt 下载)
```

**Controller**:`app/Http/Controllers/Api/CardController.php` + `CardImportController.php`,都调 Service 层。

**鉴权**:Sanctum token(P1-A 已装)。P1-B 先用 `auth:sanctum` 中间件保护管理类 API,细化角色权限留后续。

**响应格式**:
```json
// POST /api/cards/import 成功
{"import_id": 12, "status": "completed", "success_count": 998, "failed_count": 2, "total": 1000}

// GET /api/products/{id}/stock
{"product_id": 1, "stock": 456}
```

---

## 8. Filament 后台界面(当前管理端)

### 8.1 导入入口

Product 详情页/列表加 **"导入卡密" Action**:
- 选商品后弹出导入表单(Modal 或页面)
- 表单:文件上传(FileUpload) 或 文本框(Textarea)二选一 + 格式选项(single/multi + delimiter)
- 提交后:同步即时返回结果通知;队列跳转批次详情页

### 8.2 CardResource(卡密列表)

- 列:product.name、status(badge)、content(显示"加密"占位,点"查看明文"解密展示)、created_at、import.source
- 筛选:按商品、按状态
- 行操作:查看明文(解密)、禁用
- 批量:禁用、删除
- 导出 Action:按当前筛选导出 txt

### 8.3 CardImportResource(导入批次)

- 列:product.name、source、total、success_count、failed_count、status(badge)、created_at
- 详情页:失败明细(error_log 展示)
- 行操作:**撤销未用卡密**(调 revokeImport,只删 unused)

### 8.4 ProductResource 增强

- 商品列表加"可用库存"列(调 CardService::countStockForProducts 批量查)

---

## 9. P1-B 验收清单

**导入引擎:**
- [ ] CardImportService:import/parse/processSync/processChunk/revokeImport
- [ ] 文件上传 + 文本框粘贴 两种入口
- [ ] 单列/多列格式支持
- [ ] 同步(≤5000)+ 队列(>5000)切换
- [ ] 去重(UNIQUE product_id+hash)+ 失败明细
- [ ] ImportCardsJob 队列处理

**Service + API(API-first):**
- [ ] CardService:countStock/countStockForProducts/export/disable
- [ ] POST /api/cards/import + 状态查询 + 撤销
- [ ] GET /api/products/{id}/stock
- [ ] GET /api/cards 列表 + /api/cards/export 导出
- [ ] Controller 都调 Service 层(不写业务逻辑)

**Filament 后台:**
- [ ] 导入入口(文件/文本/格式)
- [ ] CardResource(列表/筛选/查看明文/禁用/导出)
- [ ] CardImportResource(批次/失败明细/撤销)
- [ ] 商品列表可用库存列

**通用:**
- [ ] docs/ 不进 git
- [ ] 测试通过

---

## 10. 风险与对策

| 风险 | 对策 |
|---|---|
| 大文件队列 Job 内存(cards 数组大) | Job 只存 import_id + 输入引用(文件路径/缓存),handle 时重新解析 |
| 加密 CPU 密集(10万条) | 分块 + 队列异步,不阻塞请求;加密在 processChunk 内 |
| insertOrIgnore 与唯一索引并发 | 走 UNIQUE(product_id, content_hash) 兜底,重复自动跳过 |
| 导出明文安全 | 导出需鉴权(Sanctum)+ 日志记录;明文不进 error_log |
| 卡密查看明文性能 | 按需解密(点"查看明文"才解),列表不批量解密 |

---

## 11. Open Questions(无)

brainstorming 阶段所有决策已确认(§2)。无遗留。

---

*本 spec 为活文档,实现中如有偏差回填。*
