<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tcgcsv;

/**
 * What the identity matcher concluded for one print of a card: `new` (a
 * product it would link, no link yet), `agree` / `different` (the product it
 * found is / is not the one the print's link already points at), or
 * `refused` with the reason.
 */
final readonly class IdentityOutcome
{
    public const NEW = 'new';

    public const AGREE = 'agree';

    public const DIFFERENT = 'different';

    public const REFUSED = 'refused';

    /** @param  array<string, mixed>  $evidence */
    public function __construct(
        public string $cardId,
        public string $variant,
        public string $status,
        public ?string $reason = null,
        public ?int $productId = null,
        public ?string $subType = null,
        public ?int $groupId = null,
        public array $evidence = [],
    ) {}

    public function label(): string
    {
        return "{$this->cardId} {$this->variant}".($this->reason !== null ? " ({$this->reason})" : '');
    }
}
