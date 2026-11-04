<?php

namespace Tests\Feature;

use App\Models\Role;

class PermissionTest extends ApiTestCase
{
    public function test_user_without_page_permission_gets_403(): void
    {
        $token = $this->tokenFor($this->userWithRole('personel'));

        $this->withToken($token)->getJson('/api/users')->assertStatus(403)
            ->assertJsonPath('required_permission', 'users.view');
        $this->withToken($token)->postJson('/api/products', ['name' => 'X', 'code' => 'X'])->assertStatus(403);
        $this->withToken($token)->getJson('/api/products')->assertOk();
    }

    public function test_direct_permission_grants_page_access(): void
    {
        $admin = $this->admin();
        $admin->update(['password' => 'secret-pass']);
        $adminToken = $this->tokenFor($admin);

        $user = $this->userWithRole('personel');
        $this->withToken($adminToken)->putJson("/api/users/{$user->id}", ['permissions' => ['reports.view']])->assertOk();

        $token = $this->tokenFor($user);
        $this->withToken($token)->getJson('/api/reports/low-stock')->assertOk();
    }

    public function test_super_admin_can_access_everything(): void
    {
        $admin = $this->admin();
        $admin->update(['password' => 'secret-pass']);
        $token = $this->tokenFor($admin);

        foreach (['/api/users', '/api/roles', '/api/permissions', '/api/products', '/api/warehouses',
            '/api/stocks', '/api/movements', '/api/dashboard', '/api/reports/usage'] as $url) {
            $this->withToken($token)->getJson($url)->assertOk();
        }
    }

    public function test_manager_cannot_grant_permissions_they_do_not_have(): void
    {
        $manager = $this->userWithRole('yonetici');
        $token = $this->tokenFor($manager);
        $staff = $this->userWithRole('personel');

        $this->withToken($token)->putJson("/api/users/{$staff->id}", ['permissions' => ['roles.delete']])
            ->assertStatus(422);

        $superRole = Role::where('slug', 'super-admin')->value('id');
        $this->withToken($token)->putJson("/api/users/{$staff->id}", ['role_id' => $superRole])
            ->assertStatus(422);

        $this->withToken($token)->putJson("/api/users/{$this->admin()->id}", ['name' => 'Ele geçirildi'])
            ->assertStatus(403);
    }

    public function test_role_permissions_can_be_edited(): void
    {
        $admin = $this->admin();
        $admin->update(['password' => 'secret-pass']);
        $token = $this->tokenFor($admin);

        $role = $this->withToken($token)->postJson('/api/roles', [
            'name' => 'Depo Sorumlusu',
            'permissions' => ['stock.view', 'stock.receive'],
        ])->assertCreated()->json();

        $this->assertSame('depo-sorumlusu', $role['slug']);

        $user = $this->userWithRole('depo-sorumlusu');
        $userToken = $this->tokenFor($user);
        $this->withToken($userToken)->getJson('/api/stocks')->assertOk();
        $this->withToken($userToken)->postJson('/api/stocks/transfer', [])->assertStatus(403);
    }
}
