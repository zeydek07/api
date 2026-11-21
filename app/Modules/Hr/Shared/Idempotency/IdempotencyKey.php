<?php

namespace App\Modules\Hr\Shared\Idempotency;

use Illuminate\Database\Eloquent\Model;

/** expires_at bilerek cast edilmez: sahiplik kontrolü ham değerle birebir karşılaştırılır. */
class IdempotencyKey extends Model
{
    public $timestamps = false;

    protected $table = 'hr_idempotency_keys';

    protected $guarded = [];
}
