<?php

declare(strict_types=1);

namespace App\Modules\Invites\Models;

use App\Models\User;
use Database\Factories\InviteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @use HasFactory<InviteFactory>
 */
final class Invite extends Model
{
    use HasFactory;

    protected $fillable = ['email', 'created_by', 'accepted_by', 'expires_at', 'used_at', 'revoked_at', 'revoked_by'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): InviteFactory
    {
        return InviteFactory::new();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * The signed URL that carries this invite stays cryptographically
     * valid until its own internal expiration even if this row is later
     * revoked or used — so accepting an invite must ALWAYS re-check
     * used_at/revoked_at/expires_at against this row, never trust the
     * signature alone as proof the invite is still good.
     */
    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /**
     * No-ops on an already-used invite — revoking is only meaningful
     * for one nobody has accepted yet; an accepted invite's account
     * already exists and revoking the invite row after the fact would
     * do nothing useful (and could read as "the account was undone",
     * which it isn't).
     *
     * The `used_at is null` guard lives in the UPDATE's WHERE clause,
     * not a check against this in-memory instance's attributes — a
     * single UPDATE is atomic at the database, so a request that
     * accepts this invite between this object being loaded and revoke()
     * being called can never be raced: whichever write actually sets
     * used_at first wins, and this revoke() then correctly no-ops.
     */
    public function revoke(?int $revokedBy = null): void
    {
        $updated = self::query()
            ->whereKey($this->id)
            ->whereNull('used_at')
            ->update(['revoked_at' => now(), 'revoked_by' => $revokedBy]);

        if ($updated > 0) {
            $this->refresh();
        }
    }
}
