<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminAccessSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'Acceso al panel', 'slug' => 'admin.access', 'group' => 'admin', 'description' => 'Entrar al lab interno'],
            ['name' => 'Ver dashboard', 'slug' => 'lab.dashboard', 'group' => 'lab', 'description' => null],
            ['name' => 'Discovery IA', 'slug' => 'lab.discovery', 'group' => 'lab', 'description' => null],
            ['name' => 'Product Hunter', 'slug' => 'lab.cj', 'group' => 'lab', 'description' => 'Buscar e importar productos de AliExpress o CJ'],
            ['name' => 'Product Score', 'slug' => 'lab.score', 'group' => 'lab', 'description' => null],
            ['name' => 'Administrar tienda', 'slug' => 'store.manage', 'group' => 'store', 'description' => 'Productos, promos, upsell, ruleta del sitio activo'],
            ['name' => 'Configuración general', 'slug' => 'settings.general', 'group' => 'settings', 'description' => 'Pagos y llaves de plataforma'],
            ['name' => 'Ver administradores', 'slug' => 'users.view', 'group' => 'users', 'description' => null],
            ['name' => 'Crear administradores', 'slug' => 'users.create', 'group' => 'users', 'description' => null],
            ['name' => 'Editar administradores', 'slug' => 'users.update', 'group' => 'users', 'description' => null],
            ['name' => 'Eliminar administradores', 'slug' => 'users.delete', 'group' => 'users', 'description' => null],
            ['name' => 'Ver roles', 'slug' => 'roles.view', 'group' => 'roles', 'description' => null],
            ['name' => 'Gestionar roles', 'slug' => 'roles.manage', 'group' => 'roles', 'description' => null],
            ['name' => 'Editar perfil propio', 'slug' => 'profile.update', 'group' => 'profile', 'description' => null],
        ];

        $permissionIds = [];
        foreach ($permissions as $row) {
            $permission = Permission::updateOrCreate(
                ['slug' => $row['slug']],
                $row
            );
            $permissionIds[$row['slug']] = $permission->id;
        }

        $allIds = array_values($permissionIds);

        $adminRole = Role::updateOrCreate(
            ['slug' => 'admin'],
            [
                'name' => 'Administrador',
                'description' => 'Acceso completo operativo (sin bypass de superusuario)',
                'is_system' => true,
            ]
        );
        $adminRole->syncPermissions($allIds);

        $operatorRole = Role::updateOrCreate(
            ['slug' => 'operator'],
            [
                'name' => 'Operador',
                'description' => 'Herramientas del lab y perfil',
                'is_system' => true,
            ]
        );
        $operatorRole->syncPermissions([
            $permissionIds['admin.access'],
            $permissionIds['lab.dashboard'],
            $permissionIds['lab.discovery'],
            $permissionIds['lab.cj'],
            $permissionIds['lab.score'],
            $permissionIds['store.manage'],
            $permissionIds['profile.update'],
        ]);

        $viewerRole = Role::updateOrCreate(
            ['slug' => 'viewer'],
            [
                'name' => 'Solo lectura',
                'description' => 'Dashboard y score sin discovery/CJ ni gestión de usuarios',
                'is_system' => true,
            ]
        );
        $viewerRole->syncPermissions([
            $permissionIds['admin.access'],
            $permissionIds['lab.dashboard'],
            $permissionIds['lab.score'],
            $permissionIds['profile.update'],
        ]);

        $email = (string) env('SUPERUSER_EMAIL', 'admin@multidrop.local');
        $password = (string) env('SUPERUSER_PASSWORD', 'Multidrop!2026');
        $name = (string) env('SUPERUSER_NAME', 'Super Admin');

        $super = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'is_superuser' => true,
                'is_active' => true,
                'must_change_password' => false,
                'email_verified_at' => now(),
            ]
        );

        $super->syncRoles([$adminRole->id]);
        $super->syncDirectPermissions([]);
    }
}
