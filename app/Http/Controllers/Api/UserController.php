<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with(['role:id,name,slug', 'manager:id,name'])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->q.'%')
                ->orWhere('email', 'like', '%'.$request->q.'%')
                ->orWhere('phone', 'like', '%'.$request->q.'%')))
            ->when($request->filled('role_id'), fn ($q) => $q->where('role_id', $request->role_id))
            ->when($request->filled('manager_id'), fn ($q) => $q->where('manager_id', $request->manager_id))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return response()->json($users);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($this->detail($user));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->guardEscalation($request->user(), $data);

        $user = User::create($data);
        $this->syncRelations($user, $data);

        return response()->json($this->detail($user), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->guardTarget($actor, $user);

        $data = $this->validated($request, $user);
        $this->guardEscalation($actor, $data);

        if (array_key_exists('manager_id', $data) && (int) $data['manager_id'] !== 0
            && in_array((int) $data['manager_id'], $user->teamUserIds(), true)) {
            throw ValidationException::withMessages(['manager_id' => 'Personel kendisinin veya ekibindeki birinin altına bağlanamaz.']);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);
        $this->syncRelations($user, $data);

        // Pasife alınan ya da şifresi değişen kullanıcının oturumlarını kapat.
        if (($user->wasChanged('is_active') && ! $user->is_active) || $user->wasChanged('password')) {
            $user->tokens()->delete();
        }

        return response()->json($this->detail($user->fresh()));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->is($user)) {
            return response()->json(['message' => 'Kendi hesabınızı silemezsiniz.'], 422);
        }
        $this->guardTarget($request->user(), $user);

        $user->tokens()->delete();
        $user->update(['is_active' => false]);
        $user->delete(); // soft delete: geçmiş stok hareketleri korunur

        return response()->json(['message' => 'Personel silindi.']);
    }

    /** Personelin tüm açık oturumlarını (ör. telefonu kaybolduğunda) kapatır. */
    public function revokeTokens(Request $request, User $user): JsonResponse
    {
        $this->guardTarget($request->user(), $user);
        $count = $user->tokens()->delete();

        return response()->json(['message' => "{$count} oturum kapatıldı."]);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $creating = $user === null;

        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'email' => [$creating ? 'required' : 'sometimes', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => [$creating ? 'required' : 'nullable', 'string', Password::min(8)],
            'role_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:roles,id'],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at'),
                Rule::notIn(array_filter([$user?->id]))],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,key'],
            'warehouse_ids' => ['sometimes', 'array'],
            'warehouse_ids.*' => ['integer', 'exists:warehouses,id'],
        ]);
    }

    private function syncRelations(User $user, array $data): void
    {
        if (array_key_exists('permissions', $data)) {
            $user->directPermissions()->sync(Permission::whereIn('key', $data['permissions'])->pluck('id'));
        }
        if (array_key_exists('warehouse_ids', $data)) {
            $user->warehouses()->sync($data['warehouse_ids']);
        }
        $user->flushPermissionCache();
    }

    /** Yönetici, kendinde olmayan bir yetkiyi başkasına veremez. */
    private function guardEscalation(User $actor, array $data): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if (isset($data['role_id'])) {
            $role = Role::with('permissions:id,key')->find($data['role_id']);
            if ($role->slug === config('permissions.super_admin_role')) {
                throw ValidationException::withMessages(['role_id' => 'Süper yönetici rolünü yalnızca süper yönetici atayabilir.']);
            }
            $missing = array_diff($role->permissions->pluck('key')->all(), $actor->permissionKeys());
            if ($missing) {
                throw ValidationException::withMessages(['role_id' => 'Bu rol sizde olmayan yetkiler içeriyor: '.implode(', ', $missing)]);
            }
        }

        $missing = array_diff($data['permissions'] ?? [], $actor->permissionKeys());
        if ($missing) {
            throw ValidationException::withMessages(['permissions' => 'Sizde olmayan yetkileri veremezsiniz: '.implode(', ', $missing)]);
        }
    }

    private function guardTarget(User $actor, User $target): void
    {
        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            abort(response()->json(['message' => 'Süper yönetici hesabını yalnızca süper yönetici değiştirebilir.'], 403));
        }
    }

    private function detail(User $user): array
    {
        $user->load(['role:id,name,slug', 'manager:id,name', 'directPermissions:id,key', 'warehouses:id,name,code,type']);

        return [
            ...$user->toArray(),
            'direct_permissions' => $user->directPermissions->pluck('key'),
            'effective_permissions' => $user->permissionKeys(),
        ];
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 25), 1), 100);
    }
}
