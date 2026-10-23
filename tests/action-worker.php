<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (count($argv) !== 3) { exit(2); }
$db = notifyTestConnect(':memory:');
notifyTestSeed($db);
require_once dirname(__DIR__) . '/Action.php';
comment_notify_storage_ensure();
$mode = $argv[1];
$operation = $argv[2];
Typecho_Plugin::init(['activated' => $mode === 'disabled' ? [] : ['CommentNotify' => []], 'handles' => []]);
$_SERVER['REQUEST_METHOD'] = $mode === 'get' ? 'GET' : 'POST';
$_SERVER['REQUEST_URI'] = '/action/comment-notify';
$_SERVER['HTTP_REFERER'] = 'https://comment-notify-test.invalid/admin/options-plugin.php?config=CommentNotify';
if ($mode !== 'anonymous') {
    $group = $mode === 'contributor' ? 'contributor' : 'administrator';
    notifyTestAssert(Typecho_Widget::widget('Widget_User')->simpleLogin([
        'uid' => 1, 'name' => 'test-user', 'screenName' => '测试用户', 'mail' => 'user@example.invalid',
        'url' => '', 'group' => $group, 'authCode' => 'isolated-auth',
    ], true), 'Authenticate isolated action');
}
comment_notify_save_config(array_replace(comment_notify_defaults(), ['qq_app_id' => '123456', 'qq_app_secret' => 'test-secret']));
comment_notify_write('qq_token', ['token' => 'cached-token']);
comment_notify_write('weixin_account', ['token' => 'weixin-test-secret', 'account_id' => 'bot@im.bot', 'owner_id' => 'owner@im.wechat', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false]);
comment_notify_write('weixin_sync', ['context_tokens' => ['owner@im.wechat' => 'session-context']]);
$_POST = ['do' => $operation];
$_POST['_'] = $mode === 'csrf' ? 'invalid-token' : Typecho_Widget::widget('Widget_Security')->getToken($_SERVER['HTTP_REFERER']);
$before = notifyTestSnapshot($db);
register_shutdown_function(static function () use ($db, $before): void {
    fwrite(STDERR, 'STATUS:' . (http_response_code() ?: 200) . "\n");
    fwrite(STDERR, 'UNCHANGED:' . ($before === notifyTestSnapshot($db) ? 'yes' : 'no') . "\n");
});
Typecho_Widget::widget('CommentNotify_Action')->action();
