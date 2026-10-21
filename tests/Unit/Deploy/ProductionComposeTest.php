<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * The production services built from the app image, read from the compose
 * file. Not base_path(): unit tests don't boot the application on their own.
 *
 * @return array<string, array{string}>
 */
function appImageServices(): array
{
    $compose = Yaml::parseFile(dirname(__DIR__, 3).'/docker/prod/compose.prod.yml');
    $services = [];

    foreach ($compose['services'] as $name => $service) {
        if (str_contains($service['image'] ?? '', '/tcg-vault:')) {
            $services[$name] = [$name];
        }
    }

    return $services;
}

test('the production compose file has app image services to check', function () {
    expect(array_keys(appImageServices()))->toContain('app', 'horizon', 'scheduler');
});

test('Telescope is switched off in every production service that runs the app', function (string $service) {
    // Telescope's job watcher queues an update for every job even though the
    // production filter drops the entry, and each of those updates queues
    // itself again. The compose environment wins over the .env file, so this
    // holds whatever the real .env says.
    $compose = Yaml::parseFile(dirname(__DIR__, 3).'/docker/prod/compose.prod.yml');

    expect($compose['services'][$service]['environment']['TELESCOPE_ENABLED'])->toBe('false');
})->with(fn () => appImageServices());
