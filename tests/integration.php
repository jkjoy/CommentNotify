<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

foreach (['pdo_sqlite', 'curl'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, 'Enable ' . $extension . " to run the isolated tests.\n");
        exit(1);
    }
}

$directory = sys_get_temp_dir() . '/comment-notify-tests-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$filename = $directory . '/fixtures.sqlite';
$db = notifyTestConnect($filename);
notifyTestSeed($db);
$gateway = notifyTestStartGateway($directory);
require_once dirname(__DIR__) . '/Plugin.php';
CommentNotify_Service::install();
CommentNotify_Service::install();
$settings = array_replace(CommentNotify_Transport::defaults(), [
    'gateway_url' => $gateway['url'], 'gateway_token' => 'PRIVATE-MOCK-GATEWAY-TOKEN',
    'qq_enabled' => '1', 'qq_account' => 'qq-test-account', 'qq_targets' => 'qqbot:c2c:TEST_OPENID',
    'wechat_enabled' => '1', 'wechat_account' => 'wechat-test-account', 'wechat_targets' => 'test-user@im.wechat',
    'qq_service_url' => $gateway['url'], 'qq_service_token' => 'PRIVATE-MOCK-QQ-SERVICE-TOKEN',
]);
notifyTestSettings($db, $settings);
CommentNotify_Plugin::activate();
Typecho_Plugin::activate('CommentNotify');

register_shutdown_function(static function () use ($gateway, $directory, &$db): void {
    Typecho_Plugin::deactivate('CommentNotify');
    proc_terminate($gateway['process']);
    notifyTestFinishPhp($gateway);
    Typecho_Widget::destroy();
    Typecho_Db::set(new Typecho_Db('Pdo_SQLite', 'typecho_'));
    $db = null;
    gc_collect_cycles();
    foreach (glob($directory . '/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
});

function notifyTestRows(Typecho_Db $db): array
{
    return $db->fetchAll($db->select()->from(CommentNotify_Service::TABLE)->order('id', Typecho_Db::SORT_ASC));
}

function notifyTestReset(Typecho_Db $db, array $settings, string $directory): void
{
    // Drain remembered hook IDs before deleting fixtures; preserve the plugin's real hooks.
    CommentNotify_Plugin::flush();
    foreach ([CommentNotify_Service::TABLE, 'table.comments', 'table.contents'] as $table) {
        $db->query($db->delete($table));
    }
    notifyTestSettings($db, $settings);
    notifyTestPost($db);
    notifyTestScenario($directory, 'success');
    file_put_contents($directory . '/requests.jsonl', '');
}

function notifyTestSetRow(Typecho_Db $db, int $id, array $changes): void
{
    $db->query($db->update(CommentNotify_Service::TABLE)->rows($changes)->where('id = ?', $id));
}

$tests = [];
$tests['real Typecho portal, hooks and safe password controls'] = static function () use ($settings): void {
    [$path, $class] = Typecho_Plugin::portal('CommentNotify', __TYPECHO_ROOT_DIR__ . __TYPECHO_PLUGIN_DIR__);
    notifyTestSame(realpath(dirname(__DIR__) . '/Plugin.php'), realpath($path), 'Discover the standalone plugin entry');
    notifyTestAssert(is_a($class, Typecho_Plugin_Interface::class, true), 'Implement the real Typecho plugin interface');
    $hooks = Typecho_Plugin::export()['handles'];
    foreach (['Widget_Feedback:finishComment', 'Widget_Comments_Edit:finishComment', 'Widget_Comments_Edit:mark',
        'index.php:begin', 'admin/common.php:begin'] as $hook) {
        notifyTestAssert(!empty($hooks[$hook]), 'Register hook ' . $hook);
    }
    $form = new Typecho_Widget_Helper_Form();
    $class::config($form);
    foreach (['gateway_token', 'qq_service_token'] as $key) {
        $form->getInput($key)->value($settings[$key]); // The core applies saved values after config().
    }
    ob_start();
    $form->render();
    $html = ob_get_clean();
    foreach (['gateway_token', 'qq_service_token'] as $key) {
        notifyTestAssert(!str_contains($html, $settings[$key]), 'Saved credentials must not appear in HTML');
        notifyTestAssert((bool) preg_match('/<input[^>]*name="' . $key . '"[^>]*type="password"[^>]*value=""/', $html),
            'Render a blank password field for ' . $key);
    }
};

$tests['only approved ordinary comments from visitors are queued'] = static function () use ($db): void {
    foreach ([['status' => 'waiting'], ['status' => 'spam'], ['type' => 'trackback'],
        ['authorId' => 1, 'ownerId' => 1]] as $overrides) {
        notifyTestSame(0, CommentNotify_Plugin::queueComment(notifyTestComment($db, $overrides)), 'Skip ineligible comment');
    }
    notifyTestSame(0, CommentNotify_Plugin::queueComment(999999), 'Ignore a missing comment');
    notifyTestSame([], notifyTestRows($db), 'No ineligible comments reach the queue');
    $coid = notifyTestComment($db);
    notifyTestSame(2, CommentNotify_Plugin::queueComment($coid), 'Create independent QQ and WeChat jobs');
    notifyTestSame(0, CommentNotify_Plugin::queueComment($coid), 'Deduplicate repeated finish hooks');
};

$tests['author notification can be explicitly enabled'] = static function () use ($db, $settings): void {
    notifyTestSettings($db, array_replace($settings, ['notify_owner' => '1']));
    notifyTestSame(2, CommentNotify_Plugin::queueComment(notifyTestComment($db, ['authorId' => 1])),
        'Allow the author when the administrator enables it');
};

$tests['moderation hook waits for the committed database status'] = static function () use ($db, $directory): void {
    $coid = notifyTestComment($db, ['status' => 'waiting']);
    $before = $db->fetchRow($db->select()->from('table.comments')->where('coid = ?', $coid));
    CommentNotify_Plugin::mark($before, (object) ['coid' => $coid], 'approved');
    notifyTestSame([], notifyTestRows($db), 'The pre-update mark hook only remembers the ID');
    notifyTestSame([], notifyTestRequests($directory), 'A pre-update mark hook does not deliver');
    $db->query($db->update('table.comments')->rows(['status' => 'approved'])->where('coid = ?', $coid));
    CommentNotify_Plugin::flush();
    notifyTestSame(['sent', 'sent'], array_column(notifyTestRows($db), 'state'), 'Read the approved row after the update');
    CommentNotify_Plugin::mark($before, (object) ['coid' => $coid], 'approved');
    $db->query($db->update('table.comments')->rows(['status' => 'waiting'])->where('coid = ?', $coid));
    CommentNotify_Plugin::flush();
    notifyTestSame(2, count(notifyTestRequests($directory)), 'A later unapproved state never causes another notification');
};

$tests['finish hooks reread the database instead of trusting widget status'] = static function () use ($db): void {
    $coid = notifyTestComment($db, ['status' => 'waiting']);
    CommentNotify_Plugin::finishComment((object) ['coid' => $coid, 'status' => 'approved']);
    CommentNotify_Plugin::flush();
    notifyTestSame([], notifyTestRows($db), 'A forged or stale widget cannot bypass moderation');
    $db->query($db->update('table.comments')->rows(['status' => 'approved'])->where('coid = ?', $coid));
    CommentNotify_Plugin::finishComment((object) ['coid' => $coid]);
    CommentNotify_Plugin::flush();
    notifyTestSame(['sent', 'sent'], array_column(notifyTestRows($db), 'state'), 'Approved finish hook sends once per destination');
};

$tests['the real Typecho moderation path defers notifications until after its UPDATE'] = static function () use ($db, $directory): void {
    $coid = notifyTestComment($db, ['status' => 'waiting']);
    $user = Typecho_Widget::widget('Widget_User');
    notifyTestAssert($user->simpleLogin(['uid' => 1, 'name' => 'isolated-admin', 'screenName' => '测试管理员',
        'mail' => 'admin@example.invalid', 'url' => '', 'group' => 'administrator', 'authCode' => 'isolated-auth'], true),
        'Authenticate through the real core user widget');
    $editor = Typecho_Widget::widget('Widget_Comments_Edit');
    $mark = new ReflectionMethod($editor, 'mark');
    if (PHP_VERSION_ID < 80100) {
        $mark->setAccessible(true);
    }
    notifyTestAssert($mark->invoke($editor, $coid, 'approved'), 'The real core approves a waiting comment');
    notifyTestSame('approved', $db->fetchRow($db->select('status')->from('table.comments')->where('coid = ?', $coid))['status'],
        'The real Typecho mark method committed its UPDATE');
    notifyTestSame([], notifyTestRows($db), 'The hook does not enqueue while the old waiting state is visible');
    notifyTestSame([], notifyTestRequests($directory), 'Moderation does not block on outgoing notifications');
    CommentNotify_Plugin::flush();
    notifyTestSame(['sent', 'sent'], array_column(notifyTestRows($db), 'state'), 'Deferred processing sees the committed approved state');
};

$tests['OpenClaw sends a Bearer-authenticated message tool request with account IDs'] = static function () use ($db, $directory, $settings): void {
    $coid = notifyTestComment($db);
    CommentNotify_Service::enqueue($coid, '<b>访客</b> &amp; 中文 😀');
    $stats = CommentNotify_Service::process();
    notifyTestSame(2, $stats['sent'], 'Both platforms confirm delivery');
    $requests = notifyTestRequests($directory);
    notifyTestSame(2, count($requests), 'Send exactly one request per platform');
    foreach ($requests as $index => $request) {
        $kind = $index === 0 ? 'qq' : 'wechat';
        notifyTestSame('/tools/invoke', $request['path'], 'Use the OpenClaw tool API');
        notifyTestSame('Bearer ' . $settings['gateway_token'], $request['authorization'], 'Use a Bearer token');
        notifyTestSame('message', $request['body']['tool'], 'Invoke message without an agent turn');
        notifyTestSame('send', $request['body']['args']['action'], 'Use message send');
        notifyTestSame($settings[$kind . '_channel'], $request['body']['args']['channel'], 'Select the correct channel');
        notifyTestSame($settings[$kind . '_account'], $request['body']['args']['accountId'], 'Select the correct accountId');
        notifyTestSame($settings[$kind . '_targets'], $request['body']['args']['target'], 'Keep the recipient platform specific');
        notifyTestSame('访客 & 中文 😀', $request['body']['args']['message'], 'Send cleaned UTF-8 text');
    }
    notifyTestSame(0, CommentNotify_Service::enqueue($coid, '编辑后的文本'), 'Sending retains the original deduplication key');
    CommentNotify_Service::process();
    notifyTestSame(2, count(notifyTestRequests($directory)), 'Sent jobs are never automatically sent again');
    foreach (CommentNotify_Service::rows() as $row) {
        notifyTestAssert(!isset($row['envelope'], $row['config_hash'], $row['event_key'], $row['lock_token']), 'List only public queue metadata');
    }
    $database = json_encode(notifyTestRows($db), JSON_THROW_ON_ERROR);
    notifyTestAssert(!str_contains($database, $settings['gateway_token']) && !str_contains($database, $settings['qq_service_token']),
        'Queue snapshots never persist credentials');
};

$tests['multiple recipients have independent deduplicated jobs'] = static function () use ($db, $settings): void {
    $settings['qq_targets'] .= "\nqqbot:group:TEST_GROUP\nqqbot:c2c:TEST_OPENID";
    $settings['wechat_targets'] .= "\nsecond-user@im.wechat";
    notifyTestSettings($db, $settings);
    $coid = notifyTestComment($db);
    notifyTestSame(4, CommentNotify_Service::enqueue($coid, '多目标通知'), 'Create one row per distinct recipient');
    notifyTestSame(0, CommentNotify_Service::enqueue($coid, '多目标通知'), 'Dedupe each recipient independently');
    notifyTestSame(4, CommentNotify_Service::process()['sent'], 'Send all distinct jobs');
};

$tests['a QQ rejection does not prevent the WeChat delivery'] = static function () use ($db, $directory): void {
    notifyTestScenario($directory, 'fail-qq');
    CommentNotify_Service::enqueue(notifyTestComment($db), '分别投递');
    $stats = CommentNotify_Service::process();
    notifyTestSame(1, $stats['sent'], 'WeChat still succeeds');
    notifyTestSame(1, $stats['failed'], 'QQ failure is reported independently');
    notifyTestSame(['pending', 'sent'], array_column(notifyTestRows($db), 'state'), 'Keep the unsuccessful destination pending only');
};

$tests['explicit failures back off and stop after five attempts'] = static function () use ($db, $settings, $directory): void {
    notifyTestSettings($db, array_replace($settings, ['wechat_enabled' => '0']));
    notifyTestScenario($directory, 'business-error');
    CommentNotify_Service::enqueue(notifyTestComment($db), '受限重试');
    $id = (int) notifyTestRows($db)[0]['id'];
    $requestId = json_decode(notifyTestRows($db)[0]['envelope'], true)['request_id'];
    for ($attempt = 1; $attempt <= 5; ++$attempt) {
        $before = time();
        notifyTestSame(1, CommentNotify_Service::process()['failed'], 'Count each explicit failure');
        $row = notifyTestRows($db)[0];
        notifyTestSame($attempt, (int) $row['attempts'], 'Increment persisted attempts');
        notifyTestSame($attempt === 5 ? 'failed' : 'pending', $row['state'], 'Stop after the fifth rejection');
        if ($attempt < 5) {
            notifyTestAssert((int) $row['next_at'] >= $before + 60 * (2 ** ($attempt - 1)), 'Apply exponential backoff');
            notifyTestSame(0, CommentNotify_Service::process()['failed'], 'Do not retry before the backoff expires');
            notifyTestSetRow($db, $id, ['next_at' => time() - 1]);
        }
    }
    CommentNotify_Service::process();
    notifyTestSame(5, count(notifyTestRequests($directory)), 'Never exceed five automatic attempts');
    foreach (notifyTestRequests($directory) as $request) {
        notifyTestSame($requestId, $request['body']['idempotencyKey'], 'Preserve a stable idempotency key across retries');
    }
};

foreach (['nested-error', 'text-error', 'http-rejected'] as $scenario) {
    $tests['recognize definite rejection: ' . $scenario] = static function () use ($db, $settings, $directory, $scenario): void {
        notifyTestSettings($db, array_replace($settings, ['wechat_enabled' => '0']));
        notifyTestScenario($directory, $scenario);
        CommentNotify_Service::enqueue(notifyTestComment($db), '错误识别');
        $stats = CommentNotify_Service::process();
        notifyTestSame(1, $stats['failed'], 'A business error must never be marked sent');
        notifyTestSame('pending', notifyTestRows($db)[0]['state'], 'Only a definite rejection may be retried automatically');
        notifyTestAssert(!str_contains(notifyTestRows($db)[0]['error'], 'PRIVATE-MOCK'), 'Never expose remote credentials in a stored error');
    };
}

foreach (['empty', 'malformed', 'http-error', 'timeout'] as $scenario) {
    $tests['ambiguous delivery requires manual review: ' . $scenario] = static function () use ($db, $settings, $directory, $scenario): void {
        notifyTestSettings($db, array_replace($settings, ['wechat_enabled' => '0']));
        notifyTestScenario($directory, $scenario);
        CommentNotify_Service::enqueue(notifyTestComment($db), '请核实机器人是否收到');
        notifyTestSame(1, CommentNotify_Service::process()['uncertain'], 'Persist ambiguous network/server responses');
        notifyTestSame('uncertain', notifyTestRows($db)[0]['state'], 'Require a manual decision after an ambiguous send');
        CommentNotify_Service::process();
        CommentNotify_Service::process();
        notifyTestSame(1, count(notifyTestRequests($directory)), 'Never automatically repeat an ambiguous delivery');
    };
}

$tests['expired worker leases become uncertain without automatic redelivery'] = static function () use ($db, $settings, $directory): void {
    notifyTestSettings($db, array_replace($settings, ['wechat_enabled' => '0']));
    CommentNotify_Service::enqueue(notifyTestComment($db), '过期发送进程');
    $id = (int) notifyTestRows($db)[0]['id'];
    notifyTestSetRow($db, $id, ['state' => 'sending', 'lock_at' => time() - 121, 'lock_token' => 'dead-worker', 'attempts' => 1]);
    notifyTestSame(1, CommentNotify_Service::process()['uncertain'], 'Recognize an interrupted sending worker');
    notifyTestSame('uncertain', notifyTestRows($db)[0]['state'], 'Do not reclaim an expired lease for redelivery');
    notifyTestSame([], notifyTestRequests($directory), 'No network request occurs for a crashed worker lease');
};

$tests['two real PHP workers cannot deliver the same claimed job'] = static function () use ($db, $settings, $directory, $filename): void {
    notifyTestSettings($db, array_replace($settings, ['wechat_enabled' => '0']));
    notifyTestScenario($directory, 'slow');
    CommentNotify_Service::enqueue(notifyTestComment($db), '竞争发送');
    $first = notifyTestStartPhp([__DIR__ . '/process-worker.php', $filename, '1']);
    $second = notifyTestStartPhp([__DIR__ . '/process-worker.php', $filename, '1']);
    foreach ([$first, $second] as $worker) {
        $result = notifyTestFinishPhp($worker);
        notifyTestSame(0, $result['code'], 'Concurrent worker completes: ' . $result['stderr']);
        notifyTestAssert(is_array(json_decode($result['stdout'], true)), 'Worker returns delivery statistics');
    }
    notifyTestSame(1, count(notifyTestRequests($directory)), 'An atomic lease allows exactly one outgoing request');
    notifyTestSame('sent', notifyTestRows($db)[0]['state'], 'The winning worker commits success');
    notifyTestSame(1, (int) notifyTestRows($db)[0]['attempts'], 'Only the lease owner consumes an attempt');
};

$tests['gateway, recipient and account changes cancel stale jobs without disclosing tokens'] = static function () use ($db, $settings, $directory): void {
    foreach ([['gateway_url' => $settings['gateway_url'] . '/new-server'], ['qq_targets' => 'qqbot:c2c:NEW_OPENID'],
        ['qq_account' => 'another-account'], ['qq_enabled' => '0']] as $change) {
        $db->query($db->delete(CommentNotify_Service::TABLE));
        $original = array_replace($settings, ['wechat_enabled' => '0']);
        notifyTestSettings($db, $original);
        CommentNotify_Service::enqueue(notifyTestComment($db), '不能转移旧任务');
        notifyTestSettings($db, array_replace($original, $change));
        notifyTestSame(1, CommentNotify_Service::process()['cancelled'], 'Cancel jobs whose destination snapshot no longer matches');
        notifyTestSame('cancelled', notifyTestRows($db)[0]['state'], 'Persist cancellation');
        notifyTestAssert(!CommentNotify_Service::retry((int) notifyTestRows($db)[0]['id']), 'Do not retry cancelled jobs');
    }
    notifyTestSame([], notifyTestRequests($directory), 'Never send an old token to a changed gateway or recipient');
};

$tests['manual retry preserves the original request ID and rejects changed destinations'] = static function () use ($db, $settings, $directory): void {
    notifyTestSettings($db, array_replace($settings, ['wechat_enabled' => '0']));
    notifyTestScenario($directory, 'empty');
    CommentNotify_Service::enqueue(notifyTestComment($db), '人工核实后重试');
    CommentNotify_Service::process();
    $row = notifyTestRows($db)[0];
    $id = (int) $row['id'];
    $requestId = json_decode($row['envelope'], true)['request_id'];
    notifyTestSettings($db, array_replace($settings, ['wechat_enabled' => '0', 'qq_targets' => 'qqbot:c2c:NEW_OPENID']));
    notifyTestAssert(!CommentNotify_Service::retry($id), 'A manual retry cannot redirect an existing notification');
    notifyTestSettings($db, array_replace($settings, ['wechat_enabled' => '0']));
    notifyTestAssert(CommentNotify_Service::retry($id), 'Allow deliberate retry after the administrator checks delivery');
    notifyTestScenario($directory, 'success');
    notifyTestSame(1, CommentNotify_Service::process()['sent'], 'Manual retry can complete');
    notifyTestSame($requestId, notifyTestRequests($directory)[1]['body']['idempotencyKey'], 'Use the original idempotency key after manual retry');
};

$tests['moderated or deleted comments are cancelled immediately before sending'] = static function () use ($db, $settings, $directory): void {
    notifyTestSettings($db, array_replace($settings, ['wechat_enabled' => '0']));
    $coid = notifyTestComment($db);
    CommentNotify_Service::enqueue($coid, '审核状态发生变化');
    $db->query($db->update('table.comments')->rows(['status' => 'spam'])->where('coid = ?', $coid));
    notifyTestSame(1, CommentNotify_Service::process()['cancelled'], 'Cancel a comment that is no longer approved');
    $coid = notifyTestComment($db);
    CommentNotify_Service::enqueue($coid, '评论被删除');
    $db->query($db->delete('table.comments')->where('coid = ?', $coid));
    notifyTestSame(1, CommentNotify_Service::process()['cancelled'], 'Cancel a deleted comment');
    notifyTestSame([], notifyTestRequests($directory), 'Never deliver comments that fail the final database check');
};

$tests['a cancelled comment may notify once when it is approved again'] = static function () use ($db, $settings, $directory): void {
    notifyTestSettings($db, array_replace($settings, ['wechat_enabled' => '0']));
    $coid = notifyTestComment($db);
    CommentNotify_Service::enqueue($coid, '第一次审核');
    $first = notifyTestRows($db)[0];
    $db->query($db->update('table.comments')->rows(['status' => 'waiting'])->where('coid = ?', $coid));
    notifyTestSame(1, CommentNotify_Service::process()['cancelled'], 'Cancel a job when its comment returns to moderation');
    $db->query($db->update('table.comments')->rows(['status' => 'approved'])->where('coid = ?', $coid));
    notifyTestSame(1, CommentNotify_Service::enqueue($coid, '再次审核通过'), 'A new approved event may restore an unsent cancelled job');
    notifyTestSame((int) $first['id'], (int) notifyTestRows($db)[0]['id'], 'Keep one durable row for this comment and recipient');
    notifyTestSame(1, CommentNotify_Service::process()['sent'], 'Notify the newly approved comment');
    notifyTestSame(0, CommentNotify_Service::enqueue($coid, '不能再发'), 'A successful delivery cannot be restored again');
    notifyTestSame(1, count(notifyTestRequests($directory)), 'The recipient receives exactly one successful notification');
};

$tests['QQ service remains compatible with /api/notifications'] = static function () use ($db, $settings, $directory): void {
    notifyTestSettings($db, array_replace($settings, ['qq_provider' => 'service', 'qq_targets' => 'owner', 'wechat_enabled' => '0']));
    foreach (['service-queued', 'service-sent'] as $scenario) {
        notifyTestScenario($directory, $scenario);
        CommentNotify_Service::enqueue(notifyTestComment($db), '旧版 QQ 服务兼容');
        notifyTestSame(1, CommentNotify_Service::process()['sent'], 'Accept a confirmed QQ service response');
    }
    foreach (notifyTestRequests($directory) as $request) {
        notifyTestSame('/api/notifications', $request['path'], 'Keep the Enhancement QQ service endpoint');
        notifyTestSame('Bearer ' . $settings['qq_service_token'], $request['authorization'], 'Use the QQ service credential only');
        notifyTestSame(['owner'], $request['body']['targets'], 'Send a single bound service alias');
        notifyTestSame('旧版 QQ 服务兼容', $request['body']['message'], 'Keep the legacy message field');
        notifyTestAssert(isset($request['body']['idempotency_key']), 'Keep the legacy idempotency key field');
    }
};

$tests['Unicode truncation and link suppression produce valid bounded text'] = static function (): void {
    $text = CommentNotify_Transport::prepareMessage(str_repeat('中文😀', 400));
    notifyTestAssert(strlen($text) <= 1800, 'Respect the QQ 1800-byte limit');
    notifyTestAssert((bool) preg_match('//u', $text), 'Never cut a UTF-8 code point');
    notifyTestAssert(str_ends_with($text, '…'), 'Clearly mark a truncated message');
    $clean = CommentNotify_Transport::prepareMessage('<b>评论</b> &amp; https://example.invalid/a www.example.invalid mailto:private@example.invalid', false);
    notifyTestAssert(!str_contains($clean, 'example.invalid') && !str_contains($clean, '<b>'), 'Suppress links and markup');
    notifyTestAssert(str_contains($clean, '评论 &'), 'Preserve readable text and decoded entities');
    notifyTestAssert((bool) preg_match('//u', CommentNotify_Transport::prepareMessage("坏字节\xff")), 'Substitute malformed UTF-8 safely');
};

$tests['blank secret submissions retain tokens only for unchanged endpoints'] = static function () use ($settings): void {
    $blank = array_replace($settings, ['gateway_token' => '', 'qq_service_token' => '']);
    $merged = CommentNotify_Plugin::mergeSecrets($blank);
    notifyTestSame($settings['gateway_token'], $merged['gateway_token'], 'Keep an unchanged gateway credential');
    notifyTestSame($settings['qq_service_token'], $merged['qq_service_token'], 'Keep an unchanged QQ service credential');
    foreach (['gateway_url', 'qq_service_url'] as $key) {
        $rejected = false;
        try {
            CommentNotify_Plugin::mergeSecrets(array_replace($blank, [$key => $settings[$key] . '/different']));
        } catch (InvalidArgumentException $exception) {
            $rejected = true;
        }
        notifyTestAssert($rejected, 'Require a newly entered token when changing ' . $key);
    }
};

$tests['the real settings handler persists blank secrets safely and rejects invalid settings'] = static function () use ($db, $settings): void {
    CommentNotify_Plugin::configHandle(array_replace($settings, ['gateway_token' => '', 'qq_service_token' => '', 'qq_enabled' => '0']), false);
    $row = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'plugin:CommentNotify'));
    $stored = json_decode($row['value'], true, 512, JSON_THROW_ON_ERROR);
    notifyTestSame($settings['gateway_token'], $stored['gateway_token'], 'The core saves the preserved gateway credential');
    notifyTestSame($settings['qq_service_token'], $stored['qq_service_token'], 'The core saves the preserved QQ service credential');
    notifyTestSame('0', $stored['qq_enabled'], 'Persist changed notification switches');
    $rejected = false;
    try {
        CommentNotify_Plugin::configHandle(array_replace($settings, ['wechat_targets' => 'someone-nickname']), false);
    } catch (Typecho_Widget_Exception $exception) {
        $rejected = true;
    }
    notifyTestAssert($rejected, 'Reject a nickname that is not a supported WeChat bot user ID');
    $after = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'plugin:CommentNotify'));
    notifyTestSame($row['value'], $after['value'], 'Invalid submitted settings never replace saved credentials');
};

$tests['saving channel changes refreshes the same request before shutdown delivery'] = static function () use ($db, $settings, $directory): void {
    // Load the Options plugin cache, then change configuration through the actual save handler.
    notifyTestSame('1', CommentNotify_Service::settings()['qq_enabled'], 'Prime the same-request Options cache');
    CommentNotify_Service::enqueue(notifyTestComment($db), '保存关闭后不能继续投递');
    CommentNotify_Plugin::configHandle(array_replace($settings, ['qq_enabled' => '0', 'wechat_enabled' => '0']), false);
    notifyTestSame('0', CommentNotify_Service::settings()['qq_enabled'], 'The saved QQ switch is immediately visible');
    notifyTestSame('0', CommentNotify_Service::settings()['wechat_enabled'], 'The saved WeChat switch is immediately visible');
    notifyTestSame(2, CommentNotify_Service::process()['cancelled'], 'Cancel both old destinations before shutdown can deliver');
    notifyTestSame([], notifyTestRequests($directory), 'Saving disabled channels prevents outgoing requests in that same request');
};

$tests['default disabled settings validate without credentials or network traffic'] = static function () use ($db, $directory): void {
    $defaults = CommentNotify_Transport::validate(CommentNotify_Transport::defaults());
    notifyTestSame('0', $defaults['qq_enabled'], 'QQ is disabled by default');
    notifyTestSame('0', $defaults['wechat_enabled'], 'WeChat is disabled by default');
    notifyTestSettings($db, $defaults);
    notifyTestSame(0, CommentNotify_Plugin::queueComment(notifyTestComment($db)), 'An unconfigured plugin creates no jobs');
    CommentNotify_Service::process();
    notifyTestSame([], notifyTestRequests($directory), 'Default activation cannot issue a network request');
};

$tests['test action enforces administrator, POST and CSRF checks'] = static function () use ($db, $directory, $filename): void {
    $before = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'plugin:CommentNotify'))['value'];
    foreach (['anonymous' => 403, 'contributor' => 403, 'get' => 405, 'csrf' => 403, 'invalid-kind' => 400] as $mode => $status) {
        $result = notifyTestFinishPhp(notifyTestStartPhp([__DIR__ . '/action-worker.php', $filename, $mode]));
        notifyTestSame(0, $result['code'], 'The action returns a controlled response: ' . $result['stderr']);
        notifyTestAssert(str_contains($result['stderr'], 'STATUS:' . $status), 'Return HTTP ' . $status . ' for ' . $mode);
        $body = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        notifyTestSame(false, $body['ok'], 'Reject invalid test requests');
    }
    notifyTestSame([], notifyTestRequests($directory), 'Rejected action calls never reach the mock robot');
    notifyTestSame($before, $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'plugin:CommentNotify'))['value'],
        'Rejected test requests cannot save settings');
};

$tests['valid test actions use unsaved fields and send only the chosen platform'] = static function () use ($db, $directory, $filename): void {
    $before = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'plugin:CommentNotify'))['value'];
    foreach (['qq', 'wechat'] as $kind) {
        $result = notifyTestFinishPhp(notifyTestStartPhp([__DIR__ . '/action-worker.php', $filename, $kind]));
        notifyTestSame(0, $result['code'], 'A valid test action returns cleanly: ' . $result['stderr']);
        notifyTestAssert(str_contains($result['stderr'], 'STATUS:200'), 'A valid test action returns HTTP 200');
        $body = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        notifyTestSame(true, $body['ok'], 'The selected test platform confirms sending');
        notifyTestSame(1, count($body['results']), 'Test only the explicitly selected recipient list');
    }
    $requests = notifyTestRequests($directory);
    notifyTestSame(2, count($requests), 'Two explicit platform tests send exactly two messages');
    notifyTestSame('qqbot:c2c:UNSAVED_QQ', $requests[0]['body']['args']['target'], 'Use the unsaved QQ field');
    notifyTestSame('unsaved-target@im.wechat', $requests[1]['body']['args']['target'], 'Use the unsaved WeChat field');
    notifyTestSame($before, $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'plugin:CommentNotify'))['value'],
        'Testing a channel never saves the form');
    notifyTestSame([], notifyTestRows($db), 'Test messages do not become comment notification jobs');
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        notifyTestReset($db, $settings, $directory);
        $test();
        fwrite(STDOUT, 'PASS ' . $name . "\n");
    } catch (Throwable $exception) {
        ++$failed;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    }
}
fwrite(STDOUT, count($tests) . ' tests, ' . $failed . " failures.\n");
exit($failed === 0 ? 0 : 1);
