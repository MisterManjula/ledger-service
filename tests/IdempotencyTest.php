<?php

declare(strict_types=1);

namespace Ledger\Tests;

/**
 * The retry and conflict tests from the README's test table.
 *
 * Both rest on the same distinction: the Idempotency-Key names an attempt, and
 * the stored request fingerprint is what decides whether two attempts carrying
 * that key describe the same movement (ADR-002). A key alone cannot tell a
 * retry apart from a client that reused it for something else.
 */
final class IdempotencyTest extends LedgerTestCase
{
    private const AMOUNT = 2_500;

    private string $from;
    private string $to;
    private string $key;

    /** @var array<string, mixed> */
    private array $body;

    protected function setUp(): void
    {
        parent::setUp();

        $this->from = $this->createAccount(10_000);
        $this->to = $this->createAccount(0);
        $this->key = $this->idempotencyKey('idempotency');
        $this->body = [
            'from' => $this->from,
            'to' => $this->to,
            'amount' => self::AMOUNT,
            'currency' => 'EUR',
        ];
    }

    public function testIdenticalRetryMovesMoneyOnceAndReturnsTheOriginalResult(): void
    {
        $first = $this->postTransfer($this->body, $this->key);
        $second = $this->postTransfer($this->body, $this->key);

        self::assertSame(201, $first['status'], 'The first attempt should commit');
        self::assertSame(200, $second['status'], 'The retry should replay, not commit again');

        // Not merely equivalent: the same transfer id and the same created_at.
        // A body rebuilt from the retry's own request would look plausible and
        // be wrong, because it would describe a transfer that never happened.
        self::assertSame($first['body'], $second['body']);

        self::assertSame(1, $this->countTransfersWithKey($this->key), 'The key produced more than one transfer');
        self::assertSame(2, $this->countEntriesForKey($this->key), 'A replay wrote extra entries');

        self::assertSame(10_000 - self::AMOUNT, $this->balanceOf($this->from));
        self::assertSame(self::AMOUNT, $this->balanceOf($this->to));
    }

    /**
     * The fingerprint covers the values, not the bytes. A client that serialises
     * its JSON differently on retry — a different library, a different field
     * order — is still retrying, and answering 409 would turn a safe retry into
     * a failure the client cannot resolve.
     */
    public function testRetryWithReorderedFieldsStillReplays(): void
    {
        $first = $this->postTransfer($this->body, $this->key);

        $reordered = $this->postTransfer([
            'currency' => 'EUR',
            'amount' => self::AMOUNT,
            'to' => $this->to,
            'from' => $this->from,
        ], $this->key);

        self::assertSame(201, $first['status']);
        self::assertSame(200, $reordered['status']);
        self::assertSame($first['body'], $reordered['body']);

        self::assertSame(1, $this->countTransfersWithKey($this->key));
        self::assertSame(2, $this->countEntriesForKey($this->key));
    }

    /**
     * The sequential retry above would pass even if the replay path had a race
     * in it, because the first request has always committed before the second
     * begins. This one removes that guarantee.
     *
     * Exactly one attempt may commit. The rest must observe it and report it,
     * rather than each deciding for itself that the key looked free.
     */
    public function testConcurrentIdenticalRetriesMoveMoneyOnce(): void
    {
        $responses = $this->postTransferConcurrently($this->body, $this->key, 8);

        $statuses = array_count_values(array_column($responses, 'status'));

        self::assertSame(1, $statuses[201] ?? 0, 'More than one attempt committed');
        self::assertSame(7, $statuses[200] ?? 0, 'Some attempt neither committed nor replayed');

        $bodies = array_unique(array_map(
            static fn (array $response): string => json_encode($response['body'], JSON_THROW_ON_ERROR),
            $responses,
        ));
        self::assertCount(1, $bodies, 'Concurrent retries disagreed about the result');

        self::assertSame(1, $this->countTransfersWithKey($this->key));
        self::assertSame(2, $this->countEntriesForKey($this->key));

        self::assertSame(10_000 - self::AMOUNT, $this->balanceOf($this->from), 'Money moved more than once');
        self::assertSame(self::AMOUNT, $this->balanceOf($this->to));
    }

    /**
     * Same key, different request. Returning the first result would be the
     * convenient answer and a silent one: the client would receive the outcome
     * of a transfer it did not ask for and have no way to tell.
     */
    public function testReusingAKeyForADifferentRequestIsRefusedAndWritesNothing(): void
    {
        $original = $this->postTransfer($this->body, $this->key);
        self::assertSame(201, $original['status']);

        $conflicting = $this->postTransfer(
            [...$this->body, 'amount' => self::AMOUNT + 1],
            $this->key,
        );

        self::assertSame(409, $conflicting['status']);
        self::assertSame(['error' => 'idempotency_key_conflict'], $conflicting['body']);

        // The first transfer survives untouched, and the second wrote nothing.
        self::assertSame(1, $this->countTransfersWithKey($this->key));
        self::assertSame(2, $this->countEntriesForKey($this->key));

        self::assertSame(10_000 - self::AMOUNT, $this->balanceOf($this->from));
        self::assertSame(self::AMOUNT, $this->balanceOf($this->to));
    }

    /**
     * Reversing the direction keeps every field the client sent and changes what
     * the request means. A fingerprint over the whole payload catches it; one
     * over the amount alone would not.
     */
    public function testReusingAKeyWithTheDirectionReversedIsRefused(): void
    {
        $this->postTransfer($this->body, $this->key);

        $reversed = $this->postTransfer([
            'from' => $this->to,
            'to' => $this->from,
            'amount' => self::AMOUNT,
            'currency' => 'EUR',
        ], $this->key);

        self::assertSame(409, $reversed['status']);
        self::assertSame(1, $this->countTransfersWithKey($this->key));
        self::assertSame(2, $this->countEntriesForKey($this->key));
    }
}
