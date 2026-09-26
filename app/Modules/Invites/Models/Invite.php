<?php

declare(strict_types=1);

namespace App\Modules\Invites\Models;

use App\Models\User;
use Database\Factories\InviteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

final class Invite extends Model
{
    /** @use HasFactory<InviteFactory> */
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /** @return BelongsTo<User, $this> */
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
     * Deterministic from this row's own id/email/expiry plus APP_KEY —
     * safe to recompute on demand (e.g. every time the invite manager
     * renders) rather than having to generate and store it once at
     * creation, which is what made it easy to create an Invite row and
     * never actually surface a link anyone could use.
     */
    public function signedUrl(): string
    {
        return URL::temporarySignedRoute('invite.accept', $this->expires_at, [
            'invite' => $this->id,
            'hash' => sha1($this->email),
        ]);
    }

    /**
     * The query-level twin of isUsable() — same three conditions,
     * expressed so callers checking "does a usable invite exist" (e.g.
     * the duplicate-invite check in InviteManager::createInvite()) can
     * ask the database directly instead of loading every row for an
     * email and filtering in PHP. Kept next to isUsable() so the two
     * can't silently drift apart.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
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
