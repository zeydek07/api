<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Role::withCount('users')->with('permissions:id,key')->orderBy('name')->get()
                ->map(fn (Role $r) => [...$r->toArray(), 'permissions' => $r->permissions->pluck('key')])
        );
    }

    /** Yetki ekranı için tüm sayfalar ve işlemleri */
    public function permissions(): JsonResponse
    {
        $labels = config('permissions.pages');

        return response()->json(
            Permission::orderBy('id')->get()->groupBy('page')->map(fn ($items, $page) => [
                'page' => $page,
                'label' => $labels[$page]['label'] ?? $page,
                'permissions' => $items->map->only(['key', 'action', 'label'])->values(),
            ])->values()
        );
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json([...$role->toArray(), 'permissions' => $role->permissions()->pluck('key')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100', 'alpha_dash', 'unique:roles,slug'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,key'],
        ]);
        $this->guardEscalation($request, $data['permissions'] ?? []);

        $role = Role::create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'description' => $data['description'] ?? null,
        ]);
        $role->permissions()->sync(Permission::whereIn('key', $data['permissions'] ?? [])->pluck('id'));

        return $this->show($role)->setStatusCode(201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->guardSuperAdminRole($role);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:100', 'alpha_dash', Rule::unique('roles', 'slug')->ignore($role->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,key'],
        ]);
        $this->guardEscalation($request, $data['permissions'] ?? []);

        if ($role->is_system) {
            unset($data['slug']); // sistem rollerinin kısa adı sabit
        }

        $role->update(collect($data)->except('permissions')->all());

        if (array_key_exists('permissions', $data)) {
            $role->permissions()->sync(Permission::whereIn('key', $data['permissions'])->pluck('id'));
        }

        return $this->show($role->fresh());
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system) {
            return response()->json(['message' => 'Sistem rolleri silinemez.'], 422);
        }
        if ($role->users()->withTrashed()->exists()) {
            return response()->json(['message' => 'Bu role atanmış personel var. Önce personelin rolünü değiştirin.'], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Rol silindi.']);
    }

    private function guardSuperAdminRole(Role $role): void
    {
        if ($role->slug === config('permissions.super_admin_role')) {
            abort(response()->json(['message' => 'Süper yönetici rolü değiştirilemez; zaten tüm yetkilere sahiptir.'], 422));
        }
    }

    private function guardEscalation(Request $request, array $permissions): void
    {
        $actor = $request->user();
        if ($actor->isSuperAdmin()) {
            return;
        }

        $missing = array_diff($permissions, $actor->permissionKeys());
        if ($missing) {
            throw ValidationException::withMessages(['permissions' => 'Sizde olmayan yetkileri bir role veremezsiniz: '.implode(', ', $missing)]);
        }
    }
}
