<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['key', 'page', 'action', 'label'];

    protected $hidden = ['pivot', 'created_at', 'updated_at'];

    /**
     * config/permissions.php içeriğini tabloya yazar, artık olmayanları siler.
     *
     * @return string[] bu çalıştırmada yeni eklenen yetkiler
     */
    public static function syncFromConfig(): array
    {
        $keys = [];
        $created = [];

        foreach (config('permissions.pages') as $page => $definition) {
            foreach ($definition['actions'] as $action => $label) {
                $key = "{$page}.{$action}";
                $keys[] = $key;

                $permission = static::query()->updateOrCreate(
                    ['key' => $key],
                    ['page' => $page, 'action' => $action, 'label' => "{$definition['label']} - {$label}"],
                );
                if ($permission->wasRecentlyCreated) {
                    $created[] = $key;
                }
            }
        }

        static::query()->whereNotIn('key', $keys)->delete();

        return $created;
    }
}
