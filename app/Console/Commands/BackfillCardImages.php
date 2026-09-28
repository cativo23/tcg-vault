<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\TcgdexImageFallback;
use Illuminate\Console\Command;

/**
 * Fills in images for cards stored without one, using tcgdex's asset
 * server where its API leaves the image out. New syncs do this on their
 * own; this covers cards synced before. Safe to run again: a card only
 * gets an image once the file is confirmed to exist.
 */
final class BackfillCardImages extends Command
{
    protected $signature = 'catalog:backfill-images';

    protected $description = 'Find images for catalog cards stored without one.';

    public function handle(TcgdexImageFallback $fallback): int
    {
        $cards = Card::query()
            ->with('set')
            ->where(fn ($query) => $query->whereNull('official_image_url')->orWhere('official_image_url', ''))
            ->orderBy('id')
            ->get();

        $found = 0;
        foreach ($cards as $card) {
            $url = $fallback->resolve($card->set->tcgdex_id, $card->local_id);

            if ($url !== null) {
                $card->update(['official_image_url' => $url]);
                $found++;
            }
        }

        $this->info(sprintf('Found images for %d of %d cards without one.', $found, $cards->count()));

        if ($found === 0 && $cards->isNotEmpty()) {
            $this->warn('None found: tcgdex may have been unreachable, or these cards have no image there.');
        }

        return self::SUCCESS;
    }
}
