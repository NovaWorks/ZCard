# ZCard P1-E.1 — 前台登录/注册 设计+计划（合并）

> Phase 1 最后一个子项的第一片。前台 Vue 登录/注册接 Laravel Sanctum token。
> 不进 git。

## 范围

**后端 API:**
- POST /api/auth/register(用户名/邮箱/密码 → 创建 user + 分配 user 角色 → 返回 token)
- POST /api/auth/login(邮箱/密码 → 验证 → 返回 token + user 信息)
- POST /api/auth/logout(登出，需 token)
- GET /api/auth/me(当前用户，需 token)

**前台:**
- auth store(Pinia)：token/user/登录状态
- Login.vue：真实登录表单
- Register.vue：真实注册表单
- AppHeader：显示登录状态 + 退出

## Tasks

### Task 1: AuthController + 路由
### Task 2: 前台 auth store + API 封装
### Task 3: Login.vue + Register.vue 改造
### Task 4: AppHeader 登录态 + 验证
