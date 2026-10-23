<?php

declare(strict_types=1);

/**
 * Router of the local webhook that the tests of ContactService send to.
 *
 * It writes what it received in the file of the environment variable
 * `WEBHOOK_LOG`, and answers according to the path: `/error` is a failure of
 * the server; other routes exercise successful and invalid JSON responses.
 */

$uri = $_SERVER['REQUEST_URI'];
if (!is_string($uri)) {
    throw new RuntimeException('The local webhook request URI must be a string.');
}
$path = parse_url($uri, PHP_URL_PATH);

file_put_contents((string) getenv('WEBHOOK_LOG'), json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'signature' => $_SERVER['HTTP_X_SIGNATURE'] ?? null,
    'body' => file_get_contents('php://input'),
]));

header('Content-Type: application/json');

if ($path === '/error') {
    http_response_code(500);
    echo '{"error":"boom"}';

    return;
}

if ($path === '/no-content') {
    http_response_code(204);
    return;
}

echo match ($path) {
    '/object' => '{}',
    '/empty-list' => '[]',
    '/list' => '[{"status":"received"}]',
    '/malformed' => '{',
    '/empty' => '',
    '/null' => 'null',
    '/boolean' => 'false',
    '/number' => '42',
    '/string' => '"received"',
    default => '{"status":"received"}',
};
