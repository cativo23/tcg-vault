<?php

declare(strict_types=1);

use App\Modules\Catalog\Listeners\BumpPriceDataVersion;

/*
 * The price-data version bumps on a statement BumpPriceDataVersion calls a
 * write. A read it mistakes for a write bumps on every render and quietly
 * turns the caches off; a write it mistakes for a read serves stale prices.
 */

dataset('statements that read the price rows', [
    'plain select' => ['select * from card_price_snapshots where card_id = ?'],
    'uppercase select' => ['SELECT count(*) FROM card_price_snapshots'],
    'mixed case select' => ['SeLeCt * FrOm Card_Price_Snapshots'],
    'quoted name' => ['select * from "card_price_snapshots" where "card_id" = ?'],
    'schema-qualified quoted name' => ['select * from "public"."card_price_snapshots"'],
    'parenthesised select' => ['(select id from card_price_snapshots) union (select id from card_price_snapshots)'],
    'CTE read' => ['with newest as (select card_id, max(captured_on) d from card_price_snapshots group by card_id) select * from newest'],
    'CTE read, uppercase' => ['WITH n AS (SELECT * FROM card_price_snapshots) SELECT * FROM n'],
    'block-comment prefixed read' => ['/* warm job */ select * from card_price_snapshots'],
    'line-comment prefixed read' => ["-- warm job\nselect * from card_price_snapshots"],
    'comment then parenthesised CTE read' => ['/* a */ /* b */ (with n as (select * from card_price_snapshots) select * from n)'],
    'select for update' => ['select * from card_price_snapshots where id = ? for update'],
    'select for no key update' => ['select * from card_price_snapshots where id = ? FOR NO KEY UPDATE'],
    'select for share' => ['select * from card_price_snapshots where id = ? for share'],
    'select for update skip locked' => ['select * from card_price_snapshots for update skip locked'],
    'explain' => ['explain select * from card_price_snapshots'],
    'explain analyze of a select' => ['EXPLAIN (ANALYZE, BUFFERS) select * from card_price_snapshots'],
    'column named like a keyword' => ['select updated_at, created_at from card_price_snapshots'],
    'read of cards' => ['select * from cards where id = ?'],
]);

dataset('statements that write the price rows', [
    'insert' => ['insert into card_price_snapshots (card_id) values (?)'],
    'update' => ['update card_price_snapshots set market_minor = ? where id = ?'],
    'delete' => ['delete from card_price_snapshots where card_id = ?'],
    'truncate' => ['truncate card_price_snapshots'],
    'truncate table, uppercase' => ['TRUNCATE TABLE "card_price_snapshots" RESTART IDENTITY'],
    'upsert' => ['insert into "card_price_snapshots" ("card_id") values (?) on conflict ("card_id") do update set "market_minor" = excluded."market_minor"'],
    'copy' => ['copy card_price_snapshots from stdin'],
    'merge' => ['merge into card_price_snapshots t using x on t.id = x.id when matched then update set market_minor = 1'],
    'mixed case update' => ['UpDaTe Card_Price_Snapshots SET market_minor = 1'],
    'schema-qualified quoted update' => ['update "public"."card_price_snapshots" set "market_minor" = ?'],
    'block-comment prefixed write' => ['/* select */ update card_price_snapshots set market_minor = 1'],
    'line-comment prefixed write' => ["-- select\ndelete from card_price_snapshots"],
    'CTE write: insert' => ['with src as (select 1) insert into card_price_snapshots (card_id) select * from src'],
    'CTE write: update' => ['with src as (select 1) update card_price_snapshots set market_minor = 1'],
    'CTE write: delete' => ['with gone as (delete from card_price_snapshots where card_id = ? returning id) select count(*) from gone'],
    'CTE write: update returning, uppercase' => ['WITH u AS (UPDATE card_price_snapshots SET market_minor = 1 RETURNING id) SELECT * FROM u'],
    'select then write in one string' => ['select 1; delete from card_price_snapshots'],
    'explain analyze of a write' => ['explain analyze update card_price_snapshots set market_minor = 1'],
    'delete of cards (cascades)' => ['delete from cards where id = ?'],
    'delete of sets, quoted (cascades)' => ['delete from "sets" where "id" = ?'],
    'truncate cards (cascades)' => ['truncate cards cascade'],
    'select then drop' => ['select 1 from card_price_snapshots; drop table card_price_snapshots'],
    'select then alter' => ['select 1 from card_price_snapshots; alter table card_price_snapshots add column x int'],
    'call of a procedure' => ['call refresh_card_price_snapshots()'],
    'do block' => ["do \$\$ begin perform 1 from card_price_snapshots; end \$\$"],
]);

test('a read of the price rows is not counted as a write', function (string $sql) {
    expect(BumpPriceDataVersion::writesPriceRows($sql))->toBeFalse();
})->with('statements that read the price rows');

test('every kind of write to the price rows is counted', function (string $sql) {
    expect(BumpPriceDataVersion::writesPriceRows($sql))->toBeTrue();
})->with('statements that write the price rows');
