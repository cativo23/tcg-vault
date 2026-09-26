<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;

test('a super-admin sees links to the staff invites and settings pages', function () {
    Role::create(['name' => 'super-admin']);
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');

    $response = $this->actingAs($admin)->get('/admin');

    $response->assertSee(route('staff.invites'), false);
    $response->assertSee(route('staff.settings'), false);
});

test('a plain user does not see links to the staff pages', function () {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin');

    $response->assertDontSee(route('staff.invites'), false);
    $response->assertDontSee(route('staff.settings'), false);
});

test('the mobile menu toggle has an accessible name', function () {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create();

    // An icon-only button with no text, aria-label, or aria-expanded
    // gave a screen reader user nothing to identify or track the state
    // of — the public layout's own hamburger already had this right.
    $response = $this->actingAs($user)->get('/admin');

    $response->assertSee('aria-label="Toggle navigation menu"', false);
    $response->assertSee('aria-controls="admin-mobile-nav"', false);
});
