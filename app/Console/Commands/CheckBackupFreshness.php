<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DiscordAlerter;
use Illuminate\Console\Command;

/**
 * Catches the nightly backup silently stopping. The `backup` container
 * (docker/prod/backup/backup.sh) writes a database dump and a photo archive once
 * a day; a broken dump, a full disk or a stopped container would otherwise
 * only be noticed on the day a restore is needed.
 *
 * Only finished files count: the script writes to a hidden `.partial`
 * name and renames it once the dump is complete.
 */
final class CheckBackupFreshness extends Command
{
    private const STALE_AFTER_HOURS = 26;

    /** Label shown in the alert => filename pattern. */
    private const KINDS = [
        'database' => 'db-*.dump',
        'photo' => 'photos-*.tar',
    ];

    protected $signature = 'backups:check-freshness';

    protected $description = 'Alert Discord if the newest database or photo backup is older than expected.';

    public function handle(DiscordAlerter $alerter): int
    {
        $dir = config('tcgvault.backups.path');

        if (! is_string($dir) || $dir === '') {
            $this->info('No backup directory configured; nothing to check.');

            return self::SUCCESS;
        }

        if (! is_dir($dir)) {
            $alerter->send("⚠️ tcg-vault: the backup directory {$dir} is missing — nightly backups are not being written where expected.");
            $this->warn("Backup directory {$dir} is missing.");

            return self::SUCCESS;
        }

        foreach (self::KINDS as $label => $pattern) {
            $this->checkKind($alerter, $dir, $label, $pattern);
        }

        $this->checkFreeSpace($alerter, $dir);

        return self::SUCCESS;
    }

    /**
     * The backups share a disk with the live database, and each night adds
     * a full copy of the photos. A full disk would stop Postgres as well as
     * the backups, so it is flagged while there is still room to act.
     */
    private function checkFreeSpace(DiscordAlerter $alerter, string $dir): void
    {
        $minFree = (int) config('tcgvault.backups.min_free_bytes');
        $free = disk_free_space($dir);

        if ($free === false || $free >= $minFree) {
            return;
        }

        $freeGb = round($free / 1024 ** 3, 1);
        $alerter->send("⚠️ tcg-vault: only {$freeGb} GB free on the backup disk, which also holds the live database.");
        $this->warn("Only {$freeGb} GB free on the backup disk.");
    }

    private function checkKind(DiscordAlerter $alerter, string $dir, string $label, string $pattern): void
    {
        $newest = null;
        foreach (glob($dir.'/'.$pattern) ?: [] as $file) {
            $mtime = filemtime($file);
            if ($mtime !== false && ($newest === null || $mtime > $newest)) {
                $newest = $mtime;
            }
        }

        if ($newest === null) {
            $alerter->send("⚠️ tcg-vault: no {$label} backup exists at all — the nightly backup may have never run.");
            $this->warn("No {$label} backup found.");

            return;
        }

        $hours = (int) now()->diffInHours(now()->setTimestamp($newest), absolute: true);

        if ($hours >= self::STALE_AFTER_HOURS) {
            $alerter->send("⚠️ tcg-vault: the newest {$label} backup is {$hours}h old — the nightly backup looks stuck.");
            $this->warn("Newest {$label} backup is {$hours}h old.");

            return;
        }

        $this->info("Newest {$label} backup is {$hours}h old — within the ".self::STALE_AFTER_HOURS.'h threshold.');
    }
}
