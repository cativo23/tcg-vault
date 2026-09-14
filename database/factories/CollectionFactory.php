<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Collection\Models\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;

final class CollectionFactory extends Factory
{
    protected $model = Collection::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'My Collection',
            'slug' => 'my-collection',
            'is_public' => false,
        ];
    }
}
