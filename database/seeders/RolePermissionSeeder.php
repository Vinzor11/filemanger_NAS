<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'employees.view',
            'employees.manage',
            'users.approve',
            'users.reject',
            'users.block',
            'users.assign_roles',
            'settings.manage',
            'registration_codes.manage',
            'files.view',
            'files.upload',
            'files.download',
            'files.update',
            'files.delete',
            'files.restore',
            'folders.create',
            'folders.create_department',
            'folders.update',
            'folders.delete',
            'folders.restore',
            'share.manage',
            'share.link.create',
            'share.link.revoke',
            'tags.manage',
            'audit.view',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::findOrCreate('SuperAdmin', 'web');
        $hrAdmin = Role::findOrCreate('HRAdmin', 'web');
        $departmentManager = Role::findOrCreate('DepartmentManager', 'web');
        $employee = Role::findOrCreate('Employee', 'web');
        $auditor = Role::findOrCreate('Auditor', 'web');

        $superAdmin->syncPermissions(Permission::all());
        $hrAdmin->syncPermissions([
            'employees.view',
            'employees.manage',
            'users.approve',
            'users.reject',
            'users.block',
            'users.assign_roles',
            'settings.manage',
            'registration_codes.manage',
            'audit.view',
        ]);
        $departmentManager->syncPermissions([
            'files.view',
            'files.upload',
            'files.download',
            'files.update',
            'files.delete',
            'files.restore',
            'folders.create',
            'folders.create_department',
            'folders.update',
            'folders.delete',
            'folders.restore',
            'share.manage',
            'share.link.create',
            'share.link.revoke',
            'tags.manage',
        ]);
        $employee->syncPermissions([
            'files.view',
            'files.upload',
            'files.download',
            'folders.create',
            'folders.update',
            'share.manage',
            'share.link.create',
            'tags.manage',
        ]);
        $auditor->syncPermissions([
            'audit.view',
        ]);
    }
}
