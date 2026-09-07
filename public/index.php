<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

[$status, $body] = match (true) {
    $method === 'GET' && $path === '/' => [200, ['service' => 'ledger-service', 'status' => 'ok']],
    default => [404, ['error' => 'not_found']],
};

http_response_code($status);
header('Content-Type: application/json');
echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
