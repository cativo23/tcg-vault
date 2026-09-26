<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\CatalogSyncService;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Scopes\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates or tops up the public example collection described by
 * config('tcgvault.demo'). Idempotent: re-running adds any newly listed
 * card and changes nothing else. Cards missing from the Catalog are
 * pulled from tcgdex, which also puts them on the daily price refresh.
 */
final class SeedDemoGallery extends Command
{
    protected $signature = 'demo:seed-gallery';

    protected $description = 'Create or top up the public example collection linked from the landing page.';

    public function handle(CatalogSyncService $sync): int
    {
        $username = config('tcgvault.demo.username');
        $email = config('tcgvault.demo.email');

        // The email is the demo account's identity, so changing
        // TCGVAULT_DEMO_USERNAME renames it rather than creating a second.
        $demo = User::where('email', $email)->first();

        // Same rules as every other path that writes a username, since
        // it becomes a public URL segment.
        $validator = Validator::make(['username' => $username], ['username' => User::usernameRules($demo?->id)]);
        if ($validator->fails()) {
            $this->error("TCGVAULT_DEMO_USERNAME [{$username}] is not usable: ".$validator->errors()->first('username'));

            return self::FAILURE;
        }

        if ($demo === null) {
            // Nobody is meant to log in as the demo: a random password that
            // is never shown or stored anywhere, and no roles.
            $demo = User::create([
                'name' => 'Demo',
                'username' => $username,
                'email' => $email,
                'password' => Str::random(64),
            ]);
        } elseif ($demo->username !== $username) {
            $demo->update(['username' => $username]);
        }

        // Console context has no authenticated user, so TenantScope would
        // filter every query to `user_id is null`.
        $collection = Collection::withoutGlobalScope(TenantScope::class)->firstOrCreate(
            ['user_id' => $demo->id, 'slug' => 'my-collection'],
            ['name' => 'My Collection', 'is_public' => true],
        );
        $collection->update(['is_public' => true]);

        $failed = [];

        foreach (config('tcgvault.demo.cards', []) as $tcgdexId) {
            try {
                $card = Card::where('tcgdex_id', $tcgdexId)->first() ?? $sync->syncCard($tcgdexId);
            } catch (Throwable $e) {
                $failed[] = $tcgdexId;
                $this->warn("Skipped [{$tcgdexId}]: {$e->getMessage()}");

                continue;
            }

            CollectionItem::firstOrCreate(
                [
                    'collection_id' => $collection->id, 'card_id' => $card->id, 'variant' => 'holofoil',
                    'condition' => 'NM', 'grade_company' => null, 'grade_value' => null,
                ],
                ['card_tcgdex_id' => $card->tcgdex_id, 'quantity' => 1],
            );
        }

        $this->info("Example collection at /{$username} has {$collection->items()->count()} card(s).");

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
