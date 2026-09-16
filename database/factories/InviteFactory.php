<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Invites\Models\Invite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invite>
 */
class InviteFactory extends Factory
{
    protected $model = Invite::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'created_by' => User::factory(),
            'expires_at' => now()->addDays(7),
        ];
    }
}
