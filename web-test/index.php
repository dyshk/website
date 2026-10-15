<?php
/**
 * ================================================================
 *  LNMP 测试站 · ① 环境自检
 * ================================================================
 *  Nginx / PHP / MySQL 三段链路一次性验证。
 *
 *  · 不需要登录，打开即看结果
 *  · 数据库参数在页面上填，点按钮即时测试（连接 → 建库 → 建表 → 写 → 读）
 *  · 本页不写任何文件
 *  · 如果 ② 动态留言板 已经装过（同目录有 config.php），会自动预填连接参数，
 *    只需补一个密码就能测
 *
 *  公共库在 lib.php；留言板在同目录 board.php。
 * ================================================================
 */

require __DIR__ . '/lib.php';
@session_start();

define('APP_START', DS_APP_START);

/* ==================== 本页专用的小工具 ==================== */

function badge($st) {
    $map = array('PASS' => 'pass', 'WARN' => 'warn', 'FAIL' => 'fail', 'SKIP' => 'skip', 'INFO' => 'info');
    $c = isset($map[$st]) ? $map[$st] : 'info';
    return '<span class="badge ' . $c . '">' . h($st) . '</span>';
}

function row($name, $st, $msg, $tip = '') {
    $html  = '<div class="row">' . badge($st) . '<span class="k">' . h($name) . '</span><span class="v">' . $msg . '</span></div>';
    if ($tip !== '') { $html .= '<div class="tip">' . $tip . '</div>'; }
    return $html;
}

function code($s) { return '<code>' . h($s) . '</code>'; }

/* ==================== MySQL 全链路测试 ==================== */

/**
 * 连接 → 建库 → 建表 → 写入 → 读取
 * 返回 array( array(状态, 项目, 说明[, 提示]) )
 */
function db_test($cfg) {
    $out = array();

    $need = array();
    if ($cfg['user'] === '') { $need[] = '账号'; }
    if ($cfg['name'] === '') { $need[] = '库名'; }
    if ($need) {
        $out[] = array('SKIP', '参数', '未填写' . implode('、', $need) . ' —— 填好后点「测试数据库」。只想测连接的话，把库名也填上（不存在会自动创建）。');
        return $out;
    }

    $t = microtime(true);
    list($db, $errno, $errmsg, $driver) = db_connect_cfg($cfg);

    if (!$db) {
        $out[] = array('FAIL', '连接 MySQL', '失败：' . h($errmsg) . '（错误号 ' . (int) $errno . '，驱动 ' . h($driver) . '，耗时 ' . ds_ms($t) . ' ms）', db_tip($errno));
        return $out;
    }
    $out[] = array('PASS', '连接 MySQL', '成功，驱动 ' . h($driver) . '，耗时 ' . ds_ms($t) . ' ms');

    $v = $db->one('SELECT VERSION()');
    $out[] = array('INFO', '服务端版本', $v === null ? '未知' : h($v));

    $name = ds_safe_dbname($cfg['name']);
    if ($name === '') {
        $out[] = array('FAIL', '库名', '库名只能包含字母、数字、下划线。', '改成合法库名（例如 <code>lnmp_test</code>）后重试。');
        return $out;
    }

    $t = microtime(true);
    if ($db->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci') === false) {
        $out[] = array('FAIL', '创建/检查库', '失败：' . h($db->err), db_tip($db->errcode));
        return $out;
    }
    $out[] = array('PASS', '创建/检查库', code($name) . ' 可用，耗时 ' . ds_ms($t) . ' ms');

    if ($db->exec('USE `' . $name . '`') === false) {
        $out[] = array('FAIL', '切换库', '失败：' . h($db->err), db_tip($db->errcode));
        return $out;
    }

    $table = DS_TABLE_CHECK;
    $sqlCreate = 'CREATE TABLE IF NOT EXISTS `' . $table . '` ('
        . ' `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . ' `note` VARCHAR(64) NOT NULL DEFAULT \'\','
        . ' `created_at` DATETIME NOT NULL,'
        . ' PRIMARY KEY (`id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $t = microtime(true);
    if ($db->exec($sqlCreate) === false) {
        $out[] = array('FAIL', '建表', '失败：' . h($db->err), db_tip($db->errcode));
        return $out;
    }
    $out[] = array('PASS', '建表', code($name . '.' . $table) . ' 就绪，耗时 ' . ds_ms($t) . ' ms');

    $t = microtime(true);
    $note = 'probe-' . date('Ymd-His');
    if ($db->exec('INSERT INTO `' . $table . '` (`note`,`created_at`) VALUES (?, NOW())', array($note)) === false) {
        $out[] = array('FAIL', '写入（INSERT）', '失败：' . h($db->err), db_tip($db->errcode));
        return $out;
    }
    $out[] = array('PASS', '写入（INSERT）', '写入 1 行（走预处理协议），耗时 ' . ds_ms($t) . ' ms');

    $t = microtime(true);
    $c = $db->one('SELECT COUNT(*) FROM `' . $table . '`');
    $last = $db->row('SELECT `note`,`created_at` FROM `' . $table . '` ORDER BY `id` DESC LIMIT 1');
    if ($c === null) {
        $out[] = array('FAIL', '读取（SELECT）', '失败：' . h($db->err), db_tip($db->errcode));
        return $out;
    }
    $out[] = array('PASS', '读取（SELECT）', '表内共 ' . (int) $c . ' 行；最新一行 '
        . ($last ? code($last['note'] . ' @ ' . $last['created_at']) : '—')
        . '，耗时 ' . ds_ms($t) . ' ms');

    if (!empty($cfg['drop'])) {
        if ($db->exec('DROP TABLE IF EXISTS `' . $table . '`') === false) {
            $out[] = array('WARN', '清理', '删除测试表失败：' . h($db->err));
        } else {
            $out[] = array('INFO', '清理', '已按勾选删除测试表 ' . code($table));
        }
    } else {
        $out[] = array('INFO', '清理', '测试表保留在 ' . code($name . '.' . $table) . '，不需要就执行 DROP TABLE。');
    }

    return $out;
}

/* ==================== 处理表单 ==================== */

$DEFAULT_FORM = array('host' => '127.0.0.1', 'port' => '3306', 'socket' => '', 'user' => '', 'pass' => '', 'name' => '', 'drop' => 0);
$form     = $DEFAULT_FORM;
$prefiled = false;
$dbRows   = null;
$notice   = '';

if (isset($_SESSION['web_test_form']) && is_array($_SESSION['web_test_form'])) {
    $form = array_merge($DEFAULT_FORM, $_SESSION['web_test_form']);
} else {
    // 留言板已安装时，用它的连接参数预填（不预填密码）
    $cfg0 = cfg_read();
    if (is_array($cfg0) && isset($cfg0['db']) && is_array($cfg0['db'])) {
        foreach (array('host', 'port', 'socket', 'user', 'name') as $f) {
            if (isset($cfg0['db'][$f]) && $cfg0['db'][$f] !== '') { $form[$f] = (string) $cfg0['db'][$f]; }
        }
        $prefiled = true;
    }
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['clear'])) {
        $_SESSION['web_test_form'] = array();
        if (function_exists('session_regenerate_id')) { @session_regenerate_id(true); }
        $form = $DEFAULT_FORM;
        $prefiled = false;
        $notice = '表单和已保存的连接信息已清空。';
    } elseif (isset($_POST['run_db'])) {
        foreach (array('host', 'port', 'socket', 'user', 'name') as $f) {
            $form[$f] = trim((string) (isset($_POST[$f]) ? $_POST[$f] : ''));
        }
        $form['pass'] = (string) (isset($_POST['pass']) ? $_POST['pass'] : '');
        $form['drop'] = empty($_POST['drop']) ? 0 : 1;
        if ($form['host'] === '') { $form['host'] = '127.0.0.1'; }
        if ($form['port'] === '') { $form['port'] = '3306'; }
        $_SESSION['web_test_form'] = $form;   // 仅存于服务端会话，不写文件
        $prefiled = false;
        $dbRows = db_test($form);
    }
}

/* ==================== Web 服务检测 ==================== */

$webRows = array();
$sw      = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '(未提供)';
$hostHdr = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '(未知)';

$https = false;
if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') { $https = true; }
if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) { $https = true; }
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') { $https = true; }

$swLower = strtolower($sw);
if ($swLower === '' || strpos($swLower, 'cli-server') !== false) {
    $webRows[] = array('WARN', 'Web 服务器', '未识别（' . h($sw === '' ? '无 SERVER_SOFTWARE' : $sw) . '）。如果是 <code>php -S</code> 内置服务器，说明这只是在本地跑着玩，生产环境请用 Nginx + php-fpm。');
} else {
    $webRows[] = array('PASS', 'Web 服务器', h($sw));
}
$webRows[] = array('PASS', '页面可达', '本页由 Web 服务器成功返回 —— 说明 Nginx 与 php-fpm 之间的转发是通的');
$webRows[] = array('INFO', '站点根目录', code($docRoot) . '（本文件：' . code(__FILE__) . '）');
$webRows[] = array('INFO', '访问地址', 'Host ' . code($hostHdr) . '　协议 ' . ($https ? 'HTTPS' : 'HTTP') . '　本页耗时 ' . ds_ms(APP_START) . ' ms');
$webRows[] = array('INFO', '客户端 IP', h(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '未知')
    . (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? '（XFF: ' . h($_SERVER['HTTP_X_FORWARDED_FOR']) . '）' : ''));

/* ==================== PHP 检测 ==================== */

$phpRows = array();
$phpVer = PHP_VERSION;
if (version_compare($phpVer, '8.2.0', '>=')) {
    $phpRows[] = array('PASS', 'PHP 版本', h($phpVer) . '（仍在支持期）');
} elseif (version_compare($phpVer, '8.1.0', '>=')) {
    $phpRows[] = array('WARN', 'PHP 版本', h($phpVer) . ' —— 安全支持已结束，建议升到 8.3 / 8.4');
} else {
    $phpRows[] = array('WARN', 'PHP 版本', h($phpVer) . ' —— 该版本早已停止安全更新，强烈建议升级');
}

$sapi = php_sapi_name();
if ($sapi === 'fpm-fcgi') {
    $phpRows[] = array('PASS', '运行方式 (SAPI)', code($sapi) . ' —— 标准 Nginx + php-fpm 组合');
} elseif ($sapi === 'apache2handler') {
    $phpRows[] = array('INFO', '运行方式 (SAPI)', code($sapi) . ' —— 以 Apache 模块方式运行（mod_php），不是 php-fpm');
} elseif ($sapi === 'cli-server') {
    $phpRows[] = array('WARN', '运行方式 (SAPI)', code($sapi) . ' —— PHP 自带开发服务器，非生产环境');
} else {
    $phpRows[] = array('INFO', '运行方式 (SAPI)', code($sapi));
}

$exts = array('mysqli', 'pdo_mysql', 'json', 'mbstring', 'curl', 'openssl', 'fileinfo', 'gd', 'zip', 'opcache');
$has = array(); $miss = array();
foreach ($exts as $e) {
    if (extension_loaded($e)) { $has[] = $e; } else { $miss[] = $e; }
}
$extMsg = '已加载 ' . count($has) . '/' . count($exts) . '：' . implode(' ', $has);
if ($miss) { $extMsg .= '<br>未加载：' . implode(' ', $miss); }
$needDbExt = extension_loaded('mysqli') || extension_loaded('pdo_mysql');
$phpRows[] = array($needDbExt ? ($miss ? 'WARN' : 'PASS') : 'FAIL', '扩展', $extMsg,
    $needDbExt ? '' : '测试 MySQL 至少需要 mysqli 或 pdo_mysql 之一，安装：<code>sudo apt install php-mysql</code> 后重启 php-fpm。');

$writable = @is_writable(__DIR__);
$phpRows[] = array($writable ? 'PASS' : 'INFO', '站点目录可写', $writable
    ? 'Web 进程对此目录有写权限（② 留言板安装、上传图片都需要它）'
    : 'Web 进程对此目录不可写 —— 留言板安装与图片上传会失败，需要 <code>chown -R www-data:www-data 站点目录</code>');

$tmp = @sys_get_temp_dir();
$phpRows[] = array(@is_writable($tmp) ? 'PASS' : 'WARN', '临时目录', code($tmp) . (@is_writable($tmp) ? '（可写）' : '（不可写：session、上传、临时文件都会出问题）'));

$phpRows[] = array('INFO', '限制', 'memory_limit ' . code((string) ini_get('memory_limit'))
    . '　upload_max_filesize ' . code((string) ini_get('upload_max_filesize'))
    . '　post_max_size ' . code((string) ini_get('post_max_size'))
    . '　max_execution_time ' . code((string) ini_get('max_execution_time')) . 's');
$phpRows[] = array('INFO', '时区 / 编码', h(date_default_timezone_get()) . '　' . h((string) ini_get('default_charset')));

$runUser = '';
if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $pw = @posix_getpwuid(@posix_geteuid());
    if (is_array($pw) && isset($pw['name'])) { $runUser = $pw['name']; }
}
$phpRows[] = array('INFO', '运行用户', $runUser !== '' ? h($runUser) . '（文件权限要允许它读写）' : '未获取');

$counts = array('PASS' => 0, 'WARN' => 0, 'FAIL' => 0, 'SKIP' => 0);
foreach (array_merge($webRows, $phpRows, $dbRows ? $dbRows : array()) as $r) {
    if (isset($counts[$r[0]])) { $counts[$r[0]]++; }
}
$summary = 'PASS ' . $counts['PASS'];
if ($counts['WARN']) { $summary .= ' · WARN ' . $counts['WARN']; }
if ($counts['FAIL']) { $summary .= ' · FAIL ' . $counts['FAIL']; }
if ($counts['SKIP']) { $summary .= ' · SKIP ' . $counts['SKIP']; }

$self = ds_self();
$boardReady = cfg_ready();

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>环境自检 · <?php echo h(DS_APP); ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f7fa;color:#1f2937;font:14px/1.65 -apple-system,"Segoe UI","Microsoft YaHei",Roboto,sans-serif}
.wrap{max-width:980px;margin:0 auto;padding:0 16px}
header{background:#0f172a;color:#fff;padding:18px 0}
header h1{margin:0;font-size:19px;font-weight:600}
header p{margin:5px 0 0;color:#94a3b8;font-size:12.5px}
.nav{background:#fff;border-bottom:1px solid #e5e7eb}
.nav .wrap{display:flex;gap:4px}
.nav a{display:inline-block;padding:10px 14px;font-size:13px;color:#6b7280;border-bottom:2px solid transparent}
.nav a:hover{color:#2563eb;text-decoration:none}
.nav a.on{color:#2563eb;border-bottom-color:#2563eb;font-weight:600}
main{padding:16px 0 44px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:15px 18px;margin-bottom:13px}
.card h2{margin:0 0 10px;font-size:15px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.card h2 em{font-style:normal;font-weight:400;color:#6b7280;font-size:12.5px}
.row{display:flex;gap:10px;align-items:flex-start;padding:6px 0;border-top:1px dashed #eef2f7;font-size:13.5px}
.row:first-of-type{border-top:0}
.k{flex:0 0 132px;color:#374151}
.v{flex:1;color:#4b5563;word-break:break-word}
.tip{margin:2px 0 6px 0;padding:7px 10px;background:#fffbeb;border-left:3px solid #f59e0b;border-radius:0 6px 6px 0;color:#92400e;font-size:12.5px}
.badge{flex:0 0 auto;font-size:11px;font-weight:700;padding:1px 7px;border-radius:20px;letter-spacing:.4px;margin-top:2px}
.badge.pass{background:#dcfce7;color:#15803d}
.badge.warn{background:#fef3c7;color:#b45309}
.badge.fail{background:#fee2e2;color:#b91c1c}
.badge.skip{background:#e5e7eb;color:#6b7280}
.badge.info{background:#dbeafe;color:#1d4ed8}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-family:Consolas,Monaco,monospace;font-size:12.5px;word-break:break-all}
.form{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.form label{display:block;font-size:12px;color:#6b7280;margin-bottom:3px}
.form input[type=text],.form input[type=password]{width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit}
.form input:focus{outline:0;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.actions{margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
button{padding:8px 16px;border:0;border-radius:7px;background:#2563eb;color:#fff;font-size:13.5px;cursor:pointer;font-family:inherit}
button:hover{background:#1d4ed8}
button.ghost{background:#fff;color:#374151;border:1px solid #d1d5db}
button.ghost:hover{background:#f9fafb}
label.chk{display:flex;align-items:center;gap:6px;font-size:13px;color:#374151}
.note{margin-top:10px;font-size:12.5px;color:#6b7280;line-height:1.75}
.notice{margin:0 0 12px;padding:9px 12px;background:#eff6ff;border-left:3px solid #2563eb;border-radius:0 6px 6px 0;color:#1e40af;font-size:13px}
.foot{color:#9ca3af;font-size:12px;text-align:center;padding-top:6px}
.gotoboard{display:inline-block;margin-left:6px;font-size:12.5px;color:#2563eb}
@media (max-width:680px){.form{grid-template-columns:1fr 1fr}.k{flex-basis:96px}}
</style>
</head>
<body>
<header><div class="wrap">
    <h1>环境自检</h1>
    <p>Nginx / PHP / MySQL 三项链路一次性验证 · 无需登录 · 不写任何文件 · <?php echo h(date('Y-m-d H:i:s')); ?></p>
</div></header>
<?php echo nav_html(1); ?>

<main class="wrap">

    <div class="notice"><b>总览：</b><?php echo h($summary); ?>　（页面加载 <?php echo ds_ms(APP_START); ?> ms）<?php echo $boardReady ? '　·　检测到 ' . code('config.php') . '，② 留言板已安装 <a class="gotoboard" href="board.php">去逛逛 →</a>' : ''; ?></div>

    <?php if ($notice !== ''): ?>
    <div class="notice"><?php echo h($notice); ?></div>
    <?php endif; ?>

    <section class="card">
        <h2>MySQL 测试 <em>— 在下面填写参数，点按钮即时测试；不填就跳过</em></h2>
        <form method="post" action="<?php echo h($self); ?>">
            <div class="form">
                <div><label>主机</label><input type="text" name="host" value="<?php echo h($form['host']); ?>" placeholder="127.0.0.1"></div>
                <div><label>端口</label><input type="text" name="port" value="<?php echo h($form['port']); ?>" placeholder="3306"></div>
                <div><label>socket 路径（可选）</label><input type="text" name="socket" value="<?php echo h($form['socket']); ?>" placeholder="/run/mysqld/mysqld.sock"></div>
                <div><label>用户名</label><input type="text" name="user" value="<?php echo h($form['user']); ?>" placeholder="建议用普通账号，别用 root"></div>
                <div><label>密码</label><input type="password" name="pass" value="<?php echo h($form['pass']); ?>" autocomplete="new-password"></div>
                <div><label>库名</label><input type="text" name="name" value="<?php echo h($form['name']); ?>" placeholder="不存在会自动创建"></div>
            </div>
            <div class="actions">
                <button type="submit" name="run_db" value="1">测试数据库</button>
                <button type="submit" name="clear" value="1" class="ghost" formnovalidate>清空表单</button>
                <label class="chk"><input type="checkbox" name="drop" value="1"<?php echo $form['drop'] ? ' checked' : ''; ?>> 测完删除测试表</label>
            </div>
            <div class="note">
                会依次执行：连接 → 建库（IF NOT EXISTS）→ 建表 <code><?php echo h(DS_TABLE_CHECK); ?></code> → 写入 1 行 → 读出统计。库名不存在会自动创建，因此账号需要有 CREATE / INSERT / SELECT 权限。<br>
                <?php if ($prefiled): ?>连接参数已从同目录的 <code>config.php</code>（② 留言板的配置）预填，补上密码即可测试。<br><?php endif; ?>
                填写的连接信息只保存在本次服务端会话中（关掉浏览器或点「清空表单」即清除），<b>不会写入任何文件</b>。
            </div>
        </form>
    </section>

    <?php if ($dbRows !== null): ?>
    <section class="card">
        <h2>MySQL 测试结果 <em>— <?php echo h($form['user'] . '@' . $form['host'] . ($form['socket'] !== '' ? ' via ' . $form['socket'] : ':' . $form['port']) . ' / ' . $form['name']); ?></em></h2>
        <?php foreach ($dbRows as $r) { echo row($r[1], $r[0], $r[2], isset($r[3]) ? $r[3] : ''); } ?>
    </section>
    <?php endif; ?>

    <section class="card">
        <h2>Web 服务（Nginx）</h2>
        <?php foreach ($webRows as $r) { echo row($r[1], $r[0], $r[2]); } ?>
    </section>

    <section class="card">
        <h2>PHP</h2>
        <?php foreach ($phpRows as $r) { echo row($r[1], $r[0], $r[2], isset($r[3]) ? $r[3] : ''); } ?>
    </section>

    <section class="card">
        <h2>静态文件服务 <em>— 由浏览器真实请求验证</em></h2>
        <div class="row" id="js-static">
            <span class="badge info">…</span><span class="k">检测中</span><span class="v">正在用浏览器请求本目录下的静态文件…</span>
        </div>
        <div class="note">这项用于确认 Nginx 能正常返回静态资源。逐个尝试 <code>README.md</code>、<code>uploads/index.html</code> 等；全都没有时显示 SKIP。</div>
    </section>

    <p class="foot">本页不写入任何文件 · ② 动态留言板在同目录 <code>board.php</code> · 测试完请删除整个 <code><?php echo h(DS_DIR); ?></code> 目录</p>
</main>

<script>
(function () {
    var box = document.getElementById('js-static');
    if (!box) { return; }
    var list = ['README.md', 'uploads/index.html', 'robots.txt', 'favicon.ico'];
    function next(i) {
        if (i >= list.length) {
            box.innerHTML = '<span class="badge skip">SKIP</span><span class="k">静态文件</span>'
                + '<span class="v">目录里没有可请求的静态文件（可忽略）。</span>';
            return;
        }
        var name = list[i], t0 = (window.performance && performance.now) ? performance.now() : Date.now();
        fetch(name + '?_=' + Date.now(), { cache: 'no-store' }).then(function (r) {
            if (r.ok) {
                var dt = Math.round(((window.performance && performance.now) ? performance.now() : Date.now()) - t0);
                box.innerHTML = '<span class="badge pass">PASS</span><span class="k">静态文件</span>'
                    + '<span class="v">浏览器成功加载 <code>' + name + '</code>（HTTP ' + r.status + '，' + dt + ' ms）—— Nginx 静态服务正常。</span>';
            } else {
                next(i + 1);
            }
        }).catch(function () { next(i + 1); });
    }
    next(0);
})();
</script>
</body>
</html>
