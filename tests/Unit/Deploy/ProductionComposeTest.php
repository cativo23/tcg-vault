<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

dataset('services that run the app code', ['app', 'horizon', 'scheduler']);

test('Telescope is switched off in every production service that runs the app', function (string $service) {
    // Telescope's job watcher queues an update for every job even though the
    // production filter drops the entry, and each of those updates re-queues
    // itself, so it was most of what Horizon ran during the nightly price
    // sync. The compose environment wins over the .env file, so this holds
    // whatever the real .env says.
    // Not base_path(): unit tests don't boot the application on their own.
    $compose = Yaml::parseFile(dirname(__DIR__, 3).'/docker/prod/compose.prod.yml');

    expect($compose['services'][$service]['environment']['TELESCOPE_ENABLED'])->toBe('false');
})->with('services that run the app code');
