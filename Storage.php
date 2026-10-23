<?php
declare(strict_types=1);

if (!defined('__TYPECHO_ROOT_DIR__')) { http_response_code(403); exit; }

/** Keep every plugin query on the writer, including older Mysqli adapters. */
function comment_notify_query($query)
{
    $db = Typecho_Db::get();
    $action = is_string($query) ? null : $query->getAttribute('action');
    if ($db->getAdapterName() !== 'Mysqli') {
        if ($action === Typecho_Db::SELECT) { $query = $query->prepare((string)$query); }
        return $db->query($query, Typecho_Db::WRITE);
    }
    $writer = $db->selectDb(Typecho_Db::WRITE);
    $sql = is_string($query) ? $query : $query->prepare((string)$query);
    $resource = @$writer->query($sql);
    if ($resource === false) { throw new RuntimeException('无法读写评论通知数据，请检查数据库权限。'); }
    return match ($action) {
        Typecho_Db::UPDATE, Typecho_Db::DELETE => $writer->affected_rows,
        Typecho_Db::INSERT => $writer->insert_id,
        default => $resource,
    };
}

/** Called on activation; protocol state may exceed Typecho's MySQL options TEXT limit. */
function comment_notify_storage_ensure(): void
{
    $db = Typecho_Db::get();
    $prefix = $db->getPrefix();
    $adapter = $db->getAdapterName();
    if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix)
        || !preg_match('/mysql|mysqli|sqlite|pgsql/i', $adapter)) {
        throw new RuntimeException('评论通知支持 MySQL、SQLite 和 PostgreSQL 数据库，表前缀只能包含字母、数字和下划线。');
    }
    $mysql = (bool)preg_match('/mysql|mysqli/i', $adapter);
    $quote = $mysql ? '`' : '"';
    $table = $quote . $prefix . 'comment_notify_state' . $quote;
    $valueType = $mysql ? 'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : 'TEXT';
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    comment_notify_query("CREATE TABLE IF NOT EXISTS {$table} (
        name VARCHAR(32) NOT NULL PRIMARY KEY,
        value {$valueType} NOT NULL
    ){$suffix}");
}

function comment_notify_state_read(string $key): array
{
    $db = Typecho_Db::get();
    $query = $db->select('value')->from('table.comment_notify_state')->where('name = ?', $key)->limit(1);
    $row = $db->fetchRow(comment_notify_query($query));
    $value = $row ? json_decode((string)$row['value'], true) : null;
    return is_array($value) ? $value : [];
}

function comment_notify_state_write(string $key, array $record): void
{
    $db = Typecho_Db::get();
    $value = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $query = $db->select('name')->from('table.comment_notify_state')->where('name = ?', $key)->limit(1);
    $exists = $db->fetchRow(comment_notify_query($query));
    if (!$exists) {
        try {
            comment_notify_query($db->insert('table.comment_notify_state')->rows(['name' => $key, 'value' => $value]));
            return;
        } catch (Throwable $exception) {
            if (!$db->fetchRow(comment_notify_query($query))) { throw $exception; }
        }
    }
    comment_notify_query($db->update('table.comment_notify_state')->rows(['value' => $value])->where('name = ?', $key));
}

/** One statement chooses the only request allowed to consume a one-time binding. */
function comment_notify_state_consume(string $key, array $expected): bool
{
    if ($expected === []) { return false; }
    $db = Typecho_Db::get();
    $value = json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $condition = preg_match('/mysql|mysqli/i', $db->getAdapterName()) ? 'BINARY value = ?' : 'value = ?';
    return (bool)comment_notify_query($db->update('table.comment_notify_state')->rows(['value' => '[]'])
        ->where('name = ?', $key)->where($condition, $value));
}
