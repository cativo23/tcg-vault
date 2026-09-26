<?php

declare(strict_types=1);

namespace App\Modules\Collection\Models;

use App\Models\User;
use App\Modules\Collection\Scopes\TenantScope;
use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The foreign keys behind these are NOT NULL and constrained, so the
 * related row always exists.
 *
 * @property-read User $user
 */
final class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'slug', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    protected static function booted(): void
    {
        self::addGlobalScope(new TenantScope);
    }

    protected static function newFactory(): CollectionFactory
    {
        return CollectionFactory::new();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CollectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CollectionItem::class);
    }
}
