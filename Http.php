<?php

declare(strict_types=1);

if (!defined('__TYPECHO_ROOT_DIR__')) { http_response_code(403); exit; }

function comment_notify_http(string $method, string $url, array $headers = [], ?array $payload = null, int $timeout = 6): array
{
    if (!function_exists('curl_init')) { throw new RuntimeException('服务器需要启用 PHP cURL 扩展。'); }
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $headers = array_merge(['Accept: application/json', 'Content-Type: application/json; charset=utf-8'], $headers);
    $handle = curl_init($url);
    $response = '';
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 1048576) { return 0; }
            $response .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($body !== null) { curl_setopt($handle, CURLOPT_POSTFIELDS, $body); }
    try {
        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $readTimeout = curl_errno($handle) === CURLE_OPERATION_TIMEDOUT && (float)curl_getinfo($handle, CURLINFO_APPCONNECT_TIME) > 0;
    } finally { curl_close($handle); }
    // Never include cURL's error string, URL, request or raw response: they can contain credentials.
    if ($ok === false) { throw new RuntimeException('通知接口连接失败或超时，请检查服务器网络和 HTTPS 证书。', $readTimeout ? 28 : 0); }
    $data = json_decode($response, true);
    if (!is_array($data)) { throw new RuntimeException('通知接口返回了无效的 JSON 响应（HTTP ' . $status . '）。'); }
    return ['status' => $status, 'data' => $data];
}

function comment_notify_api_code(array $data): string
{
    $value = $data['errcode'] ?? $data['err_code'] ?? $data['code'] ?? $data['ret'] ?? '';
    return is_scalar($value) && preg_match('/\A-?[0-9]{1,12}\z/', (string)$value) ? (string)$value : '';
}

function comment_notify_api_error(string $provider, array $response): RuntimeException
{
    $code = comment_notify_api_code($response['data']);
    $message = $provider . '接口失败（HTTP ' . (int)$response['status'] . ($code !== '' ? '，错误码 ' . $code : '') . '）。';
    if ($provider === 'QQ') {
        $message .= match ($code) {
            '40034105' => '请在 QQ 中开启机器人的主动消息权限。',
            '40054004', '40034101', '40054003' => '请添加机器人好友或将机器人加入目标群，并检查 OpenID。',
            '40054013' => '接收人已关闭机器人主动消息。',
            '40054010', '304003' => '文章域名未获允许，可关闭通知中的文章链接后重试。',
            '40054016', '304018' => '机器人离线；频道推送需要其他程序保持 WebSocket 在线。',
            '304023', '304024' => '消息正在平台审核，请勿重复发送。',
            default => (int)$response['status'] === 429 || $code === '40034100' ? '已达到平台频率限制，请稍后再试。' : '请检查机器人凭证、接收 ID 和平台权限。',
        };
    }
    return new RuntimeException($message);
}
