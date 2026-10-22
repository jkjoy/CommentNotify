<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/Transport.php';

/** Persistent, per-destination delivery with conservative handling of ambiguous sends. */
class CommentNotify_Service
{
    const TABLE = 'table.comment_notify_queue';
    const MAX_ATTEMPTS = 5;
    const LEASE_SECONDS = 120;

    private static $schemaReady = false;

    public static function settings(): array
    {
        $settings = CommentNotify_Transport::defaults();
        try {
            $config = Typecho_Widget::widget('Widget_Options')->plugin('CommentNotify');
            foreach ($settings as $key => $value) {
                if (isset($config->$key) && is_scalar($config->$key)) {
                    $settings[$key] = (string) $config->$key;
                }
            }
        } catch (Throwable $e) {
            // The settings form also calls this before the plugin has saved options.
        }
        return $settings;
    }

    /** Called on activation only; ordinary comment requests never run DDL. */
    public static function install()
    {
        if (self::$schemaReady) {
            return;
        }
        $db = self::db();
        $prefix = $db->getPrefix();
        $adapter = $db->getAdapterName();
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) {
            throw new RuntimeException('数据库表前缀包含不支持的字符。');
        }
        if (!preg_match('/mysql|mysqli|sqlite|pgsql/i', $adapter)) {
            throw new RuntimeException('评论通知支持 MySQL、SQLite 和 PostgreSQL 数据库。');
        }
        $mysql = (bool) preg_match('/mysql|mysqli/i', $adapter);
        $sqlite = (bool) preg_match('/sqlite/i', $adapter);
        $id = $mysql ? 'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY'
            : ($sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'SERIAL PRIMARY KEY');
        $quote = $mysql ? '`' : '"';
        $table = $quote . $prefix . 'comment_notify_queue' . $quote;
        $index = $mysql ? ', INDEX comment_notify_due (state, next_at, id)' : '';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $db->query("CREATE TABLE IF NOT EXISTS {$table} (
            id {$id},
            event_key VARCHAR(64) NOT NULL UNIQUE,
            coid INTEGER NOT NULL,
            kind VARCHAR(16) NOT NULL,
            target VARCHAR(512) NOT NULL,
            envelope TEXT NOT NULL,
            config_hash VARCHAR(64) NOT NULL,
            state VARCHAR(16) NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            next_at INTEGER NOT NULL DEFAULT 0,
            lock_at INTEGER NOT NULL DEFAULT 0,
            lock_token VARCHAR(64) NOT NULL DEFAULT '',
            error VARCHAR(255) NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
            {$index}){$suffix}", Typecho_Db::WRITE);
        if (!$mysql) {
            $indexName = $quote . $prefix . 'comment_notify_due' . $quote;
            $db->query("CREATE INDEX IF NOT EXISTS {$indexName} ON {$table} (state, next_at, id)",
                Typecho_Db::WRITE);
        }
        self::$schemaReady = true;
    }

    /** Return the number of new jobs; existing and sent events retain their deduplication key. */
    public static function enqueue(int $coid, string $message, ?array $settings = null): int
    {
        if ($coid < 1) {
            return 0;
        }
        $settings = $settings === null ? self::settings()
            : array_merge(CommentNotify_Transport::defaults(), $settings);
        $message = CommentNotify_Transport::prepareMessage($message,
            (string) ($settings['include_links'] ?? '0') === '1');
        if (trim($message) === '') {
            return 0;
        }
        $db = self::db();
        $count = 0;
        foreach (CommentNotify_Transport::destinations($settings) as $destination) {
            $destination = self::destination($destination);
            $snapshot = self::encode($destination);
            $eventKey = hash('sha256', $coid . "\n" . $snapshot);
            $existing = self::readRow($db->select('id', 'state')->from(self::TABLE)
                ->where('event_key = ?', $eventKey)->limit(1));
            if ($existing && $existing['state'] !== 'cancelled') {
                continue;
            }
            $envelope = $destination;
            $envelope['message'] = $message;
            $envelope['request_id'] = bin2hex(random_bytes(24));
            $now = time();
            $rows = array(
                'event_key' => $eventKey, 'coid' => $coid,
                'kind' => $destination['kind'], 'target' => $destination['target'],
                'envelope' => self::encode($envelope), 'config_hash' => hash('sha256', $snapshot),
                'state' => 'pending', 'attempts' => 0, 'next_at' => $now,
                'lock_at' => 0, 'lock_token' => '', 'error' => '', 'updated_at' => $now
            );
            if ($existing) {
                // A later real comment/approval event can revive a pre-send cancellation.
                // The unique key still requires the exact same comment and route snapshot;
                // sent or ambiguous sends are never revived by another finish hook.
                $count += (int) (bool) $db->query($db->update(self::TABLE)->rows($rows)
                    ->where('id = ? AND state = ?', (int) $existing['id'], 'cancelled')
                    ->where('event_key = ?', $eventKey));
                continue;
            }
            $rows['created_at'] = $now;
            try {
                $db->query($db->insert(self::TABLE)->rows($rows));
                ++$count;
            } catch (Throwable $e) {
                // A simultaneous finish hook may have inserted the same unique event.
                if (!self::readRow($db->select('id')->from(self::TABLE)->where('event_key = ?', $eventKey)->limit(1))) {
                    throw $e;
                }
            }
        }
        return $count;
    }

    /** Only due pending jobs are eligible; ambiguous jobs always require an administrator. */
    public static function process(int $limit = 4): array
    {
        $db = self::db();
        $now = time();
        $stats = array('sent' => 0, 'failed' => 0, 'uncertain' => 0, 'cancelled' => 0);
        $stats['uncertain'] = (int) $db->query($db->update(self::TABLE)->rows(array(
            'state' => 'uncertain', 'lock_at' => 0, 'lock_token' => '', 'next_at' => 0,
            'error' => '发送进程中断，机器人可能已经接收。请核实后手动重试。', 'updated_at' => $now
        ))->where('state = ?', 'sending')->where('lock_at <= ?', $now - self::LEASE_SECONDS));
        if ($limit < 1) {
            return $stats;
        }
        $settings = self::settings();
        $destinations = self::destinationHashes($settings);
        $jobs = self::readAll($db->select()->from(self::TABLE)
            ->where('state = ? AND next_at <= ?', 'pending', $now)
            ->where('attempts < ?', self::MAX_ATTEMPTS)
            ->order('next_at', Typecho_Db::SORT_ASC)->order('id', Typecho_Db::SORT_ASC)
            ->limit(min(20, $limit)));
        foreach ($jobs as $job) {
            $token = bin2hex(random_bytes(24));
            $claimedAt = time();
            $attempts = (int) $job['attempts'] + 1;
            $claimed = $db->query($db->update(self::TABLE)->rows(array(
                'state' => 'sending', 'lock_at' => $claimedAt, 'lock_token' => $token,
                'attempts' => $attempts, 'updated_at' => $claimedAt
            ))->where('id = ? AND state = ?', (int) $job['id'], 'pending')
                ->where('attempts = ? AND next_at <= ?', (int) $job['attempts'], $claimedAt));
            if (!$claimed) {
                continue;
            }

            $comment = self::readRow($db->select('coid', 'type', 'status', 'authorId', 'ownerId')
                ->from('table.comments')->where('coid = ?', (int) $job['coid'])->limit(1));
            if (!$comment || $comment['type'] !== 'comment' || $comment['status'] !== 'approved') {
                if (self::finish($job, $token, 'cancelled', '评论已删除、未审核通过或不再是普通评论。')) {
                    ++$stats['cancelled'];
                }
                continue;
            }
            if ((string) ($settings['notify_owner'] ?? '0') !== '1'
                && (int) $comment['authorId'] > 0
                && (int) $comment['authorId'] === (int) $comment['ownerId']) {
                if (self::finish($job, $token, 'cancelled', '已跳过文章作者本人的评论。')) {
                    ++$stats['cancelled'];
                }
                continue;
            }
            $envelope = json_decode((string) $job['envelope'], true);
            if (!self::validEnvelope($envelope, $job)) {
                if (self::finish($job, $token, 'failed', '通知记录格式无效，请检查插件数据。')) {
                    ++$stats['failed'];
                }
                continue;
            }
            if (!isset($destinations[$job['config_hash']])) {
                if (self::finish($job, $token, 'cancelled', '通知渠道或接收目标已变更，旧任务已取消。')) {
                    ++$stats['cancelled'];
                }
                continue;
            }

            try {
                $result = CommentNotify_Transport::send($envelope, $settings);
                if (!is_array($result) || !array_key_exists('ok', $result)) {
                    throw new RuntimeException('Invalid delivery result');
                }
            } catch (Throwable $e) {
                // An exception may occur after the request reached the robot.
                $result = array('ok' => false, 'uncertain' => true,
                    'message' => '无法确认机器人是否接收，请核实后手动重试。');
            }
            if (!empty($result['ok'])) {
                if (self::finish($job, $token, 'sent', '')) {
                    ++$stats['sent'];
                }
            } elseif (!empty($result['uncertain'])) {
                if (self::finish($job, $token, 'uncertain',
                    self::safeError($result['message'] ?? '机器人可能已接收，请核实后手动重试。', $settings))) {
                    ++$stats['uncertain'];
                }
            } else {
                $state = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
                $nextAt = $state === 'pending' ? time() + min(3600, 60 * (2 ** ($attempts - 1))) : 0;
                if (self::finish($job, $token, $state,
                    self::safeError($result['message'] ?? '机器人明确拒绝了通知，请检查配置。', $settings), $nextAt)) {
                    ++$stats['failed'];
                }
            }
        }
        return $stats;
    }

    /** Public queue metadata only: never expose message snapshots, credentials or lease tokens. */
    public static function rows(int $limit = 20): array
    {
        $db = self::db();
        return self::readAll($db->select('id', 'coid', 'kind', 'target', 'state', 'attempts',
            'next_at', 'lock_at', 'error', 'created_at', 'updated_at')->from(self::TABLE)
            ->order('id', Typecho_Db::SORT_DESC)->limit(max(1, min(100, $limit))));
    }

    /** Preserve the original target and request id even when explicitly retrying an ambiguous send. */
    public static function retry(int $id): bool
    {
        if ($id < 1) {
            return false;
        }
        $db = self::db();
        $job = self::readRow($db->select()->from(self::TABLE)->where('id = ?', $id)->limit(1));
        if (!$job || !in_array($job['state'], array('failed', 'uncertain'), true)) {
            return false;
        }
        $envelope = json_decode((string) $job['envelope'], true);
        if (!self::validEnvelope($envelope, $job)
            || !isset(self::destinationHashes(self::settings())[$job['config_hash']])) {
            return false;
        }
        $now = time();
        return (bool) $db->query($db->update(self::TABLE)->rows(array(
            'state' => 'pending', 'attempts' => 0, 'next_at' => $now,
            'lock_at' => 0, 'lock_token' => '', 'error' => '', 'updated_at' => $now
        ))->where('id = ?', $id)->where('state IN ?', array('failed', 'uncertain')));
    }

    private static function db()
    {
        return Typecho_Db::get();
    }

    /** Queue decisions read from the writer, avoiding replica lag after an atomic claim. */
    private static function readRow($query)
    {
        $db = self::db();
        return $db->fetchRow($db->query($query->prepare($query), Typecho_Db::WRITE));
    }

    private static function readAll($query): array
    {
        $db = self::db();
        return $db->fetchAll($db->query($query->prepare($query), Typecho_Db::WRITE));
    }

    private static function destination(array $destination): array
    {
        $snapshot = array();
        foreach (array('kind', 'provider', 'endpoint', 'channel', 'account', 'target') as $key) {
            if (!array_key_exists($key, $destination) || !is_string($destination[$key])) {
                throw new RuntimeException('通知目标格式无效。');
            }
            $snapshot[$key] = $destination[$key];
        }
        return $snapshot;
    }

    private static function destinationHashes(array $settings): array
    {
        $hashes = array();
        foreach (CommentNotify_Transport::destinations($settings) as $destination) {
            $hashes[hash('sha256', self::encode(self::destination($destination)))] = true;
        }
        return $hashes;
    }

    private static function validEnvelope($envelope, array $job): bool
    {
        if (!is_array($envelope) || !isset($envelope['message'], $envelope['request_id'])
            || !is_string($envelope['message']) || !is_string($envelope['request_id'])
            || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $envelope['request_id'])) {
            return false;
        }
        try {
            $snapshot = self::destination($envelope);
            return $snapshot['kind'] === $job['kind'] && $snapshot['target'] === $job['target']
                && hash_equals((string) $job['config_hash'], hash('sha256', self::encode($snapshot)));
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('通知内容编码失败。');
        }
        return $json;
    }

    private static function finish(array $job, string $token, string $state, string $error, int $nextAt = 0): bool
    {
        $db = self::db();
        return (bool) $db->query($db->update(self::TABLE)->rows(array(
            'state' => $state, 'next_at' => $nextAt, 'lock_at' => 0,
            'lock_token' => '', 'error' => $error, 'updated_at' => time()
        ))->where('id = ? AND state = ?', (int) $job['id'], 'sending')->where('lock_token = ?', $token));
    }

    private static function safeError($message, array $settings): string
    {
        $message = is_scalar($message) ? (string) $message : '机器人未确认接收，请检查配置。';
        foreach ($settings as $key => $value) {
            if (preg_match('/token|secret|password|key/i', (string) $key) && is_scalar($value)
                && strlen((string) $value) > 3) {
                $message = str_replace((string) $value, '[已隐藏]', $message);
            }
        }
        $message = trim(preg_replace('/[\x00-\x1f\x7f]/u', ' ', strip_tags($message)) ?? '通知发送失败。');
        return Typecho_Common::subStr($message, 0, 255, '');
    }
}
