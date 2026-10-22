<?php
/** Optional scheduler: process retries even when the blog has no visitors. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}
try {
    require_once dirname(__DIR__, 3) . '/config.inc.php';
    if (!defined('__TYPECHO_ROOT_URL__')) {
        $db = Typecho_Db::get();
        $site = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'siteUrl')->where('user = ?', 0));
        if (!$site || !filter_var($site['value'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('站点地址无效。');
        }
        define('__TYPECHO_ROOT_URL__', rtrim($site['value'], '/'));
    }
    Typecho_Widget::widget('Widget_Init');
    if (!Typecho_Plugin::exists('CommentNotify')) {
        fwrite(STDERR, "CommentNotify 插件尚未启用。\n");
        exit(1);
    }
    require_once __DIR__ . '/Plugin.php';
    $result = CommentNotify_Service::process(20);
    fwrite(STDOUT, 'CommentNotify: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, "CommentNotify 调度失败，请检查数据库与插件设置。\n");
    exit(1);
}
