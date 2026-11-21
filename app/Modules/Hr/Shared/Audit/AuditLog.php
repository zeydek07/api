<?php

namespace App\Modules\Hr\Shared\Audit;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Yalnızca eklenir: güncelleme ve silme modelde engellenir. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'hr_audit_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changed_fields' => 'array'];
    }

    public function newEloquentBuilder($query): AuditLogBuilder
    {
        return new AuditLogBuilder($query);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Denetim kaydı değiştirilemez.'));
        static::deleting(fn () => throw new RuntimeException('Denetim kaydı silinemez.'));
    }
}
