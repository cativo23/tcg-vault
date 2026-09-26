<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds the fixed set of permissions this app actually gates something
 * on today, plus the roles that use them. `super-admin` is deliberately
 * created with NO explicit permissions here — it bypasses every gate via
 * Gate::before (see AppServiceProvider), so it never needs its list kept
 * in sync as new permissions are added.
 *
 * Only roles with a real caller today get created. A future
 * platform-management-only role (content admin, billing admin) is not
 * seeded speculatively — Spatie's structure supports adding one later
 * without migrating anything that exists.
 */
class PermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        'view-horizon',
        'view-telescope',
        'manage-invites',
        'manage-platform-settings',
        'manage-members',
        'use-collection',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        Role::firstOrCreate(['name' => 'super-admin']);

        $user = Role::firstOrCreate(['name' => 'user']);
        $user->givePermissionTo('use-collection');
    }
}
