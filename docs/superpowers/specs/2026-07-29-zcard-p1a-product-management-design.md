# ZCard P1-A — 商品管理 + 前台商品只读 设计（Spec）

> Phase 1 的第一个子项目。基于 Phase 0 地基,实现后台商品/分类管理 + 前台商品浏览。
> 本文档不进 git（`.gitignore` 忽略整个 `docs/`）。

- **日期**:2026-07-29
- **范围**:P1-A(后台商品管理 + 前台商品只读 + 店铺外观设置)
- **状态**:待实现
- **对应计划**:`acg-faka/开发计划.md` Phase 1 的商品管理部分

---

## 1. 定位与范围

### 1.1 P1-A 是什么

P1-A = **后台商品管理 + 前台商品只读**。这是 Phase 1 的地基子项——商品/分类必须先就位,后续的卡密导入(P1-B)、订单/发货(P1-C)、支付(P1-D)才有载体。

### 1.2 范围(最终确认)

**后台(Filament):**
- Category Resource(分类 CRUD,树形 parent_id)
- Product Resource(商品 CRUD + SKU 管理 + 会员价/虚拟销量/虚拟评论/is_featured + 自定义控件配置器 + 图片上传 + delivery_mode)
- ProductSku Resource(或嵌入 Product 的关联管理)
- **店铺外观设置页**(Filament Setting/Custom Page,扁平卡片分区 + 左右滑动 toggle + chip 式热门标签)

**前台 API:**
- `GET /api/categories` 分类树
- `GET /api/products` 商品列表(分页/分类筛选/排序/视图参数)
- `GET /api/products/{slug}` 商品详情(含 SKU 列表)
- `GET /api/settings/storefront` 店铺外观配置(供前台渲染)
- `GET /api/products/featured` 首页推荐位商品

**前台 Vue(storefront):**
- 首页改造:真实商品列表 + (可选)首页推荐位 + 热门标签
- 列表页:视图切换器(网格/列表/双栏,localStorage 记忆)、分类导航(按后台配置渲染)
- 详情页:大厂电商风格(促销价/评分/服务保障/SKU 选择/数量/库存条/立即购买占位)
- 所有列表/详情按后台「店铺外观设置」配置驱动渲染

### 1.3 不含(明确划给后续子项)

- **卡密导入、库存精确管理、Card/CardImport 后台界面** → P1-B
- **下单、锁卡、订单状态机、自动发货、收银台、邮箱/查询密码/人机验证/提交** → P1-C
- **支付通道、回调** → P1-D
- **真实评价系统**(reviews 表、发评价、审核后台、前台发评价表单)→ 后续独立子项。P1-A 评价仅为虚拟数据,但三个评价开关在此就位
- **购物车** → 留后续(发卡场景单品直购为主,P1-A 只做"立即购买")
- **会员等级体系消费 member_price** → Phase 3(P1-A 后台可填,前台不消费)

---

## 2. 决策记录(来自 brainstorming)

| # | 决策 | 选择 | 依据 |
|---|---|---|---|
| D1 | Phase 1 策略 | 分片推进,先做 P1-A | brainstorming 默认 |
| D2 | 库存展示 | 显示,实时 count(cards WHERE unused) | 即使 P1-A 阶段为 0,接口/数据流就位 |
| D3 | 会员价 | 后台可填 JSON,前台不消费 | Phase 3 生效 |
| D4 | 自定义控件 | 后台可视化配置器,前台不渲染 | P1-C 下单页渲染 |
| D5 | 商品图片 | 本地上传(Filament FileUpload + public disk) | 开源用户上传即用 |
| D6 | 多商户隔离 | 不做,默认 merchant_id=1 | Phase 3 |
| D7 | 列表视图 | 三态切换器(网格/列表/双栏),顾客选存 localStorage | 用户要求自由切换 |
| D8 | 分类导航 | 后台配置样式(pills/sidebar/combo),前台按配置渲染 | 配置驱动 UI |
| D9 | 店铺外观配置粒度 | 一组外观设置(布局+展示开关+推荐+热门标签) | 用户要求 |
| D10 | 虚拟销量/评论 | 商品级字段(products 加列) | 不同商品基数不同 |
| D11 | 首页推荐 | 后台手动勾选 is_featured | 可控 |
| D12 | 热门标签 | 复用分类作标签,不新增表 | 控制复杂度 |
| D13 | 详情页范围 | 详情选 SKU + 立即购买,下单表单留 P1-C 收银台 | 职责分离 |
| D14 | SKU 规格 | 新建 product_skus 表 | 多规格多价格刚需 |
| D15 | 查询密码/人机验证 | 做后台开关(默认开),实际输入框留 P1-C | 开关先就位 |
| D16 | 评价系统 | P1-A 只做虚拟评论;真实评价系统留后续 | 控制范围 |
| D17 | 评价开关 | 三开关(显示/允许发布/需审核)P1-A 就位 | 真实系统接入时直接消费 |
| D18 | 设置页交互 | 大厂风格(Shopify/Stripe),扁平卡片 + 左右 toggle + chip | 用户要求 |
| D19 | 详情页视觉 | 电商大厂风格(淘宝/京东),促销氛围 | 用户要求 |
| D20 | 网格每行列数 | 后台可选 3/4/5(桌面端),移动端自适应 | 用户要求 |

---

## 3. 数据模型变更

### 3.1 products 表加列(虚拟数据 + 推荐 + 限购)

```
ALTER products ADD:
  is_featured        TINYINT(1) DEFAULT 0     -- 首页推荐
  virtual_sales      INT UNSIGNED DEFAULT 0   -- 虚拟销量基数(前台显示=真实+此数)
  virtual_reviews    JSON NULL                 -- 虚拟评论 {rating, count, list:[]}
  min_order          INT UNSIGNED DEFAULT 1   -- 最小购买量
  max_order          INT UNSIGNED DEFAULT 0   -- 最大购买量(0=不限)
```

> 真实销量 = 该商品 paid 订单的 quantity 之和(P1-C 订单就位后实时算)。P1-A 阶段真实销量=0,显示即 virtual_sales。

### 3.2 新建 product_skus 表(SKU 规格)

```
product_skus:
  id
  product_id        FK→products, cascadeOnDelete
  name              VARCHAR(60)      -- 如"月卡"
  price             BIGINT           -- 单位分
  stock_type        VARCHAR(20) NULL  -- card/url/code;NULL=继承所属商品的 stock_type
  sort              INT UNSIGNED DEFAULT 0
  status            TINYINT(1) DEFAULT 1
  timestamps
  INDEX(product_id, status)
```

**语义**:
- 商品可为**单规格**(无 product_skus 记录)→ 用 products.price。
- 商品为**多规格**(有 product_skus 记录)→ 详情页显示 SKU 选择器,价格/库存按 SKU。卡密归属 product_id(不按 SKU,P1-B 导入时同一商品的卡密共享;若需按 SKU 隔离卡密,留 P1-B 评估)。
- **库存**:P1-A 单规格商品库存 = cards WHERE product_id AND unused;多规格商品库存 = 整个商品的卡密总数(按 SKU 隔离卡密是 P1-B 决策,P1-A 暂不按 SKU 分库存)。

### 3.3 settings 表配置项(group = storefront)

| key | 值 | 默认 |
|---|---|---|
| `storefront.category_nav_style` | pills/sidebar/combo | pills |
| `storefront.list_default_view` | grid/list/dual | grid |
| `storefront.grid_columns` | 3/4/5 | 4 |
| `storefront.page_size` | int | 12 |
| `storefront.default_order` | newest/price_asc/price_desc/sort | newest |
| `storefront.show_stock` | bool | true |
| `storefront.show_sales` | bool | true |
| `storefront.show_reviews` | bool | false |
| `storefront.allow_post_review` | bool | true |
| `storefront.review_need_audit` | bool | true |
| `storefront.show_featured` | bool | true |
| `storefront.featured_count` | int | 8 |
| `storefront.show_hot_tags` | bool | true |
| `storefront.hot_tag_categories` | JSON [category_id...] | [] |
| `storefront.order_query_password` | bool | true |
| `storefront.trade_captcha` | bool | true |

> order_query_password / trade_captcha 的开关在 P1-A 就位,实际输入框/验证逻辑在 P1-C 收银台消费。

---

## 4. 后台设计(Filament)

### 4.1 Resource 清单

| Resource | 说明 |
|---|---|
| CategoryResource | 分类 CRUD,树形(parent_id),字段:name/slug/sort/status/parent_id。表格支持层级缩进展示 |
| ProductResource | 商品 CRUD。字段见 §4.2。SKU 通过 **Repeater**(编辑商品时内联管理 SKU)+ Filament **RelationManager**(列表/单独管理)两种入口 |
| ProductSkuResource | SKU 管理独立 Resource(供批量管理/列表查看);与 ProductResource 内联编辑互补 |

### 4.2 ProductResource 表单字段

- 基础:merchant_id(默认1,隐藏)/ category_id(Select 树形)/ name / slug(自动生成) / description
- 价格:price(分)/ member_price(JSON 编辑器,key=等级,value=价格)
- 库存类型:stock_type(Select: card/url/code)/ stock_visible(toggle)
- 配图:cover(FileUpload 单图)/ images(FileUpload 多图,存 public disk)
- 自定义控件:**control_config 可视化配置器**(Repeater,每行:type[select: text/email/textarea/select]/label/name/required/options[仅 select 时]),产出 §5.3 的 JSON
- 发货:delivery_mode(Select: status/delete,默认 status)
- 营销虚拟数据:is_featured(toggle)/ virtual_sales(number)/ virtual_reviews(JSON: {rating, count} 或含 list 数组)
- 限购:min_order(number)/ max_order(number,0=不限)
- 排序/状态:sort / status

### 4.3 店铺外观设置页

Filament 自定义 Page(或 Filament Settings 包),挂在「设置」下。**大厂风格**:
- 扁平卡片分区(布局/展示项/首页推荐/热门标签)
- 每行「左标题+说明 / 右控件」,开关用 **Toggle**(Filament原生)对应左右滑动 toggle
- segment 控件用 Filament 的 Radio/SegmentedControl 等价
- 热门标签:多选分类(chip 式),保存为 category_id 数组
- 保存写入 settings 表(group=storefront)

> Filament 原生 Toggle 即左右滑动开关,符合大厂交互。无需造轮子。

---

## 5. 前台设计(API + Vue)

### 5.1 API 契约

```
GET /api/categories
  → [{id, name, slug, parent_id, children:[...]}]  树形

GET /api/products?category={id}&page={n}&order={newest|price_asc|price_desc|sort}&view={grid|list|dual}
  → {data:[{id,name,slug,cover,price,stock,sales,is_featured...}], meta:{pagination}}
  仅 status=上架商品。stock=可用卡密数, sales=真实+虚拟销量(均为数字)

GET /api/products/{slug}
  → {id,name,description,price,images[],skus:[{id,name,price,stock}],virtual_reviews,is_featured,stock,sales,min_order,max_order,stock_type,delivery_mode...}

GET /api/products/featured?limit={n}
  → [商品...]  is_featured=1 的前 N 个

GET /api/settings/storefront
  → {category_nav_style, list_default_view, grid_columns, page_size, show_stock, ...}  前台渲染用
```

所有金额返回**分**(int),前台格式化为元展示。

### 5.2 前台页面

**首页**:
- (若 show_featured)顶部首页推荐位(轮播/横排)
- (若 show_hot_tags)热门标签云(点击跳该分类)
- 商品列表(按 storefront 配置渲染)

**列表(首页或分类页)**:
- 顶部或侧边分类导航(按 category_nav_style: pills 横排 / sidebar 左侧树 / combo)
- 视图切换器(右上角 ⊞/☰/▦),默认值来自 list_default_view,顾客切换存 localStorage 覆盖
- 网格列数来自 grid_columns
- 每页 page_size,排序默认 default_order(顾客可切换)
- 商品卡:cover/name/price/库存(若 show_stock)/销量(若 show_sales)

**详情页**(大厂电商风格,参考淘宝/京东):
- 左:配图轮播(主图 + 缩略图)
- 右购买区:
  - 标题 + 副标题(自动发货等)
  - 促销氛围价格区(大字价 + 原价划线 + 优惠标签)
  - 评分汇总条(评分/评价数/已售/好评率)— 评分来自 virtual_reviews
  - 服务保障图标行(自动发货/即时到账/正品保障/售后无忧)
  - SKU 按钮组(多规格时;选中态蓝边角标,缺货置灰)
  - 数量步进器 + 限购提示
  - 库存进度条(剩余 X/总 Y)
  - **立即购买**按钮(蓝色渐变,P1-A 占位:点击提示"下单功能即将开放",带所选 SKU+数量留给 P1-C)
- 下方:商品描述 + 评价区(虚拟评论,若 show_reviews)

### 5.3 control_config JSON 结构(后台配置器产出,P1-C 消费)

```json
[
  {"type": "email", "label": "收货邮箱", "required": true, "name": "email"},
  {"type": "text", "label": "游戏账号", "required": false, "name": "account"},
  {"type": "textarea", "label": "备注", "required": false, "name": "remark"},
  {"type": "select", "label": "区服", "required": true, "name": "region", "options": ["国服","国际服"]}
]
```
类型:text/email/textarea/select。P1-A 后台配置器产出,P1-C 下单页渲染。

---

## 6. 关键交互:配置驱动 UI

**数据流**:
```
店主 → 后台「店铺外观设置」 → settings 表(group=storefront)
                                        ↓
顾客访问 → 前台读 GET /api/settings/storefront → 按配置渲染分类导航/默认视图/列数/开关项
                                        ↓
顾客临时切换列表视图 → 存 localStorage(覆盖默认,不影响其他顾客)
```

- 后台改设置 → 新顾客立即生效
- 老顾客的 localStorage 视图偏好保留(只影响"默认")

---

## 7. 销量/库存计算

- **销量显示** = 真实销量 + virtual_sales。真实销量 = 该商品已支付订单的 quantity 之和(P1-C 订单就位后实时算;P1-A 阶段=0,显示即 virtual_sales)。受 show_sales 开关控制。
- **库存显示** = cards WHERE product_id AND status=unused 的 count(P1-B 导入后才有值;P1-A 阶段=0)。受 show_stock 开关控制。
- **虚拟评论** = products.virtual_reviews(JSON)。受 show_reviews 开关控制。允许发布/需审核开关在 P1-A 就位但暂不消费(真实评价系统留后续)。

---

## 8. P1-A 验收清单

- [ ] 后台 Category CRUD(树形)
- [ ] 后台 Product CRUD(全字段 + SKU 管理 + 自定义控件配置器 + 图片上传)
- [ ] products 表加列(is_featured/virtual_sales/virtual_reviews/min_order/max_order)
- [ ] product_skus 表创建
- [ ] settings 配置项(group=storefront)全部可后台配置
- [ ] 店铺外观设置页(大厂风格,扁平卡片+Toggle+chip 热门标签)
- [ ] GET /api/categories 树形
- [ ] GET /api/products 列表(分页/分类/排序)
- [ ] GET /api/products/{slug} 详情(含 SKU)
- [ ] GET /api/products/featured 推荐位
- [ ] GET /api/settings/storefront 配置
- [ ] 前台首页(推荐位+热门标签+列表)
- [ ] 前台列表页(视图切换器+分类导航按配置渲染+网格列数按配置)
- [ ] 前台详情页(大厂电商风格+SKU选择+库存条+立即购买占位)
- [ ] 评价三开关(显示/允许发布/需审核)在设置页就位
- [ ] 查询密码/人机验证开关在设置页就位
- [ ] git commit 粒度合理,docs/ 不进 git

---

## 9. 风险与对策

| 风险 | 对策 |
|---|---|
| 大厂风格视觉难一次到位 | mockup 已确认,实现时以 mockup 为准;Tailwind v4 @theme token 复用 Phase 0 |
| SKU 与卡密库存关系 | P1-A 暂按商品级库存(product_id),按 SKU 隔离卡密留 P1-B 决策 |
| 真实销量 P1-A 为 0 | 销量显示 = 真实+虚拟,P1-A 靠 virtual_sales 撑;P1-C 后真实销量接入 |
| 自定义控件配置器复杂 | 用 Filament Repeater 实现可视化配置,产出标准 JSON |
| 配置项多导致设置页臃肿 | 扁平卡片分区已确认,每行左标题右控件,不臃肿 |

---

## 10. Open Questions(无)

brainstorming 阶段所有决策已确认(§2)。无遗留问题。

---

*本 spec 为活文档,实现过程中如有偏差回填更新。*
