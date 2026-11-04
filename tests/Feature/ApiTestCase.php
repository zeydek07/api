<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function userWithRole(string $slug, array $attrs = []): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
            'password' => 'secret-pass',
            ...$attrs,
        ]);
    }

    protected function userWithPermissions(array $keys, array $attrs = []): User
    {
        $role = Role::create(['name' => 'Test '.uniqid(), 'slug' => 'test-'.uniqid()]);
        $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id, 'password' => 'secret-pass', ...$attrs]);
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    /** Testte aynı uygulama örneği kullanıldığı için önceki kullanıcıyı unut. */
    public function withToken(string $token, string $type = 'Bearer')
    {
        $this->app['auth']->forgetGuards();

        return parent::withToken($token, $type);
    }

    /** Gerçek akış: login olup Bearer token ile istek atar. */
    protected function tokenFor(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'secret-pass', 'device_name' => 'test',
        ])->assertOk()->json('token');
    }

    protected function product(array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(['name' => "Ürün {$n}", 'code' => "P{$n}", 'unit' => 'adet', ...$attrs]);
    }

    protected function warehouse(array $attrs = []): Warehouse
    {
        static $n = 0;
        $n++;

        return Warehouse::create(['name' => "Depo {$n}", 'code' => "W{$n}", 'type' => 'field', ...$attrs]);
    }
}
