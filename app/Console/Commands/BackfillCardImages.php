<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Contracts\CardImageFallback;
use App\Modules\Catalog\Contracts\LinkedCardImage;
use App\Modules\Catalog\Models\Card;
use Illuminate\Console\Command;

/**
 * Fills in images for cards stored without one: tcgdex's asset server where
 * its API leaves the image out, then the image of the TCGplayer product the
 * card is linked to. New syncs use the asset server on their own; this
 * covers cards synced before and the cards only TCGplayer has a picture
 * for. Safe to run again: a card only gets an image once the file is
 * confirmed to exist. `--dry-run` lists what would be set and writes
 * nothing.
 */
final class BackfillCardImages extends Command
{
    /** Between cards, so a long list does not hammer the image hosts. */
    private const PAUSE_MICROSECONDS = 150_000;

    protected $signature = 'catalog:backfill-images {--dry-run : List the images that would be set without writing them}';

    protected $description = 'Find images for catalog cards stored without one.';

    public function handle(CardImageFallback $fallback, LinkedCardImage $linked): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cards = Card::query()
            ->with('set')
            ->where(fn ($query) => $query->whereNull('official_image_url')->orWhere('official_image_url', ''))
            ->orderBy('id')
            ->get();

        $found = 0;
        foreach ($cards as $i => $card) {
            if ($i > 0) {
                usleep(self::PAUSE_MICROSECONDS);
            }

            $url = $fallback->resolve($card->set->tcgdex_id, $card->local_id) ?? $linked->resolve($card);

            if ($url !== null) {
                if ($dryRun) {
                    $this->line("{$card->tcgdex_id} {$card->name} → {$url}");
                } else {
                    $card->update(['official_image_url' => $url]);
                }
                $found++;
            }
        }

        $this->info(sprintf('%s images for %d of %d cards without one.', $dryRun ? 'Would set' : 'Found', $found, $cards->count()));

        if ($found === 0 && $cards->isNotEmpty()) {
            $this->warn('None found: tcgdex or TCGplayer may have been unreachable, or these cards have no image there.');
        }

        return self::SUCCESS;
    }
}
