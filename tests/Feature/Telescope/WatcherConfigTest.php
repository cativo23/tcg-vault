<?php

declare(strict_types=1);

use Laravel\Telescope\Watchers\ModelWatcher;

// Production's Telescope filter keeps only exceptions, failed requests,
// failed jobs and scheduled tasks, so a model-event entry never gets
// stored there — but the watcher still listens to every `eloquent.*`
// event, which on a gallery page means one listener call per hydrated
// price snapshot (tens of thousands per request).
test('the model watcher is off by default outside local', function () {
    $watchers = (require config_path('telescope.php'))['watchers'];

    expect($watchers[ModelWatcher::class]['enabled'])->toBeFalse();
});
