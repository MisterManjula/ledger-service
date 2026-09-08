<?php

declare(strict_types=1);

namespace Ledger;

use PDO;

/**
 * The one place a database connection is configured.
 *
 * It exists so that the transfer path in the next step cannot accidentally run
 * against different settings than the read path: a connection that emulates
 * prepared statements, or that swallows errors instead of throwing, would change
 * how a constraint violation surfaces — and a constraint violation surfacing
 * correctly is the whole subject of this project.
 */
final class Database
{
    public static function connect(): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            getenv('DB_HOST') ?: 'db',
            getenv('DB_PORT') ?: '5432',
            getenv('DB_NAME') ?: 'ledger',
        );

        return new PDO($dsn, getenv('DB_USER') ?: 'ledger', getenv('DB_PASSWORD') ?: '', [
            // A failed statement must throw. Silent failure is how an overdraft
            // would turn into a 500 with the money already moved.
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real server-side prepares: no value is ever interpolated into SQL
            // text, and integer columns come back as integers.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
