<?php

declare(strict_types=1);

if (!defined('__TYPECHO_ROOT_DIR__')) { http_response_code(403); exit; }

// Ported from Tencent/openclaw-weixin 2.4.10 (MIT); see THIRD_PARTY_NOTICES.md.
const COMMENT_NOTIFY_WEIXIN_VERSION = '2.4.10';
const COMMENT_NOTIFY_WEIXIN_BASE = 'https://ilinkai.weixin.qq.com';

function comment_notify_weixin_user_id(string $value): bool
{
    return (bool)preg_match('/\A[A-Za-z0-9_@.:-]{1,256}\z/', $value);
}

function comment_notify_weixin_base(string $url): string
{
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*weixin\.qq\.com\z/', $host)
        || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
        throw new RuntimeException('微信返回的接口地址不在受信任的官方域名范围内。');
    }
    return 'https://' . $host;
}

function comment_notify_weixin_qr_url(string $url): string
{
    if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f]/', $url)) { return ''; }
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) { return ''; }
    $host = strtolower((string)($parts['host'] ?? ''));
    return preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*weixin\.qq\.com\z/', $host) ? $url : '';
}

function comment_notify_weixin_headers(?string $token = null, bool $json = true): array
{
    $headers = ['iLink-App-Id: bot', 'iLink-App-ClientVersion: 132106'];
    if ($json) {
        $number = unpack('Nvalue', random_bytes(4));
        $headers[] = 'AuthorizationType: ilink_bot_token';
        $headers[] = 'X-WECHAT-UIN: ' . base64_encode((string)$number['value']);
    }
    if ($token !== null) {
        if (!preg_match('/\A[\x21-\x7e]{1,8192}\z/', $token)) { throw new RuntimeException('微信登录凭证格式无效，请重新扫码。'); }
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    return $headers;
}

function comment_notify_weixin_base_info(): array
{
    return ['channel_version' => COMMENT_NOTIFY_WEIXIN_VERSION, 'bot_agent' => 'CommentNotify/2.0.0'];
}

function comment_notify_weixin_failed(array $response): bool
{
    if ((int)$response['status'] < 200 || (int)$response['status'] >= 300) { return true; }
    foreach (['ret', 'errcode'] as $key) {
        if (array_key_exists($key, $response['data']) && (!is_scalar($response['data'][$key]) || (string)$response['data'][$key] !== '0')) { return true; }
    }
    return false;
}

function comment_notify_weixin_error(array $response): RuntimeException
{
    $code = comment_notify_api_code($response['data']);
    foreach (['ret', 'errcode'] as $key) {
        if (isset($response['data'][$key]) && is_scalar($response['data'][$key]) && preg_match('/\A-?[0-9]{1,12}\z/', (string)$response['data'][$key]) && (string)$response['data'][$key] !== '0') {
            $code = (string)$response['data'][$key];
            break;
        }
    }
    if (in_array($response['data']['ret'] ?? null, [-14, '-14'], true) || in_array($response['data']['errcode'] ?? null, [-14, '-14'], true) || (int)$response['status'] === 401) {
        $account = comment_notify_read('weixin_account');
        if ($account !== []) { $account['expired'] = true; comment_notify_write('weixin_account', $account); }
        return new RuntimeException('微信登录凭证已失效，请重新扫码并同步接收人（错误码 -14 或 HTTP 401）。');
    }
    $message = '微信接口失败（HTTP ' . (int)$response['status'] . ($code !== '' ? '，错误码 ' . $code : '') . '）。';
    $message .= $code === '-2'
        ? '请先向微信机器人发送一条消息，再在此同步接收人；该通道的主动发送受微信服务端限制。'
        : '请检查微信连接状态，稍后再试。';
    return new RuntimeException($message);
}

function comment_notify_weixin_status(): array
{
    $account = comment_notify_read('weixin_account');
    $login = comment_notify_read('weixin_login');
    $sync = comment_notify_read('weixin_sync');
    $userId = (string)(comment_notify_config()['weixin_user_id'] ?? '');
    $pending = isset($login['qrcode']) && (int)($login['started_at'] ?? 0) > time() - 300;
    $candidates = [];
    foreach (array_slice((array)($sync['candidates'] ?? []), 0, 20) as $candidate) {
        if (is_string($candidate) && comment_notify_weixin_user_id($candidate)) { $candidates[] = ['user_id' => $candidate]; }
    }
    return [
        'logged_in' => !empty($account['token']), 'expired' => !empty($account['expired']),
        'account_id' => (string)($account['account_id'] ?? ''), 'user_id' => $userId,
        'has_context' => $userId !== '' && !empty($sync['context_tokens'][$userId]),
        'login_pending' => $pending, 'login_status' => $pending ? (string)($login['status'] ?? 'wait') : '',
        'need_verifycode' => $pending && ($login['status'] ?? '') === 'need_verifycode',
        'qrcode_url' => $pending ? comment_notify_weixin_qr_url((string)($login['qrcode_url'] ?? '')) : '',
        'candidates' => $candidates,
    ];
}

function comment_notify_weixin_start(?callable $transport = null): array
{
    $transport ??= 'comment_notify_http';
    $account = comment_notify_read('weixin_account');
    $tokens = [];
    if (is_string($account['token'] ?? null) && $account['token'] !== '') { $tokens[] = $account['token']; }
    $response = $transport('POST', COMMENT_NOTIFY_WEIXIN_BASE . '/ilink/bot/get_bot_qrcode?bot_type=3', comment_notify_weixin_headers(), ['local_token_list' => $tokens], 12);
    if (comment_notify_weixin_failed($response)) { throw comment_notify_weixin_error($response); }
    $data = $response['data'];
    $qrcode = $data['qrcode'] ?? null;
    $url = is_string($data['qrcode_img_content'] ?? null) ? comment_notify_weixin_qr_url($data['qrcode_img_content']) : '';
    if (!is_string($qrcode) || $qrcode === '' || strlen($qrcode) > 8192 || $url === '') { throw new RuntimeException('微信未返回有效的官方扫码链接。'); }
    comment_notify_write('weixin_login', ['qrcode' => $qrcode, 'qrcode_url' => $url, 'base_url' => COMMENT_NOTIFY_WEIXIN_BASE, 'started_at' => time(), 'status' => 'wait']);
    return ['status' => 'wait', 'qrcode_url' => $url, 'message' => '请打开官方扫码链接，使用微信扫码并确认连接。'];
}

function comment_notify_weixin_check(string $verifyCode = '', ?callable $transport = null): array
{
    $transport ??= 'comment_notify_http';
    $login = comment_notify_read('weixin_login');
    if (empty($login['qrcode']) || (int)($login['started_at'] ?? 0) <= time() - 300) {
        comment_notify_write('weixin_login', []);
        return ['status' => 'expired', 'message' => '扫码链接已过期，请重新生成。'];
    }
    if ($verifyCode !== '' && !preg_match('/\A[0-9]{1,12}\z/', $verifyCode)) { throw new RuntimeException('请输入手机微信显示的数字验证码。'); }
    $base = comment_notify_weixin_base((string)($login['base_url'] ?? COMMENT_NOTIFY_WEIXIN_BASE));
    $path = '/ilink/bot/get_qrcode_status?qrcode=' . rawurlencode((string)$login['qrcode']);
    if ($verifyCode !== '') { $path .= '&verify_code=' . rawurlencode($verifyCode); }
    try {
        $response = $transport('GET', $base . $path, comment_notify_weixin_headers(null, false), null, 12);
    } catch (RuntimeException $exception) {
        if ($exception->getCode() !== 28) { throw $exception; }
        return ['status' => 'wait', 'message' => '微信尚未更新连接状态，请稍后再次检查。'];
    }
    if (comment_notify_weixin_failed($response)) { throw comment_notify_weixin_error($response); }
    $data = $response['data'];
    $status = is_string($data['status'] ?? null) ? $data['status'] : '';
    $messages = [
        'wait' => '等待微信扫码。', 'scaned' => '已扫码，请在手机微信中完成确认。',
        'need_verifycode' => '请输入手机微信显示的数字验证码，然后检查连接状态。',
        'verify_code_blocked' => '验证码多次错误，请稍后重新生成扫码链接。',
        'expired' => '扫码链接已过期，请重新生成。',
        'binded_redirect' => '微信提示已绑定此连接；如本机没有有效凭证，请在微信中解除旧连接后重新扫码。',
        'scaned_but_redirect' => '已扫码，微信正在切换服务区域，请再次检查状态。',
        'confirmed' => '微信已连接。请向微信机器人发送“绑定通知”，然后点击同步接收人。',
    ];
    if (!isset($messages[$status])) { throw new RuntimeException('微信返回了无法识别的登录状态，请稍后重试。'); }
    $login['status'] = $status;
    if ($status === 'scaned_but_redirect') {
        if (!is_string($data['redirect_host'] ?? null)) { throw new RuntimeException('微信未返回有效的区域地址。'); }
        $login['base_url'] = comment_notify_weixin_base('https://' . $data['redirect_host']);
    }
    if ($status === 'confirmed') {
        $token = $data['bot_token'] ?? null;
        $accountId = $data['ilink_bot_id'] ?? null;
        $owner = $data['ilink_user_id'] ?? '';
        if (!is_string($token) || !preg_match('/\A[\x21-\x7e]{1,8192}\z/', $token)
            || !is_string($accountId) || !comment_notify_weixin_user_id($accountId)
            || !is_string($owner) || ($owner !== '' && !comment_notify_weixin_user_id($owner))) { throw new RuntimeException('微信返回的登录凭证不完整，请重新扫码。'); }
        $accountBase = comment_notify_weixin_base(is_string($data['baseurl'] ?? null) && $data['baseurl'] !== '' ? $data['baseurl'] : $base);
        comment_notify_write('weixin_account', ['token' => $token, 'account_id' => $accountId, 'owner_id' => $owner, 'base_url' => $accountBase, 'connected_at' => time(), 'expired' => false]);
        comment_notify_write('weixin_sync', []);
        comment_notify_write('weixin_login', []);
        $config = comment_notify_config();
        $config['weixin_enabled'] = false;
        $config['weixin_user_id'] = '';
        comment_notify_save_config($config);
    } else {
        comment_notify_write('weixin_login', in_array($status, ['expired', 'verify_code_blocked', 'binded_redirect'], true) ? [] : $login);
    }
    return ['status' => $status, 'message' => $messages[$status]];
}

function comment_notify_weixin_account(): array
{
    $account = comment_notify_read('weixin_account');
    if (empty($account['token']) || !is_string($account['token'])) { throw new RuntimeException('请先连接微信。'); }
    if (!empty($account['expired'])) { throw new RuntimeException('微信登录凭证已失效，请重新扫码。'); }
    $account['base_url'] = comment_notify_weixin_base((string)($account['base_url'] ?? ''));
    return $account;
}

function comment_notify_weixin_sync(?callable $transport = null): array
{
    $transport ??= 'comment_notify_http';
    $account = comment_notify_weixin_account();
    $sync = comment_notify_read('weixin_sync');
    try {
        $response = $transport('POST', $account['base_url'] . '/ilink/bot/getupdates', comment_notify_weixin_headers($account['token']), [
            'get_updates_buf' => (string)($sync['get_updates_buf'] ?? ''), 'base_info' => comment_notify_weixin_base_info(),
        ], 12);
    } catch (RuntimeException $exception) {
        if ($exception->getCode() !== 28) { throw $exception; }
        return ['status' => 'wait', 'message' => '尚未收到新消息。请先向微信机器人发送“绑定通知”，再同步一次。', 'candidates' => []];
    }
    if (comment_notify_weixin_failed($response)) { throw comment_notify_weixin_error($response); }
    $data = $response['data'];
    if (is_string($data['get_updates_buf'] ?? null) && $data['get_updates_buf'] !== '') { $sync['get_updates_buf'] = $data['get_updates_buf']; }
    $context = is_array($sync['context_tokens'] ?? null) ? $sync['context_tokens'] : [];
    $candidates = is_array($sync['candidates'] ?? null) ? $sync['candidates'] : [];
    foreach (is_array($data['msgs'] ?? null) ? array_slice($data['msgs'], 0, 100) : [] as $message) {
        if (!is_array($message) || (int)($message['message_type'] ?? 0) !== 1 || !empty($message['group_id'])) { continue; }
        $from = $message['from_user_id'] ?? '';
        $token = $message['context_token'] ?? '';
        if (!is_string($from) || !comment_notify_weixin_user_id($from) || $from === $account['account_id']) { continue; }
        // A scanner's account may only bind itself; unrelated inbound users cannot replace the owner.
        if (!empty($account['owner_id']) && $account['owner_id'] !== $from) { continue; }
        if (is_string($token) && $token !== '' && strlen($token) <= 16384) { $context[$from] = $token; }
        if (!in_array($from, $candidates, true)) { $candidates[] = $from; }
    }
    $sync['context_tokens'] = array_slice($context, -20, null, true);
    $sync['candidates'] = array_slice($candidates, -20);
    $sync['synced_at'] = time();
    comment_notify_write('weixin_sync', $sync);
    $config = comment_notify_config();
    if ($config['weixin_user_id'] !== '') { return ['status' => 'bound', 'message' => '接收人保持绑定，会话信息已同步。', 'candidates' => []]; }
    if (!empty($account['owner_id']) && in_array($account['owner_id'], $sync['candidates'], true)) {
        comment_notify_weixin_bind($account['owner_id']);
        return ['status' => 'bound', 'message' => '已绑定扫码微信账号为评论通知接收人，现在可启用微信通知。', 'candidates' => []];
    }
    $candidatesView = array_map(static fn(string $userId): array => ['user_id' => $userId], $sync['candidates']);
    return ['status' => $candidatesView === [] ? 'wait' : 'select', 'message' => $candidatesView === [] ? '尚未收到绑定消息。请先向微信机器人发送“绑定通知”，再同步一次。' : '请选择刚发送绑定消息的接收人。', 'candidates' => $candidatesView];
}

function comment_notify_weixin_bind(string $userId): void
{
    $account = comment_notify_weixin_account();
    $sync = comment_notify_read('weixin_sync');
    if (!comment_notify_weixin_user_id($userId) || !in_array($userId, (array)($sync['candidates'] ?? []), true)
        || (!empty($account['owner_id']) && $account['owner_id'] !== $userId)) { throw new RuntimeException('只能绑定本次微信连接中已同步的接收人。'); }
    $config = comment_notify_config();
    if ($config['weixin_user_id'] !== '' && $config['weixin_user_id'] !== $userId) { throw new RuntimeException('请先断开微信连接，再更换接收人。'); }
    $config['weixin_user_id'] = $userId;
    comment_notify_save_config($config);
}

function comment_notify_weixin_send(array $config, string $text, ?callable $transport = null): void
{
    $transport ??= 'comment_notify_http';
    $account = comment_notify_weixin_account();
    $to = (string)($config['weixin_user_id'] ?? '');
    $sync = comment_notify_read('weixin_sync');
    if (!comment_notify_weixin_user_id($to) || !in_array($to, (array)($sync['candidates'] ?? []), true)
        || (!empty($account['owner_id']) && $account['owner_id'] !== $to)) { throw new RuntimeException('微信接收人尚未完成绑定，请先发送绑定消息并同步。'); }
    $msg = ['from_user_id' => '', 'to_user_id' => $to,
        'client_id' => 'comment-notify:' . (string)(int)floor(microtime(true) * 1000) . '-' . bin2hex(random_bytes(8)),
        'message_type' => 2, 'message_state' => 2, 'item_list' => [['type' => 1, 'text_item' => ['text' => $text]]],
    ];
    $context = $sync['context_tokens'][$to] ?? '';
    if (is_string($context) && $context !== '') { $msg['context_token'] = $context; }
    // No automatic resend: network timeouts do not reveal whether the server accepted a message.
    $response = $transport('POST', $account['base_url'] . '/ilink/bot/sendmessage', comment_notify_weixin_headers($account['token']), ['msg' => $msg, 'base_info' => comment_notify_weixin_base_info()]);
    if (comment_notify_weixin_failed($response)) { throw comment_notify_weixin_error($response); }
    $data = $response['data'];
    $messageId = $data['message_id'] ?? '';
    if (!array_key_exists('ret', $data) && !array_key_exists('errcode', $data) && (!is_scalar($messageId) || !preg_match('/\A[0-9]{1,24}\z/', (string)$messageId))) {
        throw new RuntimeException('微信接口未返回发送受理状态，请检查连接；为避免重复通知，不会自动重发。');
    }
}

function comment_notify_weixin_disconnect(): void
{
    foreach (['weixin_account', 'weixin_login', 'weixin_sync'] as $key) { comment_notify_write($key, []); }
    $config = comment_notify_config();
    $config['weixin_enabled'] = false;
    $config['weixin_user_id'] = '';
    comment_notify_save_config($config);
}
