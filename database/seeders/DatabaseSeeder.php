<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $newKeys = Permission::syncFromConfig();

        foreach (config('permissions.default_roles') as $slug => $definition) {
            $role = Role::firstOrCreate(['slug' => $slug], ['name' => $definition['name'], 'is_system' => true]);
            if ($definition['permissions'] === '*') {
                continue;
            }

            if ($role->wasRecentlyCreated) {
                $role->permissions()->sync(Permission::whereIn('key', $definition['permissions'])->pluck('id'));
            } else {
                // Mevcut rolün panelden yapılmış ayarlarına dokunma; yalnızca bu sürümde
                // yeni eklenen sayfaların varsayılan yetkilerini ekle.
                $add = array_intersect($definition['permissions'], $newKeys);
                $role->permissions()->syncWithoutDetaching(Permission::whereIn('key', $add)->pluck('id'));
            }
        }

        Warehouse::firstOrCreate(['code' => 'MERKEZ'], ['name' => 'Merkez Depo', 'type' => 'central']);

        $email = env('ADMIN_EMAIL', 'admin@example.com');
        if (! User::withTrashed()->where('email', $email)->exists()) {
            $password = env('ADMIN_PASSWORD') ?: Str::password(16);

            User::create([
                'name' => env('ADMIN_NAME', 'Sistem Yöneticisi'),
                'email' => $email,
                'password' => $password,
                'role_id' => Role::where('slug', config('permissions.super_admin_role'))->value('id'),
                'is_active' => true,
            ]);

            $this->command?->warn("Süper yönetici oluşturuldu: {$email}");
            if (! env('ADMIN_PASSWORD')) {
                $this->command?->warn("Geçici şifre: {$password}  (ilk girişte değiştirin)");
            }
        }
    }
}
