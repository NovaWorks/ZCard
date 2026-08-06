# ZCard 素材管理（Media Library）设计文档

- **日期**: 2026-08-06
- **阶段**: 平台能力（Phase 5）
- **状态**: 待评审
- **依赖**: 建立在已完成的后台 sysadmin SPA + `/api/admin/*` + `app/Support` 服务层之上

---

## 0. 背景与设计依据

### 0.1 目标

统一系统内所有图片上传逻辑。系统中所有涉及图片上传的地方（Logo、头像、Banner、商品图片、文章封面等）
**禁止直接上传图片**，统一调用素材管理组件（弹窗）。

素材管理支持：

- 上传图片（拖拽/点击、多文件、进度、失败重试）
- 选择已有图片（单选/多选，按调用场景）
- 图片分类管理（新增/改名/删除/迁移）
- 图片搜索（名称模糊搜索、实时刷新）
- 批量操作（批量移动分类、批量删除）
- 图片预览（放大/缩小/下载/左右切换，Element Plus Image Viewer 体验）
- 图片删除（确认后物理删除，不可恢复）

### 0.2 现状约束（已核对）

- **现有上传入口**：`app/Http/Controllers/Api/Admin/UploadController.php::image()`，
  `POST /api/admin/upload/image`，`$request->file('file')->store('products', 'public')`，返回 `{path, url}`。
- **前端现状**：
  - `sysadmin/src/api/upload.ts`（`uploadImage`）
  - `sysadmin/src/components/business/image-picker/index.vue`（URL 输入 + 上传按钮 + 悬停预览），
    使用点：`views/setting/index.vue`（`site_logo`）、`views/category/list/index.vue`（分类 `icon`）。
  - 富文本编辑器 `components/core/forms/art-wang-editor/index.vue` 通过 `server` 模式直传
    `/api/admin/upload/image`。
- **存储**：`public` disk（`storage/app/public` → `public/storage` 符号链接），URL 统一 `/storage/...`。
- **路由规范**：`routes/api.php` 中 `/api/admin/*` 全部挂 `auth:sanctum` + `admin.role`；
  静态路由必须先于资源路由（`cards/export` 先于 `cards/{id}`）。
- **前端路由**：`sysadmin/src/router/modules/*.ts` 定义菜单分组，`modules/index.ts` 汇总为 `routeModules`；
  页面组件 `component: '/media/index'` 对应 `src/views/media/index.vue`（`import.meta.glob('../../views/**/*.vue')` 懒加载）。
- **i18n**：菜单文案在 `sysadmin/src/locales/langs/{zh,en}.json` 的 `menus.*` 键。
- **API 封装**：`sysadmin/src/api/*.ts` 用 `request.get/post/put/del`，响应解包由 `utils/http` 完成。

### 0.3 已确认设计决策

1. **数据表两张**：`media_categories`（分类）+ `media`（素材），均含 `softDeletes`。
2. **`media` 记录存储路径与元数据**：`original_name`（原始文件名）、`filename`（存储文件名）、
   `path`（disk 相对路径）、`url`（`/storage/...`）、`mime_type`、`size`（字节）、`width`、`height`（图片尺寸）。
3. **上传入库**：图片保存到 `public/media/{Y/m}/` 目录，入库 `media` 表。**不修改现有 `upload/image` 行为**
   （富文本等仍可用），素材库为新入口，逐步迁移。
4. **分类删除保护**：分类下存在图片时禁止直接删除，必须先迁移到其它分类（含"未分类"）再删除。
   这保证不出现孤立图片（`media.category_id` 可空，空即"未分类"）。
5. **删除物理删文件**：删除 `media` 记录时同步删除磁盘文件（`Storage::disk('public')->delete`），不可恢复。
6. **批量操作**：`POST media/batch-delete`（ids）、`POST media/batch-move`（ids + category_id）。
7. **单选/多选由调用方决定**：MediaPicker 组件 `multiple` 属性，单选返回 url 字符串，多选返回数组。
8. **上传路径**：`POST /api/admin/media/upload`，支持多文件（`files[]`），`category_id` 可选。
9. **权限**：沿用 `admin.role`（super_admin / merchant）。merchant 分站暂不做素材隔离（本期单库）。

---

## 1. 数据库设计

### 1.1 `media_categories` 表

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint PK | |
| `name` | string(30) | 分类名称，**唯一**（重名校验），长度 ≤ 30 |
| `sort` | unsignedInteger default 0 | 排序 |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | softDeletes | |

索引：`name` 唯一索引。

### 1.2 `media` 表

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint PK | |
| `category_id` | bigint nullable FK → media_categories.id | 空 = 未分类 |
| `original_name` | string(255) | 原始文件名（展示用） |
| `filename` | string(255) | 磁盘存储文件名（随机串 + 扩展名） |
| `path` | string(255) | disk 相对路径，如 `media/2026/08/AbCd1234.png` |
| `url` | string(255) | `/storage/media/2026/08/AbCd1234.png` |
| `mime_type` | string(100) | `image/png` 等 |
| `size` | bigInteger | 字节数 |
| `width` | unsignedInteger nullable | 图片宽（px） |
| `height` | unsignedInteger nullable | 图片高（px） |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | softDeletes | |

索引：`category_id`、`created_at`。

**down()**：先 `dropIfExists('media')` 再 `dropIfExists('media_categories')`。

---

## 2. 后端 API 设计

所有端点前缀 `/api/admin`，挂 `auth:sanctum` + `admin.role`。

### 2.1 分类管理

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/admin/media-categories` | 分类列表（含每分类图片数量 `media_count`，含"未分类"计数） |
| POST | `/api/admin/media-categories` | 新增分类 `{name}`（重名校验 422，长度 ≤ 30） |
| PUT | `/api/admin/media-categories/{id}` | 改名 `{name}` |
| DELETE | `/api/admin/media-categories/{id}` | 删除分类：**有图片时 422** 并返回 `media_count`，提示先迁移 |
| POST | `/api/admin/media-categories/{id}/move` | `{target_category_id}`（可空 = 未分类）：迁移该分类全部图片后删除分类 |

> 迁移采用"迁移图片到目标分类 → 删除当前分类"两步，保证无孤立数据。

### 2.2 素材管理

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/admin/media` | 列表：`{category_id, keyword, sort, order, page, per_page}`；`sort` ∈ `created_at|filename|size`，`order` ∈ `desc|asc`，返回分页 |
| POST | `/api/admin/media/upload` | 上传 `{files[]: image, category_id?}`，返回已入库 `Media[]` |
| DELETE | `/api/admin/media/{id}` | 删除单张（物理删文件 + 删记录，204） |
| POST | `/api/admin/media/batch-delete` | `{ids: []}` 批量删除 |
| POST | `/api/admin/media/batch-move` | `{ids: [], category_id: 可空}` 批量移动分类 |

**上传校验**：`files.*` → `image|mimes:jpeg,png,webp,gif,svg|max:10240`（10MB）。
图片尺寸用 `getimagesize()` 读取（SVG 无尺寸则忽略，不报错）。

**返回约定**（沿用项目风格）：直接返回数据，错误 `response()->json(['message' => '...'], 422)`。

---

## 3. 后端实现

### 3.1 文件清单

```
app/Models/MediaCategory.php
app/Models/Media.php
app/Support/MediaService.php          ← 业务真理源
app/Http/Controllers/Api/Admin/MediaCategoryController.php
app/Http/Controllers/Api/Admin/MediaController.php
database/migrations/2026_08_06_000010_create_media_categories_table.php
database/migrations/2026_08_06_000020_create_media_table.php
routes/api.php                        ← 注册路由
```

### 3.2 `MediaService` 方法

| 方法 | 职责 |
|---|---|
| `categories(): array` | 分类列表 + 各分类 `media_count` + 未分类计数 + 总数 |
| `createCategory(string $name): MediaCategory` | 新增（含重名/长度校验） |
| `renameCategory(int $id, string $name): MediaCategory` | 改名 |
| `deleteCategory(int $id): void` | 空分类删除；有图抛 `ValidationException` 带 `media_count` |
| `moveCategory(int $id, ?int $targetId): void` | 迁移图片 → 删除分类 |
| `paginate(array $filters): LengthAwarePaginator` | 分页查询（搜索/分类/排序） |
| `upload(array $files, ?int $categoryId): array` | 多文件上传入库，返回 `Media[]` |
| `delete(int $id): void` | 删文件 + 删记录 |
| `batchDelete(array $ids): int` | 批量删 |
| `batchMove(array $ids, ?int $categoryId): int` | 批量移动 |

**上传实现要点**：`$file->store('media/' . date('Y/m'), 'public')`；`url` 用 `/storage/` . `$path`
（沿用现有 `UploadController` 注释：不用 `asset()` 避免 `APP_URL` 不匹配生产域名）。

---

## 4. 前端设计

### 4.1 素材管理页面 `src/views/media/index.vue`

左右布局：

```
┌──────────┬──────────────────────────────────────┐
│ 分类管理  │ 搜索框            [上传图片] [排序▾]   │
│ (220px)  │ ┌──────────────────────────────────┐ │
│ 全部(120)│ │ 网格卡片: 缩略图 + 名称 + 日期 + 大小 │ │
│ Logo(20) │ │   Hover: 预览/复制链接/下载/移动/删除 │ │
│ Banner(35)│ │   多选: checkbox                    │ │
│ ...      │ │ [确定] [取消] (弹窗模式)             │ │
│ [+ 新增] │ └──────────────────────────────────┘ │
└──────────┴──────────────────────────────────────┘
```

功能：
- 左侧分类：新增（弹输入框，重名校验/30 字限制）、悬停显示改名/删除；删除分类有图 → 弹迁移对话框。
- 右侧：`el-upload`（`drag` 拖拽 + 多文件 `auto-upload` 自定义）+ 进度条；搜索框防抖；
  排序下拉（最新/最早/名称/大小）；`el-image` 卡片网格；Hover 操作条；批量选择后顶部操作栏。
- 预览：`el-image` 的 `preview-src-list` 天然支持放大/缩小/下载/左右切换（Image Viewer）。
- 分页：`el-pagination`。

### 4.2 可复用选择弹窗组件 `src/components/business/media-picker/index.vue`

- Props：`modelValue`（v-model，单选 string / 多选 string[]）、`multiple`（bool）、
  `title`（默认"素材管理"）。
- 交互：外层按钮触发 `el-dialog` 打开素材管理（复用同一个页面组件的内容区，或内嵌视图）。
  点「确定」把选中项的 `url` 回传并关闭。
- 为保证复用性，页面内容抽成**公共组件**：`src/components/business/media-manager/index.vue`
  （纯功能视图，无外层 Dialog），页面与弹窗都包它。

### 4.3 接入现有 ImagePicker

`image-picker/index.vue` 增加「从素材库选择」按钮 → 打开 MediaPicker（单选）→ 回填 URL。
保留现有 URL 输入框与上传按钮（向后兼容）。

---

## 5. 测试策略

- `tests/Feature/MediaLibraryTest.php`：分类 CRUD（重名/长度/删除保护/迁移）、上传入库（路径/尺寸/元数据）、
  搜索/排序/分页、批量删除/移动、物理删文件断言。
- 前端：sysadmin 无自动化测试，靠 `pnpm build`（vue-tsc 类型检查）+ 手动验证。

---

## 6. 待办隐患 / 后续

- 现有 `upload/image` 上传的图片**不进入素材库**（本期不动，避免破坏富文本等既有调用）；
  后续可让 `UploadController::image` 也写一条 `media` 记录（category_id 空）。
- 分站 merchant 素材隔离、图片压缩/缩略图、OSS 存储为后续增强。
