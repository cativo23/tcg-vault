<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Modules\Catalog\Support\CardVariants;
use App\Modules\Collection\Services\CollectionService;

/**
 * The "other print" picker, for a print tcgdex doesn't list (a Prize Pack
 * cosmos holo): a base print plus one foil and/or one stamp from tcgdex's
 * own vocabulary. One picker serves every row of a form; the component's
 * useCustomVariant() says which row the composed key goes to.
 *
 * The vocabulary is enforced here, in the picker. A variant submitted
 * through a row is only shape-checked (CardVariants::rules()), so a stored
 * key isn't guaranteed to come from it.
 */
trait PicksCustomPrint
{
    public string $customBase = 'holofoil';

    public ?string $customFoil = null;

    public ?string $customStamp = null;

    /**
     * The key for the picker's current choice, or null (with an error on
     * customFoil) when it isn't a print — nothing picked, or a value
     * outside tcgdex's vocabulary.
     */
    protected function composeCustomVariant(): ?string
    {
        $foil = CollectionService::nullIfEmpty($this->customFoil);
        $stamp = CollectionService::nullIfEmpty($this->customStamp);
        $key = CardVariants::compose($this->customBase, $foil, $stamp !== null ? [$stamp] : []);

        if ($key === null) {
            $this->addError('customFoil', 'Pick a foil or a stamp from the list — a print needs at least one to be told apart.');

            return null;
        }

        $this->resetErrorBag('customFoil');

        return $key;
    }
}
