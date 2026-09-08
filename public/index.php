<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Ledger\AccountRepository;
use Ledger\Database;

// Canonical 8-4-4-4-12 form. An id that is not a uuid cannot name an account, so
// it falls through to the 404 arm instead of reaching Postgres and coming back as
// a 22P02 invalid_text_representation error: this endpoint has two outcomes.
const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

try {
    [$status, $body] = match (true) {
        $method === 'GET' && $path === '/' => [200, ['service' => 'ledger-service', 'status' => 'ok']],

        $method === 'GET' && preg_match('#^/accounts/(' . UUID_PATTERN . ')$#', $path, $matches) === 1
            => showAccount($matches[1]),

        default => [404, ['error' => 'not_found']],
    };
} catch (Throwable $exception) {
    // Logged in full, reported as a bare code: an exception message can carry
    // connection strings and row contents, and neither belongs in a response.
    error_log((string) $exception);
    [$status, $body] = [500, ['error' => 'internal_error']];
}

http_response_code($status);
header('Content-Type: application/json');
echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

/**
 * @return array{0: int, 1: array<string, mixed>}
 */
function showAccount(string $id): array
{
    // Connected here rather than at the top of the file so that `GET /` stays a
    // liveness check that does not depend on the database being reachable.
    $accounts = new AccountRepository(Database::connect());

    $account = $accounts->find($id);

    return $account === null
        ? [404, ['error' => 'account_not_found']]
        : [200, $account];
}
