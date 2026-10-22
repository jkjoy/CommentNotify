<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (count($argv) !== 3 || !is_file($argv[1])) {
    exit(2);
}
notifyTestConnect($argv[1]);
require_once dirname(__DIR__) . '/Action.php';
$mode = $argv[2];
$_SERVER['REQUEST_METHOD'] = $mode === 'get' ? 'GET' : 'POST';
$_SERVER['REQUEST_URI'] = '/action/comment-notify';
$_SERVER['HTTP_REFERER'] = 'https://comment-notify-test.invalid/admin/options-plugin.php?config=CommentNotify';
if ($mode !== 'anonymous') {
    $group = $mode === 'contributor' ? 'contributor' : 'administrator';
    notifyTestAssert(Typecho_Widget::widget('Widget_User')->simpleLogin([
        'uid' => 1, 'name' => 'test-user', 'screenName' => '测试用户', 'mail' => 'user@example.invalid',
        'url' => '', 'group' => $group, 'authCode' => 'isolated-auth',
    ], true), 'Authenticate the action worker');
}
$settings = CommentNotify_Service::settings();
$settings['gateway_token'] = '';
$settings['qq_service_token'] = '';
$settings['qq_targets'] = 'qqbot:c2c:UNSAVED_QQ';
$settings['wechat_targets'] = 'unsaved-target@im.wechat';
$_POST = ['do' => 'test', 'kind' => $mode === 'wechat' ? 'wechat' : 'qq', 'settings' => $settings];
if ($mode === 'invalid-kind') {
    $_POST['kind'] = 'invalid';
}
$_POST['_'] = $mode === 'csrf' ? 'invalid-token' : Typecho_Widget::widget('Widget_Security')->getToken($_SERVER['HTTP_REFERER']);
register_shutdown_function(static function (): void {
    fwrite(STDERR, 'STATUS:' . (http_response_code() ?: 200) . "\n");
});
Typecho_Widget::widget('CommentNotify_Action')->action();
