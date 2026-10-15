<?php
/**
 * ================================================================
 *  LNMP 测试站 · ② 动态留言板
 * ================================================================
 *  一个真正跑在 LNMP 上的小站：留言发布 / 图片上传 / 点赞 / 搜索分页
 *  数据全部读写 MySQL，用来验证 Nginx + PHP + MySQL 全链路可行性。
 *
 *  路由：
 *    ?                      列表（支持 ?q=关键词&page=N）
 *    ?act=detail&id=N       单条详情（可配合 nginx rewrite 做 /note/N）
 *    POST act=post          发布留言（含图片上传）
 *    POST act=del           删除留言（发布时设的口令，或安装时的管理密码）
 *
 *  口令说明：管理密码与留言的删除口令一律【明文】存储（config.php / ds_msg.pw_hash 可直接看到），
 *            便于本测试站随时查看；校验函数 ds_pw_match() 同时兼容老版本的 bcrypt 哈希。
 *    POST do_install        首次安装向导
 *
 *  公共库在 lib.php；环境自检在同目录 index.php。
 * ================================================================
 */

require __DIR__ . '/lib.php';
@session_start();

$self = ds_self();
$base = str_replace('\\', '/', dirname($self));
$base = rtrim($base, '/');
if ($base === '.') { $base = ''; }

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$flash  = '';
if (isset($_SESSION['flash'])) { $flash = (string) $_SESSION['flash']; unset($_SESSION['flash']); }

/* ================================================================
 *  页面骨架（样式内联，不再依赖 assets/ 子目录）
 * ================================================================ */

function board_css() {
    return <<<'CSS'
*{box-sizing:border-box}
body{margin:0;background:#f4f6fa;color:#1f2937;
     font:14px/1.65 -apple-system,"Segoe UI","Microsoft YaHei",Roboto,Helvetica,Arial,sans-serif}
a{color:#2563eb;text-decoration:none}
a:hover{text-decoration:underline}
code{background:#eef2f7;padding:1px 5px;border-radius:4px;font-family:Consolas,Monaco,monospace;font-size:12.5px;word-break:break-all}
.wrap{max-width:1040px;margin:0 auto;padding:0 16px}

/* ---------- 顶部导航（两个页面共用） ---------- */
.nav{background:#fff;border-bottom:1px solid #e5e7eb}
.nav .wrap{display:flex;gap:4px}
.nav a{display:inline-block;padding:10px 14px;font-size:13px;color:#6b7280;border-bottom:2px solid transparent}
.nav a:hover{color:#2563eb;text-decoration:none}
.nav a.on{color:#2563eb;border-bottom-color:#2563eb;font-weight:600}

/* ---------- 头部 ---------- */
header.top{background:linear-gradient(120deg,#0f172a,#1e3a8a);color:#fff;padding:20px 0 16px}
header.top h1{margin:0;font-size:21px;font-weight:600;display:flex;align-items:center;gap:10px}
header.top h1 a{color:#fff}
header.top h1 a:hover{text-decoration:none;opacity:.9}
header.top .tag{font-size:11px;font-weight:700;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.3);
     padding:2px 8px;border-radius:20px;letter-spacing:.5px}
header.top .sub{margin:6px 0 0;color:#a5b4d4;font-size:12.5px}

/* ---------- 环境条（LNMP 自检） ---------- */
.envbar{background:#0b1222;color:#cbd5e1;border-bottom:1px solid #1f2937}
.envbar .wrap{display:flex;flex-wrap:wrap;gap:8px;padding:9px 16px}
.pill{font-size:12px;padding:3px 10px;border-radius:20px;background:rgba(255,255,255,.07);
      border:1px solid rgba(255,255,255,.12);white-space:nowrap}
.pill b{font-weight:600}
.pill.ok{color:#86efac;border-color:rgba(134,239,172,.35)}
.pill.warn{color:#fcd34d;border-color:rgba(252,211,77,.35)}

/* ---------- 布局 ---------- */
.layout{display:grid;grid-template-columns:minmax(0,1.75fr) minmax(280px,1fr);gap:16px;padding:16px}
@media (max-width:860px){.layout{grid-template-columns:1fr}}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px;margin-bottom:14px}
.card h2{margin:0 0 12px;font-size:15px;display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
.card h2 em{font-style:normal;font-weight:400;color:#6b7280;font-size:12.5px}

/* ---------- 表单 ---------- */
label{display:block;font-size:12.5px;color:#4b5563;margin:10px 0 4px}
input[type=text],input[type=password],input[type=file],textarea{
    width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13.5px;
    font-family:inherit;background:#fff;color:#111827}
textarea{resize:vertical;min-height:96px}
input:focus,textarea:focus{outline:0;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.13)}
.form{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
@media (max-width:680px){.form{grid-template-columns:1fr 1fr}}
.actions{margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
button{padding:8px 16px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-size:13.5px;
       cursor:pointer;font-family:inherit;transition:background .15s}
button:hover{background:#1d4ed8}
button.ghost{background:#fff;color:#374151;border:1px solid #d1d5db}
button.ghost:hover{background:#f9fafb}
.prev{margin-top:8px}
.prev img{max-width:100%;max-height:150px;border-radius:8px;border:1px solid #e5e7eb}

/* ---------- 搜索 ---------- */
.search{display:flex;gap:8px;align-items:center;margin-bottom:14px}
.search input{flex:1}

/* ---------- 留言 ---------- */
.msg{display:flex;gap:12px;padding:14px 0;border-top:1px solid #f1f5f9}
.msg:first-of-type{border-top:0}
.msg .avatar{flex:0 0 38px;height:38px;border-radius:10px;background:#e0e7ff;color:#3730a3;
     display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px}
.msg .body{flex:1;min-width:0}
.msg .meta{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;font-size:13px}
.msg .meta b{color:#111827}
.msg .time{color:#9ca3af;font-size:12px}
.msg .no{color:#c7cdd6;font-size:12px}
.msg .content{margin:6px 0 8px;white-space:pre-wrap;word-break:break-word;color:#374151}
.msg.big .content{font-size:15px}
.thumb{display:inline-block;margin-bottom:8px}
.thumb img{max-width:100%;max-height:260px;border-radius:10px;border:1px solid #e5e7eb;display:block}
.ops{display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:12.5px}
.like{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;padding:4px 11px;font-size:12.5px}
.like:hover{background:#eef2ff;border-color:#c7d2fe;color:#4338ca}
.like.done{background:#eef2ff;border-color:#c7d2fe;color:#4338ca}
.delf{display:flex;gap:6px;align-items:center}
.delf input{padding:4px 8px;font-size:12.5px;width:120px}
.delf button{padding:4px 11px;font-size:12.5px}
.plink{font-size:12.5px;color:#6b7280}
.plink:hover{color:#2563eb}
.empty{color:#9ca3af;text-align:center;padding:26px 0}

/* ---------- 分页 ---------- */
.pager{display:flex;align-items:center;justify-content:space-between;gap:10px;
       border-top:1px solid #f1f5f9;padding-top:13px;margin-top:6px;font-size:13px}
.pager .pg{color:#6b7280;font-size:12.5px}
.pager .dis{color:#cbd5e1}

/* ---------- 侧栏统计 ---------- */
.stats{list-style:none;margin:0;padding:0}
.stats li{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-top:1px dashed #eef2f7}
.stats li:first-child{border-top:0}
.stats span{color:#6b7280;font-size:13px}
.stats b{font-size:16px;color:#1d4ed8}

/* ---------- 提示 ---------- */
.note{margin-top:10px;font-size:12.5px;color:#6b7280;line-height:1.75}
.alert{padding:11px 14px;border-radius:10px;margin-bottom:14px;font-size:13.5px}
.alert.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.alert.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.tip{margin-top:8px;padding:8px 11px;background:#fffbeb;border-left:3px solid #f59e0b;
     border-radius:0 7px 7px 0;color:#92400e;font-size:12.5px;line-height:1.7}
.howto{margin:0;padding-left:20px;color:#374151;font-size:13px;line-height:1.9}
.howto li{margin-bottom:4px}

/* ---------- 页脚 ---------- */
footer.foot{padding:6px 16px 40px;color:#9ca3af;font-size:12px;text-align:center}
footer.foot p{margin:4px 0}
CSS;
}

/** 输出 <head> 并打开 <body> */
function board_open($title) {
    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($title); ?></title>
<style><?php echo board_css(); ?></style>
</head>
<body>
<?php
}

/* ================================================================
 *  一、安装向导（config.php 不存在时）
 * ================================================================ */
if (!cfg_ready()) {
    $form = array(
        'host' => '127.0.0.1', 'port' => '3306', 'socket' => '',
        'user' => 'demo', 'pass' => '', 'name' => 'demo_site',
        'site' => 'LNMP 留言板', 'admin' => '',
    );
    $err = ''; $errTip = '';

    if ($method === 'POST' && isset($_POST['do_install'])) {
        foreach (array_keys($form) as $k) { $form[$k] = ds_post($k); }
        if ($form['host'] === '') { $form['host'] = '127.0.0.1'; }
        if ($form['port'] === '') { $form['port'] = '3306'; }
        if ($form['name'] === '') { $form['name'] = 'demo_site'; }

        if ($form['user'] === '') {
            $err = '请填写数据库账号。';
        } elseif (strlen($form['admin']) < 6) {
            $err = '管理密码至少 6 位（用于删除留言、重新配置）。';
        } else {
            $cfg = array(
                'site_name'    => ($form['site'] !== '' ? $form['site'] : DS_APP),
                'db'           => array(
                    'host'   => $form['host'],
                    'port'   => (int) $form['port'],
                    'socket' => $form['socket'],
                    'user'   => $form['user'],
                    'pass'   => $form['pass'],
                    'name'   => ds_safe_dbname($form['name']),
                ),
                'admin_pass'   => ds_pw_store($form['admin']),   // 明文存储，方便随时查看（见下方提示）
                'admin_pass_plain' => true,                       // 标记：这条配置里存的是明文
                'installed_at' => ds_now(),
            );

            list($tryDb, $no, $msg2) = db_connect_cfg($cfg['db']);
            if (!$tryDb) {
                $err    = '连接 MySQL 失败：' . $msg2 . '（错误号 ' . (int) $no . '）';
                $errTip = db_tip($no);
            } else {
                list($ok, $emsg) = ds_setup_schema($tryDb, $cfg['db']['name']);
                if (!$ok) {
                    $err = $emsg;
                } elseif (!cfg_write($cfg)) {
                    $err    = '数据库已建好，但配置文件写入失败（目录不可写）。';
                    $errTip = '在服务器上执行一次：<code>sudo chown -R www-data:www-data ' . h(DS_DIR) . '</code>，然后重新提交本表单即可。';
                } else {
                    $_SESSION['flash'] = '安装完成：数据库与数据表已就绪。管理密码「' . $form['admin'] . '」已明文写入 config.php（字段 admin_pass）。';
                    header('Location: ' . $self);
                    exit;
                }
            }
        }
    }

    /* ---------- 安装页 ---------- */
    board_open('安装 · ' . DS_APP);
    ?>
<header class="top"><div class="wrap">
    <h1>安装 <?php echo h(DS_APP); ?></h1>
    <p class="sub">填一次数据库信息即可 —— 会自动建库、建表，并把配置写进同目录的 config.php</p>
</div></header>
<?php echo nav_html(2); ?>

<div class="wrap" style="padding-top:20px;max-width:720px">
    <?php if ($err !== ''): ?>
    <div class="alert err"><b>安装失败：</b><?php echo h($err); ?>
        <?php if ($errTip !== ''): ?><div class="tip"><?php echo $errTip; ?></div><?php endif; ?>
    </div>
    <?php endif; ?>

    <form class="card" method="post" action="<?php echo h($self); ?>">
        <h2>数据库</h2>
        <div class="form">
            <div><label>主机</label><input type="text" name="host" value="<?php echo h($form['host']); ?>" placeholder="127.0.0.1"></div>
            <div><label>端口</label><input type="text" name="port" value="<?php echo h($form['port']); ?>" placeholder="3306"></div>
            <div><label>socket 路径（可选）</label><input type="text" name="socket" value="<?php echo h($form['socket']); ?>" placeholder="/run/mysqld/mysqld.sock"></div>
            <div><label>用户名</label><input type="text" name="user" value="<?php echo h($form['user']); ?>" placeholder="建议新建专用账号"></div>
            <div><label>密码</label><input type="password" name="pass" value="<?php echo h($form['pass']); ?>" autocomplete="new-password"></div>
            <div><label>库名</label><input type="text" name="name" value="<?php echo h($form['name']); ?>" placeholder="不存在会自动创建"></div>
        </div>
        <p class="note">账号需要 <code>CREATE</code>（建库建表）、<code>INSERT/SELECT/UPDATE/DELETE</code> 权限。
        一键建号（在 VPS 终端执行，把密码换掉）：<br>
        <code>sudo mysql -e "CREATE DATABASE IF NOT EXISTS demo_site DEFAULT CHARSET utf8mb4; CREATE USER IF NOT EXISTS 'demo'@'localhost' IDENTIFIED BY '换成你的密码'; GRANT ALL ON demo_site.* TO 'demo'@'localhost'; FLUSH PRIVILEGES;"</code></p>

        <h2 style="margin-top:22px">站点</h2>
        <div class="form">
            <div><label>站点名称</label><input type="text" name="site" value="<?php echo h($form['site']); ?>"></div>
            <div><label>管理密码（≥6 位）</label><input type="text" name="admin" value="" autocomplete="new-password" placeholder="用于删除留言 / 重新配置"></div>
        </div>
        <p class="note">管理密码会以 <b>明文</b> 写进同目录的 <code>config.php</code>（字段 <code>admin_pass</code>），忘了随时打开看即可 —— 本测试站刻意用明文，公网生产环境请自行改回哈希。</p>

        <div class="actions">
            <button type="submit" name="do_install" value="1">开始安装</button>
        </div>
    </form>

    <p class="foot">安装前请确认：① php-fpm 在运行 ② 本目录对 www-data 可写（<code>sudo chown -R www-data:www-data <?php echo h(DS_DIR); ?></code>）③ 已安装 php-mysql 扩展</p>
</div>
</body>
</html>
    <?php
    exit;
}

/* ================================================================
 *  二、连接数据库（表不存在会自动补建）
 * ================================================================ */
$config   = cfg_read();
$siteName = (isset($config['site_name']) && $config['site_name'] !== '') ? $config['site_name'] : DS_APP;
$adminPw = isset($config['admin_pass']) ? (string) $config['admin_pass'] : '';
$dbName   = ds_safe_dbname($config['db']['name']);

list($db, $no, $msg, $driver) = db_connect_cfg($config['db']);
if (!$db) {
    ds_render_db_error('连接失败', $msg, (int) $no, $driver, $config);
    exit;
}
if ($db->exec('USE `' . $dbName . '`') === false) {
    // 库被删了 → 尝试自动重建
    list($ok, $emsg) = ds_setup_schema($db, $dbName);
    if (!$ok) {
        ds_render_db_error('数据库不可用', $emsg, $db->errcode, $driver, $config);
        exit;
    }
} else {
    // 库在，但可能有表被手工删了（比如在 phpMyAdmin 里误删）→ 用一条 SHOW TABLES 检查并补建
    $tbls = array();
    foreach ($db->rows('SHOW TABLES') as $r) {
        $v = array_values($r);
        if (isset($v[0])) { $tbls[strtolower((string) $v[0])] = 1; }
    }
    if (!isset($tbls[strtolower(DS_TABLE_MSG)]) || !isset($tbls[strtolower(DS_TABLE_VISIT)])) {
        list($ok, $emsg) = ds_setup_schema($db, $dbName);
        if (!$ok) {
            ds_render_db_error('数据表不可用', $emsg, $db->errcode, $driver, $config);
            exit;
        }
    }
}

/* ================================================================
 *  三、写操作（PRG 模式：处理完重定向，避免刷新重复提交）
 * ================================================================ */
$keep = array('name' => '', 'content' => '');
$err  = '';

if ($method === 'POST') {
    $pact = ds_post('act');

    /* ---------- 发布留言 ---------- */
    if ($pact === 'post') {
        $name    = ds_post('name');
        $content = ds_post('content');
        $pw      = ds_post('pw');
        $keep    = array('name' => $name, 'content' => $content);

        $nameLen = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        $contLen = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);

        if ($name === '' || trim($content) === '') {
            $err = '昵称和内容都不能为空。';
        } elseif ($nameLen > 20) {
            $err = '昵称最多 20 个字（当前 ' . $nameLen . '）。';
        } elseif ($contLen > 500) {
            $err = '内容最多 500 个字（当前 ' . $contLen . '）。';
        } elseif ($pw !== '' && strlen($pw) < 4) {
            $err = '删除口令至少 4 位字符，或者留空（留空则只能用管理密码删除）。';
        } else {
            $imgErr = '';
            $image  = ds_upload_image('image', $imgErr);
            if ($imgErr !== '') {
                $err = $imgErr;
            } else {
                $sql = 'INSERT INTO `' . DS_TABLE_MSG . '` (`name`,`content`,`image`,`likes`,`ip`,`pw_hash`,`created_at`)'
                     . ' VALUES (?,?,?,0,?,?,?)';
                $ret = $db->exec($sql, array(
                    $name, $content, $image, ds_client_ip(),
                    ($pw !== '' ? ds_pw_store($pw) : ''),
                    ds_now(),
                ));
                if ($ret === false) {
                    if ($image !== '') { @unlink(DS_DIR . '/' . $image); }   // 入库失败则不留孤儿文件
                    $err = '写入 MySQL 失败：' . $db->err;
                } else {
                    $_SESSION['flash'] = '发布成功，已写入 MySQL' . ($image !== '' ? '（图片保存到 uploads/，由 Nginx 直接对外提供）' : '') . '。';
                    header('Location: ' . $self);
                    exit;
                }
            }
        }
    }

    /* ---------- 删除留言 ---------- */
    if ($pact === 'del') {
        $id  = (int) ds_post('id');
        $pw  = ds_post('del_pw');
        $row = $db->row('SELECT `id`,`image`,`pw_hash` FROM `' . DS_TABLE_MSG . '` WHERE `id` = ? LIMIT 1', array($id));

        if (!$row) {
            $err = '这条留言不存在（可能已被删除）。';
        } else {
            $isAdmin = ($pw !== '' && $adminPw !== '' && ds_pw_match($pw, $adminPw));
            $isOwner = ($pw !== '' && $row['pw_hash'] !== '' && ds_pw_match($pw, $row['pw_hash']));

            if (!$isAdmin && !$isOwner) {
                $err = ($row['pw_hash'] === '' && !$isAdmin)
                    ? '这条留言发布时没有设置删除口令，需要输入安装时的管理密码才能删除。'
                    : '删除口令或管理密码不正确。';
            } elseif ($db->exec('DELETE FROM `' . DS_TABLE_MSG . '` WHERE `id` = ?', array($id)) === false) {
                $err = '删除失败：' . $db->err;
            } else {
                if ($row['image'] !== '' && strpos($row['image'], 'uploads/') === 0) {
                    @unlink(DS_DIR . '/' . $row['image']);   // 顺带清理图片文件
                }
                $_SESSION['flash'] = '已删除留言 #' . (int) $id . '（并清理了对应图片文件）。';
                header('Location: ' . $self);
                exit;
            }
        }
    }
}

/* ================================================================
 *  四、读操作：访问记录 / 统计 / 列表或详情
 * ================================================================ */
$db->exec('INSERT INTO `' . DS_TABLE_VISIT . '` (`path`,`ip`,`ua_hash`,`created_at`) VALUES (?,?,?,?)',
    array(substr(ds_get('act', 'list'), 0, 120), ds_client_ip(), ds_ua_hash(), ds_now()));

$stats = $db->row('SELECT'
    . ' (SELECT COUNT(*) FROM `' . DS_TABLE_MSG . '`) AS msgs,'
    . ' (SELECT COALESCE(SUM(`likes`),0) FROM `' . DS_TABLE_MSG . '`) AS likes,'
    . ' (SELECT COUNT(*) FROM `' . DS_TABLE_VISIT . '` WHERE `created_at` >= ?) AS today,'
    . ' (SELECT COUNT(*) FROM `' . DS_TABLE_VISIT . '`) AS visits',
    array(date('Y-m-d') . ' 00:00:00'));

$mysqlVer = $db->one('SELECT VERSION()');
$mysqlVer = $mysqlVer === null ? '未知' : $mysqlVer;

$act     = ds_get('act', 'list');
$q       = ds_get('q');
$page    = (int) ds_get('page', '1');
if ($page < 1) { $page = 1; }

$items   = array();
$total   = 0;
$pages   = 1;
$detail  = null;

if ($act === 'detail') {
    $detail = $db->row('SELECT * FROM `' . DS_TABLE_MSG . '` WHERE `id` = ? LIMIT 1', array((int) ds_get('id', '0')));
} else {
    $where  = '';
    $params = array();
    if ($q !== '') {
        $where  = ' WHERE (`content` LIKE ? OR `name` LIKE ?)';
        $params = array('%' . $q . '%', '%' . $q . '%');
    }
    $total = (int) $db->one('SELECT COUNT(*) FROM `' . DS_TABLE_MSG . '`' . $where, $params);
    $pages = max(1, (int) ceil($total / DS_PER_PAGE));
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * DS_PER_PAGE;
    $items  = $db->rows(
        'SELECT * FROM `' . DS_TABLE_MSG . '`' . $where . ' ORDER BY `id` DESC LIMIT ' . (int) DS_PER_PAGE . ' OFFSET ' . (int) $offset,
        $params
    );
}

$webSw    = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
$isNginx  = (stripos($webSw, 'nginx') !== false);
$isFpm    = (php_sapi_name() === 'fpm-fcgi');
$uploadsOk = @is_writable(DS_DIR . '/uploads') || @is_writable(DS_DIR);

/* ================================================================
 *  五、渲染
 * ================================================================ */
board_open($siteName . ($detail ? ' · #' . (int) $detail['id'] : ''));
?>
<header class="top"><div class="wrap">
    <h1><a href="<?php echo h($self); ?>"><?php echo h($siteName); ?></a><span class="tag">动态站</span></h1>
    <p class="sub">留言发布 · 图片上传 · 点赞 · 搜索分页 —— 数据全部实时读写 MySQL</p>
</div></header>

<div class="envbar"><div class="wrap">
    <span class="pill <?php echo $isNginx ? 'ok' : 'warn'; ?>">Nginx <b><?php echo h($webSw !== '' ? $webSw : '未识别'); ?></b></span>
    <span class="pill <?php echo $isFpm ? 'ok' : 'warn'; ?>">PHP <b><?php echo h(PHP_VERSION); ?></b> · <?php echo h(php_sapi_name()); ?></span>
    <span class="pill ok">MySQL <b><?php echo h($mysqlVer); ?></b> · <?php echo h($driver); ?></span>
    <span class="pill <?php echo $uploadsOk ? 'ok' : 'warn'; ?>">上传目录 <b><?php echo $uploadsOk ? '可写' : '不可写'; ?></b></span>
    <span class="pill" id="js-static">静态资源 检测中…</span>
</div></div>
<?php echo nav_html(2); ?>

<div class="wrap layout">
    <main>
        <?php if ($flash !== ''): ?><div class="alert ok"><?php echo h($flash); ?></div><?php endif; ?>
        <?php if ($err !== ''): ?><div class="alert err"><?php echo h($err); ?></div><?php endif; ?>

        <?php if ($detail): ?>
        <section class="card">
            <h2>留言 #<?php echo (int) $detail['id']; ?></h2>
            <?php echo ds_render_msg($detail, $base, true); ?>
            <p style="margin-top:14px"><a class="plink" href="<?php echo h($base); ?>/board.php">← 返回列表</a></p>
        </section>
        <?php else: ?>
        <section class="card">
            <h2>留言板 <em>共 <?php echo (int) $total; ?> 条<?php echo $q !== '' ? '，匹配「' . h($q) . '」' : ''; ?></em></h2>

            <form class="search" method="get" action="<?php echo h($self); ?>">
                <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="搜索昵称或内容（MySQL LIKE 查询）">
                <button type="submit">搜索</button>
                <?php if ($q !== ''): ?><a class="plink" href="<?php echo h($self); ?>">清除</a><?php endif; ?>
            </form>

            <?php if (!$items): ?>
                <p class="empty">还没有<?php echo $q !== '' ? '匹配的' : ''; ?>留言，在右边发第一条试试。</p>
            <?php else: ?>
                <?php foreach ($items as $m) { echo ds_render_msg($m, $base, false); } ?>

                <nav class="pager">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo h(ds_url(array('page' => $page - 1))); ?>">← 上一页</a>
                    <?php else: ?><span class="dis">← 上一页</span><?php endif; ?>
                    <span class="pg">第 <?php echo (int) $page; ?> / <?php echo (int) $pages; ?> 页 · 每页 <?php echo DS_PER_PAGE; ?> 条</span>
                    <?php if ($page < $pages): ?>
                        <a href="<?php echo h(ds_url(array('page' => $page + 1))); ?>">下一页 →</a>
                    <?php else: ?><span class="dis">下一页 →</span><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>

    <aside>
        <section class="card">
            <h2>发一条留言</h2>
            <form method="post" action="<?php echo h($self); ?>" enctype="multipart/form-data">
                <input type="hidden" name="act" value="post">
                <label>昵称</label>
                <input type="text" name="name" maxlength="20" value="<?php echo h($keep['name']); ?>" required placeholder="最多 20 字">
                <label>内容</label>
                <textarea name="content" rows="5" maxlength="500" required placeholder="最多 500 字"><?php echo h($keep['content']); ?></textarea>
                <label>配图（可选，≤2 MB）</label>
                <input type="file" name="image" accept="image/*" id="img-file">
                <div id="img-prev" class="prev"></div>
                <label>删除口令（可选，≥4 位）</label>
                <input type="text" name="pw" maxlength="40" autocomplete="new-password" placeholder="留空则只能用管理密码删">
                <div class="actions"><button type="submit">发布到 MySQL</button></div>
            </form>
            <p class="note">这条链路会依次用到：<b>PHP</b> 表单处理 → <b>文件上传</b>（写入 uploads/）→ <b>MySQL</b> INSERT。<br>
            图片上传后由 <b>Nginx</b> 直接以静态文件方式返回（不经过 PHP）。</p>
        </section>

        <section class="card">
            <h2>运行统计 <em>实时查库</em></h2>
            <ul class="stats">
                <li><span>留言总数</span><b><?php echo (int) $stats['msgs']; ?></b></li>
                <li><span>累计点赞</span><b><?php echo (int) $stats['likes']; ?></b></li>
                <li><span>今日访问</span><b><?php echo (int) $stats['today']; ?></b></li>
                <li><span>累计访问</span><b><?php echo (int) $stats['visits']; ?></b></li>
            </ul>
            <p class="note">每次打开本页都会往 <code><?php echo h(DS_TABLE_VISIT); ?></code> 写一条记录并回查统计 —— 写与读都在同一次请求里发生。</p>
        </section>

        <section class="card">
            <h2>环境自检</h2>
            <p class="note">想看 Nginx / PHP / MySQL 逐项状态、或单独测一个数据库连接，去 <a href="index.php">① 环境自检</a> 页。</p>
        </section>
    </aside>
</div>

<footer class="foot wrap">
    <p>
        本次请求 <b><?php echo ds_ms(DS_APP_START); ?> ms</b>
        · SQL <b><?php echo (int) $GLOBALS['DS_SQL_COUNT']; ?></b> 条（<?php echo round($GLOBALS['DS_SQL_TIME'] * 1000, 1); ?> ms）
        · 数据库 <code><?php echo h($dbName); ?></code>
        · 表 <code><?php echo h(DS_TABLE_MSG . ' / ' . DS_TABLE_VISIT); ?></code>
    </p>
    <p>伪静态示例：把 nginx 里的 <code>/web-test/note/3</code> 重写到 <code>/web-test/board.php?act=detail&amp;id=3</code>（配置见 README）</p>
</footer>

<script>window.DS_BASE = "<?php echo h($base); ?>";</script>
<script>
/* ================================================================
 *  留言板前端脚本
 *  1) 点赞：fetch 调用 api.php，走「JS → PHP → MySQL → JSON」链路
 *  2) 删除确认  3) 图片选择预览  4) 静态资源自检
 * ================================================================ */
(function () {
    'use strict';

    /* 站点根路径（由页面注入，兼容 nginx 子目录部署） */
    var BASE = (typeof window.DS_BASE === 'string') ? window.DS_BASE : '';

    /* ---------- 1. 点赞（JSON 接口） ---------- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.like') : null;
        if (!btn) { return; }
        if (btn.classList.contains('done')) { return; }

        var id = btn.getAttribute('data-id');
        var num = btn.querySelector('.n');
        btn.disabled = true;

        var body = new URLSearchParams();
        body.append('act', 'like');
        body.append('id', id);

        fetch(BASE + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json();
        }).then(function (d) {
            btn.disabled = false;
            if (d.likes !== undefined && num) { num.textContent = d.likes; }
            if (d.ok) {
                btn.classList.add('done');
                btn.title = d.msg || '已点赞';
            } else {
                btn.title = d.msg || '点赞失败';
                flash(btn, d.msg || '点赞失败', 'err');
            }
        }).catch(function () {
            btn.disabled = false;
            flash(btn, '请求 api.php 失败', 'err');
        });
    });

    function flash(el, msg, kind) {
        var box = document.createElement('span');
        box.textContent = ' · ' + msg;
        box.style.fontSize = '12px';
        box.style.color = (kind === 'err') ? '#b91c1c' : '#6b7280';
        el.parentNode.appendChild(box);
        setTimeout(function () { if (box.parentNode) { box.parentNode.removeChild(box); } }, 3500);
    }

    /* ---------- 2. 删除确认 ---------- */
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f.classList || !f.classList.contains('delf')) { return; }
        var pw = f.querySelector('input[name=del_pw]');
        if (pw && pw.value === '') {
            e.preventDefault();
            pw.focus();
            alert('请先填写删除口令（或安装时设置的管理密码）');
            return;
        }
        if (!confirm('确定删除这条留言吗？此操作不可撤销。')) { e.preventDefault(); }
    });

    /* ---------- 3. 图片预览 ---------- */
    var file = document.getElementById('img-file');
    var prev = document.getElementById('img-prev');
    if (file && prev && window.FileReader) {
        file.addEventListener('change', function () {
            prev.innerHTML = '';
            var f = file.files && file.files[0];
            if (!f) { return; }
            if (f.size > 2 * 1024 * 1024) {
                prev.innerHTML = '<span style="color:#b91c1c;font-size:12.5px">图片超过 2 MB，请压缩后再上传</span>';
                return;
            }
            var r = new FileReader();
            r.onload = function (ev) {
                var img = document.createElement('img');
                img.src = ev.target.result;
                prev.appendChild(img);
            };
            r.readAsDataURL(f);
        });
    }

    /* ---------- 4. 静态资源自检（验证 Nginx 直接返回静态文件） ---------- */
    var pill = document.getElementById('js-static');
    if (pill) {
        var probe = BASE + '/uploads/index.html';
        var t0 = (window.performance && performance.now) ? performance.now() : Date.now();
        fetch(probe + '?_=' + Date.now(), { cache: 'no-store' }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.text();
        }).then(function (txt) {
            var dt = Math.round(((window.performance && performance.now) ? performance.now() : Date.now()) - t0);
            var ok = txt.length > 0;
            pill.className = 'pill ' + (ok ? 'ok' : 'warn');
            pill.innerHTML = '静态资源 <b>' + (ok ? '正常' : '异常') + '</b> · uploads ' + txt.length + ' B / ' + dt + ' ms';
        }).catch(function (e) {
            pill.className = 'pill warn';
            pill.innerHTML = '静态资源 <b>请求失败</b>（' + e.message + '）';
        });
    }
})();
</script>
</body>
</html>
<?php

/* ================================================================
 *  渲染辅助
 * ================================================================ */

/** 单条留言的 HTML */
function ds_render_msg($m, $base, $big = false) {
    $id      = (int) $m['id'];
    $initial = function_exists('mb_substr') ? mb_substr($m['name'], 0, 1, 'UTF-8') : substr($m['name'], 0, 1);
    $html  = '<article class="msg' . ($big ? ' big' : '') . '">';
    $html .= '<div class="avatar">' . h($initial) . '</div>';
    $html .= '<div class="body">';
    $html .= '<div class="meta"><b>' . h($m['name']) . '</b>'
           . '<span class="time">' . h($m['created_at']) . '</span>'
           . '<span class="no">#' . $id . '</span></div>';
    $html .= '<p class="content">' . nl2br(h($m['content'])) . '</p>';

    if ($m['image'] !== '') {
        $src = $base . '/' . $m['image'];
        $html .= '<a class="thumb" href="' . h($src) . '" target="_blank" rel="noopener">'
               . '<img src="' . h($src) . '" alt="配图" loading="lazy"></a>';
    }

    $html .= '<div class="ops">';
    $html .= '<button type="button" class="like" data-id="' . $id . '">♡ 点赞 <span class="n">' . (int) $m['likes'] . '</span></button>';
    $html .= '<form class="delf" method="post" action="' . h(ds_self()) . '">'
           . '<input type="hidden" name="act" value="del"><input type="hidden" name="id" value="' . $id . '">'
           . '<input type="text" name="del_pw" placeholder="删除口令" required autocomplete="off">'
           . '<button type="submit" class="ghost">删除</button>'
           . '</form>';
    $html .= '<a class="plink" href="' . h(ds_url(array('act' => 'detail', 'id' => $id))) . '">固定链接</a>';
    $html .= '</div></div></article>';
    return $html;
}

/** 数据库异常时的整页错误提示 */
function ds_render_db_error($title, $msg, $no, $driver, $config) {
    $dir = DS_DIR;
    board_open('数据库异常 · ' . DS_APP);
    ?>
<header class="top"><div class="wrap"><h1>数据库异常</h1><p class="sub"><?php echo h($title); ?></p></div></header>
<?php echo nav_html(2); ?>

<div class="wrap" style="padding-top:20px;max-width:760px">
    <div class="alert err">
        <b><?php echo h($msg); ?></b>
        <div style="margin-top:6px;font-size:13px">错误号 <?php echo (int) $no; ?> · 驱动 <?php echo h($driver); ?>
        · 目标 <code><?php echo h($config['db']['user'] . '@' . $config['db']['host'] . '/' . $config['db']['name']); ?></code></div>
        <div class="tip"><?php echo db_tip($no); ?></div>
    </div>
    <div class="card">
        <h2>怎么修</h2>
        <ol class="howto">
            <li>确认 MySQL 在跑：<code>systemctl status mysql</code>（或 <code>mariadb</code>）</li>
            <li>确认账号密码正确，且有该库权限：<code>mysql -u<?php echo h($config['db']['user']); ?> -p<?php echo h($config['db']['name']); ?></code></li>
            <li>本机自测时主机请填 <code>127.0.0.1</code>（不要填服务器对外 IP，MySQL 默认只监听回环地址）</li>
            <li>改完配置：删除 <code><?php echo h(cfg_file()); ?></code> 后刷新本页，会重新进入安装向导</li>
        </ol>
        <p class="note">当前配置文件：<code><?php echo h(cfg_file()); ?></code>
        <?php echo is_writable($dir) ? '（目录可写，可自动重装）' : '（目录不可写：<code>sudo chown -R www-data:www-data ' . h($dir) . '</code>）'; ?></p>
    </div>
</div>
</body>
</html>
    <?php
}
