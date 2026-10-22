<?php
/** Only ever launched on loopback by integration.php. All credentials are fixtures. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit;
}
$directory = getenv('NOTIFY_TEST_DIRECTORY');
if (!$directory || !is_dir($directory)) {
    http_response_code(500);
    exit;
}
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$request = ['path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'body' => $body, 'raw' => $raw];
file_put_contents($directory . '/requests.jsonl', json_encode($request, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
$scenario = trim((string) @file_get_contents($directory . '/scenario'));
header('Content-Type: application/json; charset=utf-8');
if ($scenario === 'slow') {
    usleep(750000);
}
if ($scenario === 'timeout') {
    usleep(11000000);
}
if ($scenario === 'empty') {
    exit;
}
if ($scenario === 'malformed') {
    echo '{broken';
    exit;
}
if ($scenario === 'http-error') {
    http_response_code(503);
    echo '{"ok":false,"error":"temporarily unavailable"}';
    exit;
}
if ($scenario === 'http-rejected') {
    http_response_code(403);
    echo '{"ok":false,"error":"unauthorized PRIVATE-MOCK-GATEWAY-TOKEN"}';
    exit;
}
if ($scenario === 'business-error') {
    echo '{"ok":false,"error":"delivery rejected"}';
    exit;
}
if ($scenario === 'nested-error') {
    echo '{"ok":true,"result":{"isError":true,"content":[{"type":"text","text":"delivery rejected"}]}}';
    exit;
}
if ($scenario === 'text-error') {
    echo json_encode(['ok' => true, 'result' => ['content' => [
        ['type' => 'text', 'text' => json_encode(['status' => 'error', 'message' => 'channel rejected'])],
    ]]]);
    exit;
}
if ($scenario === 'fail-qq' && ($body['args']['channel'] ?? '') === 'qqbot') {
    echo '{"ok":false,"error":"QQ rejected"}';
    exit;
}
if ($scenario === 'service-queued' || $scenario === 'service-sent') {
    echo json_encode(['ok' => true, 'status' => substr($scenario, 8), 'id' => 'mock-service-1']);
    exit;
}
echo json_encode(['ok' => true, 'result' => ['content' => [
    ['type' => 'text', 'text' => json_encode(['status' => 'sent', 'messageId' => 'mock-message'])],
]]]);
