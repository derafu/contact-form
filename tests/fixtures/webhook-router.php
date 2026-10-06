<?php

declare(strict_types=1);

/**
 * Router of the local webhook that the tests of ContactService send to.
 *
 * It writes what it received in the file of the environment variable
 * `WEBHOOK_LOG`, and answers according to the path: `/error` is a failure of
 * the server and any other path is a success.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

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

echo '{"status":"received"}';
