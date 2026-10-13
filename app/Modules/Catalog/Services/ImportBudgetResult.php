<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

enum ImportBudgetResult
{
    /** The import may start: it was in budget, or already started today. */
    case Started;

    /** The user has started the daily limit of sets today. */
    case Exhausted;

    /** Another request of the same user holds the budget; try again. */
    case Busy;
}
