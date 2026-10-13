<?php

declare(strict_types=1);

use App\Modules\Catalog\Services\ImportBudget;
use App\Modules\Catalog\Services\ImportBudgetResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
});

afterEach(function () {
    Cache::flush();
});

function budget(): ImportBudget
{
    return app(ImportBudget::class);
}

test('a user may start up to the daily limit of different sets', function () {
    for ($i = 1; $i <= ImportBudget::DAILY_LIMIT; $i++) {
        expect(budget()->tryStart(1, "set{$i}"))->toBe(ImportBudgetResult::Started);
    }

    expect(budget()->tryStart(1, 'one-too-many'))->toBe(ImportBudgetResult::Exhausted);
});

test('starting the same set again on the same day is free', function () {
    for ($i = 1; $i <= ImportBudget::DAILY_LIMIT; $i++) {
        budget()->tryStart(1, "set{$i}");
    }

    expect(budget()->tryStart(1, 'set1'))->toBe(ImportBudgetResult::Started)
        ->and(budget()->tryStart(1, 'set20'))->toBe(ImportBudgetResult::Started);
});

test('a repeat start does not consume budget', function () {
    for ($i = 0; $i < ImportBudget::DAILY_LIMIT + 5; $i++) {
        budget()->tryStart(1, 'me05');
    }

    for ($i = 1; $i < ImportBudget::DAILY_LIMIT; $i++) {
        expect(budget()->tryStart(1, "set{$i}"))->toBe(ImportBudgetResult::Started);
    }

    expect(budget()->tryStart(1, 'last'))->toBe(ImportBudgetResult::Exhausted);
});

test('the budget is per user', function () {
    for ($i = 1; $i <= ImportBudget::DAILY_LIMIT; $i++) {
        budget()->tryStart(1, "set{$i}");
    }

    expect(budget()->tryStart(2, 'set1'))->toBe(ImportBudgetResult::Started)
        ->and(budget()->tryStart(1, 'other'))->toBe(ImportBudgetResult::Exhausted);
});

test('the budget resets on the next UTC day', function () {
    for ($i = 1; $i <= ImportBudget::DAILY_LIMIT; $i++) {
        budget()->tryStart(1, "set{$i}");
    }

    $this->travel(1)->days();

    expect(budget()->tryStart(1, 'fresh'))->toBe(ImportBudgetResult::Started);
});

test('a held lock answers busy without consuming budget', function () {
    // The lock wait sleeps; a fake sleep that moves the clock keeps it
    // finite under frozen test time and instant.
    Sleep::fake(syncWithCarbon: true);
    $lock = Cache::lock('import-budget:lock:1', 10);
    expect($lock->get())->toBeTrue();

    expect(budget()->tryStart(1, 'me05'))->toBe(ImportBudgetResult::Busy);

    $lock->release();

    for ($i = 1; $i <= ImportBudget::DAILY_LIMIT; $i++) {
        expect(budget()->tryStart(1, "set{$i}"))->toBe(ImportBudgetResult::Started);
    }
});
