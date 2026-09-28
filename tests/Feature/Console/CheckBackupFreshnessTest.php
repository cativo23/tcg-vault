<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.discord.alert_webhook_url' => 'https://discord.com/api/webhooks/test']);

    $this->backupDir = storage_path('framework/testing/backups-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->backupDir);
    config(['tcgvault.backups.path' => $this->backupDir]);
});

afterEach(function () {
    File::deleteDirectory($this->backupDir);
});

function makeBackupAt(string $dir, string $name, int $hoursAgo): void
{
    $path = $dir.'/'.$name;
    file_put_contents($path, 'x');
    touch($path, now()->subHours($hoursAgo)->getTimestamp());
}

test('does not alert when both the database dump and the photo archive are recent', function () {
    Http::fake();
    makeBackupAt($this->backupDir, 'db-2026-09-28.dump', 5);
    makeBackupAt($this->backupDir, 'photos-2026-09-28.tar.gz', 5);

    $this->artisan('backups:check-freshness')->assertSuccessful();

    Http::assertNothingSent();
});

test('alerts when the newest database dump is older than the threshold', function () {
    Http::fake();
    makeBackupAt($this->backupDir, 'db-2026-09-26.dump', 30);
    makeBackupAt($this->backupDir, 'photos-2026-09-28.tar.gz', 5);

    $this->artisan('backups:check-freshness')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'database backup is 30h old'));
});

test('alerts when there is no photo archive at all', function () {
    Http::fake();
    makeBackupAt($this->backupDir, 'db-2026-09-28.dump', 5);

    $this->artisan('backups:check-freshness')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'no photo backup'));
});

test('ignores a half-written dump that never finished', function () {
    Http::fake();
    makeBackupAt($this->backupDir, '.db-2026-09-28.dump.partial', 1);
    makeBackupAt($this->backupDir, 'photos-2026-09-28.tar.gz', 5);

    $this->artisan('backups:check-freshness')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'no database backup'));
});

test('alerts when the backup directory is missing', function () {
    Http::fake();
    config(['tcgvault.backups.path' => $this->backupDir.'/nope']);

    $this->artisan('backups:check-freshness')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'backup directory'));
});

test('does nothing when no backup directory is configured', function () {
    Http::fake();
    config(['tcgvault.backups.path' => null]);

    $this->artisan('backups:check-freshness')->assertSuccessful();

    Http::assertNothingSent();
});
