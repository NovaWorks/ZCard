# ZCard

> 现代化、插件制虚拟发卡 / 个人店铺系统。Laravel 13 + Filament v5 + Vue3。
>
> 核心差异化：**现代技术栈 + 真插件系统 + 极致插件开发体验**。

## 快速启动（开发环境）

需要：Docker、Node 22、pnpm。（PHP/MySQL/Redis 走 Docker，无需本机安装。）

```bash
# 1. 起后端容器（首次构建镜像约 1-3 分钟）
./vendor/bin/sail up -d

# 2. 初始化系统（迁移 + RBAC + 默认商户 + 超管账号）
./vendor/bin/sail artisan zcard:install
#    命令行会打印一个随机 8 位初始密码；首次登录强制改密

# 3. 后台
#    访问 http://localhost:8092/admin ，用 install 打印的邮箱密码登录
#    （admin@zcard.local + 打印的密码）

# 4. 前台
cd storefront && pnpm install && pnpm dev
#    访问 http://localhost:5173
```

## 默认端口

| 服务 | 地址 |
|---|---|
| Laravel 应用 | http://localhost:8092 |
| **后台管理系统(art-design-pro)** | **http://localhost:8092/admin/** |
| Filament 后台(开发期CRUD) | http://localhost:8092/filament |
| 前台商城 (dev) | http://localhost:5173 |
| MySQL | localhost:3307 |
| Redis | localhost:6380 |

> 端口可在 `.env` 的 `APP_PORT` / `FORWARD_DB_PORT` / `FORWARD_REDIS_PORT` 调整（默认避开本机已占用的 80/3306/6379）。

## 常用命令

| 命令 | 说明 |
|---|---|
| `./vendor/bin/sail up -d` | 起容器（后台） |
| `./vendor/bin/sail artisan migrate` | 跑迁移 |
| `./vendor/bin/sail artisan zcard:install` | 系统初始化（幂等） |
| `./vendor/bin/sail test` | 跑测试 |
| `./vendor/bin/sail composer xxx` | 容器内 composer |
| `./vendor/bin/sail artisan tinker` | tinker |
| `cd storefront && pnpm dev` | 前台 dev server |
| `cd storefront && pnpm build` | 前台构建 |
| `cd sysadmin && pnpm install` | 后台管理系统装依赖 |
| `cd sysadmin && pnpm dev` | 后台 dev server(:3006) |
| `cd sysadmin && pnpm build` | 后台编译到 `public/admin/` |

## 项目结构

```
ZCard/
├── app/                        Laravel 后端
│   ├── Console/Commands/InstallCommand.php     zcard:install 初始化
│   ├── Filament/Resources/      后台 CRUD（User, Merchant）
│   ├── Http/Middleware/ForcePasswordChange.php 首次登录强制改密
│   ├── Models/                  数据模型（12 张业务表）
│   └── Support/CardCipher.php   卡密应用层加密 + sha256 去重
├── database/migrations/         数据库迁移
├── storefront/                  Vue3 前台（独立工程）
├── plugins/example-plugin/      插件骨架占位（Phase 2 生效）
└── config/zcard.php             ZCard 配置 + 功能开关
```

## 技术栈

- **后端**：PHP 8.3+ · Laravel 13 · Filament v5 · spatie/laravel-permission · filament-shield · MySQL 8 · Redis
- **前台**：Vue 3 · Vite · Tailwind CSS v4 · Pinia · Vue Router · axios

## 阶段说明

当前为 **Phase 0（地基）**：
- ✅ Laravel + Sail 开发环境
- ✅ 核心 schema（用户/商户/商品/卡密/订单/支付/发货快照/配置）
- ✅ RBAC（super_admin / merchant / user）+ zcard:install 初始化 + 首次改密
- ✅ Filament 后台骨架 + 主题（设计图配色）
- ✅ 前台 Vue3 工程骨架 + API 联调
- ✅ 卡密加密 + 导入批次表（为 Phase 1 大量导入铺路）

**下一阶段（Phase 1）**：商品管理、卡密导入、订单状态机、收银台、内置支付通道、自动发货。
**Phase 2**：插件系统 Hook 总线、安装/启停生命周期。

---

*开源协议待定（见开发计划 §6.3）。*
