<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One staff action on a member's account: suspended, unsuspended or
 * deleted. Written to the database rather than the log, which production
 * keeps at warning level on stderr, and pruned after a year as the privacy
 * policy states.
 */
final class ModerationAction extends Model
{
    use Prunable;

    public const KEEP_DAYS = 365;

    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'member_id', 'action'];

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
