<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Listeners;

use App\Modules\Catalog\Services\PriceDataVersion;
use Illuminate\Database\Events\QueryExecuted;
use RuntimeException;

/**
 * Bumps the price-data version on every statement that can change
 * card_price_snapshots, whichever way it was issued: an Eloquent save or
 * delete, a query-builder insert/upsert/update/delete, or raw SQL. Every one
 * of those runs through a database connection and fires QueryExecuted, so
 * listening there catches them all without a list of call sites to keep up.
 *
 * Counted as a write: any statement naming card_price_snapshots that is not
 * a read (see isRead(): SELECT, WITH or EXPLAIN with no write keyword in it,
 * so a CTE read, a commented read or SELECT ... FOR UPDATE stay reads while a
 * data-modifying CTE or a multi-statement string is a write). A DELETE or
 * TRUNCATE on cards or sets counts too, because the foreign keys cascade
 * into the price rows. A false positive only costs a
 * cache miss; a missed write would show a stale price.
 *
 * Inside a transaction the bump waits for the commit, so a page rendered
 * between the write and the commit can't cache the old rows under the new
 * version; a rolled-back write bumps nothing.
 */
final class BumpPriceDataVersion
{
    public function __construct(private readonly PriceDataVersion $version) {}

    public function handle(QueryExecuted $query): void
    {
        if (! self::writesPriceRows($query->sql)) {
            return;
        }

        $bump = fn () => $this->version->bump();

        try {
            $query->connection->afterCommit($bump);
        } catch (RuntimeException) {
            // No transactions manager on this connection: nothing to wait for.
            $bump();
        }
    }

    public static function writesPriceRows(string $sql): bool
    {
        if (stripos($sql, 'card_price_snapshots') !== false) {
            return ! self::isRead($sql);
        }

        if (stripos($sql, 'cards') === false && stripos($sql, 'sets') === false) {
            return false;
        }

        return preg_match('/\b(delete\s+from|truncate)\b[^;]*?(?<![\w.])"?(cards|sets)"?(?!\w)/i', $sql) === 1;
    }

    /**
     * A statement that only reads: it starts, past any comments and opening
     * parentheses, with SELECT, WITH or EXPLAIN, and carries no write keyword
     * anywhere. A row-locking clause (FOR UPDATE, FOR SHARE) is not a write.
     * Comments are only skipped at the start, so a keyword hidden behind
     * one anywhere else still counts and a write is never taken for a read.
     */
    private static function isRead(string $sql): bool
    {
        $body = preg_replace('#^(?:\s+|\(|/\*.*?\*/|--[^\n]*(?:\n|$))+#s', '', $sql) ?? $sql;

        if (preg_match('/^(select|with|explain)\b/i', $body) !== 1) {
            return false;
        }

        $body = preg_replace('/\bfor\s+(?:no\s+key\s+update|key\s+share|update|share)\b/i', ' ', $sql) ?? $sql;

        return preg_match('/\b(insert|update|delete|truncate|merge|copy|drop|alter|call|do)\b/i', $body) !== 1;
    }
}
