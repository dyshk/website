# LNMP 测试站

一个目录、两个页面，用来确认服务器上的 **Nginx + PHP + MySQL** 是不是真的都能跑起来。

| 页面 | 打开地址 | 做什么 |
|---|---|---|
| ① 环境自检 | `index.php` | 逐项体检：Web 服务器、PHP 版本与扩展、参数限制、目录权限、静态文件服务；页面上填一次数据库账号密码，就跑一遍「连接 → 建库 → 建表 → 写 → 读」 |
| ② 动态留言板 | `board.php` | 一个真的能用的动态站：发留言、传图片、点赞、搜索、分页、删除 —— 数据实时读写 MySQL |

无登录、无框架、无 composer 依赖，数据库参数在页面上填，不用改任何配置文件。

> 📖 **本文件只讲"怎么部署、怎么用"。**
> 所有技术内容（架构原理、请求链路、数据库每张表每个字段的设计、代码逐块拆解、nginx 配置逐行解释、改造指南、排错手册）都在 **[`TECH.md`](TECH.md)** 里。

---

## 部署步骤总览（先看这里）

一句话讲清楚要干什么：**把文件放上服务器 → 把目录交给 PHP 的运行用户 → 配一个 Nginx 站点 → 建一个数据库账号 → 浏览器里点完自检和安装。**

1. **上传文件。** 把整个 web-test 目录放进服务器的网站目录，推荐直接用 FinalShell 左侧的文件面板整体拖进去。放哪儿都行，下文统一以 /var/www/web-test 举例。
2. **改目录属主。** 把该目录的所有者改成 PHP 的运行用户（Ubuntu 和 Debian 上是 www-data），否则后面写配置文件、传图片这两步一定会失败。
3. **配置 Nginx。** 套用目录里的通用配置模板，只改三处：站点名（域名或服务器 IP）、站点目录路径、PHP 的 socket 地址；然后校验语法并重载。
4. **建一个数据库账号**（可选）。建一个只对本站数据库有权限的专用账号，不要用 root；这个账号需要建库建表权限，因为安装向导会自己创建数据库和数据表。
5. **打开环境自检页。** 在页面上填一次数据库参数点测试，看 Web 服务器、PHP、MySQL 三段链路是不是都通。
6. **打开动态留言板。** 第一次打开会出现安装向导，填数据库信息和站点信息，它会自动建库建表并生成配置文件。
7. **动手验证一遍。** 发一条留言、带图发一条、点赞、搜索、翻页、进详情页、删除，逐项点过去。
8. **用完删掉。** 这是没有登录的演示站，任何能打开网址的人都能发帖传图；验证完请删除目录和数据库，或者至少加上访问限制。

每一步的具体命令、对照表和常见报错处理，都在下面各节。遇到 404 / 502 / 权限问题，直接看文末「遇到问题」表。

---

## 目录里有什么

```
web-test/
├─ index.php     ① 环境自检页
├─ board.php     ② 动态留言板（含首次安装向导）
├─ api.php       JSON 接口（点赞 / 探活），页面 JS 调用
├─ lib.php       公共库
├─ uploads/      上传的图片放这里（已含 index.html 占位，防目录列表）
├─ config.php    ← 安装留言板时自动生成，不用手工创建
├─ nginx.conf.example   通用 Nginx 配置模板（改 3 处即可用，见下）
├─ README.md     部署与使用说明（本文）
└─ TECH.md       技术原理与实现详解
```

---

## 部署

### 1. 上传并改权限

**先把整个 `web-test` 目录放到服务器上。** 最省事的方式：用 FinalShell 左侧的文件面板，把 `web-test` 文件夹整体拖到 `/var/www/` 下，别用命令行拷相对路径。

如果你已经把它传到了别的位置（比如 `/root/`、`/home/你的用户名/`），用命令挪过去：

```bash
# ① 先查出它到底在哪（可能在 /root、/home 下），路径不确定就先跑这条
sudo find / -maxdepth 4 -type d -name web-test 2>/dev/null

# ② 假设查出来是 /root/web-test，把它整体搬到 /var/www/web-test
#    源路径写 ① 查出来的真实路径；-T 表示"目标已存在时就地合并，不套一层 web-test/web-test"
sudo cp -rT /root/web-test /var/www/web-test

# ③ 核对：应该能看到 index.php、board.php、lib.php、api.php 和 uploads
ls -a /var/www/web-test
```

> ⚠️ **报错 `cp: cannot stat 'web-test/*': No such file or directory` 的原因**
> `cp -r web-test/*` 里的 `web-test` 是"**当前所在目录下**的相对路径"。你当时在 `/root`（root 家目录）下执行，而 `web-test` 并不在 `/root` 里 —— 所以 shell 找不到它。
> 解决：先用第 ① 条查出真实路径，再用**绝对路径**拷贝；或者干脆用 FinalShell 拖拽。
> 另外 `web-test/*` 这种通配不会包含隐藏文件，从别处拷贝时更推荐 `cp -r 源目录 目标目录`。

然后改属主。**PHP 干活时用的身份不是 root、也不是你，而是 `www-data`**，不改这一步，安装留言板会提示"配置文件写入失败"、传图会提示"上传目录不可写"：

```bash
sudo chown -R www-data:www-data /var/www/web-test
sudo find /var/www/web-test -type d -exec chmod 755 {} \;
sudo find /var/www/web-test -type f -exec chmod 644 {} \;
```

> 用 root 登录操作时 `sudo` 可以省略（命令一样能跑），但**属主仍必须是 `www-data`**。
> CentOS / Rocky / Alma 系把 `www-data` 换成 `nginx`。
> 两个页面和 `lib.php`、`api.php` 都放在同一个目录里，`uploads/` 子目录要一起传过去。

**为什么必须改属主**：PHP 干活时用的身份不是 root、也不是你，而是 `www-data`。本站在运行过程中要写三处东西：

| 写入点 | 什么时候写 | 写不了会怎样 |
|---|---|---|
| 站点目录下的 `config.php` | 安装留言板时 | 提示"配置文件写入失败"（数据库其实已经建好了） |
| `uploads/` 目录 | 每次传图片 | 提示"上传目录不可写：…" |
| PHP 的 session 目录（`/var/lib/php/sessions`） | 每次 `session_start()` | 点赞防刷、提示语丢失；自检页会把它标成 WARN |

所以"目录 755、文件 644、属主 www-data"是一套固定搭配：**属主可写、其他用户只读**。

### 2. 配置 Nginx

用同目录的 **[`nginx.conf.example`](nginx.conf.example)** —— 一份通用模板，Ubuntu / Debian / CentOS / Rocky / Alpine 都能套，**只需要改 3 处**：

| 改哪一行 | 改成什么 |
|---|---|
| `server_name` | 你的域名或服务器 IP；不确定就保留 `_`（表示任意） |
| `root` | 第 1 步里 `web-test` 的实际绝对路径，如 `/var/www/web-test` |
| `fastcgi_pass` | php-fpm 的监听地址，三选一（下一行的命令查实际值） |

放到哪（文件名叫什么都行，如 `web-test.conf`；下面假设站点目录是 `/var/www/web-test`，**换成你自己的**）：

```bash
# Debian / Ubuntu：放 sites-available，再软链接到 sites-enabled 启用
sudo cp /var/www/web-test/nginx.conf.example /etc/nginx/sites-available/web-test
sudo ln -s /etc/nginx/sites-available/web-test /etc/nginx/sites-enabled/

# CentOS / Rocky / Alma：放 conf.d 即可，无需软链接
# sudo cp /var/www/web-test/nginx.conf.example /etc/nginx/conf.d/web-test.conf
```

> 软链接那步若提示 **`File exists`**，说明之前已经建过，跳过即可，不用删了重建。
> 查看当前启用了哪些站点：`ls -l /etc/nginx/sites-enabled/`。

两处必须先确认，否则页面会是 404 或 502：

```bash
ls /run/php/                     # ① 看 php-fpm 的 socket 真实文件名（填进 fastcgi_pass）
sudo nginx -t                    # ② 语法校验，看到 test is successful 再继续
sudo systemctl reload nginx
```

> 两个页面都靠 php-fpm 跑，它没启动就是 **502 Bad Gateway**：`sudo systemctl start php8.3-fpm`（把 `8.3` 换成你装的版本，用 `systemctl list-units | grep php` 查）。
>
> web-test 要挂在某个已有站点的子目录下（访问 `http://域名/web-test/`）时，看 `nginx.conf.example` 末尾的「情况 B」。

### 3. 建一个数据库账号（推荐专用账号，别用 root）

如果你手上的账号已经有 `CREATE` 权限，这一步可以**跳过**——安装向导会自己建库建表。想规规矩矩建一个专用账号，在服务器终端执行：

```bash
sudo mysql -e "
CREATE DATABASE IF NOT EXISTS demo_site DEFAULT CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS 'demo'@'localhost' IDENTIFIED BY '换成一个强密码';
CREATE USER IF NOT EXISTS 'demo'@'127.0.0.1' IDENTIFIED BY '换成一个强密码';
GRANT ALL ON demo_site.* TO 'demo'@'localhost', 'demo'@'127.0.0.1';
FLUSH PRIVILEGES;"
```

> **为什么要建两条账号？** MySQL 里 `'demo'@'localhost'` 和 `'demo'@'127.0.0.1'` 是**两条互不相干的账号**：
> `localhost` 只匹配走本机 **socket** 的连接（命令行的 `mysql -udemo -p` 走这条），
> `127.0.0.1` 匹配走 **TCP** 的连接（网页里连数据库走这条，主机填 `127.0.0.1`）。
> 只建前者，网页里就会报 **1045 Access denied** —— 这是最常见的坑，两条都建最省事。
>
> 补充：如果 PHP 版本很老（< 7.4），MySQL 8 默认的 `caching_sha2_password` 它不认，
> 把建账号语句改成 `IDENTIFIED WITH mysql_native_password BY '密码'` 即可。

---

## 怎么用

### 第 1 步：打开环境自检页

访问 **`http://你的域名或IP/`**（顶部导航默认就在「① 环境自检」；如果配成子目录则是 `http://你的域名或IP/web-test/`）。

- 页面会逐项列出 Nginx / PHP 的检测结果，每项带 `PASS` / `WARN` / `FAIL` / `INFO` 标签，顶部有一句总览。
- 想测数据库就在下面的表单里填参数，点「测试数据库」：
  - **主机填 `127.0.0.1`**（不要填服务器对外 IP —— MySQL 默认只监听回环地址，填 IP 会连不上）
  - 端口 `3306`、用户名 `demo`、密码、库名 `demo_site`（库不存在会自动创建）
  - 勾选「测完删除测试表」可让它在验证后自己清理
- 右边有「清空」按钮，清掉你填过的连接信息。

> 这一页填的参数**不会写进任何文件**，只存在当前会话里，关掉浏览器就没了。

### 第 2 步：打开动态留言板

访问 **`http://你的域名或IP/board.php`**。

**首次打开是安装向导**，填两栏：

| 填什么 | 说明 |
|---|---|
| 数据库 | 主机 `127.0.0.1`、端口 `3306`、socket 留空、用户名、密码、库名（不存在会自动创建） |
| 站点名称 + 管理密码（≥6 位） | 管理密码用于删除**没设口令**的留言；会明文写进同目录的 `config.php`，忘了打开这个文件看 `admin_pass` 即可 |

点「开始安装」→ 自动建库建表 → 写好 `config.php` → 直接进入留言板。

> **安装要求目录可写**。若提示"配置文件写入失败"，执行一次
> `sudo chown -R www-data:www-data /var/www/web-test` 再重新提交即可（数据库此时已经建好了，重新提交不会重复建）。

装好之后，按顺序点一遍，就能把整条链路验证完：

1. **发一条留言** —— 试试中英文混排；页脚会显示本次请求耗时和 SQL 条数
2. **带图发一条** —— 选一张 2 MB 以内的图片，页面上会出现预览，发布后图片正常显示
3. **点赞** —— 数字 +1；同一条留言再点会提示"同一会话只能点一次"
4. **搜索** —— 搜刚才留言里的关键词，能筛出结果
5. **翻页** —— 发够 9 条以上就能看到分页，每页 8 条
6. **点「固定链接」** —— 进入单条留言详情页
7. **删除** —— 填发布时设的删除口令；没设口令的留言用安装时的管理密码删
8. **看右侧「运行统计」** —— 留言总数 / 累计点赞 / 今日访问 / 累计访问，每次刷新页面数字都会变

页面顶部那条深色横条是实时环境条（Nginx 版本 / PHP 版本与运行方式 / MySQL 版本与驱动 / 上传目录是否可写 / 静态资源能否加载），打开就知道三段链路各自什么状态。

> 安装后数据库里会多出 `ds_msg`、`ds_visit` 两张表（自检页还会建一张 `lnmp_check` 探针表）——
> 它们各自存什么、字段为什么这么设计、被误删了怎么办，见 [`TECH.md` 第 4 章](TECH.md#4-数据库库表是怎么来的每张表干什么)。

### 附：命令行探活

```bash
curl http://你的域名/api.php?act=ping        # 子目录部署写成 http://你的域名/web-test/api.php?act=ping
```

返回一段 JSON：PHP 版本、SAPI、Web 服务器、MySQL 版本、驱动、库名、留言数、本次耗时。适合挂到监控上。

---

## 遇到问题

先看这里；如果是带 MySQL 错误号的疑难问题，去 [`TECH.md` 第 11 章 排错手册](TECH.md#11-排错手册) 查完整表格。

| 现象 | 怎么办 |
|---|---|
| 上传目录时 `cp: cannot stat 'web-test/*': No such file or directory` | 你当前所在目录里没有 `web-test`。用 `sudo find / -maxdepth 4 -type d -name web-test` 查真实路径，再用绝对路径拷；或直接用 FinalShell 拖拽 |
| 页面 **502 Bad Gateway** | php-fpm 没启动（`systemctl list-units \| grep php` 查到版本后 `sudo systemctl start phpX.Y-fpm`），或 nginx 的 `fastcgi_pass` socket 名写错了（`ls /run/php/` 查实际文件名） |
| 页面 **404 Not Found** | `root` 路径写错，或 `try_files` / `location ~ \.php$` 没配上；对照 `nginx.conf.example` 逐段检查 |
| 页面 **403 Forbidden** | 目录权限不对 —— `sudo chown -R <php-fpm 用户> <站点目录>`（Debian 系 `www-data`，CentOS 系 `nginx`） |
| 打开 `board.php` 一直是安装向导 | 正常 —— 说明还没装或者 `config.php` 被删了；装好之后就会跳过 |
| 安装时提示**连接失败 / 报错号 2002** | MySQL 没启动，或 socket 路径不对；主机改填 `127.0.0.1` |
| 安装时提示**报错号 1045 Access denied** | 账号或密码不对；特别注意 `'demo'@'localhost'` 和 `'demo'@'127.0.0.1'` 是**两个不同账号**，网页走 TCP，只建了前者就会报 1045（见第 3 步） |
| 安装时提示 **配置文件写入失败** | 目录对 php-fpm 用户不可写，执行 `sudo chown -R www-data:www-data 站点目录` 后重新提交 |
| 图片传不上去 | 看页面提示：超过 2 MB / 不是有效图片 / 目录不可写；另外 nginx 的 `client_max_body_size` 别小于 8m |
| 图片传上去了但显示不出来 | `uploads/` 目录有没有一起传？直接访问 `http://你的域名/uploads/图片名`（子目录部署加 `/web-test`）试试 |
| 想重新装一遍留言板 | 删掉站点目录里的 `config.php`，刷新 `board.php` 就会回到安装向导（**留言不会丢**） |
| 忘记管理密码了 | 打开站点目录的 `config.php`，`admin_pass` 那一行就是明文密码 |
| 想清空所有留言重来 | 见 `TECH.md` 第 4.6 节里的 SQL |

> 完整的排查思路（分"部署连接类 / 写入显示类 / 功能数据类"三类，含 MySQL 错误号对照）在 [`TECH.md` 第 11 章](TECH.md#11-排错手册)。

---

## 用完记得删

这是**功能验证用的演示站**：没有登录，任何能打开网址的人都能发帖、传图。验证完请删掉，或者至少加上访问限制。

```bash
# 彻底删除（推荐）：换成你实际的目录与库名
sudo rm -rf /var/www/web-test
sudo mysql -e "DROP DATABASE demo_site;"
```

```nginx
# 或者临时加一道 IP 白名单，只给自己访问（把整段套在你的 location 上）
location ^~ / {
    allow 203.0.113.10;    # 换成你的出口 IP
    deny  all;
    # ... 原有配置
}
```

> `config.php` 里有数据库口令和管理密码原文（本测试站刻意用明文，方便查看），
> 所以务必保证它不能被公网直接下载 —— 这也是 `nginx.conf.example` 里 `location ~ \.php$` 必须配置的原因。
