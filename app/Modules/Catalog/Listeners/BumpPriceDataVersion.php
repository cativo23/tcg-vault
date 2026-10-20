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
 * a plain SELECT, or that carries a write keyword anywhere (a multi-statement
 * string). A DELETE or TRUNCATE on cards or sets counts too, because the
 * foreign keys cascade into the price rows. A false positive only costs a
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
            return preg_match('/^\s*\(?\s*select\b/i', $sql) !== 1
                || preg_match('/\b(insert|update|delete|truncate|merge|copy)\b/i', $sql) === 1;
        }

        if (stripos($sql, 'cards') === false && stripos($sql, 'sets') === false) {
            return false;
        }

        return preg_match('/\b(delete\s+from|truncate)\b[^;]*?(?<![\w.])"?(cards|sets)"?(?!\w)/i', $sql) === 1;
    }
}
