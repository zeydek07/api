<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'role_id', 'manager_id', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = ['is_active' => true];

    /** İstek boyunca tekrar sorgulamamak için önbellek */
    private ?array $permissionKeyCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    public function directPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->slug === config('permissions.super_admin_role');
    }

    /** Rolden gelen + kişiye özel yetkilerin birleşimi */
    public function permissionKeys(): array
    {
        if ($this->permissionKeyCache !== null) {
            return $this->permissionKeyCache;
        }

        if ($this->isSuperAdmin()) {
            return $this->permissionKeyCache = Permission::query()->orderBy('key')->pluck('key')->all();
        }

        $fromRole = $this->role ? $this->role->permissions()->pluck('key')->all() : [];
        $direct = $this->directPermissions()->pluck('key')->all();

        $keys = array_values(array_unique([...$fromRole, ...$direct]));
        sort($keys);

        return $this->permissionKeyCache = $keys;
    }

    public function hasPermission(string $key): bool
    {
        return $this->isSuperAdmin() || in_array($key, $this->permissionKeys(), true);
    }

    public function flushPermissionCache(): void
    {
        $this->permissionKeyCache = null;
        $this->unsetRelation('role');
    }

    /** Kendisi ve altındaki tüm kademelerdeki personelin id'leri */
    public function teamUserIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $frontier = User::withTrashed()
                ->whereIn('manager_id', $frontier)
                ->whereNotIn('id', $ids)
                ->pluck('id')
                ->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
