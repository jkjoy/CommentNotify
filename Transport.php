<?php

/**
 * Notification-only transports. The text cleanup and QQ service protocol are
 * adapted from Enhancement/QqNotifyHelper.php; OpenClaw sends use its documented
 * POST /tools/invoke message tool API, without running an agent turn.
 */
class CommentNotify_Transport
{
    public static function defaults(): array
    {
        return array(
            'gateway_url' => '',
            'gateway_token' => '',
            'qq_enabled' => '0',
            'qq_provider' => 'openclaw',
            'qq_channel' => 'qqbot',
            'qq_account' => '',
            'qq_targets' => '',
            'wechat_enabled' => '0',
            'wechat_channel' => 'openclaw-weixin',
            'wechat_account' => '',
            'wechat_targets' => '',
            'qq_service_url' => '',
            'qq_service_token' => '',
            'include_links' => '1',
            'notify_owner' => '0'
        );
    }

    public static function normalize(array $settings): array
    {
        $normalized = self::defaults();
        foreach ($normalized as $key => $default) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            if (!is_scalar($settings[$key])) {
                throw new InvalidArgumentException('评论通知配置项必须是文本或开关值');
            }
            $normalized[$key] = trim((string)$settings[$key]);
        }
        foreach (array('qq_targets', 'wechat_targets') as $key) {
            $normalized[$key] = implode("\n", self::targets($normalized[$key]));
        }
        return $normalized;
    }

    public static function validate(array $settings): array
    {
        $settings = self::normalize($settings);
        foreach (array('qq_enabled', 'wechat_enabled', 'include_links', 'notify_owner') as $key) {
            if (!in_array($settings[$key], array('0', '1'), true)) {
                throw new InvalidArgumentException('评论通知开关值必须为 0 或 1');
            }
        }
        if (!in_array($settings['qq_provider'], array('openclaw', 'service'), true)) {
            throw new InvalidArgumentException('QQ 通知方式无效');
        }
        if ($settings['qq_enabled'] === '1') {
            if ($settings['qq_provider'] === 'service') {
                self::validUrl($settings['qq_service_url'], 'QQ 服务地址');
                self::validToken($settings['qq_service_token'], 'QQ 服务访问令牌');
                self::validTargets($settings['qq_targets'], 'service');
            } else {
                self::validChannel($settings['qq_channel'], 'QQ');
                self::validAccount($settings['qq_account'], 'QQ');
                self::validTargets($settings['qq_targets'], 'qq');
            }
        }
        if ($settings['wechat_enabled'] === '1') {
            self::validChannel($settings['wechat_channel'], '微信');
            self::validAccount($settings['wechat_account'], '微信');
            self::validTargets($settings['wechat_targets'], 'wechat', $settings['wechat_channel']);
        }
        if ($settings['wechat_enabled'] === '1'
            || ($settings['qq_enabled'] === '1' && $settings['qq_provider'] === 'openclaw')) {
            self::validUrl($settings['gateway_url'], 'OpenClaw Gateway 地址');
            self::validToken($settings['gateway_token'], 'OpenClaw Gateway 访问令牌');
        }
        return $settings;
    }

    /** Each destination is a credential-free snapshot stored with the job. */
    public static function destinations(array $settings): array
    {
        $settings = self::validate($settings);
        $destinations = array();
        foreach (array('qq', 'wechat') as $kind) {
            if ($settings[$kind . '_enabled'] !== '1') {
                continue;
            }
            $provider = $kind === 'qq' ? $settings['qq_provider'] : 'openclaw';
            $service = $provider === 'service';
            $endpoint = self::endpoint(
                $settings[$service ? 'qq_service_url' : 'gateway_url'],
                $service ? '/api/notifications' : '/tools/invoke'
            );
            foreach (self::targets($settings[$kind . '_targets']) as $target) {
                $destinations[] = array(
                    'kind' => $kind,
                    'provider' => $provider,
                    'endpoint' => $endpoint,
                    'channel' => $service ? '' : $settings[$kind . '_channel'],
                    'account' => $service ? '' : $settings[$kind . '_account'],
                    'target' => $target
                );
            }
        }
        return $destinations;
    }

    /** Adapted from Enhancement_QqNotifyHelper::prepareMessage (1800-byte UTF-8 limit). */
    public static function prepareMessage($message, $includeLinks = true): string
    {
        $message = is_scalar($message) ? (string)$message : '';
        if (!preg_match('//u', $message)) {
            $encoded = json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE);
            $message = $encoded === false ? '' : (string)json_decode($encoded, true);
        }
        $message = html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = (string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $message);
        if (!$includeLinks) {
            $message = (string)preg_replace('~(?:[a-z][a-z0-9+.-]*://|www\.)[^\s<>\x{3000}]+|\bmailto:[^\s<>]+~iu', '[链接已省略]', $message);
        }
        if (strlen($message) > 1800) {
            $message = substr($message, 0, 1797);
            while ($message !== '' && !preg_match('//u', $message)) {
                $message = substr($message, 0, -1);
            }
            $message .= '…';
        }
        return trim($message);
    }

    public static function send(array $envelope, array $settings): array
    {
        try {
            $settings = self::validate($settings);
            $matched = false;
            foreach (self::destinations($settings) as $destination) {
                $same = true;
                foreach ($destination as $key => $value) {
                    if (!isset($envelope[$key]) || !is_string($envelope[$key]) || $envelope[$key] !== $value) {
                        $same = false;
                        break;
                    }
                }
                if ($same) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return self::result(false, '通知目标或服务配置已变更，旧记录已停止投递');
            }
            if (!isset($envelope['message'], $envelope['request_id'])
                || !is_string($envelope['message']) || trim($envelope['message']) === ''
                || strlen($envelope['message']) > 1800 || !preg_match('//u', $envelope['message'])
                || !is_string($envelope['request_id'])
                || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $envelope['request_id'])) {
                return self::result(false, '通知队列记录无效');
            }
            if (!function_exists('curl_init')) {
                return self::result(false, '当前 PHP 环境缺少 cURL 扩展');
            }

            $service = $envelope['provider'] === 'service';
            $headers = array(
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json',
                'Authorization: Bearer ' . $settings[$service ? 'qq_service_token' : 'gateway_token']
            );
            if ($service) {
                $payload = array(
                    'message' => $envelope['message'],
                    'targets' => array($envelope['target']),
                    'idempotency_key' => $envelope['request_id']
                );
            } else {
                $args = array(
                    'action' => 'send',
                    'channel' => $envelope['channel'],
                    'target' => $envelope['target'],
                    'message' => $envelope['message']
                );
                if ($envelope['account'] !== '') {
                    $args['accountId'] = $envelope['account'];
                }
                // OpenClaw derives a stable tool-call ID from this key. It does
                // not promise HTTP deduplication; uncertain sends require review.
                $payload = array('tool' => 'message', 'args' => $args, 'idempotencyKey' => $envelope['request_id']);
                $headers[] = 'x-openclaw-message-channel: ' . $envelope['channel'];
                $headers[] = 'x-openclaw-message-to: ' . $envelope['target'];
                if ($envelope['account'] !== '') {
                    $headers[] = 'x-openclaw-account-id: ' . $envelope['account'];
                }
            }
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return self::result(false, '通知内容必须是有效的 UTF-8 文本');
            }
            $reply = self::post($envelope['endpoint'], $headers, $json);
            if ($reply['errno'] !== 0) {
                // Connect, DNS, and TLS setup failures happen before a request can be delivered.
                $notSent = in_array($reply['errno'], array(1, 3, 5, 6, 7, 35, 51, 58, 60, 77), true);
                return self::result(false, $notSent ? '通知服务连接失败，请检查网络和服务配置'
                    : '通知请求中断，服务是否已收到尚未确认', !$notSent);
            }
            $code = $reply['code'];
            if ($code >= 500 || $code === 408 || $code === 0) {
                return self::result(false, '通知服务暂未确认投递（HTTP ' . $code . '）', true);
            }
            if ($code < 200 || $code >= 300) {
                return self::result(false, '通知服务拒绝请求（HTTP ' . $code . '），请检查令牌、通道和权限');
            }
            $body = json_decode($reply['body'], true);
            if (!is_array($body)) {
                return self::result(false, '通知服务响应无效，是否已投递尚未确认', true);
            }
            if ($service) {
                if (($body['ok'] ?? null) === true
                    && in_array($body['status'] ?? '', array('queued', 'sent'), true)
                    && is_string($body['id'] ?? null)
                    && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $body['id'])) {
                    return self::result(true, $body['status'] === 'queued' ? 'QQ 服务已接收通知并排队' : 'QQ 服务已发送通知');
                }
                return self::result(false, 'QQ 服务未确认接受通知，请检查服务日志和接收别名', !self::hasToolError($body));
            }
            if (self::hasUncertainDelivery($body)) {
                return self::result(false, 'OpenClaw 返回部分投递或结果未知，请核对 Gateway 日志后再重试', true);
            }
            if (self::hasToolError($body)) {
                return self::result(false, 'OpenClaw 消息工具拒绝投递，请检查通道、目标、登录状态和工具权限');
            }
            if ($code === 200 && ($body['ok'] ?? null) === true
                && is_array($body['result'] ?? null) && self::hasDeliveryReceipt($body['result'])) {
                return self::result(true, 'OpenClaw 已确认通知投递');
            }
            return self::result(false, 'OpenClaw 未返回有效投递确认，请检查 Gateway 日志', true);
        } catch (InvalidArgumentException $e) {
            return self::result(false, $e->getMessage());
        } catch (Throwable $e) {
            // Never include exception text or remote response bodies: they may contain credentials.
            return self::result(false, '通知请求异常，是否已投递尚未确认', true);
        }
    }

    private static function targets(string $value): array
    {
        $targets = preg_split('/\r\n|\r|\n/', $value);
        return array_values(array_unique(array_filter(array_map('trim', $targets), 'strlen')));
    }

    private static function validTargets(string $value, string $kind, string $channel = ''): void
    {
        $targets = self::targets($value);
        if (count($targets) < 1 || count($targets) > 20) {
            throw new InvalidArgumentException('每个平台请填写 1 至 20 个接收目标，每行一个');
        }
        foreach ($targets as $target) {
            if ($kind === 'service' && !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $target)) {
                throw new InvalidArgumentException('QQ 服务接收别名只支持 1 至 64 位英文字母、数字、下划线和连字符');
            }
            if ($kind === 'qq' && !preg_match('/\Aqqbot:(?:c2c|group|channel):[A-Za-z0-9_-]{1,128}\z/D', $target)) {
                throw new InvalidArgumentException('QQ 目标格式为 qqbot:c2c:用户OpenID、qqbot:group:群OpenID 或 qqbot:channel:频道ID');
            }
            if ($kind === 'wechat') {
                if (in_array(strtolower($channel), array('openclaw-weixin', 'wechat', 'weixin'), true)) {
                    if (!preg_match('/\A[\x21-\x7e]{1,256}@im\.wechat\z/D', $target)
                        || strpos(substr($target, 0, -10), '@') !== false) {
                        throw new InvalidArgumentException('微信目标须为机器人收到的用户 ID（如 xxx@im.wechat），不能填写微信号或昵称');
                    }
                } elseif (!preg_match('/\A[\x21-\x7e]{1,512}\z/D', $target)) {
                    throw new InvalidArgumentException('自定义微信通道目标须为 1 至 512 位不含空白的 ASCII 字符，请按该通道插件的格式填写');
                }
            }
        }
    }

    private static function validUrl(string $url, string $label): void
    {
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new InvalidArgumentException($label . '须为有效的 http/https 地址，不能包含用户名、密码、查询参数或片段');
        }
    }

    private static function validToken(string $token, string $label): void
    {
        if (!preg_match('/\A[\x21-\x7e]{1,4096}\z/D', $token)) {
            throw new InvalidArgumentException($label . '不能为空，且须为不含空白的 ASCII 字符');
        }
    }

    private static function validChannel(string $channel, string $label): void
    {
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,63}\z/D', $channel)) {
            throw new InvalidArgumentException($label . '通道 ID 格式无效');
        }
    }

    private static function validAccount(string $account, string $label): void
    {
        if (!preg_match('/\A[A-Za-z0-9_.@:-]{0,256}\z/D', $account)) {
            throw new InvalidArgumentException($label . '账号 ID 格式无效');
        }
    }

    private static function endpoint(string $url, string $suffix): string
    {
        $url = rtrim($url, '/');
        return substr($url, -strlen($suffix)) === $suffix ? $url : $url . $suffix;
    }

    private static function result(bool $ok, string $message, bool $uncertain = false): array
    {
        return array('ok' => $ok, 'message' => $message, 'uncertain' => $uncertain);
    }

    /** Also inspect JSON text blocks: OpenClaw tool errors can be nested there. */
    private static function hasToolError(array $value, int $depth = 0): bool
    {
        if ($depth > 12) {
            return true;
        }
        if (!empty($value['isError']) || !empty($value['error'])
            || (array_key_exists('ok', $value) && $value['ok'] === false)
            || (array_key_exists('success', $value) && $value['success'] === false)
            || in_array($value['status'] ?? null, array('error', 'failed', 'rejected', 'denied', 'cancelled', 'canceled', 'suppressed'), true)
            || in_array($value['deliveryStatus'] ?? null, array('failed', 'suppressed', 'rejected'), true)) {
            return true;
        }
        foreach ($value as $key => $item) {
            if (is_array($item) && self::hasToolError($item, $depth + 1)) {
                return true;
            }
            if ($key === 'text' && is_string($item)) {
                $decoded = json_decode($item, true);
                if (is_array($decoded) && self::hasToolError($decoded, $depth + 1)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function hasUncertainDelivery(array $value, int $depth = 0): bool
    {
        if ($depth > 12) {
            return true;
        }
        if (($value['partialDelivery'] ?? null) === true
            || in_array($value['deliveryStatus'] ?? null, array('partial', 'unknown', 'uncertain'), true)
            || in_array($value['status'] ?? null, array('partial', 'unknown', 'uncertain'), true)) {
            return true;
        }
        foreach ($value as $key => $item) {
            if (is_array($item) && self::hasUncertainDelivery($item, $depth + 1)) {
                return true;
            }
            if ($key === 'text' && is_string($item)) {
                $decoded = json_decode($item, true);
                if (is_array($decoded) && self::hasUncertainDelivery($decoded, $depth + 1)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function hasDeliveryReceipt(array $value, int $depth = 0): bool
    {
        if ($depth > 12) {
            return false;
        }
        foreach (array('messageId', 'message_id') as $key) {
            if (isset($value[$key]) && is_scalar($value[$key]) && trim((string)$value[$key]) !== '') {
                return true;
            }
        }
        if (($value['ok'] ?? null) === true || ($value['success'] ?? null) === true
            || in_array($value['status'] ?? null, array('sent', 'delivered', 'queued', 'accepted', 'duplicate'), true)
            || in_array($value['deliveryStatus'] ?? null, array('sent', 'delivered', 'queued', 'accepted'), true)) {
            return true;
        }
        foreach ($value as $key => $item) {
            if (is_array($item) && self::hasDeliveryReceipt($item, $depth + 1)) {
                return true;
            }
            if ($key === 'text' && is_string($item)) {
                $decoded = json_decode($item, true);
                if (is_array($decoded) && self::hasDeliveryReceipt($decoded, $depth + 1)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function post(string $url, array $headers, string $payload): array
    {
        $response = '';
        $handle = curl_init();
        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$response) {
                if (strlen($response) + strlen($chunk) > 65536) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            }
        );
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_NOSIGNAL')) {
            $options[CURLOPT_NOSIGNAL] = true;
        }
        curl_setopt_array($handle, $options);
        curl_exec($handle);
        $code = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $errno = curl_errno($handle);
        if (PHP_VERSION_ID < 80000) {
            curl_close($handle);
        } else {
            unset($handle);
        }
        return array('body' => $response, 'code' => $code, 'errno' => $errno);
    }
}
