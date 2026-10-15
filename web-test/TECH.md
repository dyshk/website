# LNMP 测试站 · 技术原理与实现详解

> 这份文档回答三件事：**代码是怎么跑的**、**数据库里那些库和表是干什么的**、**想改的话该动哪一行**。
> 面向"拿到这个目录、想读懂或改造它"的人。
>
> **本站的全部技术内容都在这份文档里**（含 nginx 配置逐行解释、数据库设计、代码拆解、改造指南、排错手册）。
> `README.md` 只讲"怎么部署、怎么用"这一件事，两者不重复。
>
> 适用版本：`DS_VERSION = 2.0`。全部代码 1 个公共库 + 3 个入口文件，共约 1700 行，无框架、无 composer 依赖。

---

## 目录

1. [这个站是干什么的](#1-这个站是干什么的)
2. [文件地图](#2-文件地图)
3. [整体架构与请求链路](#3-整体架构与请求链路)
4. [数据库：库、表是怎么来的，每张表干什么](#4-数据库库表是怎么来的每张表干什么)
5. [lib.php 公共库逐块拆解](#5-libphp-公共库逐块拆解)
6. [board.php 动态留言板](#6-boardphp-动态留言板)
7. [api.php JSON 接口](#7-apiphp-json-接口)
8. [index.php 环境自检页](#8-indexphp-环境自检页)
9. [安全性说明与适用边界](#9-安全性说明与适用边界)
10. [改造指南：想改什么，改哪里](#10-改造指南想改什么改哪里)
11. [排错手册](#11-排错手册)
12. [部署配置详解](#12-部署配置详解)

---

## 1. 这个站是干什么的

一句话：**用一个真实可用的动态网站，把 Nginx → PHP-FPM → MySQL 这条链路整条跑一遍**，用来确认服务器环境"真的能用"，而不只是"服务进程在跑"。

它提供两个页面：

| 页面 | 文件 | 验证什么 |
|---|---|---|
| ① 环境自检 | `index.php` | Nginx 是否转发到 PHP、PHP 版本/扩展/权限、MySQL 连接→建库→建表→写→读 |
| ② 动态留言板 | `board.php` | 一个完整动态站：表单 POST、文件上传、INSERT/SELECT/UPDATE/DELETE、LIKE 搜索、分页、JSON 接口、实时统计 |

两者的分工是刻意的：**① 是"体检表"，只读不写文件；② 是"真实业务"，会把数据真正落到磁盘和数据库**。体检通过但业务跑不起来，通常问题在写权限、上传目录、字符集这些地方 —— 这正是 ② 要负责暴露的部分。

> 本站在设计上**面向个人/内网测试**：没有登录、没有权限体系、数据库参数在页面上填、口令明文存储。这些取舍在第 9 节统一说明。

---

## 2. 文件地图

```
web-test/
├─ index.php    411 行  ① 环境自检页（含 db_test() 全链路测试函数）
├─ board.php    736 行  ② 动态留言板（安装向导 + 路由 + 渲染 + 内联 CSS/JS）
├─ api.php       87 行  JSON 接口（点赞 / ping），供页面 JS 调用
├─ lib.php      471 行  公共库：常量、编码清洗、配置、DB 封装、建表、上传
├─ uploads/             图片上传目录（由 Nginx 当静态文件直接返回）
│   └─ index.html       占位文件：防止目录列表 + 作为"静态资源自检"探针
├─ config.php           ← 安装留言板时自动生成（初始不存在）
├─ nginx.conf.example   通用 Nginx 配置模板（改 3 处即可用）
├─ README.md            部署与使用说明
└─ TECH.md              本文档
```

三个入口文件都只有一行启动代码 `require __DIR__ . '/lib.php';`，**所有公共能力都收在 lib.php**，避免三份重复实现互相打架。

### lib.php 函数索引

| 分组 | 函数 / 类 | 作用 |
|---|---|---|
| 常量 | `DS_APP` `DS_VERSION` `DS_DIR` `DS_PER_PAGE` `DS_MAX_UPLOAD` `DS_TABLE_*` | 站点名、版本、目录、每页条数、上传上限、表名 |
| 编码 | `ds_to_utf8()` `h()` | 任意字节串 → 合法 UTF-8；输出转义 |
| 视图 | `nav_html()` `ds_ms()` `ds_now()` `ds_self()` `ds_url()` | 导航栏、毫秒计时、当前时间、当前脚本路径、带参 URL |
| 输入 | `ds_post()` `ds_get()` `ds_client_ip()` `ds_ua_hash()` | 取参并 trim、取访客 IP、UA 摘要 |
| 错误 | `db_tip()` `ds_safe_dbname()` | MySQL 错误号 → 可执行建议；库名白名单 |
| 配置 | `cfg_file()` `cfg_read()` `cfg_write()` `cfg_ready()` | config.php 的读写与就绪判断 |
| 口令 | `ds_pw_store()` `ds_pw_match()` | 存口令 / 校验口令（明文 + 兼容哈希） |
| 数据库 | `class DB`（`prep/exec/rows/row/one/lastId`）`db_connect_cfg()` `ds_setup_schema()` | SQL 封装、连接、建库建表 |
| 上传 | `ds_upload_image()` | 校验并保存图片，返回相对路径 |

### board.php 函数索引

| 函数 | 作用 |
|---|---|
| `board_css()` | 返回整站 CSS（heredoc 内联，替代原来的 `assets/style.css`） |
| `board_open($title)` | 输出 `<head>` 与页面骨架开头 |
| `ds_render_msg($m,$base,$big)` | 单条留言的 HTML（列表卡片 / 详情大图共用） |
| `ds_render_db_error(...)` | 数据库连不上时的整页错误提示 + 修复命令 |

---

## 3. 整体架构与请求链路

### 3.1 三层结构

```
浏览器
  │  HTTP
  ▼
Nginx ──────────┬─ .php 请求 → 通过 FastCGI 转发给 php-fpm（socket: /run/php/phpX.Y-fpm.sock）
  │             └─ uploads/xxx.jpg 请求 → Nginx 自己读磁盘文件返回（不经过 PHP）
  ▼
php-fpm（PHP 8.5）执行 index.php / board.php / api.php
  │  mysqli（优先）或 PDO（回退）
  ▼
MySQL 8.x（默认只监听 127.0.0.1:3306）
```

**关键点：图片不走 PHP。** 上传时 PHP 只负责把文件从临时目录移动到 `uploads/`，之后浏览器取图是 Nginx 直接读文件。这样做的好处是把 PHP-FPM 从大流量静态请求中解放出来，代价是需要 nginx 的 `location /uploads/` 能正常匹配到目录（README 的 nginx 配置里有写）。

### 3.2 一次"发布留言"的完整链路

```
1. 浏览器  POST /web-test/board.php   （multipart/form-data：act=post, name, content, pw, image）
2. Nginx   匹配 location ~ \.php$，把请求转给 php-fpm
3. PHP     board.php 载入 lib.php → 判断 config.php 是否存在（存在 → 进入业务逻辑）
4. PHP     ds_post() 取字段 → 校验（非空 / 长度 / 口令长度）
5. PHP     ds_upload_image() 校验图片（大小、真实类型）→ move_uploaded_file 到 uploads/
6. PHP     DB::exec() 走预处理：INSERT INTO ds_msg (...) VALUES (?,?,?,...)
7. MySQL   InnoDB 写入数据行，返回 affected_rows = 1
8. PHP     写 $_SESSION['flash'] 提示语 → header('Location: ...') 302 重定向
9. 浏览器  跟随 302 重新 GET 列表页（此时数据已落库，刷新不会重复提交）
10. PHP    记录一条访问到 ds_visit → 聚合查询统计 → 渲染 HTML → 输出
```

第 8 步是经典的 **PRG 模式（Post/Redirect/Get）**：POST 处理完立刻重定向，而不是直接渲染结果页。否则用户按 F5 会"重复提交表单"（浏览器会二次 POST），留言就会发两遍。这是留言板类程序最常见的 bug 来源。

### 3.3 一次"点赞"的链路（AJAX）

```
页面 JS 点击 ♡ → fetch POST api.php?act=like&id=3
             → api.php 查会话是否已赞 → UPDATE ds_msg SET likes = likes + 1
             → 再 SELECT 回读当前值 → json_encode → 前端更新数字
```

这条链路专门用来验证 **JS → PHP → MySQL → JSON** 这一段（Node/Vue 之类的前端要和后端对接时走的就是这条路），和前端的服务端渲染是两条不同的通路。

### 3.4 三个入口文件的职责边界

| 文件 | 有无 side effect（写数据） | 是否依赖 config.php | 输出 |
|---|---|---|---|
| `index.php` | 只在你点"测试数据库"时写（建库建表 INSERT），**不写任何文件** | 不依赖，参数在表单填 | HTML |
| `board.php` | 是：发帖 / 删帖 / 记录访问 | 依赖（没有则进安装向导） | HTML |
| `api.php` | 是：点赞计数 | 依赖（没有则返回 JSON 错误） | JSON |

`index.php` 刻意"不落盘"：它的测试参数只存在 `$_SESSION['web_test_form']` 里，用完关掉浏览器就没了。这是为了让"体检"这个动作尽可能干净 —— 不会在服务器上留下配置文件。

---

## 4. 数据库：库、表是怎么来的，每张表干什么

### 4.1 库和表是谁创建的？

**不是手工建的，是程序在运行中自己建的。** 这是整个测试站最重要的设计：服务器上只要有一个"能建库建表的 MySQL 账号"，其余全部由代码完成。

两个创建时机：

| 触发点 | 代码位置 | 建了什么 |
|---|---|---|
| 首次打开 `board.php`（`config.php` 不存在）→ 提交安装表单 | `board.php` 安装分支 → `ds_setup_schema()` | 库 + `ds_msg` + `ds_visit` |
| 在 `index.php` 点"测试数据库" | `index.php` 的 `db_test()` | 库 + `lnmp_check`（可选勾选测完删表） |

创建的 SQL 就三条，全部带 `IF NOT EXISTS`：

```sql
CREATE DATABASE IF NOT EXISTS `web_demo_db`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ds_msg`   ( ... ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `ds_visit` ( ... ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

三个技术点值得记住：

1. **`IF NOT EXISTS` 让语句可重复执行**。所以自动补建可以放心地"该建就建"：`board.php` 每次访问都会用一条 `SHOW TABLES` 检查 `ds_msg` / `ds_visit` 在不在，缺了才调用 `ds_setup_schema()` 补建；如果连库都没了（`USE 库名` 失败），则从建库开始整条重建。已有数据不受影响 —— 也正因为如此，**删掉 `config.php` 重装不会丢留言**。
2. **连库时不能指定 database**。第一次运行时库还不存在，如果 DSN/连接参数里写了库名，MySQL 会直接报 `1049 Unknown database`。所以 `db_connect_cfg()` 连的是一个"不带库"的连接，之后再用 `USE 库名` 切进去（见 5.4）。
3. **账号需要 `CREATE` 权限**。只用 `SELECT/INSERT` 的账号跑不通 —— 这是本站报错提示里反复强调给 GRANT 语句的原因。

数据库名从哪来？安装表单里的"库名"，经 `ds_safe_dbname()` 过滤（只保留字母数字下划线）后才拼接进 SQL。因为库名无法用预处理占位符（`CREATE DATABASE ?` 语法不支持），只能用白名单式过滤来防注入。

### 4.2 `ds_msg` —— 留言表（动态留言板的核心）

一条留言 = 一行记录。这是本站唯一直正"承载业务"的表。

| 列名 | 类型 | 作用 | 为什么这么设计 |
|---|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | 主键，留言编号 | 自增整数做主键，聚簇索引写入快；页面上的 `#3` 就是它。也用于"固定链接" `/note/3` |
| `name` | `VARCHAR(32)` | 昵称 | 表单限制 20 个字符，字段留到 32 给余量（UTF-8 下 1 个汉字算 1 个字符，不是 3 字节） |
| `content` | `VARCHAR(1000)` | 留言正文 | 表单限 500 字，字段留一倍余量。用 VARCHAR 而非 TEXT：1000 字符内行内存储，读列表页更快 |
| `image` | `VARCHAR(180)` | 图片的**相对路径**，如 `uploads/20261013-ab12cd34ef.jpg` | **只存路径不存二进制**。图片本体在磁盘上，数据库只记"文件在哪"，这是 Web 应用的标准做法（BLOB 存储会让备份、CDN、并发都变难）。空串表示没传图 |
| `likes` | `INT UNSIGNED DEFAULT 0` | 点赞数 | 用 `UPDATE ... SET likes = likes + 1` 原子自增，而不是"读出来 +1 再写回"，避免并发丢更新 |
| `ip` | `VARCHAR(45)` | 发布者 IP | 45 字符是为了兼容 IPv6（IPv4 最长 15，IPv6 带 `::` 最长 45） |
| `pw_hash` | `VARCHAR(255)` | 删除口令 | ⚠️ 列名沿用 `pw_hash`，但**当前版本存的是明文**（详见 5.3）。留 255 是为了兼容"将来想改回 bcrypt 哈希"——bcrypt 输出是 60 字符，原设计余量充足 |
| `created_at` | `DATETIME` | 发布时间 | 由 PHP 用 `date('Y-m-d H:i:s')` 写入，而不是 `NOW()`。好处是页面显示的时间与 PHP 时区设置一致，排查时区问题更直观 |

索引：

```sql
PRIMARY KEY (`id`),            -- 列表页 ORDER BY id DESC 直接走主键，倒序扫描
KEY `idx_created` (`created_at`)
```

诚实说明：**`idx_created` 在当前代码里其实还没被任何查询用上**（列表按 `id` 倒序，详情按主键查）。保留它是为了将来加"按日期范围筛选/归档"时有现成索引。想精简可以直接删掉这一行。

### 4.3 `ds_visit` —— 访问记录表

每次有人打开留言板（不管列表页还是详情页），都往这里插一条记录。它是"运行统计"卡片的数据源。

| 列名 | 类型 | 作用 | 说明 |
|---|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | 主键 | |
| `path` | `VARCHAR(120)` | 访问的动作名，如 `list` / `detail` | 取自 `?act=`，截断到 120 字符 |
| `ip` | `VARCHAR(45)` | 访客 IP | 取不到时存空串 |
| `ua_hash` | `VARCHAR(32)` | User-Agent 的 MD5 | **只存摘要不存原文**：既能统计"多少不同浏览器"，又不累积大量长字符串。AA 同一个访客换 UA 只变一行 |
| `created_at` | `DATETIME` | 访问时间 | 索引 `idx_time` 就是为"今日访问"这条查询准备的 |

**统计原理：写一条，再用聚合查询读回来。** 页面上四个数字用一条 SQL 同时算出，一句 `SELECT` 里塞了 4 个标量子查询：

```sql
SELECT
  (SELECT COUNT(*)                        FROM ds_visit)                       AS msgs  -- 留言总数
  (SELECT COALESCE(SUM(likes),0)          FROM ds_msg)                         AS likes -- 累计点赞
  (SELECT COUNT(*) FROM ds_visit WHERE created_at >= '2026-10-13 00:00:00')     AS today -- 今日访问
  (SELECT COUNT(*)                        FROM ds_visit)                       AS visits
```

这么写一次往返就拿到 4 个值，比发 4 次查询快。`idx_time` 让"今日访问"那条子查询只扫今天的数据，不用全表扫描。

**这张表是"消耗型"的**：访问越多行越多。测试站随便跑，真上量的话要么定期清理、要么改成按天聚合的统计表：

```sql
DELETE FROM ds_visit WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

### 4.4 `lnmp_check` —— 自检页的探针表

这张表只服务于 `index.php` 的"MySQL 全链路测试"，没有任何业务含义。表结构刻意做到最简：

```sql
CREATE TABLE IF NOT EXISTS `lnmp_check` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `note`       VARCHAR(64)  NOT NULL DEFAULT '',   -- 形如 probe-20261013-110456
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

它的存在意义是：**用建表、写行、读回这三步，证明 PHP 对这个库有"真权限"**，而不只是"能连上"。很多环境的真实故障是"能连上但没权限写"（报 1044），只测连接是发现不了的。

`note` 字段写入的时间戳可以让你在数据库工具里一眼看到最近一次测试是什么时候跑的。不需要时：

```sql
DROP TABLE `lnmp_check`;        -- 或勾选页面上的"测完删除测试表"
```

### 4.5 表 ↔ 页面功能 对照

| 页面位置 | 用到的表 | 具体操作 |
|---|---|---|
| 留言列表 / 详情 | `ds_msg` | `SELECT ... ORDER BY id DESC LIMIT/OFFSET`、`SELECT * WHERE id=?` |
| 搜索框 | `ds_msg` | `WHERE content LIKE ? OR name LIKE ?`（`%关键词%`） |
| 发布留言 | `ds_msg` | `INSERT`（含 `image` 路径字节） |
| 点赞按钮 | `ds_msg` | `UPDATE SET likes = likes + 1` + `SELECT` 回读 |
| 删除按钮 | `ds_msg` | `SELECT` 校验口令 → `DELETE` → 顺带 `unlink()` 图片文件 |
| 右侧"运行统计" | `ds_msg` + `ds_visit` | 一条含 4 个标量子查询的聚合 SQL |
| 每次打开页面 | `ds_visit` | `INSERT` 一条访问记录 |
| 页脚"表 ds_msg / ds_visit" | — | 显示当前使用的表名，方便对照数据库工具 |
| ① 自检页"测试数据库" | `lnmp_check` | 建库 → 建表 → `INSERT` → `SELECT` →（可选）`DROP` |

### 4.6 数据清理 / 重置

```sql
-- 只清空数据、保留表结构（自增 id 归零）
TRUNCATE TABLE ds_msg;
TRUNCATE TABLE ds_visit;

-- 彻底重来（下次打开 board.php 会自动重建）
DROP TABLE ds_msg, ds_visit, lnmp_check;
-- 连库一起删（注意会同时删掉别的程序的表）
DROP DATABASE web_demo_db;
```

想"重装但不丢留言"：删掉站点目录下的 `config.php`，重新访问 `board.php` 走一遍安装向导即可（表已存在，`IF NOT EXISTS` 会直接跳过建表）。

### 4.7 自愈机制（表/库被误删了会怎样）

`board.php` 在连上数据库之后有一段"体检 + 自愈"逻辑，每次请求都会执行，成本是一条 `SHOW TABLES`：

```php
if ($db->exec('USE `' . $dbName . '`') === false) {
    // 库整个没了 → 从建库开始重建
    list($ok, $emsg) = ds_setup_schema($db, $dbName);
    ...
} else {
    // 库在，但可能有表被手工删了 → 检查后补建
    foreach ($db->rows('SHOW TABLES') as $r) { $tbls[strtolower($v[0])] = 1; }
    if (!isset($tbls['ds_msg']) || !isset($tbls['ds_visit'])) {
        list($ok, $emsg) = ds_setup_schema($db, $dbName);
        ...
    }
}
```

对使用者的实际意义：

| 你干了什么 | 页面表现 |
|---|---|
| 在 phpMyAdmin 里误删了 `ds_msg` | 刷新页面表自动回来（**表内数据无法恢复**，本来就是被你删掉的） |
| 误删了 `ds_visit` | 同上，访问统计从 0 重新开始 |
| 误删了整个库 `web_demo_db` | 刷新页面自动重建库 + 两张表，页面正常打开 |
| 只删了库里的数据（TRUNCATE） | 一切照常，数据自然是空的 |

已实测：删表、删库三种情况刷新后都自动恢复，页面不报错（14 项断言全通过）。

---

## 5. lib.php 公共库逐块拆解

### 5.1 常量与运行环境预处理

```php
define('DS_DIR', __DIR__);          // 站点绝对路径，所有文件操作都基于它
define('DS_PER_PAGE', 8);           // 留言板每页条数（改这里即可改分页大小）
define('DS_MAX_UPLOAD', 2097152);   // 图片上限 2 MB
```

```php
if (function_exists('mysqli_report')) { @mysqli_report(MYSQLI_REPORT_OFF); }
```

这行是**兼容 PHP 8.1+ 的关键**。PHP 8.1 起 mysqli 扩展默认从"返回错误码"改成"抛异常"，代码里那些 `if ($db->exec(...) === false)` 的判断就永远不生效，异常会直接冒到最外层变成 500 白屏。显式关掉异常模式，让所有版本统一走"返回 false + 读 errno"的老路，代码才只需要维护一套逻辑。

`error_reporting` 关掉了 notice/warning/deprecated，`display_errors = 0`：测试站常跑在各种 PHP 版本上，把弃用警告直接打在页面上会干扰判断。真正的数据库错误会通过页面上的提示条明确展示，不依赖 PHP 的报错输出。

### 5.2 编码清洗与输出转义

```php
function h($s) {
    return htmlspecialchars(ds_to_utf8($s), ENT_QUOTES, 'UTF-8');
}
```

一个小小的 `h()` 里藏了两个必须做的事，顺序不能换：

1. **`ds_to_utf8()` 先做编码修复**。MySQL 返回的文本理论上应该是 UTF-8，但只要服务器上任何一环（连接字符集、表字符集、客户端）不是 UTF-8，中文就可能以 GBK 字节的形式回来。而 `htmlspecialchars()` 遇到非法 UTF-8 字节会**直接返回空字符串** —— 于是页面上中文全部消失，看起来像"数据丢了"，其实只是转义函数罢工。先修编码再转义，就避免了这类鬼问题。
2. **`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` 做 XSS 防护**。所有用户输入（昵称、留言内容）进 HTML 前都必须过这一手，否则有人留言写 `<script>...</script>` 就会在所有访客的浏览器里执行。

`ds_to_utf8()` 的降级顺序：本来就是合法 UTF-8 → 原样返回；否则试 `mb_convert_encoding(GB18030→UTF-8)`；再试 `iconv`；都失败才把非 ASCII 字符替换成 `?`。层层降级是为了在缺少 mbstring/iconv 扩展的精简服务器上也能跑。

### 5.3 配置读写与口令

**config.php 是"能被 include 的 PHP 文件"，不是 ini/json：**

```php
function cfg_read() {
    $f = cfg_file();
    if (!is_file($f)) { return null; }
    $c = @include $f;          // 文件内容 return array(...); 直接拿到数组
    return is_array($c) ? $c : null;
}
```

选 PHP 而不是 JSON/INI 的理由：**PHP 文件放在 web 目录里，直接访问会被解释器执行（返回空），而不是被下载成文本**。前提是 nginx 里那条 `location ~ \.php$` 存在 —— 否则 `.php` 会被当成静态文件让人下载走，里面的数据库口令就泄露了。README 里反复强调这条配置，原因就在这里。

`cfg_write()` 用 `var_export()` 生成代码、写完 `chmod 0640`（仅属主可读写），减少被其他用户读到的机会。

**口令：明文存储 + 兼容旧哈希**

```php
function ds_pw_store($pw) { return (string) $pw; }      // 明文本字存储

function ds_pw_match($input, $stored) {
    $input = (string) $input; $stored = (string) $stored;
    if ($input === '' || $stored === '') { return false; }
    if (preg_match('/^\$(2[aby]|argon2)/', $stored)) {  // 看起来是哈希
        return password_verify($input, $stored);
    }
    return hash_equals($stored, $input);                // 明文：常量时间比较
}
```

设计说明：

- 本站是**给个人/内网做环境验证用的**，明文的好处是"忘了随时能看"：管理密码在 `config.php` 的 `admin_pass` 字段，留言的删除口令在 `ds_msg.pw_hash` 列，用数据库工具一眼可见。
- 校验函数用"格式嗅探"做向下兼容：存储值以 `$2y$`/`$2a$`/`$2b$`/`$argon2` 开头就当哈希处理，否则当明文。**这样别人手上"用旧版哈希存的 config.php 和存量数据"直接搬过来就能用，不需要任何迁移脚本。**
- `hash_equals()` 而不是 `===`：常量时间比较，避免通过响应时间差逐字节猜口令（教科书级细节，成本为零顺手就做了）。
- **想改回哈希只需改一处**：`ds_pw_store()` 里把 `return (string) $pw;` 换成 `return password_hash($pw, PASSWORD_DEFAULT);`。校验逻辑不用动，`ds_pw_match()` 会自动识别。

### 5.4 `class DB` —— 一套封装，两种驱动

为什么要自己包一层，而不用 mysqli 或 PDO 直写？因为**同一份代码要在不同服务器上跑**，有的 PHP 只装了 mysqli，有的只装了 pdo_mysql。包一层的代价很小（不到 150 行），换来的是"哪套扩展在都能跑"。

**（1）连接：不带库名，超时 5 秒**

```php
$realHost = ($sock !== '') ? 'localhost' : $host;   // 给 socket 时 host 必须是 localhost
$ok = @mysqli_real_connect($link, $realHost, $user, $pass, '', $port, ($sock ?: null), 0);
if ($ok) { @mysqli_set_charset($link, 'utf8mb4'); }
```

三个容易踩的点全在这几行里：

- **不传库名**（第 5 个参数为空串）：库可能还不存在，见 4.1。
- **`mysqli_set_charset('utf8mb4')` 必须显式调用**。PHP 7.3 的 mysqli 默认字符集不是 utf8mb4，结果是：英文留言一切正常，一写中文就报 `Incorrect string value`。这个坑极具迷惑性（"英文能写说明代码没问题"），实际是连接层字符集的问题。PDO 那边则在 DSN 里带 `;charset=utf8mb4`。
- **给了 socket 路径时，主机名必须是 `localhost`**。这是 MySQL 客户端的约定：`localhost` 表示走 Unix socket，写 `127.0.0.1` 会强制走 TCP，socket 参数就被忽略了。

**（2）`exec()`：无参数走原生，有参数才预处理**

```php
if (!$params) {
    $r = @$this->c->query($sql);        // 原生执行
    ...
    return (int) $this->c->affected_rows;
}
$st = $this->prep($sql, $params);       // 走预处理
```

第一个分支不是优化，是**必需**：`USE 库名`、`CREATE DATABASE`、`CREATE TABLE` 这类语句**在 MySQL 协议层不支持预处理**，硬走 `prepare()` 会报 "not supported in the prepared statement protocol yet"。所以规则定为：**没有占位符参数的 SQL 直接原生执行，带参数的才走预处理**。建库建表这类天然没有占位符，正好落在原生分支。

`return (int) $this->c->affected_rows;` 这里还有个经典坑：**DDL 语句（CREATE TABLE）成功时 `affected_rows` 也是 0**。所以调用方一律用 `=== false` 判断失败，绝不能写 `if (!$db->exec($sql))` —— 那会把"成功建表"误判为失败，然后报一个假的"建表失败"错误。这个 bug 在本站的开发过程中真实出现过。

**（3）预处理：类型自动识别 + 引用绑定**

```php
foreach ($vals as $v) { $types .= (is_int($v) || is_float($v)) ? 'd' : 's'; }
$refs = array(); foreach ($vals as $k => $v) { $refs[$k] = &$vals[$k]; }
array_unshift($refs, $types);
call_user_func_array(array($stmt, 'bind_param'), $refs);
```

mysqli 的 `bind_param()` 要求参数**按引用传**，而 `call_user_func_array` 传的是值，所以必须先把数组元素转成引用（`$refs[$k] = &$vals[$k]`）。这是 mysqli 时代流传最广的一个技巧。类型自动判断省去了手工写 `'ssd'` 的麻烦。

**（4）`rows()`：没有 mysqlnd 时的回退**

```php
if (method_exists($st, 'get_result')) { ... }        // 有 mysqlnd：直接取关联数组
else { /* bind_result 逐列绑变量再逐行拷贝 */ }
```

`get_result()` 是 mysqlnd 驱动才有的方法。一些发行版编译 PHP 时用的是 libmysqlclient 而不是自带的 mysqlnd，调用就会报错 —— 所以准备了一条 `bind_result()` 的回退路径。这段代码写起来啰嗦，但能让程序在更多服务器上跑起来。

**（5）SQL 计数与耗时**

`$GLOBALS['DS_SQL_COUNT']` / `DS_SQL_TIME` 在每次执行时累加，最后显示在页脚："本次请求 42.3 ms · SQL 7 条（1.8 ms）"。这是**给排查性能用的**：如果页面慢而 SQL 只花了 1 ms，问题就在 Nginx/PHP-FPM/网络，而不在数据库。这本身就是一次"环境自检"。

### 5.5 `ds_setup_schema()` —— 建库建表

流程：净化库名 → `CREATE DATABASE IF NOT EXISTS` → `USE` → 建 `ds_msg` → 建 `ds_visit` → 返回三元组 `array(是否成功, 错误信息, 错误号)`。

用三元组而不是抛异常/返回 false，是为了让调用方能把"错误号"喂给 `db_tip()`，转化成页面上可执行的修复建议（"你需要这样 GRANT……"），而不是甩一句英文错误让用户自己猜。

### 5.6 `ds_upload_image()` —— 上传处理

按顺序做 6 道校验/处理：

| 步骤 | 代码 | 为什么 |
|---|---|---|
| 1 | 检查 `$_FILES[$field]['error']` | 用错误码映射表翻译成中文原因（超 php.ini 限制、临时目录不可写……），比"上传失败"有用得多 |
| 2 | `size > DS_MAX_UPLOAD` → 拒绝 | 2 MB 上限，避免有人传几百 MB 拖垮磁盘 |
| 3 | `getimagesize($tmp)` 判断**真实文件类型** | 关键安全点：**不信任扩展名和浏览器上报的 Content-Type**，而是读文件头。只有 JPG/PNG/GIF/WebP 四种允许。否则有人可以把 `.php` 改名成 `.jpg` 传上来，如果目录恰好能被 PHP 执行就是远程代码执行 |
| 4 | 目录不存在则 `mkdir`，不可写则报错 | 报错信息直接给出 `chown` 命令，这是部署初期最常见的故障 |
| 5 | 文件名 = `日期 + md5(uniqid()) + 真实后缀` | 双重目的：**避免重名覆盖**、**避免用户控制文件名**（原名可能带 `../` 或特殊字符） |
| 6 | `move_uploaded_file()` + `chmod 0644` | 用这个函数而不是 `copy()`：它会校验文件确实是通过 HTTP 上传的临时文件，防止路径伪造 |

配套的两个细节：`uploads/index.html` 占位文件防止目录被列出（nginx 的 `autoindex` 关掉后仍建议放一个）；发帖流程中如果 **INSERT 失败会 `@unlink()` 掉刚上传的图片**，避免"图片在磁盘上、数据库里却没有引用"的孤儿文件。

### 5.7 `db_tip()` —— 把错误号翻译成人话

| 错误号 | 含义 | 页面给出的建议 |
|---|---|---|
| 1045 | 账号密码错误 | 提示 `user@localhost` 和 `user@%` 是两个不同账号；MariaDB 报 1698 是 unix_socket 认证问题 |
| 1044 | 无库权限 | 给出 `GRANT ALL ON 库名.* TO ...` 语句 |
| 1049 | 库不存在且无建库权 | 给出手工建库 SQL |
| 2002/2003 | 连不上 MySQL | 检查 `systemctl status mysql`、端口、socket、防火墙 |
| 1130 | IP 不被允许 | 需要授权 `账号@'本机IP'` 或 `@'%'` |

这个小函数是本站"教学价值"最集中的地方：**把 MySQL 的英文错误号，翻译成下一步该敲的命令**。

---

## 6. board.php 动态留言板

### 6.1 结构总览

文件按四个编号区块组织，读代码时按区块找：

```
一、安装向导（config.php 不存在时）
二、连接数据库（表不存在会自动补建）
三、写操作（PRG 模式：发布 / 删除）
四、读操作：访问记录 / 统计 / 列表或详情
五、渲染（HTML + 内联 CSS/JS）
```

### 6.2 安装向导

判断条件就一句 `if (!cfg_ready())` —— 只要 `config.php` 不存在或缺少关键字段，就进入向导页。提交后依次做：

1. 取字段、补默认值（主机 `127.0.0.1`、端口 `3306`、库名 `demo_site`）；
2. 校验：账号必填、管理密码 ≥ 6 位；
3. **试连数据库** —— 连不上就报错并给出 `db_tip()` 建议，不写任何文件；
4. 连通 → `ds_setup_schema()` 建库建表；
5. 建表成功 → `cfg_write()` 落盘 `config.php`；
6. 写盘失败 → 明确提示"数据库已建好，但配置文件写入失败（目录不可写）"+ `chown` 命令。**这个中间状态必须说清楚**，否则用户会重复提交、反复建表，还以为失败了；
7. 全部成功 → 写 flash 提示（含刚设置的管理密码明文）→ 302 回本页，此后就是正常的留言板。

"不需要登录"的取舍让首次使用变成"打开页面 → 填一次参数 → 完事"，适合给不熟悉服务器的人做验证。

### 6.3 写操作一：发布留言

```
取字段 → 校验（昵称/内容非空、长度、口令长度）→ 上传图片 → INSERT → 失败则删图 → flash + 302
```

校验规则：昵称 ≤ 20 字、内容 ≤ 500 字（用 `mb_strlen` 按**字符**算而不是字节，否则 20 个汉字会被算成 60 而误报超长）、删除口令留空或 ≥ 4 位。

注意表单里的 `maxlength="20"` 只是前端体验，**服务端仍然完整校验一遍** —— 前端限制绕过成本为零，服务端校验才是真正的门。

### 6.4 写操作二：删除留言

```php
$isAdmin = ds_pw_match($pw, $adminPw);                 // 安装时的管理密码
$isOwner = ds_pw_match($pw, $row['pw_hash']);          // 发布时设的删除口令
if (!$isAdmin && !$isOwner) { 报错 }
```

两种口令任一通过即可删。这样设计是为了解决一个现实问题：**匿名发帖的留言没人能删**。发布者设了口令可以自己删；没设口令的，站主用管理密码兜底清理。当留言本身没有口令（`pw_hash` 为空）且输入的不是管理密码时，错误信息会明确说"这条留言发布时没有设置删除口令，需要输入安装时的管理密码" —— 而不是笼统的"口令不正确"。

删除成功后还会 `@unlink()` 图片文件（且只删 `uploads/` 开头的路径，防止被构造成 `../config.php` 之类的路径删到别的东西）。

### 6.5 读操作：访问统计、列表、搜索、分页

**访问记录**放在业务查询之前：`INSERT INTO ds_visit` 一条，然后是那条 4 子查询的统计 SQL（见 4.3），再往下才是列表/详情。

**搜索**：

```php
$where = ' WHERE (`content` LIKE ? OR `name` LIKE ?)';
$params = array('%' . $q . '%', '%' . $q . '%');
```

关键词两头加 `%` 是"包含匹配"。`$q` 作为参数传入，**不是拼进 SQL 字符串**，所以即使有人搜索 `' OR 1=1 --` 也只是当成普通文本匹配，不会变成注入。

**分页**：

```php
$total = (int) $db->one('SELECT COUNT(*) ... ' . $where, $params);
$pages = max(1, (int) ceil($total / DS_PER_PAGE));
if ($page > $pages) { $page = $pages; }              // 越界回落（如 ?page=999）
$offset = ($page - 1) * DS_PER_PAGE;
$items = $db->rows('SELECT * ... ORDER BY `id` DESC LIMIT ' . DS_PER_PAGE . ' OFFSET ' . $offset, $params);
```

四个要点：

- `LIMIT`/`OFFSET` 用了 `(int)` 强制转换后直接拼接 —— 因为 MySQL 协议**不允许把 LIMIT 的值当占位符传**，只能拼字符串。这也是为什么这里必须显式做整数转换（这是唯一未走预处理的地方，安全性由强制类型转换保证）。
- `COUNT(*)` 和取数据用**同一套 `$where`/`$params`**，保证"搜索后的分页"和"搜索结果总数"一致。
- 越界回落：`?page=999` 自动回到最后一页，而不是显示空白 —— 这是穷举 URL 时常见的手抖场景。
- 代价说明：每次翻页都跑一次 `COUNT(*)`。留言上万条时这一步会成为瓶颈，真实项目通常改成缓存总数或用游标分页。本站是测试站，追求的是"逻辑清楚"。

### 6.6 渲染与环境条

页面上那条深色横条展示五个状态：Nginx 版本（`$_SERVER['SERVER_SOFTWARE']` 里含 "nginx" 则标绿）、PHP 版本与 SAPI（`fpm-fcgi` 说明真的跑在 php-fpm 上，而不是 CLI/内置服务器）、MySQL 版本与驱动、上传目录是否可写、静态资源能否加载（由 JS 探测 `uploads/index.html` 是否 200）。**打开页面就知道三段链路各自什么状态**，这是本页最重要的实用性设计。

页脚显示实时性能数据：

```
本次请求 42.3 ms · SQL 7 条（1.8 ms） · 数据库 web_demo_db · 表 ds_msg / ds_visit
```

### 6.7 内联样式与脚本（为什么没有 assets/ 目录）

CSS 由 `board_css()` 以 heredoc 返回、JS 直接写在页面底部。这样做的理由很实在：**部署项越少，越不容易因为路径问题失败**。一个 `assets/style.css` 如果因为目录没传全、nginx 规则不匹配而 404，页面就会变成光秃秃的默认样式，用户会误判成"PHP 没跑起来"。内联之后，只要 PHP 能执行，页面就是完整的。

页面里唯一的"外部资源"探针就是 `uploads/index.html`，它同时承担"防目录列表"和"静态文件服务自检"两个职责。

导航栏由 `nav_html($active)` 统一输出（`index.php` 传 1、`board.php` 传 2），两个页面互相有入口，不用记 URL。

### 6.8 `ds_url()` 的一个真实 bug（保留作为教训）

生成"固定链接"和分页链接时，需要一个"保留当前查询参数、只改其中几个"的函数：

```php
function ds_url($params = array()) {
    $q = $_GET;
    unset($q['act'], $q['id']);        // ← 必须先清，再合并
    foreach ($params as $k => $v) { ... }
```

最初的写法是"先合并、后 `unset($q['act'])`"，结果列表页的"固定链接"被生成为 `board.php?id=3` —— `act=detail` 被顺手删掉了，点进去只会回到列表页。修法就是把 `unset` 提到前面。这个 bug 说明：**改 URL 参数的函数，"清除旧参数"和"合并新参数"的顺序是有语义的**。

---

## 7. api.php JSON 接口

### 7.1 为什么单独一个文件

留言板的页面是服务端渲染的 HTML，但点赞这种小交互不该整页刷新。`api.php` 提供纯 JSON 响应，专门验证 **前端 JS → PHP → MySQL → JSON** 这条路。它自己设置响应头：

```php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');      // 防止浏览器缓存住点赞结果
```

`nosniff` 防止浏览器把 JSON 按别的内容类型解释；`no-store` 保证每次都拿最新数据。

### 7.2 点赞与防刷

```php
if (empty($_SESSION['liked'][$id])) {
    $db->exec('UPDATE `ds_msg` SET `likes` = `likes` + 1 WHERE `id` = ?', array($id));
    $_SESSION['liked'][$id] = 1;
    $now = $db->one('SELECT `likes` FROM `ds_msg` WHERE `id` = ?', array($id));
    api_out(array('ok' => true, 'likes' => (int) $now, 'msg' => '已点赞'));
}
api_out(array('ok' => false, 'likes' => ..., 'msg' => '同一会话对同一条只能点一次赞（防刷）'));
```

- 自增用 SQL 端 `likes = likes + 1`，不是"读出-加一-写回"，避免并发下两次点赞只加了一次。
- 防刷靠 `$_SESSION`：同一浏览器会话对同一条留言只能点一次。
- **必须诚实说明这只是"演示级防刷"**：清掉 cookie 就能再点一次。真实的防刷要靠 IP + 时间窗、图形验证码或账号体系。这里保留 session 方案，是为了展示"服务端状态"这个概念，同时不引入额外依赖。
- 点赞成功后**再回读一次** `SELECT likes`，把数据库里的真实值返回给前端（而不是前端自己 +1），保证多点几次数字不会漂移。

### 7.3 编码清洗

```php
array_walk_recursive($arr, 'ds_fix_encoding_ref');
$s = json_encode($arr, JSON_UNESCAPED_UNICODE);
if ($s === false) { $s = json_encode(array('ok' => false, 'msg' => '响应编码失败')); }
```

`json_encode()` 有个特点：**只要数组里有任何一个非 UTF-8 字符串，整个 JSON 就编码失败返回 false**，前端拿到空响应会一脸懵。而这里恰恰可能混入非 UTF-8 —— 比如 MySQL 报错信息在中文 Windows 客户端下可能是 GBK。所以先递归修一遍编码。这是 5.2 那个坑在 JSON 场景下的翻版。

`JSON_UNESCAPED_UNICODE` 让中文原样输出（`"已经点赞"` 而不是 `"\u5df2\u7ecf"`），用 curl 调试时肉眼可读。

### 7.4 ping 探活

`api.php?act=ping` 返回一个状态快照：PHP 版本、SAPI、Web 服务器、MySQL 版本、驱动、库名、留言数、本条请求耗时。适合挂到外部监控（UptimeRobot 之类的 HTTP 监控）上，一眼看出"整条链路是活的"。

---

## 8. index.php 环境自检页

### 8.1 三段链路

| 段落 | 检测项 |
|---|---|
| **Nginx** | 页面能否返回（能打开就说明 Nginx 与 php-fpm 之间的 FastCGI 转发是通的）、站点根目录、访问地址与 Host、客户端 IP、HTTPS 判断、`SERVER_SOFTWARE` 里是否含 nginx |
| **PHP** | 版本、SAPI（`fpm-fcgi` 表示跑在 php-fpm 下）、已加载/缺失扩展（mysqli、pdo_mysql、json、mbstring、curl、openssl、fileinfo、gd、zip、opcache）、站点目录是否可写、临时目录是否可写、memory_limit / 上传上限 / 时区 / default_charset、运行用户 |
| **MySQL** | 页面上填参数后点测试，跑 `db_test()` 六步 |

每项带状态标签（`PASS` / `WARN` / `FAIL` / `INFO` / `SKIP`），页面顶部给一句总结"总览：PASS 12　WARN 1　FAIL 0"。这种"每项都有明确结论"的形式，比让人自己看一堆 `phpinfo()` 输出要快得多。

### 8.2 `db_test()` 六步

```php
连接 MySQL → SELECT VERSION() → CREATE DATABASE IF NOT EXISTS → USE → CREATE TABLE lnmp_check
→ INSERT（走预处理）→ SELECT COUNT(*) 与最新一行 → [可选] DROP TABLE
```

每一步都有独立的耗时统计和错误处理：任何一步失败立刻返回，并把 `db->errcode` 交给 `db_tip()` 转成建议。**失败即返回**而不是"继续往下跑"，是因为后面的步骤依赖前面的结果，硬跑只会产生一串连带的假错误。

为什么要在自检页里做 `INSERT`？因为"能连接"和"能写入"是两回事：权限不足的账号连接会成功、`SELECT` 也可能成功，但 `INSERT`/`CREATE` 会报 1044。这一步就是为了把这类"半通"的环境揪出来。

### 8.3 参数不落盘

```php
$_SESSION['web_test_form'] = $form;   // 仅存于服务端会话，不写文件
```

自检页填的数据库参数**只存在 session 里**（对比留言板会写 `config.php`）。这样"体检"这个动作不会在服务器上留下任何配置文件；点"清空"时连 session 一起清掉。如果同目录已经存在留言板的 `config.php`，页面会自动**预填**主机/端口/socket/账号/库名（**不预填密码**），省得重复输入。

---

## 9. 安全性说明与适用边界

**先说结论：本站是给个人/内网做 LNMP 可用性验证用的，不要直接放到公网长期运行。**

已知的、刻意保留的"不设防"设计：

| 项目 | 现状 | 说明 |
|---|---|---|
| 访问控制 | 无任何登录 | 任何能访问 URL 的人都可以发帖、上传图片、删自己发的帖 |
| 管理密码 | 明文存于 `config.php` | 刻意为之，便于查看；仅拥有服务器文件读取权限的人能看到 |
| 留言删除口令 | 明文存于数据库 | 同上 |
| 点赞防刷 | 仅 session 级 | 清 cookie 可绕过 |
| 错误信息 | 直接显示在页面上（含库名、表名、MySQL 版本） | 便于排障；对公网会泄露环境信息 |

已经做对的防护（这些保留，不用改）：

| 风险 | 防护手段 |
|---|---|
| SQL 注入 | **所有带用户输入的 SQL 一律走预处理**（`?` 占位符 + `bind_param`/`execute` 绑定）；库名走白名单过滤；LIMIT/OFFSET 走整数强制转换 |
| XSS | 所有输出统一过 `h()`（`htmlspecialchars` + `ENT_QUOTES`）；留言内容额外 `nl2br` 保留换行 |
| 恶意文件上传 | 用 `getimagesize()` 读文件头校验真实类型（不信任扩展名/Content-Type）；只允许 jpg/png/gif/webp；2 MB 上限；文件名服务端随机生成 |
| 路径穿越 | 上传文件名由程序生成；删除图片时校验路径必须以 `uploads/` 开头 |
| 口令比较时序 | `hash_equals()` 常量时间比较 |
| MIME 嗅探 | `api.php` 加 `X-Content-Type-Options: nosniff` |

**要放到公网时，按需加这几道门（任选，从简到严）：**

```nginx
# 1) 只允许自己的 IP 访问
location ^~ /web-test/ {
    allow 203.0.113.10;     # 你的出口 IP
    deny  all;
    # ... 其余配置
}

# 2) 加一道 HTTP 基本认证
location ^~ /web-test/ {
    auth_basic "test";
    auth_basic_user_file /etc/nginx/.htpasswd-test;
}

# 3) 或者干脆用防火墙只开给自己
```

代码层面：

```php
// 想恢复哈希存储：lib.php 的 ds_pw_store()
function ds_pw_store($pw) {
    return password_hash($pw, PASSWORD_DEFAULT);   // 改这一行即可，校验逻辑自动兼容
}
```

```nginx
# 禁止 config.php 被直接下载（正常情况下 .php 会被执行返回空，双保险）
location = /web-test/config.php { deny all; }
```

**验证完就删掉**，这是最简单也最有效的安全措施：

```bash
sudo rm -rf /var/www/web-test
sudo mysql -e "DROP DATABASE web_demo_db;"
```

---

## 10. 改造指南：想改什么，改哪里

| 想改的东西 | 改哪里 | 具体做法 |
|---|---|---|
| 每页留言条数 | `lib.php` 顶部 | `define('DS_PER_PAGE', 8)` → 改成你要的数字 |
| 图片上传上限 | `lib.php` 顶部 | `define('DS_MAX_UPLOAD', 2097152)`（单位字节）。**同时确认 php.ini 的 `upload_max_filesize` 与 `post_max_size` 不小于它** |
| 允许的上传类型 | `lib.php` → `ds_upload_image()` | `$allow` 数组里增删 MIME 类型与后缀 |
| 昵称/内容长度限制 | `board.php` 发布校验 + 表单 `maxlength` + `lib.php` 建表 SQL | 三处要一起改（校验、前端提示、字段长度），否则长内容会被 MySQL 截断 |
| 留言表加一个字段 | `lib.php` → `ds_setup_schema()` 的 `$sqlMsg` | 加列定义；然后改 `board.php` 的 INSERT 与 `ds_render_msg()` 的渲染。**注意：`IF NOT EXISTS` 不会给已存在的表加列**，已有环境要手工 `ALTER TABLE ds_msg ADD COLUMN ...` 或删表重建 |
| 加一张新表 | `lib.php` → 加常量 + `ds_setup_schema()` 里加 `CREATE TABLE IF NOT EXISTS` | 表名建议沿用 `DS_TABLE_*` 常量风格 |
| 加一个新的 JSON 接口 | `api.php` | 仿照 `if ($act === 'like') { ... }` 增加一个分支，用 `api_out()` 返回 |
| 站点名称 | 安装表单里的"站点名称"，或 `config.php` 的 `site_name` | 影响 `board.php` 的标题和头部；`index.php` 用的是常量 `DS_APP` |
| 改配色 / 样式 | `board.php` → `board_css()` | 全站样式集中在那个 heredoc 里，`header.top` 是顶部渐变条，`.pill` 是环境条标签 |
| 改页面标题固定文案 | `board.php` 头部 `<p class="sub">`、`index.php` 的 header | |
| 换数据库驱动 | 不用改 | 优先 mysqli，没有则自动回退 PDO。想强制用某个可以调整 `db_connect_cfg()` 里的检测顺序 |
| 用 socket 而不是 TCP 连 MySQL（更快） | 安装表单的"socket 路径" | 填 `/run/mysqld/mysqld.sock`（Ubuntu 默认位置，MariaDB 是 `/run/mysqld/mysqld.sock`，MySQL 8 也可能是 `/var/run/mysqld/mysqld.sock`），并把主机留 `localhost` |
| 恢复哈希存口令 | `lib.php` → `ds_pw_store()` | 见第 9 节 |
| 点赞防刷加严 | `api.php` 的 like 分支 | 现在只查 session；可加 IP + 时间窗（比如同 IP 同一留言 60 秒内只算一次） |
| 关掉访问记录（不想让表增长） | `board.php` 四、读操作开头 | 删掉那条 `INSERT INTO ds_visit ...`，同时把统计 SQL 里的今日/累计访问改成固定值 |

**改代码的通用注意点：**

1. 改完先用 `php -l 文件名` 做语法检查（不需要装什么，PHP 自带）。
2. 表结构相关的改动，注意 `IF NOT EXISTS` 的特性 —— **它只保证"表不存在时创建"，不会修改已存在的表**。
3. 任何新的 SQL，**带用户输入的必须走预处理**；没有占位符的（DDL、USE）走原生 query。
4. DDL 语句判断失败一律用 `=== false`，不要用 `!$result`。
5. 新建的库/表记得用 `utf8mb4`，否则中文和 emoji 都会出问题。

---

## 11. 排错手册

### 11.1 部署/连接类

| 现象 | 原因 | 解决 |
|---|---|---|
| 页面 **502 Bad Gateway** | php-fpm 没在跑，或 `fastcgi_pass` 指向的 socket 名不对 | `sudo systemctl start phpX.Y-fpm`（版本号用 `ls /run/php/` 查）；`ls /run/php/` 还能看到真实的 socket 文件名，据此改 nginx 里的 `fastcgi_pass` |
| 页面 **404 Not Found** | nginx 的 `location /web-test/`、`try_files`、`location ~ \.php$` 有一段没配上 | 对照第 12.1 节的配置逐段检查 |
| 打开 `board.php` 直接是安装向导 | **正常现象**（不是错误）：`config.php` 不存在时会进向导 | 走一遍安装即可；装好后就不再出现（`cfg_ready()` 判断） |
| 自检页 MySQL 项显示 **SKIP** | 没填数据库参数 —— 这一项本来就是可选的 | 想测就填参数点「测试数据库」，不测不影响其它检测项 |
| 自检页「静态文件」显示 **SKIP** | 目录里没有可被请求的静态文件 | 可忽略；正常部署下有 `uploads/index.html`，会显示 PASS |
| 页面能打开，但登录/提交表单报"配置文件写入失败" | 站点目录对 php-fpm 的运行用户不可写，写不了 `config.php` | `sudo chown -R www-data:www-data /var/www/web-test` |
| 打开 `board.php` 提示连接失败，错误号 1045 | 账号或密码不对；或账号建在了 `user@localhost` 但你连的是 `127.0.0.1`（**MySQL 视作两个不同账号**） | 用 `-h127.0.0.1` 确认账号能登录；必要时同时建 `'user'@'localhost'` 和 `'user'@'127.0.0.1'` |
| 错误号 1044 | 账号能连但没权限 | `GRANT ALL ON 库名.* TO '账号'@'localhost'; FLUSH PRIVILEGES;` |
| 错误号 1049 | 库不存在且账号没有 `CREATE` 权限 | 给 `CREATE` 权限，或先手工建库 |
| 错误号 2002 / 2003 | MySQL 没在跑 / 端口不对 / 只监听 socket | `systemctl status mysql`；`ss -lntp | grep 3306`；本机自测用 `127.0.0.1` |
| 页面上的 PHP 版本对了，但 SAPI 显示 `cli-server` | 你是在用 `php -S` 本地调试，不是 nginx + php-fpm | 正常现象；部署到 VPS 后应显示 `fpm-fcgi` |
| 环境条里"上传目录 不可写" | `uploads/` 权限不对 | `sudo chown -R www-data:www-data uploads && sudo chmod 755 uploads` |
| 环境条里"静态资源"红/警告 | `uploads/index.html` 拿不到（nginx 规则没匹配到目录） | 检查 nginx 的 `location /` 与 root 是否正确 |
| 从别的机器填 `192.168.x.x` 连 MySQL 失败 | MySQL 的 `bind-address` 默认只监听 127.0.0.1，且账号没授权远程 IP | 优先在本机填 `127.0.0.1`；确实要远程访问需改 `bind-address` + 授权 `@'%'` + 防火墙放行（**不建议对公网开放 3306**） |

### 11.2 写入/显示类

| 现象 | 原因 | 解决 |
|---|---|---|
| 英文留言能发，一写中文就报 `Incorrect string value` | 连接字符集不是 utf8mb4（老 PHP 的 mysqli 默认不是） | 本站在 `db_connect_cfg()` 里已显式 `set_charset('utf8mb4')`；如果你改过连接代码，记得保留这一步 |
| 页面中文显示成乱码或空白 | MySQL 返回的字节不是 UTF-8，而 `htmlspecialchars` 遇到非法 UTF-8 会返回**空串** | 同样源于连接/表字符集。确认库和表都是 `utf8mb4`，且连接层设了字符集 |
| 数据库里看留言内容是乱码 | 客户端工具（如 FinalShell 自带的）显示编码不是 UTF-8 | 在数据库工具里把连接编码设为 UTF-8；数据本身一般是好的 |
| 报错"建表失败"，但表其实建好了 | 用 `!$db->exec()` 判断 DDL 结果 —— 成功时 `affected_rows` 也是 0 | 一律用 `=== false` 判断 |
| 报错 "not supported in the prepared statement protocol yet" | 把 `USE` / `CREATE DATABASE` 之类的语句走了预处理 | 无参数的 SQL 走原生 `query()`（`DB::exec()` 已经这么做了） |
| 用 Windows 下的 `curl --data-urlencode` 发中文，怎么都失败 | **curl 会把中文按 GBK 发送**，不是代码问题 | 用 Python `urllib` 显式 `.encode('utf-8')`，或改用浏览器/Postman 测 |
| 提交表单后按 F5，留言发了两遍 | 直接渲染结果页而不是重定向 | 本项目用 PRG 模式（处理完 302），如果你在改造时破坏了这一点就会复现 |
| PHP 8.1+ 上直接 500 白屏，错误日志里是 mysqli 异常 | PHP 8.1 起 mysqli 默认抛异常 | 保留 `mysqli_report(MYSQLI_REPORT_OFF)` 那一行 |

### 11.3 功能/数据类

| 现象 | 原因 | 解决 |
|---|---|---|
| 列表点"固定链接"进不去详情，只回列表 | URL 生成时丢了 `act=detail` | 本项目已修（`ds_url()` 先 `unset` 再合并）；如果你改了 `ds_url()`，注意顺序 |
| 上传成功但图片不显示，数据库里 `image` 有值 | 图片文件在，但 nginx 没把 `uploads/` 当静态目录返回 | 检查 nginx 的 root/location；也可直接访问 `/web-test/uploads/xxx.jpg` 试 |
| 磁盘上有一堆没有被引用的图片 | 历史上 INSERT 失败留下的孤儿文件（新版已在上传失败时删除） | 手工清理：比对 `SELECT image FROM ds_msg` 与 `uploads/` 目录内容 |
| 留言删了但图片还在 | 早期版本不删图 | 新版删除时会 `unlink()`；历史残留手工清理即可 |
| 忘记管理密码 | 哈希不可逆（旧版）或没去看（新版） | 新版直接看 `config.php` 的 `admin_pass`；旧版删掉 `config.php` 重新走安装向导（**不丢留言**，建表是 `IF NOT EXISTS`） |
| `ds_visit` 表越来越大 | 每次访问都插一条 | `DELETE FROM ds_visit WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);` |
| 分页到最后一页后还能点下一页 | 越界回落逻辑被改坏 | 保留 `if ($page > $pages) { $page = $pages; }` |
| 图片完全传不上去 | 四种可能：超过 2 MB、不是有效图片（`getimagesize()` 校验失败）、`uploads/` 不可写、nginx 的 `client_max_body_size` 小于文件 | 看页面具体提示；nginx 里设 `client_max_body_size 8m;`；`chown` 目录 |
| 想重装留言板（换数据库参数或换管理密码） | `config.php` 存在时不会进安装向导 | 删掉 `config.php` 刷新即可；也可以直接编辑它（它是 `return array(...)` 的 PHP 文件）。**留言不会丢**，建表是 `IF NOT EXISTS` |
| 想清空所有留言重来 | —— | `TRUNCATE TABLE ds_msg; TRUNCATE TABLE ds_visit;`（详见 4.6） |

---

## 12. 部署配置详解

这一章解释"为什么 README 里让我这么部署" —— 每一行配置背后的原理。操作步骤本身在 `README.md`。

### 12.1 Nginx 配置逐行解释

> **通用可用的完整配置在同目录 [`nginx.conf.example`](nginx.conf.example)** —— 不绑定发行版 / PHP 版本 / 域名 / 目录名，改 3 处即可用（Debian 系放 `sites-available`，CentOS 系放 `conf.d`）。
> 下面为了讲解方便，用"**web-test 挂在站点子目录 `/web-test/` 下**"这种写法逐段拆解；如果你把 web-test 单独做成一个站点（`root` 直接指向它），把每段里的 `/web-test/` 前缀去掉即可。

```nginx
# ① 静态优先，找不到就交给 PHP 入口
location /web-test/ {
    try_files $uri $uri/ /web-test/board.php?$query_string;
}

# ② 伪静态：把好看的单条链接改写到真实参数
location ~ ^/web-test/note/(\d+)/?$ {
    rewrite ^/web-test/note/(\d+)/?$ /web-test/board.php?act=detail&id=$1 last;
}

# ③ 上传目录里禁止执行脚本
location ~* ^/web-test/uploads/.*\.(php|phtml|php[0-9])$ { deny all; }

# ④ 请求体上限（上传图片要过这一关）
client_max_body_size 8m;

# ⑤ 所有 .php 交给 php-fpm
location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/phpX.Y-fpm.sock;   # ← 换成 ls /run/php/ 查到的真实文件名
}
```

**① `try_files`**：请求进来先看磁盘上有没有对应的真实文件（`$uri`），有就直接返回（`uploads/xxx.jpg`、`README.md` 走这条）；再试目录（`$uri/`）；都没有才落到 `board.php`。这样 `/web-test/` 和 `/web-test/board.php` 都能通向留言板，而图片依旧由 Nginx 直接读磁盘，不经过 PHP。

**② `rewrite`**：把 `/web-test/note/3` 这种"好看"的地址在 Nginx 层翻译成 `board.php?act=detail&id=3`。PHP 侧完全不用改 —— 这也是伪静态的意义：**URL 形式与程序逻辑解耦**。参数在 PHP 里仍然走 `ds_get('id')` 取值。

**③ 禁止 `uploads/` 里执行脚本**：本项目的上传逻辑已经用 `getimagesize()` 读文件头校验，正常传不进 `.php`；这条是**双保险**（万一将来有人放宽了校验，或者别的程序往这个目录写了脚本）。注意它写在 `location ~ \.php$` **之前**才有意义 —— Nginx 的 location 匹配中，正则 location 按出现顺序匹配，先命中先生效。

**④ `client_max_body_size`**：这是 Nginx 层的请求体上限，默认 1 MB。图片上限 2 MB，所以这里必须放大（写 8m 留余量）。**它和 php.ini 是两道独立的门槛**，任意一道卡住都会导致"上传失败"：

| 位置 | 配置项 | 本项目要求 |
|---|---|---|
| Nginx | `client_max_body_size` | ≥ 8m |
| PHP | `upload_max_filesize` | ≥ 2M |
| PHP | `post_max_size` | ≥ 8M（`post_max_size` 必须大于 `upload_max_filesize`，因为它含整个表单） |
| 程序 | `DS_MAX_UPLOAD`（lib.php） | 2097152 字节 = 2 MB |

**⑤ `location ~ \.php$` + `fastcgi_pass`**：这是整条链路的关键。Nginx 自己不会执行 PHP，它把 `.php` 请求通过 **FastCGI 协议**转发给 php-fpm 进程，把响应再吐回浏览器。`fastcgi_pass` 指向的可以是 unix socket（`unix:/run/php/phpX.Y-fpm.sock`，本机通信、最快，Debian/Ubuntu 默认）或 TCP 端口（`127.0.0.1:9000`，CentOS 常见），CentOS/Rocky 系还有 `/run/php-fpm/www.sock` 这种写法。**socket 名字必须自己对**：`ls /run/php/`、`ss -lntp | grep fpm`。

> **这段配置必须存在，否则后果很严重**：没有它，`.php` 文件会被 Nginx 当成静态文本**让浏览器直接下载**。那么任何人访问 `/web-test/config.php` 就能拿到数据库账号密码和管理密码。这是本站安全模型里唯一"必须由部署方保证"的一环。
>
> `include snippets/fastcgi-php.conf;` 是发行版自带的片段，里面主要做两件事：把 `SCRIPT_FILENAME` 等 FastCGI 参数传过去、以及 `try_files $uri =404`（防止把不存在的脚本名传进 php-fpm）。

改完一定要先测语法再重载：

```bash
sudo nginx -t && sudo systemctl reload nginx
```

一个完整的 `server` 块长这样（**这是"挂在已有站点子目录"的版本；独立站点的通用版本见 [`nginx.conf.example`](nginx.conf.example)**，路径、域名、socket 按你自己的改）：

```nginx
server {
    listen 80;
    server_name 你的域名或IP;      # 不确定写什么就填 _
    root /var/www/你的站点;         # 注意：子目录方案下 root 是站点根，不是 web-test 目录
    index index.php index.html;

    location /web-test/ {
        try_files $uri $uri/ /web-test/board.php?$query_string;
    }
    location ~ ^/web-test/note/(\d+)/?$ {
        rewrite ^/web-test/note/(\d+)/?$ /web-test/board.php?act=detail&id=$1 last;
    }
    location ~* ^/web-test/uploads/.*\.(php|phtml|php[0-9])$ { deny all; }

    client_max_body_size 8m;

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/phpX.Y-fpm.sock;   # ← ls /run/php/ 查真实文件名
    }
}
```

### 12.2 权限模型（为什么要 chown）

php-fpm 干活时用的身份是 **`www-data`**（Debian/Ubuntu；CentOS/Rocky 系是 `nginx`，个别系统是 `apache`）—— **不是你的登录用户，也不是 root**。本站在运行过程中要写三处东西：

| 写入点 | 什么时候写 | 写不了会怎样 |
|---|---|---|
| 站点目录下的 `config.php` | 安装留言板时 | 提示"配置文件写入失败"（数据库其实已经建好了） |
| `uploads/` 目录 | 每次传图片 | 提示"上传目录不可写：…" |
| PHP 的 session 目录（`/var/lib/php/sessions`） | 每次 `session_start()` | 点赞防刷、提示语丢失；自检页会把它标成 WARN |

所以部署时那三条命令的意思是：**把文件属主交给运行用户**，并把权限设成"目录 755、文件 644（属主可写、其他人只读）"。用 root 直接拷进去、不做 chown，就会出现"页面能看、一提交就报错"的典型症状。

```bash
sudo chown -R www-data:www-data /var/www/web-test
sudo find /var/www/web-test -type d -exec chmod 755 {} \;
sudo find /var/www/web-test -type f -exec chmod 644 {} \;
```

程序自己也会检查并给出提示：`ds_upload_image()` 在目录不可写时报错文案里直接带上 `chown` 命令；安装向导写盘失败时的提示同理。**报错信息本身就是部署指引**，这是刻意设计的。

### 12.3 PHP-FPM 侧需要满足什么

| 项目 | 要求 | 检查方式 |
|---|---|---|
| PHP 版本 | 5.6 ~ 8.4 均可（代码里做了兼容） | 自检页会显示 |
| 数据库扩展 | **`mysqli` 或 `pdo_mysql` 至少一个** | 自检页「扩展」一项会标红；装 `sudo apt install php-mysql` |
| 编码扩展 | 建议有 `mbstring`（没有则回退 `iconv`，都没有会降级把非 ASCII 变 `?`） | 自检页扩展列表 |
| `upload_max_filesize` / `post_max_size` | ≥ 2M / ≥ 8M | 自检页「限制」一行显示实际值 |
| `session.save_path` 可写 | 点赞防刷、提示语依赖它 | 自检页「临时目录」一项 |
| `date.timezone` | 设成 `Asia/Shanghai` 之类，否则留言时间会差几小时 | 自检页「时区 / 编码」一行 |

**为什么"服务器上明明装了 PHP 却报 cannot connect"**：多半是装的 php-fpm 版本和 `fastcgi_pass` 的 socket 名不一致（比如系统装的是 php8.3-fpm，配置里写的却是 php8.5-fpm）。`ls /run/php/` 一眼就能看出来。

改动 php.ini 后要重启 fpm 才生效：

```bash
# 版本号换成你装的（ls /run/php/ 或 systemctl list-units | grep php 查）
sudo systemctl restart php8.3-fpm
```

### 12.4 MySQL 侧需要满足什么

1. **有 `CREATE` 权限**（因为程序要自己建库建表）。只给 `SELECT/INSERT` 的账号会在建库那一步失败，页面会提示 1044 并给出 GRANT 语句。
2. **账号的 host 要和连接方式一致**。MySQL 把 `'demo'@'localhost'` 和 `'demo'@'127.0.0.1'` 当作**两个不同的账号**。填 `127.0.0.1` 连接时匹配的是后者（或 `'demo'@'%'`）。最省事的做法是三种都建，或直接用 `@'%'`（内网测试可接受）。
3. **字符集 `utf8mb4`**。库、表、**以及连接**三处都要是 utf8mb4，缺一处中文就可能插入失败（详见 11.2 的 `Incorrect string value`）。程序已经在建表和连接时都设好了，你不需要手动干预。
4. **`bind-address` 默认只监听 `127.0.0.1`**，所以：

```bash
# 在 VPS 上看到的正常状态：3306 只绑在回环地址
ss -lntp | grep 3306
#   LISTEN 0 151 127.0.0.1:3306  ...
```

这意味着**从别的机器用服务器对外 IP 连 3306 是连不上的（正常现象）**；这也正是安装向导里要你填 `127.0.0.1` 的原因 —— PHP 和 MySQL 在同一台机器上。要给外部访问就得改 `bind-address` + 授权 `@'%'` + 防火墙放行，**但不建议对公网开放数据库端口**。

### 12.5 部署检查清单

```bash
# 1. php-fpm 在跑吗？socket 叫什么？（版本号换成你实际装的）
systemctl status php8.3-fpm
ls /run/php/

# 2. MySQL 在跑吗？监听在哪？
systemctl status mysql
ss -lntp | grep 3306

# 3. nginx 配置语法对不对？
nginx -t

# 4. 站点目录权限对不对？（属主应是 www-data）
ls -ld /var/www/web-test /var/www/web-test/uploads

# 5. PHP 有没有数据库扩展？
php -m | grep -E 'mysqli|pdo_mysql'

# 6. 上传相关限制是多少？
php -i | grep -E 'upload_max_filesize|post_max_size'
```

以上任何一条不对，`index.php` 打开时的自检结果都会直接指出来 —— **这就是①页存在的意义**：把上面这些命令要查的东西，一次性变成一个网页。

---

## 附：一页速查

```
入口：index.php（自检，不落盘） / board.php（动态站，需要 config.php） / api.php（JSON）
公共库：lib.php（常量 / h() / cfg_* / DB 类 / db_connect_cfg / ds_setup_schema / ds_upload_image）
数据库：web_demo_db（可自定义）
  ├─ ds_msg    留言（id,name,content,image,likes,ip,pw_hash,created_at）
  ├─ ds_visit  访问记录（id,path,ip,ua_hash,created_at）
  └─ lnmp_check 自检探针（id,note,created_at）
口令：明文存（config.php 的 admin_pass / ds_msg.pw_hash），ds_pw_match() 兼容旧哈希
安全：SQL 全预处理 / 输出全 h() 转义 / 上传校验真实类型；无登录，仅适合内网或临时测试
常用改动：DS_PER_PAGE（分页）、DS_MAX_UPLOAD（上传上限）、ds_pw_store()（回到哈希）
```
