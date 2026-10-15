<?php
/**
 * ================================================================
 *  LNMP 测试站 · JSON 接口
 * ================================================================
 *  用于验证「前端 JS → PHP → MySQL → JSON」这条链路。
 *
 *    api.php?act=like&id=1    点赞（同一会话对同一条只能点一次，防刷）
 *    api.php?act=ping         站点状态：PHP / MySQL / 驱动 / 留言数
 *
 *  需要先完成 ② 动态留言板（board.php）的安装。
 * ================================================================
 */

require __DIR__ . '/lib.php';
@session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function api_out($arr) {
    // 递归清洗非 UTF-8 字节（MySQL 报错在中文环境可能是 GBK，否则 json_encode 会整体失败）
    array_walk_recursive($arr, 'ds_fix_encoding_ref');
    $s = json_encode($arr, JSON_UNESCAPED_UNICODE);
    if ($s === false) { $s = json_encode(array('ok' => false, 'msg' => '响应编码失败')); }
    echo $s;
    exit;
}

function ds_fix_encoding_ref(&$v) {
    if (is_string($v) && $v !== '' && !preg_match('//u', $v)) { $v = ds_to_utf8($v); }
}

if (!cfg_ready()) { api_out(array('ok' => false, 'msg' => '站点尚未完成安装，请先打开 board.php')); }

$config = cfg_read();
$dbName = ds_safe_dbname($config['db']['name']);

list($db, $no, $cmsg, $driver) = db_connect_cfg($config['db']);
if (!$db) {
    api_out(array('ok' => false, 'msg' => '数据库连接失败：' . $cmsg, 'errno' => (int) $no, 'hint' => db_tip($no)));
}
if ($db->exec('USE `' . $dbName . '`') === false) {
    api_out(array('ok' => false, 'msg' => '数据库不可用：' . $db->err, 'errno' => (int) $db->errcode, 'hint' => db_tip($db->errcode)));
}

$act = isset($_REQUEST['act']) ? (string) $_REQUEST['act'] : '';
$id  = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;

/* ---------- 点赞 ---------- */
if ($act === 'like') {
    if ($id <= 0) { api_out(array('ok' => false, 'msg' => '缺少有效的留言 id')); }

    $row = $db->row('SELECT `id`,`likes` FROM `' . DS_TABLE_MSG . '` WHERE `id` = ? LIMIT 1', array($id));
    if (!$row) { api_out(array('ok' => false, 'msg' => '这条留言不存在')); }

    if (empty($_SESSION['liked'][$id])) {
        if ($db->exec('UPDATE `' . DS_TABLE_MSG . '` SET `likes` = `likes` + 1 WHERE `id` = ?', array($id)) === false) {
            api_out(array('ok' => false, 'msg' => '更新失败：' . $db->err, 'hint' => db_tip($db->errcode)));
        }
        $_SESSION['liked'][$id] = 1;
        $now = $db->one('SELECT `likes` FROM `' . DS_TABLE_MSG . '` WHERE `id` = ?', array($id));
        api_out(array('ok' => true, 'likes' => (int) $now, 'msg' => '已点赞（UPDATE + SELECT 都走通了）', 'sql' => (int) $GLOBALS['DS_SQL_COUNT']));
    }

    api_out(array('ok' => false, 'likes' => (int) $row['likes'], 'msg' => '同一会话对同一条只能点一次赞（防刷）'));
}

/* ---------- 站点状态（给外部监控探活用） ---------- */
if ($act === 'ping') {
    $msgs = $db->one('SELECT COUNT(*) FROM `' . DS_TABLE_MSG . '`');
    api_out(array(
        'ok'      => true,
        'php'     => PHP_VERSION,
        'sapi'    => php_sapi_name(),
        'web'     => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '',
        'mysql'   => $db->one('SELECT VERSION()'),
        'driver'  => $driver,
        'db'      => $dbName,
        'msgs'    => (int) $msgs,
        'sql'     => (int) $GLOBALS['DS_SQL_COUNT'],
        'elapsed' => ds_ms(DS_APP_START) . ' ms',
    ));
}

api_out(array('ok' => false, 'msg' => '未知操作：' . $act, 'usage' => 'api.php?act=like&id=1 或 api.php?act=ping'));
