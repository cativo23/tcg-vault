<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\Rule;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * Reserved words `username` may never equal — every one is a
     * top-level route segment this app itself registers. A collision
     * wouldn't necessarily break routing (`/{username}/gallery` needs a
     * literal second segment `route()` already disambiguates), but
     * letting an account claim the literal string "admin" or "storage"
     * as its own public identity is a footgun not worth the risk.
     *
     * @return array<int, string>
     */
    public static function reservedUsernames(): array
    {
        return [
            'login', 'logout', 'register', 'admin', 'profile', 'gallery',
            'forgot-password', 'reset-password', 'verify-email', 'confirm-password',
            'dashboard', 'storage', 'livewire', 'up', 'api', 'staff',
            'privacy', 'terms',
        ];
    }

    /**
     * The single source of truth for what a valid `username` looks like.
     * Every write path — the profile form, registration, and the
     * seeder's `TCGVAULT_ADMIN_USERNAME` override — MUST validate
     * through this, not a hand-copied rule list. A username that skips
     * these rules (wrong case, illegal characters, a reserved word) can
     * still end up as a public gallery URL segment and can destabilize
     * the login rate-limiter's case-insensitive lookup (see
     * LoginForm::throttleKey()) if two differently-cased usernames both
     * pass some other, laxer check.
     *
     * @return list<mixed>
     */
    /** Staff have blocked this account; it can't sign in and its page is hidden. */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public static function usernameRules(?int $ignoreUserId = null): array
    {
        return [
            'required',
            'string',
            'lowercase',
            'min:3',
            'max:30',
            'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
            Rule::unique(self::class)->ignore($ignoreUserId),
            Rule::notIn(self::reservedUsernames()),
        ];
    }
}
