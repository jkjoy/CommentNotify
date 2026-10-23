<?php
declare(strict_types=1);

if (!defined('__TYPECHO_ROOT_DIR__')) { http_response_code(403); exit; }

require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Qq.php';
require_once __DIR__ . '/QqWebhook.php';
require_once __DIR__ . '/Weixin.php';

function comment_notify_defaults(): array
{
    return [
        'protocol_version' => 2,
        'qq_enabled' => false, 'qq_app_id' => '', 'qq_app_secret' => '',
        'qq_target_type' => 'c2c', 'qq_target_id' => '',
        'weixin_enabled' => false, 'weixin_user_id' => '',
        'notify_pending' => true, 'notify_approved' => true,
        'notify_admin' => false, 'include_link' => true,
    ];
}

/** Configuration reads use the writer so saved credentials are immediately visible. */
function comment_notify_option(string $name): array
{
    $db = Typecho_Db::get();
    $query = $db->select('value')->from('table.options')->where('name = ? AND user = ?', $name, 0)->limit(1);
    $row = $db->fetchRow(comment_notify_query($query));
    $value = $row ? json_decode((string)$row['value'], true) : null;
    return is_array($value) ? $value : [];
}

function comment_notify_store(string $name, array $record): void
{
    $db = Typecho_Db::get();
    $value = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $query = $db->select('name')->from('table.options')->where('name = ? AND user = ?', $name, 0)->limit(1);
    $exists = $db->fetchRow(comment_notify_query($query));
    if (!$exists) {
        try {
            comment_notify_query($db->insert('table.options')->rows(['name' => $name, 'user' => 0, 'value' => $value]));
            return;
        } catch (Throwable $exception) {
            if (!$db->fetchRow(comment_notify_query($query))) { throw $exception; }
        }
    }
    comment_notify_query($db->update('table.options')->rows(['value' => $value])->where('name = ? AND user = ?', $name, 0));
}

function comment_notify_read(string $key): array
{
    return comment_notify_state_read($key);
}

function comment_notify_write(string $key, array $record): void
{
    comment_notify_state_write($key, $record);
}

function comment_notify_config(): array
{
    $defaults = comment_notify_defaults();
    $saved = comment_notify_option('plugin:CommentNotify');
    if (($saved['protocol_version'] ?? null) !== 2) { return $defaults; }
    foreach ($defaults as $key => $value) {
        if (array_key_exists($key, $saved) && gettype($saved[$key]) === gettype($value)) { $defaults[$key] = $saved[$key]; }
    }
    return $defaults;
}

function comment_notify_input(array $input, string $key): string
{
    return isset($input[$key]) && is_scalar($input[$key]) ? trim((string)$input[$key]) : '';
}

function comment_notify_validate(array $input, array $existing): array
{
    $existing = array_replace(comment_notify_defaults(), array_intersect_key($existing, comment_notify_defaults()));
    $config = $existing;
    $errors = [];
    foreach (['qq_enabled', 'weixin_enabled', 'notify_pending', 'notify_approved', 'notify_admin', 'include_link'] as $key) {
        $value = comment_notify_input($input, $key);
        if (array_key_exists($key, $input) && !in_array($input[$key], [false, true, 0, 1, '0', '1', ''], true)) {
            $errors[] = '通知开关值必须为 0 或 1。';
        }
        $config[$key] = $value === '1';
    }
    foreach (['qq_app_id', 'qq_app_secret', 'qq_target_type', 'qq_target_id'] as $key) {
        if (isset($input[$key]) && !is_scalar($input[$key])) { $errors[] = 'QQ 配置项必须是文本。'; }
    }
    foreach (['qq_app_id', 'qq_target_type', 'qq_target_id'] as $key) { $config[$key] = comment_notify_input($input, $key); }
    $secret = comment_notify_input($input, 'qq_app_secret');
    if ($secret !== '') {
        $config['qq_app_secret'] = $secret;
    } elseif ($config['qq_app_id'] !== $existing['qq_app_id']) {
        $config['qq_app_secret'] = '';
        if ($config['qq_app_id'] !== '') { $errors[] = '更换 QQ AppID 时，请重新填写 AppSecret。'; }
    }
    if ($config['qq_app_id'] !== '' && !preg_match('/\A[0-9]{1,32}\z/D', $config['qq_app_id'])) { $errors[] = 'QQ AppID 只能填写数字。'; }
    if ($config['qq_app_secret'] !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $config['qq_app_secret'])) { $errors[] = 'QQ AppSecret 格式无效。'; }
    if (!in_array($config['qq_target_type'], ['c2c', 'group', 'channel'], true)) { $errors[] = '请选择有效的 QQ 接收类型。'; }
    if ($config['qq_target_id'] !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $config['qq_target_id'])) { $errors[] = 'QQ 接收 ID 格式无效。'; }
    if ($config['qq_enabled'] && ($config['qq_app_id'] === '' || $config['qq_app_secret'] === '' || $config['qq_target_id'] === '')) {
        $errors[] = '启用 QQ 通知前，请填写 AppID、AppSecret 和接收 ID。';
    }
    if ($config['weixin_enabled']) {
        $account = comment_notify_read('weixin_account');
        if (empty($account['token']) || !empty($account['expired']) || $config['weixin_user_id'] === '') {
            $errors[] = '启用微信通知前，请先扫码登录，并向机器人发送消息后同步绑定接收人。';
        }
    }
    if (!$config['notify_pending'] && !$config['notify_approved']) { $errors[] = '请至少选择一种评论状态。'; }
    return ['config' => $config, 'errors' => array_values(array_unique($errors))];
}

function comment_notify_save_config(array $config): void
{
    $existing = comment_notify_config();
    $config = array_replace(comment_notify_defaults(), array_intersect_key($config, comment_notify_defaults()));
    $config['protocol_version'] = 2;
    if ($existing['qq_app_id'] !== $config['qq_app_id'] || $existing['qq_app_secret'] !== $config['qq_app_secret']) {
        foreach (['qq_token', 'qq_binding', 'qq_webhook'] as $key) { comment_notify_write($key, []); }
    } elseif ($existing['qq_target_type'] !== $config['qq_target_type'] || $existing['qq_target_id'] !== $config['qq_target_id']) {
        comment_notify_write('qq_binding', []);
    }
    // Replace the complete option to discard credentials and switches from the old transport.
    comment_notify_store('plugin:CommentNotify', $config);
}

/** Preserve concurrent settings changes while applying the winner's bound QQ target. */
function comment_notify_qq_save_bound_target(array $expected, string $target): bool
{
    $db = Typecho_Db::get();
    $query = $db->select('value')->from('table.options')
        ->where('name = ? AND user = ?', 'plugin:CommentNotify', 0)->limit(1);
    $row = $db->fetchRow(comment_notify_query($query));
    $raw = $row ? (string)$row['value'] : '';
    $current = json_decode($raw, true);
    if (!is_array($current) || ($current['protocol_version'] ?? null) !== 2
        || ($current['qq_app_id'] ?? null) !== $expected['qq_app_id']
        || ($current['qq_app_secret'] ?? null) !== $expected['qq_app_secret']
        || ($current['qq_target_type'] ?? null) !== $expected['qq_target_type']
        || ($current['qq_target_id'] ?? null) !== $expected['qq_target_id']) { return false; }
    if (($current['qq_target_id'] ?? null) === $target) { return true; }
    $current['qq_target_id'] = $target;
    $value = json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $condition = preg_match('/mysql|mysqli/i', $db->getAdapterName()) ? 'BINARY value = ?' : 'value = ?';
    return (bool)comment_notify_query($db->update('table.options')->rows(['value' => $value])
        ->where('name = ? AND user = ?', 'plugin:CommentNotify', 0)->where($condition, $raw));
}

function comment_notify_admin_url(): string
{
    return Typecho_Common::url('options-plugin.php?config=CommentNotify', Typecho_Widget::widget('Widget_Options')->adminUrl);
}

function comment_notify_status(): array
{
    $config = comment_notify_config();
    return [
        'qq' => array_merge(comment_notify_qq_webhook_status(), [
            'target_type' => $config['qq_target_type'], 'target_id' => $config['qq_target_id'], 'enabled' => $config['qq_enabled'],
        ]),
        'weixin' => array_merge(comment_notify_weixin_status(), ['enabled' => $config['weixin_enabled']]),
        'results' => ['qq' => comment_notify_read('result_qq'), 'weixin' => comment_notify_read('result_weixin')],
    ];
}

function comment_notify_text(string $value, int $limit): string
{
    $text = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = trim((string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $text));
    $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($characters)) { return ''; }
    return count($characters) > $limit ? implode('', array_slice($characters, 0, $limit)) . '…' : $text;
}

function comment_notify_deliver(string $channel, array $config, string $text, ?callable $transport = null): array
{
    try {
        match ($channel) {
            'qq' => comment_notify_qq_send($config, $text, $transport),
            'weixin' => comment_notify_weixin_send($config, $text, $transport),
            default => throw new RuntimeException('无效的通知渠道。'),
        };
        $result = ['ok' => true, 'message' => ($channel === 'qq' ? 'QQ' : '微信') . '接口已接受通知。', 'time' => time()];
    } catch (Throwable $exception) {
        $message = get_class($exception) === RuntimeException::class ? $exception->getMessage() : '通知发送失败，请检查数据库和服务器环境。';
        $result = ['ok' => false, 'message' => $message, 'time' => time()];
    }
    try { comment_notify_write('result_' . $channel, $result); }
    catch (Throwable) { error_log('[CommentNotify] 无法保存通知结果。'); }
    return $result;
}

function comment_notify_test(string $channel, ?callable $transport = null): array
{
    $config = comment_notify_config();
    $options = Typecho_Widget::widget('Widget_Options');
    $text = '评论通知连接测试' . "\n站点：" . comment_notify_text((string)$options->title, 60)
        . "\n时间：" . (new Typecho_Date(time()))->format('Y-m-d H:i:s');
    if ($config['include_link']) { $text .= "\n" . $options->siteUrl; }
    return comment_notify_deliver($channel, $config, $text, $transport);
}

function comment_notify_comment_created(int $coid, ?callable $transport = null): void
{
    try {
        $config = comment_notify_config();
        if (!$config['qq_enabled'] && !$config['weixin_enabled']) { return; }
        $db = Typecho_Db::get();
        $query = $db->select()->from('table.comments')->where('coid = ?', $coid)->limit(1);
        $comment = $db->fetchRow(comment_notify_query($query));
        if (!$comment || $comment['type'] !== 'comment' || !in_array($comment['status'], ['waiting', 'approved'], true)
            || !$config[$comment['status'] === 'waiting' ? 'notify_pending' : 'notify_approved']
            || (!$config['notify_admin'] && (int)$comment['authorId'] > 0)) { return; }
        $widget = Typecho_Widget::widget('Widget_Abstract_Comments@comment-notify-' . $coid);
        $widget->push($comment);
        $text = '新评论 · ' . comment_notify_text((string)Typecho_Widget::widget('Widget_Options')->title, 60)
            . "\n文章：" . comment_notify_text((string)$widget->title, 100)
            . "\n状态：" . ($comment['status'] === 'approved' ? '已发布' : '待审核')
            . "\n评论人：" . comment_notify_text((string)$comment['author'], 50)
            . "\n\n" . comment_notify_text((string)$comment['text'], 400);
        if ($config['include_link']) {
            $text .= "\n\n" . ($comment['status'] === 'approved' ? $widget->permalink : $widget->parentContent->permalink . '#comments');
        }
        foreach (['qq', 'weixin'] as $channel) {
            if ($config[$channel . '_enabled']) { comment_notify_deliver($channel, $config, $text, $transport); }
        }
    } catch (Throwable) {
        error_log('[CommentNotify] 无法准备评论通知。');
    }
}
