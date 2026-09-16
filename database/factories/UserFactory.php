<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * A plain factory user models an ordinary collector unless a test
     * says otherwise — so it gets the `user` role (and its
     * use-collection permission) by default, same as every real invited
     * account. Guarded on the role actually existing: plenty of tests
     * never seed roles/permissions at all, and Spatie throws rather than
     * no-op on an unknown role name.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if (Role::where('name', 'user')->exists()) {
                $user->assignRole('user');
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            // Str::slug() strips punctuation instead of substituting it, so
            // two distinct Faker values (e.g. "O'Brien.Tom" and
            // "obrien.tom") can collapse to the same slug even though
            // fake()->unique() only guarantees uniqueness on the raw,
            // pre-slug value — demonstrated collision, not theoretical.
            // Appending fake()->unique()'s own numeric suffix keeps the
            // slug readable while guaranteeing no two factory users ever
            // collide on username, independent of how lossy the slug is.
            'username' => Str::slug(fake()->userName()).'-'.fake()->unique()->numerify('####'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
