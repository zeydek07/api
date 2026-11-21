<?php

namespace App\Modules\Hr\Shared\Audit;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/** Toplu güncelleme/silme yollarını kapatır; yalnızca ekleme ve okuma açıktır. */
class AuditLogBuilder extends Builder
{
    public function update(array $values)
    {
        $this->refuse();
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $this->refuse();
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->refuse();
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->refuse();
    }

    public function delete()
    {
        $this->refuse();
    }

    public function forceDelete()
    {
        $this->refuse();
    }

    public function truncate(): void
    {
        $this->refuse();
    }

    private function refuse(): never
    {
        throw new RuntimeException('Denetim kaydı değiştirilemez.');
    }
}
