# ZCard 安全审计报告

> 审计时间：2026-08（v1.12.84）
> 审计范围：`app/` 全部控制器/服务/中间件/模型、`routes/`、`database/` 迁移与 Seeder、`config/`、两个前端 SPA（storefront / sysadmin）
> 方法：静态代码精读 + 8 域并行深挖 + 关键项人工复核（✅ 表示本报告作者已亲自读码确认）
> 说明：标注「模型层/纵深缺陷」的条目在当前代码中**尚无现成利用路径**（控制器大多已白名单校验），但属于随时会被新代码引爆的定时炸弹，强烈建议一并修复。

---

## 一、漏洞清单（按严重级排序）

### 🔴 Critical（1 项）

#### C-1 硬编码默认管理员 `admin / admin123456`，CLI 安装不重置密码 → 管理员接管 / RCE ✅
- **文件**：`database/migrations/2026_08_02_210000_seed_default_payment_channels.php:44-53`、`database/seeders/PaymentChannelSeeder.php:23-32`、`app/Console/Commands/InstallCommand.php:93-117`、`app/Providers/Filament/AdminPanelProvider.php:70`
- **描述**：迁移与 Seeder 都会幂等种入 `username=admin / password=admin123456 / status=1` 的账号。CLI 安装（`php artisan zcard:install`）发现该占位账号后**只补挂 super_admin 角色、跳过密码设置**（`warn("管理员账号已存在,跳过创建")`）。而 `ForcePasswordChange`（强制首登改密）只挂在 Filament 面板，正式后台 sysadmin SPA 走 `/api/admin/*` 不受约束；`AuthController::login` 只校验 `status==1`。
- **攻击**：对任何用 CLI 安装（或迁移后未走完 Web 向导）的站点，攻击者直接以 `admin/admin123456` 登录 `/admin`，获得全部管理权限，包括 UpdateController 的 `git/composer` 执行（→ 服务器 RCE）。
- **修复**：见 P0-1。

### 🟠 High（5 项）

#### H-1 分站域名绑定 `type=subdomain` 免验证，可劫持主站前台与订单利润 ✅
- **文件**：`app/Http/Controllers/Api/SubsiteConsoleController.php:80-90`、`app/Http/Middleware/ResolveSubsite.php:30-38`、`app/Support/DomainVerificationService.php:136-147`
- **描述**：`bindDomain()` 对 `type=subdomain` 直接写 `verification_status=verified / status=active`，只做了「域名能解析到公网 IP」的格式校验，不要求 DNS TXT / HTTP well-known 验证，也不限制域名必须是平台子域、不排除主站域名。`ResolveSubsite` 按 Host 头匹配后把请求路由到该 merchant。
- **攻击**：任意已获批的分站主绑定主站自身域名 → 主站全部流量被其分站品牌/公告覆盖、商品被加价、订单 `subsite_id/subsite_profit` 归属攻击者；平台通配符 DNS 下可抢注悬垂子域做钓鱼。
- **修复**：见 P0-2。

#### H-2 安装向导仅靠 `storage/app/installed` 文件门禁：文件丢失后匿名可重跑安装、覆盖 admin 密码并改写 .env ✅
- **文件**：`app/Http/Controllers/InstallController.php:23-26,114-221`、`app/Http/Middleware/EnsureInstalled.php:63-66`
- **描述**：`isInstalled()` 只检查标记文件。一旦该文件缺失（清空 storage、容器重建、迁移遗漏等），任意访客可调 `/api/install/run`：把请求中的 DB 凭据写入 `.env` 并重连数据库；若 `users` 表已存在 `username=admin` 或同 email 的行，会**无条件覆盖其密码为攻击者提交的密码**并授予 super_admin。
- **攻击**：标记文件缺失 → POST `/api/install/run` → 以攻击者密码成为 super_admin → 配合 `/api/admin/update` 升级为 RCE。
- **修复**：见 P0-3。

#### H-3 SSRF：供货回调 `callback_url` 可经 HTTP 重定向绕过守卫，把含卡密明文的 POST 转发到内网/云元数据 ✅
- **文件**：`app/Supply/SupplyCallbackService.php:28-58`、`app/Supply/CallbackUrlGuard.php:21-42`
- **描述**：`CallbackUrlGuard` 只对初始 URL 的主机解析一次（`gethostbyname`），而发送用 Laravel `Http` 门面，Guzzle 默认跟随最多 5 次 307/308 重定向且不重新校验目标；请求体含 `orderDeliveries` 卡密明文。
- **攻击**：任意供货账号（自助注册可得）下单时填 `callback_url=http://attacker.com/cb`，该地址 307 → `http://169.254.169.254/latest/meta-data/` 或内网服务，发货时服务器把卡密明文 POST 进内网；还可作为跳板打本站其它回调端点。
- **修复**：见 P0-4。

#### H-4 供货下单零成本套卡：`factory_price=0` 兜底 + 任意注册用户自动获得 active 供货账号 ✅
- **文件**：`app/Supply/SupplyOrderService.php:56-68`、`app/Supply/SupplyPricingService.php:44`、`app/Http/Controllers/Api/MySupplyController.php:29-39`
- **描述**：任何登录用户 GET `/api/supplier-account/me` 自动创建 `status=ACTIVE / balance=0` 的供货账号；商品未配置专属供货价时 `resolvePrice()` 兜底返回 `(int)$product->factory_price`，本地自建商品成本常为 0 → `amount = 0` → `balance < amount` 判定 `0<0` 为 false → 放行并发卡，库存被免费清空。
- **攻击**：注册 → 拿 api_key → 枚举 `price=0` 商品 → 下单 quantity=100 白嫖全部卡密。
- **修复**：见 P0-5。

#### H-5 优惠券并发重复核销：validate 事务外无锁 + apply 无条件 UPDATE，单券多单套利 ✅
- **文件**：`app/Support/CouponService.php:47,102-110`、`app/Support/OrderService.php:97-102,190-193`
- **描述**：`validate()` 在 `DB::transaction` 之外普通 SELECT 读券状态；`apply()` 在事务内执行无 `WHERE status` 条件的 `UPDATE`，全程无 `lockForUpdate`。并发 N 个请求都读到 `active`，各自按折扣价建单并各自核销成功。
- **攻击**：对同一券码并发 20-50 个下单请求，一张券生效 N 次，每单以近 1 分钱买走券额商品，损失 = (N-1)×券面值。
- **修复**：见 P0-6。

### 🟡 Medium（16 项）

| # | 标题 | 位置 | 要点与修复方向 |
|---|---|---|---|
| M-1 | 在线更新端点=命令执行，纵深防御缺失 ✅ | `app/Http/Controllers/Api/Admin/UpdateController.php:112-254,716-721` | super_admin 令牌即可触发 `git reset --hard`/`composer`/`migrate:rollback`；无二次确认/OTP；`Cache::lock` TTL 600s 与慢构建竞态、`finally` 无 owner 校验直接 `release()`；`rollback` 自身不写锁。修复：加操作口令/OTP、锁续期与 owner 校验、rollback 前自动备份数据库 |
| M-2 | `git clean -fd` 静默删除未跟踪文件 | `UpdateController.php:572-613` | `parseDirtyFiles` 跳过 `??`/`A`，未跟踪文件不在备份范围。修复：先完整备份或改白名单式清理 |
| M-3 | CSV 导出公式注入（订单 contact / 卡密内容）✅ | `app/Http/Controllers/Api/Admin/OrderController.php:270-285`、`Admin/CardController.php:176-191` | `fputcsv` 未转义 `= + - @` 开头字段；contact 由买家可控、卡密可能来自上游。修复：首字符为公式字符时前置 `'` |
| M-4 | 供货源 `api_key` 明文返回 ✅ | `Admin/SupplySourceController.php:564-576` | `maskCredentials` 只掩码含 `secret`/恰为 `app_key` 的键，`api_key`（dujiao/zcard 驱动）裸奔。修复：白名单式脱敏 |
| M-5 | 店铺设置数值无边界 ✅ | `Admin/SettingController.php:26-56`、`StorefrontConfig.php:303-327` | 可写入负 `cash_fee`（提现倒挂）、>100% 佣金率、`order_close_minutes=0`（秒关全站订单）、`supply_rate_limit=0`（供货全线 429）。修复：按 key 定义 min/max |
| M-6 | 上游回调验签无防重放 ✅ | `app/Supply/Drivers/DujiaoNextDriver.php:191-215`、`ZCardDriver.php:177-203` | dujiao 驱动不校验时间戳新鲜度（签名永不过期可无限重放）；zcard 驱动无 nonce。修复：补 `timestampValid` + per-source nonce |
| M-7 | 关单与支付回调竞态：已支付订单被翻转 closed / 付款不发卡 ✅ | `app/Support/OrderService.php:334-349` | `closeExpired()` 无锁 SELECT 后无条件 `UPDATE status='closed'`。修复：事务内 `lockForUpdate` 复检或条件 UPDATE 并检查影响行数 |
| M-8 | 下单接口无频率限制，批量锁卡耗尽库存 DoS ✅ | `routes/api.php:321-323` | 单次最多锁 2000 张卡 15 分钟，无 throttle。修复：限流 + 每 IP 在锁卡总量上限 |
| M-9 | 查单密码在线爆破防护不足（可读他人卡密）✅ | `routes/api.php:324-326`、`OrderService.php:466-472` | 仅 10 次/分钟/IP，密码强度无下限，订单号可枚举。修复：按订单+IP 失败计数锁定、失败响应一致化 |
| M-10 | 卡密加密密钥轮换/损坏后把密文当卡密交付；默认明文入库 ✅ | `app/Support/CardCipher.php:47-93`、`StorefrontConfig.php:90` | `decrypt()` 任何失败都返回原值 → 密钥变更后历史密文被原样发给买家/导出。修复：解密失败显式报错；提供批量重加密命令 |
| M-11 | 找回密码接口邮箱枚举 ✅ | `AuthController.php:230-246` | 默认 mail 关闭时：未注册→200，已注册→422，可稳定区分。修复：响应完全一致 + 发送队列化 |
| M-12 | 注册接口账号枚举（且在验证码之前）✅ | `AuthController.php:28-55` | unique 冲突差异化报错，验证码校验在唯一性之后。修复：先验证码、统一文案 |
| M-13 | 登录/注册默认无验证码、无账号锁定、管理员告警默认关闭 | `StorefrontConfig.php:84-85,160-164`、`AuthController.php:95-116` | 仅 IP 级 5 次/分钟。修复：默认开验证码、账号级失败计数与锁定、失败告警 |
| M-14 | UsdtDriver 回调仅 API-Key 校验（无签名）+ 元↔USDT 往返截断可能付款不发卡 ✅ | `app/Payment/Drivers/UsdtDriver.php:65-89,44-60` | 回调任何字段都不参与签名，api_key 即伪造凭证；`bcdiv(6位)` 与 `round` 往返可差 1 分触发 amount mismatch。修复：加 HMAC 签名、金额按显示值精确比对 |
| M-15 | 模型 mass-assignment / 敏感字段序列化纵深缺陷（当前无现成利用路径）| `app/Models/User.php:17-21`、`Product.php:16-33`、`Order.php:15-24`、`SupplierAccount.php:13-18`、`SupplySource.php:60-89`、`OrderDelivery.php:10`、`Payment.php:10-23`、`SubsiteDomain.php:8-12` | `User` fillable 含 `balance/status/pid/group_id` 等；`Product/Order` 含价格/状态；`SupplySource.credentials` accessor 解密后未 `$hidden`；`OrderDelivery.card_content`、`Payment.raw`、`SubsiteDomain.verification_token` 未隐藏。当前控制器基本白名单化（已抽查 Auth/Admin User/Recharge/SupplierAccount/Card），但属定时炸弹。修复：收窄 fillable + 补 `$hidden` |
| M-16 | 批量下单接口向匿名用户回显内部异常消息 ✅ | `OrderController.php:158-160` | `catch (\Throwable) return $e->getMessage()` 泄露 SQL 约束/内部文案。修复：日志记全、对外统一文案 |

### ⚪ Low（10 项，简表）

| # | 标题 | 位置 |
|---|---|---|
| L-1 | 后台导入 `dedup` 参数恒覆盖商品去重设置，同卡可重复入库重复售卖 | `Admin/CardController.php:74,87`、`CardImportService.php:97-99` |
| L-2 | `mock-pay` 在 `APP_ENV=testing` 完全放行（配置失误即全站白嫖） | `OrderController.php:166-169` |
| L-3 | Nonce 键全局共享且验签前写入：可预投毒使合法请求 401、撑爆 nonce 表 | `app/Supply/NonceStore.php:32-36`、`SupplyAuth.php:48-51` |
| L-4 | `/api/supply/callback` 无认证无限流，可无差别打 DB/HMAC 计算 | `routes/api.php:396` |
| L-5 | 供货 API 可查隐藏/下架商品库存，`page_size` 无上限 | `app/Http/Controllers/Api/Supply/SupplyProductController.php:31-85` |
| L-6 | `install/test-db` 未过滤内网地址，可对内网 MySQL 盲探测 | `InstallController.php:83-107` |
| L-7 | 分站域名缓存 300s 无主动失效，解绑后旧映射仍生效 | `ResolveSubsite.php:30-37` |
| L-8 | 更新自愈对 `.git` 执行 `chmod go+rwX`（同机用户可投毒）；cards 预览无审计；update.log 每次覆盖；批量删除无确认 | `UpdateController.php:755-772`、`routes/api.php:317-318` |
| L-9 | 验证码非原子消费可并发复用；验证码端点无限流；trade 验证码 4 位纯数字且答案哈希下发可穷举；Sanctum token 无轮换/自助吊销；密码策略仅长度；审计来源恒为 admin_api | `AuthController.php:77,125,181`、`config/captcha.php:23-35`、`AuditAdminAction.php:39-46` |
| L-10 | 前端 token 存 localStorage（XSS→窃取→RCE 链）；分站 announcement 覆盖路径未做服务端 HTML 清洗；`service_widget` 同源脚本为管理员可控代码 | `storefront/src/stores/auth.ts:7,17`、`sysadmin/src/utils/storage/`、`StorefrontSettingsController.php:22` |

---

## 二、修复方案（按优先级）

### P0 — 立即修复（发布前必须完成）

1. **C-1 默认管理员凭据**
   - 删除 `2026_08_02_210000_seed_default_payment_channels.php` 与 `PaymentChannelSeeder.php` 中 `Hash::make('admin123456')` 的账号创建逻辑（支付通道种子改依赖安装向导建好的商户，或 `merchant_id` 后置绑定）。
   - `InstallCommand` Step 8：发现已存在的 `admin` 且 `password_changed_at === null`（即来自种子）时**必须重置为新密码**，不得"跳过创建"。
   - `ForcePasswordChange` 同挂到 sysadmin/API 链路（`/api/auth/login` 返回 `must_change_password` 或加 `/api/admin/*` 中间件）。
   - 发布安全公告：存量站点重置该账号；提供 `php artisan zcard:security-reset-admin` 一键重置命令。
2. **H-1 分站域名免验证**
   - 删除 `type=subdomain` 直写 verified/active 分支；所有域名统一走 DNS TXT / HTTP well-known 验证。
   - `type=subdomain` 强制校验后缀 ∈ `StorefrontConfig('subsite_subdomain_base')`；主站域名（`config('app.url')` 主机名）加入绑定黑名单；绑定/验证接口加限流 + 审计。
3. **H-2 安装向导重跑**
   - 安装完成标志落库（settings 表），并双保险：`run()/testDb()` 开头检测 `users` 表已存在非空管理员即 403。
   - 安装完成后 `/api/install/*` 一律 404；`run` 增加一次性安装令牌（CLI/部署脚本生成）或至少验证码+IP 限流；`status()` 不再输出 PHP 版本与扩展明细。
4. **H-3 供货回调 SSRF**
   - `SupplyCallbackService` 发送时加 `->withoutRedirecting()`；若业务需要跟随重定向，则逐跳对每个 `Location` 重新过 `CallbackUrlGuard`。
   - 解析域名后按 IP 连接（防 DNS rebinding）；回调请求体分级限制；发送超时收紧。
5. **H-4 零价供货套卡**
   - `SupplyPricingService::resolvePrice()` / `SupplyOrderService` 对 `unitPrice < 1` 抛 `SupplyApiException`；管理员配置专属价接口同步做 `> 0` 校验。
   - `MySupplyController::me` 自动建账号与 `supply_enabled` 联动；考虑开通门槛（最低充值/人工审核）。
6. **H-5 优惠券并发核销**
   - `CouponService::apply` 改为事务内先 `Coupon::whereKey($id)->where('status','active')->lockForUpdate()` 复检，或条件 UPDATE `UPDATE coupons SET status='used' WHERE id=? AND status='active'` 并检查影响行数，0 行即回滚订单。
   - `POST /api/orders`、`/api/orders/batch`、`/api/coupons/validate` 补 `throttle`。

### P1 — 本周修复

7. **M-1/M-2 UpdateController**：update/rollback 加二次确认（OTP/操作口令）；锁改持有者令牌 + 续期；`migrate:rollback` 前自动 `mysqldump`；`git clean -fd` 改为白名单清理或先全量备份未跟踪文件；`.git` 权限自愈去掉 `go+rwX`。
8. **M-3 CSV 注入**：订单/卡密导出对 `= + - @ \t \r` 开头字段前置 `'`。
9. **M-4 供货源脱敏**：改为「白名单式」——除明确非敏感键外一律掩码。
10. **M-5 设置边界**：`StorefrontConfig::setMany` 内按 key 定义 bounds（`cash_fee≥0`、`distribution_rate_l1..3∈[0,100]`、`supply_rate_limit≥1`、`order_close_minutes∈[1,1440]`、`recharge_max_amount>0` 等）。
11. **M-6 上游回调防重放**：dujiao 驱动补 `HmacSigner::timestampValid`；两个驱动补 per-source nonce（`Cache::add('upstream_cb:{source_id}:{nonce}', 1, skew)`）；`/api/supply/callback` 加 throttle。
12. **M-7 关单竞态**：`closeExpired` 事务内 `lockForUpdate` 复检 `status='pending'` 或条件 UPDATE 并检查影响行数。
13. **M-8 下单限流**：`/api/orders*` 挂 `throttle:10,1`；服务层加「每 IP 在锁卡数量上限」。
14. **M-9 查单防爆破**：按 `(order_no/contact + IP)` 失败计数，5 次失败锁 10 分钟；下单时对查询密码做最小长度/复杂度校验。
15. **M-10 卡密加密**：`CardCipher::decrypt` 失败抛错/标记损坏，禁止把密文当明文交付与导出；提供 `zcard:reencrypt-cards` 密钥轮换命令。
16. **M-11/M-12 枚举收敛**：找回密码与注册响应统一（未注册/已注册同码同文案同耗时）；验证码校验前置。
17. **M-13 登录防护**：默认开启登录/注册验证码；账号级失败计数与锁定；管理员登录失败告警默认开启。
18. **M-14 UsdtDriver**：回调加 HMAC 签名（key 为通道 secret）；金额比较用 `bcdiv` 显示值精确匹配而非 round 往返。
19. **M-15 模型收窄**：按「待补充」清单收窄 `User/Product/Order/SupplierAccount/Recharge/Withdrawal/Commission/Bill` 的 fillable；为 `SupplySource.credentials`、`OrderDelivery.card_content`、`Payment.raw`、`SubsiteDomain.verification_token` 补 `$hidden`。
20. **M-16 异常文案**：`OrderController::batch` 只回显白名单业务异常，其余记日志。

### P2 — 迭代加固

21. **L-1**：`dedup` 用 `$request->has('dedup')` 判断而非 `boolean()` 恒写。
22. **L-2**：mock-pay 改为 `in_array(env,['local','testing']) && config('zcard.allow_mock_payment', false)` 双条件白名单。
23. **L-3/L-4/L-5**：nonce 键绑定 api_key、验签成功后再写 nonce；`/api/supply/callback` 限流；供货目录过滤 `hide` 商品、`page_size` 上限 100。
24. **L-6**：`test-db` host 拒绝私网/环回/链路本地（`FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE`）。
25. **L-7**：域名增删改处统一 `Cache::forget("subsite:domain:{host}")`。
26. **L-8**：cards 列表/库存接口补 `audit.admin`；update.log 追加+轮转；批量删除加 `confirm` 参数。
27. **L-9**：验证码原子消费（GETDEL）；验证码端点限流；trade 验证码加长度/扭曲且不把答案哈希下发客户端；登录轮换 token + 「全部设备登出」接口；密码复杂度 + 历史防重用；审计 source 区分来源。
28. **L-10**：前端 token 改用 httpOnly Cookie（Sanctum stateful）或至少 sessionStorage+短期过期；分站 `announcement` 覆盖路径过 `HtmlContentSanitizer`；`service_widget` 设置页加危险提示。

---

## 三、修复后验证建议（补测试）

| 测试 | 覆盖漏洞 |
|---|---|
| 优惠券并发核销（2 并发下单同券，断言只有 1 单成功） | H-5 |
| 安装重跑拒绝（installed 文件缺失 + users 表有管理员 → 403） | H-2 |
| 分站域名绑定需验证（subdomain 类型返回 pending） | H-1 |
| 供货零价下单拒绝（factory_price=0 → 422） | H-4 |
| 回调重定向不跟随（callback_url 307→内网 → 拒绝/不请求） | H-3 |
| 上游回调重放拒绝（过期 ts / 重复 nonce） | M-6 |
| 关单与 markPaid 并发（付费订单不被翻 closed） | M-7 |
| CLI 安装后 admin 密码非默认值 | C-1 |
| 支付回调验签负向用例（9 个驱动的篡改/重放/金额不匹配） | M-14 及回归基线 |
| settings 越界值写入被 422 拒绝 | M-5 |

---

## 四、已确认「做得对」的部分（供基线参考）

- 支付回调主链路：`PaymentService::handleCallback` 金额核对 + 订单行锁 + 幂等设计严谨；Epay/Alipay/Wechat/Stripe/Paypal/OkPay/CodePay/TokenPay/BEpusdt/EpuSdt 均用 `hash_equals` + 服务端配置签名，Epay 还有 pid 归属校验。
- 余额支付校验订单归属 + `lockForUpdate`；充值单强制本人 + 金额服务端上下限。
- 卡密 `content/content_hash` 已 `$hidden`；支付通道 config 已 `$hidden` + 脱敏掩码；`api_secret` 已 `$hidden`。
- `StorefrontConfig::public()` 白名单输出 + HTML 清洗；CSP/安全头已全局挂载；`EnsureInstalled` 未安装时 API 返回 503（挡住了安装前的 API 利用窗口）。
- 管理后台控制器字段校验普遍白名单化，未发现现成 mass-assignment 直通路径。
