<?php

declare(strict_types=1);

use Laravel\Horizon\ProvisioningPlan;

test('the production supervisor does not balance across queues', function () {
    // Horizon builds one process pool per queue under balance
    // auto/simple, each floored at minProcesses=1. With maxProcesses=2
    // and two queues, 'default' was capped at a single worker every
    // night regardless of 'imports' activity — the nightly
    // catalog:refresh-prices fan-out (2,952 jobs) couldn't drain fast
    // enough for SyncCardPricingJob::retryUntil() before it expired.
    // Turning balancing off gives a single pool of workers that each
    // check 'default' before 'imports', matching the queue list's
    // documented priority order. Resolved through ProvisioningPlan (the
    // same defaults+environment merge Horizon itself uses), not the raw
    // config array, so an override that only exists at the 'defaults'
    // level is actually exercised.
    $options = ProvisioningPlan::get('horizon')->optionsFor('production', 'supervisor-1');

    expect($options->balance)->toBe('off');
    expect($options->queue)->toBe('default,imports');
});

test('Horizon keeps its recent and completed job records for the same few minutes', function () {
    // Every job leaves a record in Redis (about 3 kB each) until it is
    // trimmed, and the nightly price sync runs tens of thousands of them,
    // so the retention window is what sets how much memory Redis holds
    // once the drain is over. A completed job's record expires after
    // 'completed' minutes and the recent list drops ids whose record is
    // gone, so a longer 'recent' only makes the dashboard's count disagree
    // with the rows it lists.
    expect(config('horizon.trim.recent'))->toBe(15);
    expect(config('horizon.trim.completed'))->toBe(15);
});
