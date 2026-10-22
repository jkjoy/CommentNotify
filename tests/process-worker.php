<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (count($argv) !== 3 || !is_file($argv[1])) {
    exit(2);
}
notifyTestConnect($argv[1]);
require_once dirname(__DIR__) . '/Service.php';
echo json_encode(CommentNotify_Service::process((int) $argv[2]), JSON_THROW_ON_ERROR) . "\n";
