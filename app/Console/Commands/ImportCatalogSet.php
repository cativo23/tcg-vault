<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Exceptions\CatalogIdentityMismatchException;
use App\Modules\Catalog\Services\CatalogSyncService;
use Illuminate\Console\Command;

final class ImportCatalogSet extends Command
{
    protected $signature = 'catalog:import-set {setId : The tcgdex set ID, e.g. me05}';

    protected $description = 'Import every card in a tcgdex set, with current pricing, into the local Catalog.';

    public function handle(CardCatalogProvider $provider, CatalogSyncService $syncService): int
    {
        $setId = $this->argument('setId');
        $cardIds = $provider->listSetCardIds($setId);

        $imported = 0;

        $this->withProgressBar($cardIds, function (string $cardId) use ($syncService, &$imported) {
            try {
                $syncService->syncCard($cardId);
                $imported++;
            } catch (CardNotFoundException|CatalogIdentityMismatchException $e) {
                $this->newLine();
                $this->warn("Failed: {$cardId} ({$e->getMessage()})");
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn("Failed: {$cardId} (unexpected: {$e->getMessage()})");
            }
        });

        $this->newLine(2);
        $this->info(sprintf('Imported %d / %d cards for set [%s].', $imported, count($cardIds), $setId));

        return self::SUCCESS;
    }
}
