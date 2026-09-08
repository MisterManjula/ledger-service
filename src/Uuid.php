<?php

declare(strict_types=1);

namespace Ledger;

/**
 * Just enough uuid handling to avoid taking on ramsey/uuid, which would be a
 * dependency carried for two functions.
 */
final class Uuid
{
    /**
     * The canonical 8-4-4-4-12 form. Shared so that the router and the request
     * parser cannot drift into accepting different things.
     */
    public const PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public static function isValid(string $value): bool
    {
        return preg_match('/^' . self::PATTERN . '$/', $value) === 1;
    }

    /**
     * A random version 4 uuid, from random_bytes rather than from a sequence:
     * transfer ids must not be guessable or countable from outside.
     */
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40); // version 4
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80); // variant 10xx

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
