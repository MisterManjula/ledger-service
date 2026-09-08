<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Ledger\AccountRepository;
use Ledger\Database;
use Ledger\InvalidRequest;
use Ledger\TransferRejected;
use Ledger\TransferRequest;
use Ledger\TransferService;
use Ledger\Uuid;

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

try {
    [$status, $body] = match (true) {
        $method === 'GET' && $path === '/' => [200, ['service' => 'ledger-service', 'status' => 'ok']],

        // An id that is not a uuid cannot name an account, so it falls through to
        // the 404 arm instead of reaching Postgres and coming back as a 22P02
        // invalid_text_representation error: this endpoint has two outcomes.
        $method === 'GET' && preg_match('#^/accounts/(' . Uuid::PATTERN . ')$#', $path, $matches) === 1
            => showAccount($matches[1]),

        $method === 'POST' && $path === '/transfers' => createTransfer(),

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

/**
 * @return array{0: int, 1: array<string, mixed>}
 */
function createTransfer(): array
{
    try {
        $request = TransferRequest::fromJson(
            (string) file_get_contents('php://input'),
            $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
        );
    } catch (InvalidRequest $exception) {
        // Nothing was opened, nothing was written: the request never described a
        // transfer that could be attempted.
        return [400, ['error' => $exception->reason]];
    }

    $transfers = new TransferService(Database::connect());

    try {
        return [201, $transfers->execute($request)];
    } catch (TransferRejected $exception) {
        // The ledger understood the request and refused it. Which number that is
        // reported as is decided here, so that the service layer stays free of
        // HTTP.
        $status = match ($exception->reason) {
            'idempotency_key_reused' => 409,
            default => 422,
        };

        return [$status, ['error' => $exception->reason]];
    }
}
