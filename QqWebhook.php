<?php

declare(strict_types=1);

if (!defined('__TYPECHO_ROOT_DIR__')) { http_response_code(403); exit; }

function comment_notify_qq_webhook_url(): string
{
    return Typecho_Common::url('action/comment-notify?do=qq_webhook', Typecho_Widget::widget('Widget_Options')->index);
}

function comment_notify_qq_identity(array $config): string
{
    return hash('sha256', $config['qq_app_id'] . ':' . $config['qq_app_secret']);
}

function comment_notify_qq_keypair(string $secret): string
{
    if (!function_exists('sodium_crypto_sign_seed_keypair')) { throw new RuntimeException('QQ Webhook 需要启用 PHP sodium 扩展。'); }
    if ($secret === '') { throw new RuntimeException('请先保存 QQ AppID 和 AppSecret。'); }
    $seed = $secret;
    while (strlen($seed) < 32) { $seed .= $secret; }
    return sodium_crypto_sign_seed_keypair(substr($seed, 0, 32));
}

function comment_notify_qq_webhook_verify(string $raw, string $timestamp, string $signature, string $secret): bool
{
    if ($secret === '' || !function_exists('sodium_crypto_sign_verify_detached')
        || !preg_match('/\A[0-9]{1,20}\z/', $timestamp) || !preg_match('/\A[a-fA-F0-9]{128}\z/', $signature)) { return false; }
    $keypair = comment_notify_qq_keypair($secret);
    return sodium_crypto_sign_verify_detached((string)hex2bin($signature), $timestamp . $raw, sodium_crypto_sign_publickey($keypair));
}

function comment_notify_qq_webhook_status(): array
{
    $config = comment_notify_config();
    $identity = comment_notify_qq_identity($config);
    $state = comment_notify_read('qq_webhook');
    $binding = comment_notify_read('qq_binding');
    if (($state['identity'] ?? '') !== $identity) { $state = []; }
    if (($binding['identity'] ?? '') !== $identity || (int)($binding['expires_at'] ?? 0) <= time()
        || !is_string($binding['code'] ?? null) || !preg_match('/\A[A-F0-9]{16}\z/', $binding['code'])
        || !in_array($binding['target_type'] ?? '', ['c2c', 'group'], true)) { $binding = []; }
    return [
        'available' => function_exists('sodium_crypto_sign_verify_detached'),
        'configured' => $config['qq_app_id'] !== '' && $config['qq_app_secret'] !== '',
        'url' => comment_notify_qq_webhook_url(),
        'last_challenge_at' => (int)($state['last_challenge_at'] ?? 0),
        'last_event_at' => (int)($state['last_event_at'] ?? 0),
        'binding' => $binding === [] ? [] : array_intersect_key($binding, array_flip(['code', 'expires_at', 'target_type'])),
    ];
}

function comment_notify_qq_binding_start(): array
{
    $config = comment_notify_config();
    comment_notify_qq_keypair($config['qq_app_secret']);
    if ($config['qq_app_id'] === '') { throw new RuntimeException('请先保存 QQ AppID 和 AppSecret。'); }
    if (!in_array($config['qq_target_type'], ['c2c', 'group'], true)) { throw new RuntimeException('自动绑定支持 QQ 私聊和群聊；频道请手动填写子频道 ID。'); }
    $binding = ['code' => strtoupper(bin2hex(random_bytes(8))), 'expires_at' => time() + 600,
        'target_type' => $config['qq_target_type'], 'identity' => comment_notify_qq_identity($config)];
    comment_notify_write('qq_binding', $binding);
    return array_intersect_key($binding, array_flip(['code', 'expires_at', 'target_type']));
}

function comment_notify_qq_binding_cancel(): void
{
    comment_notify_write('qq_binding', []);
}

function comment_notify_qq_bind_event(array $event, int $now): void
{
    $type = match ($event['t'] ?? null) { 'C2C_MESSAGE_CREATE' => 'c2c', 'GROUP_AT_MESSAGE_CREATE' => 'group', default => '' };
    $data = $event['d'] ?? null;
    if ($type === '' || !is_array($data) || !is_string($data['content'] ?? null)) { return; }
    $target = $type === 'group' ? ($data['group_openid'] ?? null) : ($data['author']['user_openid'] ?? null);
    if (!is_string($target) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $target)) { return; }
    $content = preg_replace('/\A\s*(?:<@!?[A-Za-z0-9_-]+>\s*)*/u', '', $data['content']);
    if (!is_string($content) || !preg_match('/\A绑定通知\s+([A-Fa-f0-9]{16})\s*\z/u', $content, $matches)) { return; }
    $code = strtoupper($matches[1]);
    $pending = comment_notify_read('qq_binding');
    if (!is_string($pending['code'] ?? null) || !hash_equals($pending['code'], $code)) { return; }

    $config = comment_notify_config();
    if ((int)($pending['expires_at'] ?? 0) <= $now || ($pending['target_type'] ?? '') !== $type
        || ($pending['identity'] ?? '') !== comment_notify_qq_identity($config) || $config['qq_target_type'] !== $type) { return; }
    if (!comment_notify_state_consume('qq_binding', $pending)) { return; }
    if (!comment_notify_qq_save_bound_target($config, $target)) {
        throw new RuntimeException('QQ 配置已变更，请重新生成绑定码。');
    }
}

function comment_notify_qq_webhook_handle(string $method, string $raw, array $headers, ?int $now = null): array
{
    $now ??= time();
    $failure = static fn(int $status): array => ['status' => $status, 'body' => ['op' => 12, 'd' => 1]];
    if ($method !== 'POST') { return $failure(405); }
    if (strlen($raw) > 65536) { return $failure(413); }
    $config = comment_notify_config();
    if ($config['qq_app_id'] === '' || $config['qq_app_secret'] === '' || !function_exists('sodium_crypto_sign_verify_detached')) { return $failure(503); }
    $appId = $headers['x-bot-appid'] ?? null;
    $timestamp = $headers['x-signature-timestamp'] ?? null;
    $signature = $headers['x-signature-ed25519'] ?? null;
    if (!is_string($appId) || !hash_equals($config['qq_app_id'], $appId)
        || !is_string($timestamp) || !is_string($signature)
        || !comment_notify_qq_webhook_verify($raw, $timestamp, $signature, $config['qq_app_secret'])) { return $failure(403); }
    try { $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { return $failure(400); }
    if (!is_array($event) || !is_int($event['op'] ?? null) || !is_array($event['d'] ?? null)) { return $failure(400); }
    $state = comment_notify_read('qq_webhook');
    $identity = comment_notify_qq_identity($config);
    if (($state['identity'] ?? '') !== $identity) { $state = ['identity' => $identity]; }
    if ($event['op'] === 13) {
        $token = $event['d']['plain_token'] ?? null;
        $eventTs = $event['d']['event_ts'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 4096 || !is_string($eventTs)
            || !preg_match('/\A[0-9]{1,20}\z/', $eventTs)) { return $failure(400); }
        $key = sodium_crypto_sign_secretkey(comment_notify_qq_keypair($config['qq_app_secret']));
        $signature = bin2hex(sodium_crypto_sign_detached($eventTs . $token, $key));
        $state['last_challenge_at'] = $now;
        comment_notify_write('qq_webhook', $state);
        return ['status' => 200, 'body' => ['plain_token' => $token, 'signature' => $signature]];
    }
    if ($event['op'] !== 0 || !is_string($event['t'] ?? null)) { return $failure(400); }
    comment_notify_qq_bind_event($event, $now);
    $state['last_event_at'] = $now;
    comment_notify_write('qq_webhook', $state);
    return ['status' => 200, 'body' => ['op' => 12, 'd' => 0]];
}

function comment_notify_qq_webhook_request(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') { header('Allow: POST'); }
    try {
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        $response = comment_notify_qq_webhook_handle($method, is_string($raw) ? $raw : '', [
            'x-bot-appid' => $_SERVER['HTTP_X_BOT_APPID'] ?? null,
            'x-signature-timestamp' => $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? null,
            'x-signature-ed25519' => $_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? null,
        ]);
    } catch (Throwable) {
        error_log('CommentNotify: QQ webhook processing failed.');
        $response = ['status' => 500, 'body' => ['op' => 12, 'd' => 1]];
    }
    http_response_code($response['status']);
    echo json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
