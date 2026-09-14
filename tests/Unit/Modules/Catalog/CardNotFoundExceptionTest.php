<?php

declare(strict_types=1);

use App\Modules\Catalog\Exceptions\CardNotFoundException;

test('CardNotFoundException::forTcgdexId strips control characters from the ID to prevent log injection', function () {
    $exception = CardNotFoundException::forTcgdexId("me05-116\r\n[2026-09-14 12:00:00] production.CRITICAL: forged log entry");

    expect($exception->getMessage())->not->toContain("\r");
    expect($exception->getMessage())->not->toContain("\n");
});
