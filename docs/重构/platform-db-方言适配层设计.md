# platform/db 方言适配层规格（Go 接口 + 三方言 SQL 对照）

> **版本**：v1.0（2026-08-15）
> **关联文档**：`docs/ZCard2.0-重构开发规划.md` §3.5/§3.6/§3.7（ADR-D18/D19/D20）、`docs/重构/数据库架构设计.md`（表结构）
> **定位**：`platform/db` 是 ZCard 全仓**唯一触碰数据库方言**的包，面向三类人——① ZCard 自身业务模块（95% 走 Ent builder，本包只服务 report 层裸 SQL）；② **对接 ZCard 的第三方/下游**（通过 open API 接入，需理解本包保证的幂等与方言契约）；③ **SaaS 平台运营者**（多租户连接池、读写分离、容量护栏）。

---

## 0. 核心承诺（对外契约，方向钉死）

1. **一套代码三方言**：SQLite（最小化单体）/ MySQL 8（自托管）/ PostgreSQL 15+（平台多租户），同一二进制运行时切换，无编译期分叉。
2. **业务层永不写方言 SQL**：`mods/*` 一律用 Ent builder；仅 `internal/data/report` 可写裸 SQL，且必须经 `platform/db.SQL` 原语构造。
3. **能力差异显式降级**：`Capabilities` 开关运行时判定，`false` 时走降级路径或返回明确错误，**禁止静默炸 SQL**。
4. **幂等是基础设施**：upsert / 唯一键 / `RETURNING` 由本包统一提供，对接方依赖的写幂等由数据库层保证而非应用层祈祷。

---

## 1. 包结构与目录

```
server/internal/platform/db/
├── dialect.go          # Dialect 枚举 + DetectFromEnt
├── capabilities.go     # Capabilities 能力开关
├── sql.go              # SQL 跨方言原语（report 层唯一裸 SQL 构造器）
├── tx.go               # Tx 工作单元（WithTx / 事务传播）
├── pool.go             # 连接池 + 多租户 Client 管理（SaaS 高并发）
├── readwrite.go        # 读写分离（主库写 / 只读副本读）
├── batch.go            # 批量操作（bulk insert / 分批）
├── types.go            # 类型映射（DML 值转换）
└── *_test.go           # 三方言矩阵测试（SQLite/MySQL/PG 各跑一遍）
```

**架构测试强制**（`internal/architecture/`）：

- `mods/*` 不得 import `github.com/go-sql-driver/mysql`、`jackc/pgx/v5`、`modernc.org/sqlite` 等驱动包，只能 import `platform/db`；
- `platform/db` 不得 import 任何 `mods/*`（反向依赖检测）；
- report 层之外的裸 SQL 字符串拼接 = 编译期/CI 红灯。

---

## 2. Dialect 与能力开关

```go
// dialect.go
package db

import (
    "fmt"
    "strings"
)

type Dialect string

const (
    MySQL    Dialect = "mysql"
    Postgres Dialect = "postgres"
    SQLite   Dialect = "sqlite"
)

// DetectFromEnt 从 Ent client 的方言名检测（运行时，非编译期）。
// 参数来源：ent.Client.Dialect() 的字符串。
func DetectFromEnt(entDialect string) (Dialect, error) {
    switch strings.ToLower(entDialect) {
    case "mysql", "mariadb":
        return MySQL, nil
    case "postgres", "postgresql":
        return Postgres, nil
    case "sqlite3", "sqlite":
        return SQLite, nil
    }
    return "", fmt.Errorf("db: unsupported dialect %q", entDialect)
}
```

```go
// capabilities.go
package db

// Capabilities 描述当前方言支持哪些数据库能力。
// 业务代码据以下降级——这是「显式分支」，不是「运行时碰运气」。
type Capabilities struct {
    SupportsRLS          bool // 行级安全策略（仅 PG）
    SupportsSchema       bool // 独立 schema 命名空间（PG 原生；MySQL database==schema；SQLite attach 模拟，默认不用）
    SupportsJSONB        bool // JSONB 高级查询/GIN 索引（PG；MySQL JSON 弱等价；SQLite JSON1 弱）
    SupportsReturning    bool // INSERT/UPDATE ... RETURNING（PG/SQLite；MySQL 无）
    SupportsILIKE        bool // ILIKE 大小写不敏感（PG；MySQL/SQLite 用 LOWER 降级）
    SupportsForUpdate    bool // SELECT ... FOR UPDATE（PG/MySQL；SQLite 无）
    SupportsSkipLocked   bool // FOR UPDATE SKIP LOCKED（PG/MySQL8；SQLite 无）—— 高并发队列/锁卡利器
    SupportsArray        bool // 数组列（PG；MySQL/SQLite 用 JSON 模拟）
    SupportsPartialIndex bool // 部分索引（PG/SQLite；MySQL 无）
    SupportsWindowFunc   bool // 窗口函数（三方言均支持，但 SQLite 需 3.25+）
}

func (d Dialect) Capabilities() Capabilities {
    switch d {
    case Postgres:
        return Capabilities{SupportsRLS: true, SupportsSchema: true, SupportsJSONB: true,
            SupportsReturning: true, SupportsILIKE: true, SupportsForUpdate: true,
            SupportsSkipLocked: true, SupportsArray: true, SupportsPartialIndex: true,
            SupportsWindowFunc: true}
    case MySQL:
        return Capabilities{SupportsForUpdate: true, SupportsSkipLocked: true, SupportsWindowFunc: true}
    case SQLite:
        return Capabilities{SupportsReturning: true, SupportsPartialIndex: true, SupportsWindowFunc: true}
    }
    return Capabilities{}
}
```

> **降级铁律**：`if !cap.SupportsX { return errUnsupported }` 或走降级路径，**禁止**在 `false` 时仍生成带 X 的 SQL。

---

## 3. SQL 跨方言原语（report 层唯一裸 SQL 构造器）

```go
// sql.go
package db

type SQL struct{ d Dialect }

func New(d Dialect) *SQL { return &SQL{d: d} }

// Placeholder 返回第 i 个占位符（从 1 起）。
//   MySQL/SQLite: ?
//   Postgres:     $i
func (s *SQL) Placeholder(i int) string

// QuoteIdent 引用标识符（表名/列名）。
//   MySQL: `orders`   PG/SQLite: "orders"
func (s *SQL) QuoteIdent(id string) string

// ILike 大小写不敏感匹配表达式（返回含占位符的片段）。
//   PG:     col ILIKE ?
//   MySQL:  LOWER(col) LIKE LOWER(?)
//   SQLite: LOWER(col) LIKE LOWER(?)
func (s *SQL) ILike(col string) string

// Concat 字符串拼接。
//   PG/SQLite: (a || b || c)     MySQL: CONCAT(a, b, c)
func (s *SQL) Concat(exprs ...string) string

// DateAdd 日期加减表达式。
//   MySQL:  DATE_ADD(col, INTERVAL n unit)
//   PG:     col + (n * interval '1 unit')
//   SQLite: datetime(col, printf('%+d unit', n))
func (s *SQL) DateAdd(col, unit string, n int) string

// Bool 布尔字面量。
//   MySQL: 1/0   PG/SQLite: TRUE/FALSE
func (s *SQL) Bool(v bool) string

// Paginate 分页子句。
//   MySQL:  LIMIT offset, limit
//   PG/SQLite: LIMIT limit OFFSET offset
func (s *SQL) Paginate(limit, offset int) string

// ForUpdate 行锁子句（高并发下单锁卡 / 支付回调）。
//   PG/MySQL: FOR UPDATE [SKIP LOCKED]
//   SQLite: 返回空串（SQLite 无行锁，走 BEGIN IMMEDIATE 单写者语义）
func (s *SQL) ForUpdate(skipLocked bool) string

// Upsert 幂等写入子句（report 层少量手写 upsert；业务一律走 Ent.Upsert）。
//   MySQL:  INSERT ... ON DUPLICATE KEY UPDATE col=VALUES(col), ...
//   PG:     INSERT ... ON CONFLICT (cols) DO UPDATE SET col=EXCLUDED.col, ...
//   SQLite: INSERT ... ON CONFLICT (cols) DO UPDATE SET col=excluded.col, ...
func (s *SQL) Upsert(table string, conflictCols, updateCols []string) string
```

---

## 4. 三方言 SQL 对照总表（对接方速查）

| 能力 | SQLite | MySQL 8 | PostgreSQL 15+ |
|---|---|---|---|
| 占位符 | `?` | `?` | `$1, $2, ...` |
| 标识符引用 | `"id"` | `` `id` `` | `"id"` |
| 大小写不敏感 | `LOWER(c) LIKE LOWER(?)` | `LOWER(c) LIKE LOWER(?)` | `c ILIKE ?` |
| 字符串拼接 | `a \|\| b` | `CONCAT(a, b)` | `a \|\| b` |
| 布尔字面量 | `1/0`（或 `TRUE/FALSE`） | `1/0` | `TRUE/FALSE` |
| 分页 | `LIMIT l OFFSET o` | `LIMIT o, l` | `LIMIT l OFFSET o` |
| upsert | `ON CONFLICT(c) DO UPDATE SET c=excluded.c` | `ON DUPLICATE KEY UPDATE c=VALUES(c)` | `ON CONFLICT(c) DO UPDATE SET c=EXCLUDED.c` |
| 自增回填 | `RETURNING id` | `LAST_INSERT_ID()` | `RETURNING id` |
| 行锁 | 无（`BEGIN IMMEDIATE`） | `FOR UPDATE` / `FOR UPDATE SKIP LOCKED` | `FOR UPDATE` / `FOR UPDATE SKIP LOCKED` |
| 日期加减 | `datetime(c, '+n unit')` | `DATE_ADD(c, INTERVAL n unit)` | `c + (n * interval '1 unit')` |
| 当前时间 | `CURRENT_TIMESTAMP` | `NOW()` | `NOW()` |
| 全文搜索 | `FTS5`（外置） | `MATCH ... AGAINST` | `tsvector`（外置） |
| 数组 | 无（JSON 模拟） | 无（JSON 模拟） | `ARRAY[]` |
| JSON 查询 | `json_extract()` | `JSON_EXTRACT()` / `->` | `->>` / `@>`（GIN） |
| 部分索引 | `WHERE` 部分索引 | 无 | `WHERE` 部分索引 |
| RLS | 无 | 无 | `CREATE POLICY` |
| Schema | `ATTACH`（模拟） | database==schema | 原生 `schema` + `search_path` |

> **结论**：核心交易 SQL（等值查、聚合、join、upsert、分页）三方言完全收敛；**JSONB 高级查询、RLS、数组、部分索引、Skip Locked 高级用法是 PG 独占**，MySQL/SQLite 走降级（§2 能力开关）。

---

## 5. 类型系统与 DML 值转换

```go
// types.go
package db

// NormalizeValue 把 Go 值转为驱动可接受的 DML 值（bool/time/json/[]byte 的方言差异）。
func (d Dialect) NormalizeValue(v any) any {
    switch x := v.(type) {
    case bool:
        if d == MySQL { return map[bool]int8{true: 1, false: 0}[x] }
        return x
    case time.Time:
        // 统一 UTC；MySQL 驱动接受 time.Time，PG 驱动接受 time.Time（timestamptz），SQLite 存 RFC3339 文本
        return x.UTC()
    case json.RawMessage:
        // PG: 直接 []byte -> jsonb；MySQL/SQLite: 需要额外 marshal 由驱动处理
        return []byte(x)
    default:
        return v
    }
}
```

> DDL 侧类型映射（`INT`→`INTEGER`、`TINYINT(1)`→`BOOLEAN`、`JSON`→`JSONB`、`BLOB`→`BYTEA`…）见 `数据库架构设计.md` §0 与规划 §8.4，由 Atlas 生成，本包只管 DML 值转换。

---

## 6. 事务与锁（高并发下单 / 账务的一致性底座）

```go
// tx.go
package db

import "context"

// TxFunc 事务回调。biz 层只见 TxFunc 与 WithTx，不见 *ent.Tx 具体类型。
type TxFunc func(ctx context.Context) error

// WithTx 在事务中执行 fn；自动处理方言差异：
//   - PG/MySQL：BEGIN ... COMMIT/ROLLBACK（可嵌套 SAVEPOINT）
//   - SQLite：BEGIN IMMEDIATE（单写者，替代无行锁）
func WithTx(ctx context.Context, fn TxFunc, opts ...TxOption) error

// TxOption 控制事务隔离级别与锁策略。
type TxOption func(*txConfig)
func WithReadCommitted() TxOption   // 下单/回调默认
func WithSerializable() TxOption    // 极少数需要串行化的账务场景
```

**锁策略三方言对照（下单锁卡 / 支付回调）**：

| 方言 | 锁卡方式 | 说明 |
|---|---|---|
| PG/MySQL | `SELECT ... FOR UPDATE`（+`SKIP LOCKED` 高并发） | 行锁，`ForUpdate(true)` |
| SQLite | `BEGIN IMMEDIATE` + `UPDATE ... WHERE status='available'` 校验 affected rows | 单写者 + CAS 语义 |

> 业务规则不变：「事务内 `FOR UPDATE` 锁可用卡密行 → 校验 → 置 reserved → 校验 affected rows」，SQLite 下由 `WithTx` 自动换成 `BEGIN IMMEDIATE`，biz 无感知（§5.20.3）。

---

## 7. 连接池与多租户 Client 管理（SaaS 高并发的关键）

面向「别人在 ZCard 上开站」的 SaaS 多租户场景：`database-per-tenant` 每租户独立库，需要**按租户隔离连接池**。

```go
// pool.go
package db

import (
    "context"
    "sync"
    "time"
)

// PoolConfig 连接池参数（SaaS 场景的关键护栏）。
type PoolConfig struct {
    MaxIdleConn        int           // 单租户空闲连接上限
    MaxOpenConn        int           // 单租户打开连接上限
    ConnMaxLifetime    time.Duration // 连接生命周期（防长连接被 DB 端回收）
    ConnMaxIdleTime    time.Duration // 空闲回收
    GlobalMaxOpenConn  int           // 全局打开连接总数上限（防连接风暴）
}

// ClientManager 管理「多租户 → 多个 *ent.Client」的生命周期。
// databaseStore（platform/tenancy）依赖它，业务不直接使用。
type ClientManager struct {
    mu       sync.Mutex
    clients  map[string]*ent.Client      // key = tenant 稳定标识（如 "tenant:123"）
    lru      []string                     // LRU 回收序
    poolCfg  PoolConfig
    onClose  func(*ent.Client) error
}

// Acquire 取（或懒建）某租户的 client；连接按需创建、空闲 LRU 回收。
func (m *ClientManager) Acquire(ctx context.Context, key string, open OpenFunc) (*ent.Client, error)

// Release 归还引用；引用计数归零且超空闲阈值时回收连接。
func (m *ClientManager) Release(key string)

// Ping 健康检查：周期探测每个远程库，不可用则熔断该租户（不影响其他租户）。
func (m *ClientManager) Ping(ctx context.Context, key string) error

// OpenFunc 按 DSN + dialect 打开 client（懒加载）。
type OpenFunc func(ctx context.Context) (*ent.Client, error)
```

**高并发护栏（SaaS 必做）**：

1. **单租户连接上限 + 全局上限**：某租户流量暴涨不拖垮其他租户（`MaxOpenConn` / `GlobalMaxOpenConn`）。
2. **熔断**：`Ping` 周期探测，某租户库不可用 → 该租户请求快速失败 + 告警，其余租户不受影响（§4.11.3）。
3. **连接复用**：Row/Schema 模式（同实例）共享一个底层连接池；Database 模式（远程）每租户独立池 + LRU 回收空闲池。
4. **连接生命周期**：`ConnMaxLifetime` 小于 DB 端 `wait_timeout`/`idle_session_timeout`，防「池里拿到的连接已被服务端杀掉」。

---

## 8. 读写分离与只读副本（大订单量下的报表卸载）

```go
// readwrite.go
package db

// Route 描述一次访问的路由意图。
type Route struct {
    ReadOnly  bool   // true = 可走只读副本
    TenantKey string // 多租户定位（空 = 主站/共享库）
}

// ResolveConn 返回「写库」或「只读副本」的连接。
//   - ReadOnly=false：主库（写路径）
//   - ReadOnly=true 且配置了副本：只读副本（报表/对账/列表）
//   - 副本不可用：自动回退主库（读降级不报错）
func (m *ClientManager) ResolveConn(ctx context.Context, r Route) (*ent.Client, error)
```

**适用**：`daily_stats` 聚合、订单/流水历史报表、对账查询、商品列表只读——这些**占大订单量下的读 QPS 大头**，卸载到只读副本；写路径（下单/回调/账务）永远走主库。

---

## 9. 批量操作（高并发写吞吐）

```go
// batch.go
package db

// BatchInsert 分批插入，方言差异：
//   PG:    multi-VALUES + ON CONFLICT（RETURNING 回填 id）
//   MySQL: multi-VALUES + ON DUPLICATE KEY（LAST_INSERT_ID 或逐行）
//   SQLite: 单事务内逐行（SQLite 无高效 bulk upsert，靠 BEGIN IMMEDIATE 串行）
func BatchInsert(ctx context.Context, client *ent.Client, batchSize int, rows [][]any) error

// 约定：批量卡密导入（千万级）走 low 队列 + 按 id/批次分片，避免长事务锁大范围。
```

---

## 10. 三方言测试矩阵（CI 强制）

```go
// *_test.go —— 同一份测试三方言各跑一遍（t.Run 循环 dialect）
func TestSQLPrimitives(t *testing.T) {
    for _, d := range []Dialect{SQLite, MySQL, Postgres} {
        t.Run(string(d), func(t *testing.T) {
            s := New(d)
            // 断言 Placeholder/ILike/Concat/Paginate/Upsert 输出符合该方言
            assert.Equal(t, "$1", s.Placeholder(1))            // 仅 PG
            // ...
        })
    }
}
```

**CI 三线**（`database架构设计.md` §8 对齐）：SQLite（单元/快）+ MySQL（集成）+ PostgreSQL（集成，**必跑**）。PG 线覆盖 SQLite 掩盖的 `ILIKE`/JSONB/数组/序列/RLS 差异。

---

## 11. 业务使用规范（正例 / 反例）

**反例（禁止）**：

```go
// ❌ mods/order/data.go —— 业务层写方言 SQL
func (r *repo) List(ctx, subsiteID) {
    sql := "SELECT * FROM orders WHERE subsite_id = ?" // 漏了占位符差异、漏了租户注入
    // 若换 PG 这里就炸；若换 SQLite 分页就炸
}
```

**正例（推荐）**：

```go
// ✅ 业务层走 Ent builder（跨方言 + 租户 interceptor 自动注入）
orders, err := r.client.Order.Query().
    Where(order.HasSubsiteWith(subsiteID(...))).
    Limit(20).Offset(0).
    All(ctx)

// ✅ report 层裸 SQL 走 platform/db.SQL 原语（唯一合法裸 SQL）
func (r *reportRepo) TopProducts(ctx, d db.Dialect, since time.Time) ([]Row, error) {
    s := db.New(d)
    q := fmt.Sprintf(`SELECT product_id, SUM(amount) total
        FROM order_items
        WHERE created_at >= %s AND subsite_id = %s
        GROUP BY product_id ORDER BY total DESC %s`,
        s.Placeholder(1), s.Placeholder(2), s.Paginate(10, 0))
    return r.query(ctx, q, since, ctxTenantID)
}
```

---

## 12. 决策速查（方向钉死）

| # | 决策 | 结论 |
|---|---|---|
| 1 | 触碰方言 | 仅 `platform/db` + Ent schema Annotation，业务零方言 |
| 2 | 切换方式 | 运行时 `DetectFromEnt`，非 build tag，同一二进制三方言 |
| 3 | 能力降级 | `Capabilities` 显式分支，false 时降级或报错，禁止静默炸 SQL |
| 4 | 占位符 | `Placeholder()` 统一（MySQL/SQLite `?`，PG `$i`） |
| 5 | 锁 | PG/MySQL `FOR UPDATE`（+SKIP LOCKED），SQLite `BEGIN IMMEDIATE`+CAS |
| 6 | 幂等 | upsert 由 Ent/`SQL.Upsert` 统一，业务不手写 |
| 7 | 多租户连接 | `ClientManager` 按租户池 + LRU + 熔断 + 全局上限 |
| 8 | 读写分离 | report/列表/对账走只读副本，写走主库 |
| 9 | 测试 | 三方言矩阵，PG 线必跑 |

---

*本文为 platform/db 方言适配层规格 v1.0，与 `数据库架构设计.md` 形成「架构 → 数据 → 代码」闭环：`数据库架构设计.md` 定义表与字段，本文定义数据访问代码如何跨三方言、如何支撑 SaaS 多租户与大订单量。*
