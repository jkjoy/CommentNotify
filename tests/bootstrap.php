<?php
/** Standalone integration fixtures. Never load the live config.inc.php. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__, 4));
define('__TYPECHO_ROOT_URL__', 'https://comment-notify-test.invalid/');
define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
define('__TYPECHO_THEME_DIR__', '/usr/themes');
define('__TYPECHO_ADMIN_DIR__', '/admin');
define('__TYPECHO_DEBUG__', true);
define('__TYPECHO_CLASS_ALIASES__', [
    'Typecho_Plugin_Interface' => '\\Typecho\\Plugin\\PluginInterface',
    'Widget_Abstract' => '\\Widget\\Base',
    'Widget_Abstract_Contents' => '\\Widget\\Base\\Contents',
    'Widget_Abstract_Comments' => '\\Widget\\Base\\Comments',
    'Widget_Interface_Do' => '\\Widget\\ActionInterface',
    'Helper' => '\\Utils\\Helper',
]);
require_once __TYPECHO_ROOT_DIR__ . '/var/Typecho/Common.php';

$_SERVER['HTTP_HOST'] = 'comment-notify-test.invalid';
$_SERVER['SERVER_NAME'] = 'comment-notify-test.invalid';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __TYPECHO_ROOT_DIR__ . '/index.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function notifyTestConnect(string $filename): Typecho_Db
{
    if ($filename === ':memory:') {
        // Typecho has separate read/write connections; share a unique in-memory database.
        $filename = 'file:comment-notify-' . bin2hex(random_bytes(8)) . '?mode=memory&cache=shared';
    }
    $db = new Typecho_Db('Pdo_SQLite', 'typecho_');
    $db->addServer(['file' => $filename], Typecho_Db::READ | Typecho_Db::WRITE);
    Typecho_Db::set($db);
    foreach ([Typecho_Db::READ, Typecho_Db::WRITE] as $mode) {
        $db->query('PRAGMA busy_timeout = 10000', $mode);
    }
    Typecho_Widget::destroy();
    Typecho_Plugin::init(['activated' => [], 'handles' => []]);
    return $db;
}

function notifyTestSeed(Typecho_Db $db): void
{
    foreach (explode(';', file_get_contents(__TYPECHO_ROOT_DIR__ . '/install/sqlite.sql')) as $statement) {
        if (trim($statement) !== '') {
            $db->query($statement, Typecho_Db::WRITE);
        }
    }
    $options = [
        'theme' => 'default', 'siteUrl' => 'https://comment-notify-test.invalid/', 'title' => '通知测试博客',
        'timezone' => '28800', 'rewrite' => '1', 'charset' => 'UTF-8', 'contentType' => 'text/html',
        'pageSize' => '10', 'actionTable' => serialize([]), 'panelTable' => serialize([]),
        'lang' => 'zh_CN', 'secret' => 'isolated-test-secret',
        'routingTable' => serialize(['post' => ['url' => '/archives/[cid:digital]/', 'widget' => 'Widget_Archive', 'action' => 'render']]),
        'plugins' => serialize(['activated' => [], 'handles' => []]),
        'plugin:CommentNotify' => json_encode([]),
    ];
    foreach ($options as $name => $value) {
        $db->query($db->insert('table.options')->rows(['name' => $name, 'user' => 0, 'value' => $value]));
    }
    Typecho_Router::setRoutes(Typecho_Widget::widget('Widget_Options')->routingTable);
    Typecho_Date::setTimezoneOffset(28800);
}

function notifyTestSettings(Typecho_Db $db, array $settings): void
{
    $db->query($db->update('table.options')->rows(['value' => json_encode($settings, JSON_THROW_ON_ERROR)])
        ->where('name = ?', 'plugin:CommentNotify')->where('user = ?', 0));
    Typecho_Widget::destroy();
}

function notifyTestPost(Typecho_Db $db, int $cid = 1, array $overrides = []): void
{
    $db->query($db->insert('table.contents')->rows(array_replace([
        'cid' => $cid, 'title' => '文章 <测试> ' . $cid, 'slug' => 'post-' . $cid,
        'created' => time() - 3600, 'modified' => time(), 'text' => '正文',
        'type' => 'post', 'status' => 'publish', 'password' => '', 'authorId' => 1, 'allowComment' => 1,
    ], $overrides)));
}

function notifyTestComment(Typecho_Db $db, array $overrides = []): int
{
    return (int) $db->query($db->insert('table.comments')->rows(array_replace([
        'cid' => 1, 'created' => time(), 'author' => '访客 & 测试', 'mail' => 'guest@example.invalid',
        'text' => '中文评论 😀 <b>内容</b>', 'type' => 'comment', 'status' => 'approved',
        'parent' => 0, 'authorId' => 0, 'ownerId' => 1,
    ], $overrides)));
}

function notifyTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function notifyTestSame($expected, $actual, string $message): void
{
    notifyTestAssert($expected === $actual, $message . ' | expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
}

function notifyTestSnapshot(Typecho_Db $db): array
{
    return [
        'options' => $db->fetchAll($db->select()->from('table.options')->order('name')),
        'state' => $db->fetchAll($db->select()->from('table.comment_notify_state')->order('name')),
    ];
}

function notifyTestStartPhp(array $arguments, ?array $environment = null, ?string $stderrFile = null): array
{
    $command = [PHP_BINARY];
    if (php_ini_loaded_file()) {
        array_push($command, '-c', php_ini_loaded_file());
    }
    array_push($command, '-d', 'display_errors=stderr');
    array_push($command, '-d', 'disable_functions=curl_exec');
    $pipes = [];
    $environment = $environment === null ? null : array_replace(getenv(), $environment);
    $process = proc_open(array_merge($command, $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $stderrFile === null ? ['pipe', 'w'] : ['file', $stderrFile, 'a']], $pipes, __DIR__, $environment);
    notifyTestAssert(is_resource($process), 'Launch isolated PHP process');
    fclose($pipes[0]);
    return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2] ?? fopen($stderrFile, 'r')];
}

function notifyTestFinishPhp(array $worker): array
{
    $stdout = stream_get_contents($worker['stdout']);
    $stderr = stream_get_contents($worker['stderr']);
    fclose($worker['stdout']);
    fclose($worker['stderr']);
    return ['code' => proc_close($worker['process']), 'stdout' => $stdout, 'stderr' => $stderr];
}
