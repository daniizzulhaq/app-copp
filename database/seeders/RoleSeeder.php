<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage', 'view-reports', 'transact'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::findOrCreate('super-admin', 'web')->syncPermissions(['manage', 'view-reports']);
        Role::findOrCreate('member', 'web')->syncPermissions(['transact']);
    }
}
