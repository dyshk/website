<?php
/**
 * ================================================================
 *  LNMP 测试站 · 公共库
 * ================================================================
 *  两个页面共用这一份：
 *    index.php  ① 环境自检（Nginx / PHP / MySQL 三段链路）
 *    board.php  ② 动态留言板（留言 / 图片上传 / 点赞 / 搜索分页）
 *    api.php    JSON 接口（点赞 / 探活）
 *
 *  内容：编码清洗、输出转义、配置读写、数据库连接与 SQL 封装、
 *        自动建库建表、图片上传、统一导航
 *  · 兼容 PHP 5.6 ~ 8.4（mysqli 优先，PDO 回退）
 *  · 所有带参数的 SQL 一律走预处理，杜绝注入
 * ================================================================
 */

define('DS_APP', 'LNMP 测试站');
define('DS_VERSION', '2.0');
define('DS_DIR', __DIR__);
define('DS_PER_PAGE', 8);                   // 留言板每页条数
define('DS_MAX_UPLOAD', 2097152);           // 图片上限 2 MB
define('DS_TABLE_MSG', 'ds_msg');           // 留言表
define('DS_TABLE_VISIT', 'ds_visit');       // 访问记录表
define('DS_TABLE_CHECK', 'lnmp_check');     // 自检页的测试表
define('DS_APP_START', isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true));

error_reporting(E_ALL & ~E_NOTICE & ~E_USER_NOTICE & ~E_DEPRECATED & ~E_WARNING & ~E_STRICT);
@ini_set('display_errors', '0');
// PHP 8.1+ 起 mysqli 默认抛异常，会直接 500；这里改回返回错误码（与旧版一致）
if (function_exists('mysqli_report')) { @mysqli_report(MYSQLI_REPORT_OFF); }

$GLOBALS['DS_SQL_COUNT'] = 0;
$GLOBALS['DS_SQL_TIME']  = 0.0;

/* ==================== 基础工具 ==================== */

/** 把可能是 GBK 等非 UTF-8 的字节串转成合法 UTF-8（失败则降级为 ASCII） */
function ds_to_utf8($s) {
    $s = (string) $s;
    if ($s === '' || preg_match('//u', $s)) { return $s; }
    if (function_exists('mb_convert_encoding')) {
        $t = @mb_convert_encoding($s, 'UTF-8', 'GB18030');
        if (is_string($t) && preg_match('//u', $t)) { return $t; }
    }
    if (function_exists('iconv')) {
        $t = @iconv('GB18030', 'UTF-8//IGNORE', $s);
        if (is_string($t) && preg_match('//u', $t)) { return $t; }
    }
    return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $s);
}

/** 输出转义（先做编码清洗：MySQL 返回的文本可能是 GBK 等非 UTF-8） */
function h($s) {
    return htmlspecialchars(ds_to_utf8($s), ENT_QUOTES, 'UTF-8');
}

/** 生成到「第 N 页」的链接（两个页面共用一条导航栏） */
function nav_html($active) {
    $active = (int) $active;
    $one = ($active === 1) ? ' class="on"' : '';
    $two = ($active === 2) ? ' class="on"' : '';
    return '<div class="nav"><div class="wrap">'
        . '<a' . $one . ' href="index.php">① 环境自检</a>'
        . '<a' . $two . ' href="board.php">② 动态留言板</a>'
        . '</div></div>';
}

function ds_ms($t0) { return round((microtime(true) - (float) $t0) * 1000, 1); }

function ds_now() { return date('Y-m-d H:i:s'); }

function ds_self() {
    $s = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : 'index.php';
    return $s === '' ? 'index.php' : $s;
}

/** 在保留现有查询参数的基础上生成 URL（先清掉当前 act，再由 $params 决定） */
function ds_url($params = array()) {
    $q = $_GET;
    unset($q['act'], $q['id']);
    foreach ($params as $k => $v) {
        if ($v === null) { unset($q[$k]); } else { $q[$k] = $v; }
    }
    $s = http_build_query($q);
    return ds_self() . ($s !== '' ? '?' . $s : '');
}

function ds_post($key, $default = '') {
    return isset($_POST[$key]) ? (is_array($_POST[$key]) ? $_POST[$key] : trim((string) $_POST[$key])) : $default;
}

function ds_get($key, $default = '') {
    return isset($_GET[$key]) ? trim((string) $_GET[$key]) : $default;
}

function ds_client_ip() {
    foreach (array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR') as $k) {
        if (!empty($_SERVER[$k])) {
            $parts = explode(',', (string) $_SERVER[$k]);
            $v = trim($parts[0]);
            if (filter_var($v, FILTER_VALIDATE_IP)) { return $v; }
        }
    }
    return '';
}

function ds_ua_hash() {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    return $ua === '' ? '' : md5($ua);
}

/** MySQL 错误号 → 可执行建议 */
function db_tip($no) {
    $n = (int) $no;
    if ($n === 1045 || $n === 1698) {
        return '账号或密码错误。注意 MySQL 里 <code>user@localhost</code> 与 <code>user@%</code> 是两个不同账号；MariaDB 报 1698 说明该账号用的是 unix_socket 认证，需改成密码认证。';
    }
    if ($n === 1044) {
        return '账号能连上，但对该库没有权限。授权示例：<code>GRANT ALL ON 库名.* TO \'账号\'@\'localhost\'; FLUSH PRIVILEGES;</code>';
    }
    if ($n === 1049) {
        return '库不存在、且账号没有建库权限。先手工建：<code>CREATE DATABASE 库名 DEFAULT CHARACTER SET utf8mb4;</code>';
    }
    if ($n === 2002 || $n === 2003) {
        return '连不上 MySQL 服务。先确认它在跑：<code>systemctl status mysql</code>（或 <code>mariadb</code>），再核对端口 / socket 路径 / 防火墙。';
    }
    if ($n === 1130) {
        return 'MySQL 拒绝来自该 IP 的连接，需要授权 <code>账号@\'本机IP\'</code> 或 <code>账号@\'%\'</code>。';
    }
    return '按 MySQL 返回的错误信息核对账号权限与服务状态。';
}

function ds_safe_dbname($n) {
    return preg_replace('/[^A-Za-z0-9_]/', '', (string) $n);
}

/* ==================== 配置（仅留言板需要） ==================== */

function cfg_file() { return DS_DIR . '/config.php'; }

function cfg_read() {
    $f = cfg_file();
    if (!is_file($f)) { return null; }
    $c = @include $f;
    return is_array($c) ? $c : null;
}

function cfg_write($cfg) {
    $php  = "<?php\n";
    $php .= "/**\n * " . DS_APP . " · 留言板配置\n";
    $php .= " * 由安装向导生成，可手工编辑；删除本文件即可重新安装。\n";
    $php .= " * 安全提示：本文件含数据库口令与【明文】管理密码，务必禁止被 Web 直接下载。\n";
    $php .= " * 明文存储是本测试站刻意为之（方便查看/找回），切勿用于公网生产环境。\n */\n\n";
    $php .= "return " . var_export($cfg, true) . ";\n";
    $ok = @file_put_contents(cfg_file(), $php);
    if ($ok !== false) { @chmod(cfg_file(), 0640); return true; }
    return false;
}

function cfg_ready() {
    $c = cfg_read();
    return is_array($c)
        && isset($c['db']['user']) && $c['db']['user'] !== ''
        && isset($c['db']['name']) && $c['db']['name'] !== '';
}

/* ==================== 口令校验 ==================== */

/**
 * 校验口令：明文优先，同时兼容历史版本留下的哈希值。
 *  - 存储值为 bcrypt($2y$/$2a$/$2b$) 或 argon2 格式 → 走 password_verify
 *  - 其它情况（明文）→ 常量时间比较
 * 这样把老 config.php / 老数据直接搬过来也能照常使用，无需迁移。
 */
function ds_pw_match($input, $stored) {
    $input  = (string) $input;
    $stored = (string) $stored;
    if ($input === '' || $stored === '') { return false; }
    if (preg_match('/^\$(2[aby]|argon2)/', $stored)) {
        return password_verify($input, $stored);
    }
    return hash_equals($stored, $input);
}

/** 存储口令：明文原样存（方便在 config.php / 数据表里直接看到） */
function ds_pw_store($pw) {
    return (string) $pw;
}

/* ==================== 数据库 ==================== */

class DB {
    public $kind, $c, $err = '', $errcode = 0;

    function __construct($kind, $c) { $this->kind = $kind; $this->c = $c; }

    function prep($sql, $params) {
        $GLOBALS['DS_SQL_COUNT']++;
        $t0 = microtime(true);

        if ($this->kind === 'mysqli') {
            $stmt = @$this->c->prepare($sql);
            if (!$stmt) {
                $this->err = (string) $this->c->error;
                $this->errcode = (int) $this->c->errno;
                return false;
            }
            if ($params) {
                $types = '';
                $vals  = array_values($params);
                foreach ($vals as $v) { $types .= (is_int($v) || is_float($v)) ? 'd' : 's'; }
                $refs = array();
                foreach ($vals as $k => $v) { $refs[$k] = &$vals[$k]; }
                array_unshift($refs, $types);
                if (!@call_user_func_array(array($stmt, 'bind_param'), $refs)) {
                    $this->err = '参数绑定失败';
                    return false;
                }
            }
            if (!@$stmt->execute()) {
                $this->err = (string) $stmt->error;
                $this->errcode = (int) $stmt->errno;
                return false;
            }
        } else {
            $stmt = @$this->c->prepare($sql);
            if (!$stmt) {
                $i = $this->c->errorInfo();
                $this->err = isset($i[2]) ? (string) $i[2] : 'prepare 失败';
                $this->errcode = isset($i[1]) ? (int) $i[1] : 0;
                return false;
            }
            if (!@$stmt->execute(array_values((array) $params))) {
                $i = $stmt->errorInfo();
                $this->err = isset($i[2]) ? (string) $i[2] : 'execute 失败';
                $this->errcode = isset($i[1]) ? (int) $i[1] : 0;
                return false;
            }
        }

        $GLOBALS['DS_SQL_TIME'] += microtime(true) - $t0;
        return $stmt;
    }

    /**
     * 执行写语句 / DDL，返回受影响行数（失败返回 false）
     * 注意：USE、CREATE DATABASE 这类语句无法走预处理协议，
     *       因此无参数时直接原生执行，只有带参数时才用预处理。
     */
    function exec($sql, $params = array()) {
        $t0 = microtime(true);
        $GLOBALS['DS_SQL_COUNT']++;

        if (!$params) {
            if ($this->kind === 'mysqli') {
                $r = @$this->c->query($sql);
                if ($r === false) {
                    $this->err = (string) $this->c->error;
                    $this->errcode = (int) $this->c->errno;
                    return false;
                }
                $GLOBALS['DS_SQL_TIME'] += microtime(true) - $t0;
                return (int) $this->c->affected_rows;
            }
            $n = @$this->c->exec($sql);
            if ($n === false) {
                $i = $this->c->errorInfo();
                $this->err = isset($i[2]) ? (string) $i[2] : '执行失败';
                $this->errcode = isset($i[1]) ? (int) $i[1] : 0;
                return false;
            }
            $GLOBALS['DS_SQL_TIME'] += microtime(true) - $t0;
            return (int) $n;
        }

        $st = $this->prep($sql, $params);
        if ($st === false) { return false; }
        if ($this->kind === 'mysqli') {
            $n = (int) $st->affected_rows;
            @$st->close();
            return $n;
        }
        return (int) $st->rowCount();
    }

    function rows($sql, $params = array()) {
        $st = $this->prep($sql, $params);
        if ($st === false) { return array(); }
        $out = array();
        if ($this->kind === 'mysqli') {
            if (method_exists($st, 'get_result')) {
                $res = @$st->get_result();
                if ($res) { while ($r = $res->fetch_assoc()) { $out[] = $r; } }
            } else {
                // 没有 mysqlnd 时退回到 bind_result 方式
                $meta = @$st->result_metadata();
                $fields = array(); $row = array();
                if ($meta) {
                    while ($f = $meta->fetch_field()) { $fields[] = $f->name; $row[$f->name] = null; }
                    $refs = array();
                    foreach ($row as $k => $v) { $refs[$k] = &$row[$k]; }
                    @call_user_func_array(array($st, 'bind_result'), $refs);
                    while (@$st->fetch()) {
                        $copy = array();
                        foreach ($row as $k => $v) { $copy[$k] = $v; }
                        $out[] = $copy;
                    }
                }
            }
            @$st->close();
        } else {
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        return $out;
    }

    function row($sql, $params = array()) {
        $r = $this->rows($sql, $params);
        return $r ? $r[0] : null;
    }

    /** 取第一行第一列（标量） */
    function one($sql, $params = array()) {
        $r = $this->row($sql, $params);
        if (!$r) { return null; }
        $v = array_values($r);
        return $v[0];
    }

    function lastId() {
        return ($this->kind === 'mysqli') ? (int) $this->c->insert_id : (int) $this->c->lastInsertId();
    }
}

/**
 * 按配置连接 MySQL（连库本身可能还不存在，所以不指定 database）
 * 返回 array(DB|null, 错误号, 错误信息, 驱动名)
 */
function db_connect_cfg($cfg) {
    $host = (isset($cfg['host']) && $cfg['host'] !== '') ? $cfg['host'] : '127.0.0.1';
    $port = isset($cfg['port']) ? (int) $cfg['port'] : 3306;
    if ($port <= 0) { $port = 3306; }
    $sock = isset($cfg['socket']) ? trim((string) $cfg['socket']) : '';
    $user = isset($cfg['user']) ? (string) $cfg['user'] : '';
    $pass = isset($cfg['pass']) ? (string) $cfg['pass'] : '';

    if (class_exists('mysqli')) {
        $link = @mysqli_init();
        if (!$link) { return array(null, -1, 'mysqli_init 失败', 'mysqli'); }
        @mysqli_options($link, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        $realHost = ($sock !== '') ? 'localhost' : $host;
        $ok = @mysqli_real_connect($link, $realHost, $user, $pass, '', $port, ($sock !== '' ? $sock : null), 0);
        if ($ok) {
            // 必须显式设置连接字符集，否则老版本 PHP 默认字符集不是 utf8mb4，中文会插入失败
            @mysqli_set_charset($link, 'utf8mb4');
            return array(new DB('mysqli', $link), 0, '', 'mysqli');
        }
        $e = (int) @mysqli_connect_errno();
        $m = (string) @mysqli_connect_error();
        @mysqli_close($link);
        return array(null, $e, ($m !== '' ? $m : '无法连接（未返回错误详情），请核对主机、端口或 socket 路径'), 'mysqli');
    }

    if (class_exists('PDO')) {
        $drv = @PDO::getAvailableDrivers();
        if (is_array($drv) && in_array('mysql', $drv, true)) {
            $dsn = ($sock !== '')
                ? 'mysql:unix_socket=' . $sock . ';charset=utf8mb4'
                : 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
            try {
                $pdo = new PDO($dsn, $user, $pass, array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                    PDO::ATTR_TIMEOUT => 5,
                ));
                return array(new DB('pdo', $pdo), 0, '', 'pdo_mysql');
            } catch (Exception $e) {
                return array(null, (int) $e->getCode(), $e->getMessage(), 'pdo_mysql');
            }
        }
    }

    return array(null, -1, 'PHP 未安装 mysqli 或 pdo_mysql 扩展（安装：sudo apt install php-mysql）', 'none');
}

/**
 * 建库 + 建表（留言板用）
 * 注意：DDL 成功时 affected_rows 可能是 0，所以一律用 === false 判断失败
 */
function ds_setup_schema($db, $dbname) {
    $name = ds_safe_dbname($dbname);
    if ($name === '') { return array(false, '库名只能包含字母、数字、下划线', 0); }

    if ($db->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci') === false) {
        return array(false, '建库失败：' . $db->err, $db->errcode);
    }
    if ($db->exec('USE `' . $name . '`') === false) {
        return array(false, '切换数据库失败：' . $db->err, $db->errcode);
    }

    $sqlMsg = 'CREATE TABLE IF NOT EXISTS `' . DS_TABLE_MSG . '` ('
        . ' `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . ' `name` VARCHAR(32) NOT NULL,'
        . ' `content` VARCHAR(1000) NOT NULL,'
        . ' `image` VARCHAR(180) NOT NULL DEFAULT \'\','
        . ' `likes` INT UNSIGNED NOT NULL DEFAULT 0,'
        . ' `ip` VARCHAR(45) NOT NULL DEFAULT \'\','
        . ' `pw_hash` VARCHAR(255) NOT NULL DEFAULT \'\','
        . ' `created_at` DATETIME NOT NULL,'
        . ' PRIMARY KEY (`id`),'
        . ' KEY `idx_created` (`created_at`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
    if ($db->exec($sqlMsg) === false) { return array(false, '建表 ' . DS_TABLE_MSG . ' 失败：' . $db->err, $db->errcode); }

    $sqlVisit = 'CREATE TABLE IF NOT EXISTS `' . DS_TABLE_VISIT . '` ('
        . ' `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . ' `path` VARCHAR(120) NOT NULL DEFAULT \'\','
        . ' `ip` VARCHAR(45) NOT NULL DEFAULT \'\','
        . ' `ua_hash` VARCHAR(32) NOT NULL DEFAULT \'\','
        . ' `created_at` DATETIME NOT NULL,'
        . ' PRIMARY KEY (`id`),'
        . ' KEY `idx_time` (`created_at`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
    if ($db->exec($sqlVisit) === false) { return array(false, '建表 ' . DS_TABLE_VISIT . ' 失败：' . $db->err, $db->errcode); }

    return array(true, '', 0);
}

/* ==================== 文件上传 ==================== */

/**
 * 处理图片上传，返回相对路径（如 uploads/20261013-ab12cd34ef.jpg），失败返回 ''
 */
function ds_upload_image($field, &$err) {
    $err = '';
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) { return ''; }
    $f = $_FILES[$field];
    if (!isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) { return ''; }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $map = array(
            UPLOAD_ERR_INI_SIZE   => '文件超过 php.ini 的 upload_max_filesize 限制',
            UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制',
            UPLOAD_ERR_PARTIAL    => '文件只上传了一部分',
            UPLOAD_ERR_NO_TMP_DIR => 'PHP 没有可用的临时目录',
            UPLOAD_ERR_CANT_WRITE => 'PHP 无法写入临时目录',
            UPLOAD_ERR_EXTENSION  => '被 PHP 扩展拦截',
        );
        $err = isset($map[$f['error']]) ? $map[$f['error']] : ('上传失败（错误码 ' . (int) $f['error'] . '）');
        return '';
    }
    if ((int) $f['size'] > DS_MAX_UPLOAD) { $err = '图片不能超过 ' . round(DS_MAX_UPLOAD / 1048576, 1) . ' MB'; return ''; }

    $info = @getimagesize($f['tmp_name']);
    $allow = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp');
    if (!$info || !isset($info['mime']) || !isset($allow[$info['mime']])) {
        $err = '只允许 JPG / PNG / GIF / WebP 图片（当前文件不是有效图片）';
        return '';
    }

    $dir = DS_DIR . '/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_writable($dir)) {
        $err = '上传目录不可写：' . $dir . '（执行 sudo chown -R www-data:www-data ' . DS_DIR . '）';
        return '';
    }

    $name = date('Ymd') . '-' . substr(md5(uniqid('', true)), 0, 10) . '.' . $allow[$info['mime']];
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) { $err = '保存文件失败（检查目录权限与磁盘空间）'; return ''; }
    @chmod($dir . '/' . $name, 0644);
    return 'uploads/' . $name;
}
