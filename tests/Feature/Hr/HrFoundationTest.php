<?php

namespace Tests\Feature\Hr;

use App\Models\Permission;
use App\Models\Role;
use App\Modules\Hr\Shared\HrException;
use Illuminate\Support\Facades\Route;
use Tests\Feature\ApiTestCase;

class HrFoundationTest extends ApiTestCase
{
    public function test_hr_routes_require_token(): void
    {
        $this->getJson('/api/hr/me')->assertStatus(401);
    }

    public function test_me_requires_hr_self_permission(): void
    {
        $u = $this->userWithPermissions([]);
        $this->withToken($this->tokenFor($u))->getJson('/api/hr/me')->assertStatus(403);
    }

    public function test_me_returns_null_employee_with_permission(): void
    {
        $u = $this->userWithPermissions(['hr_self.view']);
        $this->withToken($this->tokenFor($u))->getJson('/api/hr/me')
            ->assertOk()->assertExactJson(['data' => ['employee' => null]]);
    }

    public function test_field_and_warehouse_roles_get_self_service(): void
    {
        foreach (['personel', 'depocu', 'yonetici'] as $slug) {
            $this->assertTrue($this->userWithRole($slug)->hasPermission('hr_self.view'));
        }
    }

    public function test_department_roles_are_seeded(): void
    {
        foreach (['ik-uzmani', 'ik-yoneticisi', 'finans', 'muhasebe'] as $slug) {
            $this->assertDatabaseHas('roles', ['slug' => $slug, 'is_system' => true]);
        }
    }

    public function test_finance_cannot_view_employees(): void
    {
        $this->assertFalse($this->userWithRole('finans')->hasPermission('hr_employees.view_all'));
    }

    public function test_reseeding_keeps_panel_changes_on_existing_roles(): void
    {
        $role = Role::where('slug', 'depocu')->first();
        $role->permissions()->detach(Permission::where('key', 'hr_self.view')->pluck('id'));
        $this->seed();
        $this->assertFalse($role->fresh()->permissions()->where('key', 'hr_self.view')->exists());
    }

    public function test_storage_path_has_no_default_inside_project(): void
    {
        $old = getenv('HR_STORAGE_PATH');
        unset($_ENV['HR_STORAGE_PATH'], $_SERVER['HR_STORAGE_PATH']);
        putenv('HR_STORAGE_PATH');

        try {
            $config = require config_path('hr.php');
        } finally {
            if ($old !== false) {
                putenv("HR_STORAGE_PATH={$old}");
            }
        }

        $this->assertNull($config['storage_path']);
    }

    public function test_hr_exception_uses_contract_shape(): void
    {
        Route::middleware('api')->get('api/hr-test-throw', function () {
            throw new HrException('test.conflict', 'Çakışma.', 409, ['x' => ['y']]);
        });

        $this->getJson('/api/hr-test-throw')
            ->assertStatus(409)
            ->assertJsonPath('code', 'test.conflict')
            ->assertJsonStructure(['message', 'code', 'errors']);
    }
}
