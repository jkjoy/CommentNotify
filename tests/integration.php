<?php
/** Real Typecho core, disposable SQLite, and injected HTTP replies only. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
foreach (['pdo_sqlite', 'sodium'] as $extension) {
    if (!extension_loaded($extension)) { fwrite(STDERR, 'Enable ' . $extension . " to run the isolated tests.\n"); exit(1); }
}
require_once dirname(__DIR__) . '/Plugin.php';

$checks = [];
$results = [];
function check(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) { throw new RuntimeException($message !== '' ? $message : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}
function raises(callable $callback, string $contains = ''): Throwable
{
    try { $callback(); } catch (Throwable $exception) {
        if ($contains !== '') { check(str_contains($exception->getMessage(), $contains), 'Unexpected error: ' . $exception->getMessage()); }
        return $exception;
    }
    throw new RuntimeException('Expected an exception');
}
function response(array $data = [], int $status = 200): array { return ['status' => $status, 'data' => $data]; }
function transport(array &$calls, array $responses): Closure
{
    return static function (string $method, string $url, array $headers = [], ?array $payload = null, int $timeout = 6) use (&$calls, &$responses): array {
        $calls[] = compact('method', 'url', 'headers', 'payload', 'timeout');
        if ($responses === []) { throw new RuntimeException('Unexpected HTTP request'); }
        $next = array_shift($responses);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    };
}
function test(string $name, callable $callback): void { $GLOBALS['checks'][$name] = $callback; }
function fresh(): void
{
    $db = notifyTestConnect(':memory:');
    notifyTestSeed($db);
    comment_notify_storage_ensure();
    Typecho_Plugin::init(['activated' => ['CommentNotify' => []], 'handles' => []]);
    $_POST = [];
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
function qqConfig(array $changes = []): array
{
    return array_replace(comment_notify_defaults(), ['qq_enabled' => true, 'qq_app_id' => '123456', 'qq_app_secret' => 'test-secret', 'qq_target_id' => 'USER_OPENID'], $changes);
}
function cachedQQ(array $config, int $expires = 3600, string $token = 'cached-token'): void
{
    comment_notify_write('qq_token', ['identity' => hash('sha256', $config['qq_app_id'] . ':' . $config['qq_app_secret']), 'token' => $token, 'expires_at' => time() + $expires]);
}
function weixinAccount(string $owner = 'owner@im.wechat'): array
{
    $account = ['token' => 'weixin-test-secret', 'account_id' => 'bot@im.bot', 'owner_id' => $owner, 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false];
    comment_notify_write('weixin_account', $account);
    return $account;
}
function weixinBound(bool $context = true): array
{
    weixinAccount();
    comment_notify_write('weixin_sync', ['candidates' => ['owner@im.wechat'], 'context_tokens' => $context ? ['owner@im.wechat' => 'session-context'] : []]);
    $config = qqConfig(['weixin_enabled' => true, 'weixin_user_id' => 'owner@im.wechat']);
    comment_notify_save_config($config);
    return $config;
}
function commentFixture(string $status = 'approved', int $authorId = 0, string $type = 'comment'): int
{
    $db = Typecho_Db::get();
    notifyTestPost($db, 7, ['title' => '真实文章', 'slug' => 'actual-post']);
    return notifyTestComment($db, ['cid' => 7, 'authorId' => $authorId, 'type' => $type, 'author' => '真实评论人', 'mail' => 'private@example.test', 'ip' => '192.0.2.17', 'text' => '真实评论内容', 'status' => $status]);
}
function qqWebhookHeaders(string $raw, ?array $config = null, string $timestamp = '1725442341'): array
{
    $config ??= comment_notify_config();
    // Independent signing, so a self-consistent implementation mistake cannot pass.
    $secret = $config['qq_app_secret'];
    $seed = substr(str_repeat($secret, (int)ceil(32 / strlen($secret))), 0, 32);
    $pair = sodium_crypto_sign_seed_keypair($seed);
    return ['x-bot-appid' => $config['qq_app_id'], 'x-signature-timestamp' => $timestamp,
        'x-signature-ed25519' => bin2hex(sodium_crypto_sign_detached($timestamp . $raw, sodium_crypto_sign_secretkey($pair)))];
}
function qqWebhookBody(string $event, string $content, string $recipient = 'NEW_USER_OPENID'): array
{
    return ['op' => 0, 's' => 1, 'id' => 'event-1', 't' => $event, 'd' => ['id' => 'message-1', 'content' => $content,
        'author' => ['id' => 'LEGACY_MEMBER_ID', 'user_openid' => $recipient], 'group_openid' => $recipient]];
}
function qqWebhookDeliver(array $event, ?int $now = null): array
{
    $raw = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return comment_notify_qq_webhook_handle('POST', $raw, qqWebhookHeaders($raw), $now);
}
function pushSnapshot(): array
{
    return notifyTestSnapshot(Typecho_Db::get());
}

require __DIR__ . '/protocol.php';

test('state storage initializes repeatedly and consumes each exact binding snapshot only once', static function (): void {
    comment_notify_storage_ensure();
    $first = ['code' => '0011223344556677', 'target_type' => 'c2c', 'expires_at' => time() + 600, 'identity' => 'first-bot'];
    comment_notify_write('qq_binding', $first);
    $snapshot = comment_notify_read('qq_binding');
    same($first, $snapshot);
    same(true, comment_notify_state_consume('qq_binding', $snapshot));
    same([], comment_notify_read('qq_binding'));
    same(false, comment_notify_state_consume('qq_binding', $snapshot));
    $replacement = array_replace($first, ['code' => '8899AABBCCDDEEFF']);
    comment_notify_write('qq_binding', $replacement);
    same(false, comment_notify_state_consume('qq_binding', $snapshot));
    same($replacement, comment_notify_read('qq_binding'));
    same(true, comment_notify_state_consume('qq_binding', $replacement));
    same([], comment_notify_read('qq_binding'));
});

test('QQ target binding preserves newer unrelated settings and rejects changed credentials or targets', static function (): void {
    $snapshot = qqConfig();
    comment_notify_save_config($snapshot);
    $newer = array_replace($snapshot, ['include_link' => false, 'notify_admin' => true]);
    comment_notify_save_config($newer);
    same(true, comment_notify_qq_save_bound_target($snapshot, 'BOUND_OPENID'));
    same(array_replace($newer, ['qq_target_id' => 'BOUND_OPENID']), comment_notify_config());
    $boundSnapshot = comment_notify_config();
    $changedCredentials = array_replace($boundSnapshot, ['qq_app_secret' => 'new-bot-secret']);
    comment_notify_save_config($changedCredentials);
    same(false, comment_notify_qq_save_bound_target($boundSnapshot, 'STALE_OPENID'));
    same($changedCredentials, comment_notify_config());
    $changedTarget = array_replace($changedCredentials, ['qq_target_id' => 'MANUAL_OPENID']);
    comment_notify_save_config($changedTarget);
    same(false, comment_notify_qq_save_bound_target($changedCredentials, 'STALE_OPENID'));
    same($changedTarget, comment_notify_config());
});

test('Typecho portal registers only the two new-comment hooks and official action', static function (): void {
    [$path, $class] = Typecho_Plugin::portal('CommentNotify', __TYPECHO_ROOT_DIR__ . __TYPECHO_PLUGIN_DIR__);
    same(realpath(dirname(__DIR__) . '/Plugin.php'), realpath($path));
    check(is_a($class, Typecho_Plugin_Interface::class, true));
    Typecho_Plugin::init(['activated' => [], 'handles' => []]);
    CommentNotify_Plugin::activate();
    Typecho_Plugin::activate('CommentNotify');
    $hooks = array_keys(Typecho_Plugin::export()['handles']);
    sort($hooks);
    same(['Widget_Comments_Edit:finishComment', 'Widget_Feedback:finishComment'], $hooks);
    $db = Typecho_Db::get();
    $row = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'actionTable')->where('user = ?', 0));
    check(str_contains($row['value'], 'comment-notify') && str_contains($row['value'], 'CommentNotify_Action'));
    same([], $db->fetchAll("SELECT name FROM sqlite_master WHERE name = 'typecho_comment_notify_queue'"));
});

test('legacy gateway configuration and malformed storage cannot enable direct notifications', static function (): void {
    $db = Typecho_Db::get();
    foreach ([['qq_enabled' => '1', 'qq_account' => 'old', 'qq_targets' => 'old-target', 'gateway_token' => 'old-secret'],
        ['qq_enabled' => true, 'qq_app_id' => '123456', 'qq_app_secret' => 'test-secret', 'qq_target_id' => 'USER_OPENID'],
        ['protocol_version' => 1, 'qq_enabled' => true], ['protocol_version' => 2, 'qq_enabled' => [], 'qq_app_id' => [], 'qq_target_type' => []]] as $legacy) {
        notifyTestSettings($db, $legacy);
        same(false, comment_notify_config()['qq_enabled']);
        same(false, comment_notify_config()['weixin_enabled']);
        check(!isset(comment_notify_config()['gateway_token']));
    }
    $db->query($db->update('table.options')->rows(['value' => 'not-json'])->where('name = ?', 'plugin:CommentNotify')->where('user = ?', 0));
    same(comment_notify_defaults(), comment_notify_config());
});

test('configuration reads fresh saved state without leaking private account state', static function (): void {
    $config = qqConfig();
    comment_notify_save_config($config);
    same($config, comment_notify_config());
    notifyTestSettings(Typecho_Db::get(), array_replace($config, ['qq_target_id' => 'NEW_OPENID']));
    same('NEW_OPENID', comment_notify_config()['qq_target_id']);
    weixinBound();
    cachedQQ($config);
    $status = json_encode(comment_notify_status(), JSON_THROW_ON_ERROR);
    foreach (['test-secret', 'weixin-test-secret', 'cached-token', 'session-context'] as $secret) {
        check(!str_contains($status, $secret), 'Status exposed ' . $secret);
    }
});

test('validation rejects malformed values and preserves only intentional secret updates', static function (): void {
    $result = comment_notify_validate(['qq_enabled' => '1', 'qq_app_id' => [], 'qq_app_secret' => ['bad'], 'qq_target_id' => ['bad'], 'qq_target_type' => ['c2c'], 'notify_pending' => '1'], comment_notify_defaults());
    check(count($result['errors']) >= 2);
    same('', $result['config']['qq_app_id']);
    $input = ['qq_app_id' => '123456', 'qq_target_type' => 'c2c', 'qq_target_id' => 'USER_OPENID', 'notify_pending' => '1', 'qq_app_secret' => ''];
    same('test-secret', comment_notify_validate($input, qqConfig())['config']['qq_app_secret']);
    $input['qq_app_secret'] = 'replacement-secret';
    same('replacement-secret', comment_notify_validate($input, qqConfig())['config']['qq_app_secret']);
    $result = comment_notify_validate(['qq_target_type' => 'c2c', 'notify_pending' => '1', 'weixin_user_id' => 'attacker@im.wechat'], qqConfig(['weixin_user_id' => 'owner@im.wechat']));
    same('owner@im.wechat', $result['config']['weixin_user_id']);
});

test('QQ credential changes invalidate access token while unrelated changes preserve it', static function (): void {
    $config = qqConfig();
    comment_notify_save_config($config);
    cachedQQ($config);
    comment_notify_save_config(array_replace($config, ['include_link' => false]));
    same('cached-token', comment_notify_read('qq_token')['token']);
    comment_notify_save_config(array_replace($config, ['qq_app_secret' => 'new-secret']));
    same([], comment_notify_read('qq_token'));
});

test('test notifications use saved settings and ignore unsaved request fields', static function (): void {
    $config = qqConfig(['qq_enabled' => false]);
    comment_notify_save_config($config);
    cachedQQ($config);
    $_POST = ['qq_target_id' => 'UNSAVED_OPENID', 'settings' => ['qq_target_id' => 'UNSAVED_OPENID', 'qq_app_secret' => 'unsaved-secret']];
    $calls = [];
    $result = comment_notify_test('qq', transport($calls, [response(['id' => 'ok'])]));
    same(true, $result['ok']);
    same(1, count($calls));
    same('https://api.bot.qq.com/v2/users/USER_OPENID/messages', $calls[0]['url']);
    same(['Authorization: QQBot cached-token'], $calls[0]['headers']);
    same($config, comment_notify_config());
});

test('committed ordinary comments use real Typecho article and public comment content', static function (): void {
    $config = qqConfig();
    comment_notify_save_config($config);
    cachedQQ($config);
    $coid = commentFixture();
    $calls = [];
    comment_notify_comment_created($coid, transport($calls, [response(['id' => 'ok'])]));
    same(1, count($calls));
    $text = $calls[0]['payload']['content'];
    foreach (['真实文章', '真实评论人', '真实评论内容', '/archives/7/#comment-' . $coid] as $value) { check(str_contains($text, $value), 'Notification missing ' . $value . ': ' . $text); }
    foreach (['private@example.test', '192.0.2.17'] as $private) { check(!str_contains($text, $private)); }
    same(true, comment_notify_read('result_qq')['ok']);
});

test('waiting comments follow pending setting; approved follows approved setting', static function (): void {
    foreach ([['waiting', 'notify_pending', '#comments'], ['approved', 'notify_approved', '#comment-']] as [$status, $flag, $anchor]) {
        foreach ([false, true] as $enabled) {
            fresh();
            $config = qqConfig([$flag => $enabled]);
            comment_notify_save_config($config);
            cachedQQ($config);
            $coid = commentFixture($status);
            $calls = [];
            comment_notify_comment_created($coid, transport($calls, $enabled ? [response(['id' => 'ok'])] : []));
            same($enabled ? 1 : 0, count($calls), 'Incorrect filter: ' . $status);
            if ($enabled) { check(str_contains($calls[0]['payload']['content'], $anchor)); }
        }
    }
});

test('disabled channels, spam, trackbacks, missing comments and registered authors do not send', static function (): void {
    foreach ([['spam', 0, 'comment', []], ['approved', 4, 'comment', []], ['approved', 0, 'trackback', []],
        ['approved', 0, 'comment', ['qq_enabled' => false]]] as [$status, $author, $type, $changes]) {
        fresh();
        comment_notify_save_config(qqConfig($changes));
        $coid = commentFixture($status, $author, $type);
        $calls = [];
        comment_notify_comment_created($coid, transport($calls, []));
        comment_notify_comment_created(99999999, transport($calls, []));
        same([], $calls);
    }
});

test('registered-author opt-in and include-link option change notification behavior', static function (): void {
    $config = qqConfig(['notify_admin' => true, 'include_link' => false]);
    comment_notify_save_config($config);
    cachedQQ($config);
    $coid = commentFixture('approved', 4);
    $calls = [];
    comment_notify_comment_created($coid, transport($calls, [response(['id' => 'ok'])]));
    same(1, count($calls));
    check(!str_contains($calls[0]['payload']['content'], 'https://comment-notify-test.invalid/archives/'));
});

test('one failed direct channel does not prevent the other and results record failures', static function (): void {
    $config = weixinBound();
    cachedQQ($config);
    $coid = commentFixture();
    $calls = [];
    comment_notify_comment_created($coid, transport($calls, [response(['err_code' => 40034105]), response(['ret' => 0])]));
    same(2, count($calls));
    same(false, comment_notify_read('result_qq')['ok']);
    same(true, comment_notify_read('result_weixin')['ok']);
    check(str_ends_with($calls[1]['url'], '/ilink/bot/sendmessage'));
});

test('Typecho settings render saved identities safely and never expose saved secrets', static function (): void {
    $config = weixinBound();
    cachedQQ($config);
    comment_notify_write('weixin_account', array_replace(comment_notify_read('weixin_account'), ['account_id' => '<script>alert(1)</script>']));
    $form = new Typecho_Widget_Helper_Form();
    CommentNotify_Plugin::config($form);
    $form->getInput('qq_app_secret')->value($config['qq_app_secret']);
    ob_start();
    try { $form->render(); $html = (string)ob_get_contents(); }
    finally { ob_end_clean(); }
    foreach (['test-secret', 'weixin-test-secret', 'cached-token', 'session-context'] as $secret) { check(!str_contains($html, $secret), 'Saved secret was rendered: ' . $secret); }
    check(!str_contains($html, '<script>alert(1)</script>'));
    check((bool)preg_match('/<input[^>]*name="qq_app_secret"[^>]*type="password"[^>]*value=""/', $html));
    foreach (['gateway_url', 'qq_service_url', 'qq_targets', 'wechat_targets'] as $old) { check(!str_contains($html, 'name="' . $old . '"')); }
});

test('Action requires administrator, POST, CSRF, and enabled plugin before mutations', static function (): void {
    $operations = ['test_qq', 'test_weixin', 'qq_binding_start', 'qq_binding_cancel', 'weixin_start', 'weixin_check', 'weixin_sync', 'weixin_bind', 'weixin_disconnect', 'status'];
    foreach (['anonymous' => 403, 'contributor' => 403, 'get' => 405, 'csrf' => 403, 'disabled' => 404] as $mode => $expectedStatus) {
        foreach ($mode === 'disabled' ? array_merge($operations, ['qq_webhook']) : $operations as $operation) {
            $worker = notifyTestFinishPhp(notifyTestStartPhp([__DIR__ . '/action-worker.php', $mode, $operation]));
            same(0, $worker['code'], $mode . '/' . $operation . ': ' . $worker['stderr']);
            $json = json_decode($worker['stdout'], true);
            check(is_array($json) && ($json['ok'] ?? null) === false, $mode . '/' . $operation . ' was accepted: ' . $worker['stdout']);
            check(str_contains($worker['stderr'], 'STATUS:' . $expectedStatus), $mode . '/' . $operation . ': ' . $worker['stderr']);
            check(str_contains($worker['stderr'], 'UNCHANGED:yes'), 'Rejected request changed persisted state');
        }
    }
});

test('Action rejects unsupported operation and returns sanitized administrator status', static function (): void {
    foreach (['unknown', 'status'] as $operation) {
        $worker = notifyTestFinishPhp(notifyTestStartPhp([__DIR__ . '/action-worker.php', 'admin', $operation]));
        same(0, $worker['code'], $worker['stderr']);
        $json = json_decode($worker['stdout'], true);
        same($operation === 'status', $json['ok'] ?? null);
        foreach (['test-secret', 'weixin-test-secret', 'cached-token', 'session-context'] as $secret) { check(!str_contains($worker['stdout'], $secret)); }
        check(str_contains($worker['stderr'], 'UNCHANGED:yes'));
    }
});

foreach ($checks as $name => $callback) {
    fresh();
    $level = ob_get_level();
    ob_start();
    try { $callback(); $results[] = 'PASS ' . $name; }
    catch (Throwable $exception) { $results[] = 'FAIL ' . $name . "\n     " . get_class($exception) . ': ' . $exception->getMessage() . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine(); }
    finally { while (ob_get_level() > $level) { ob_end_clean(); } }
}
$failed = count(array_filter($results, static fn(string $result): bool => str_starts_with($result, 'FAIL')));
echo implode("\n", $results) . "\n\n" . count($results) . ' checks, ' . $failed . " failures.\n";
exit($failed === 0 ? 0 : 1);
