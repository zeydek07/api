<?php

namespace App\Modules\Hr\Shared\Idempotency;

use Illuminate\Console\Command;

class PruneIdempotencyKeys extends Command
{
    protected $signature = 'hr:prune-idempotency';

    protected $description = 'Süresi dolan İK idempotency kayıtlarını siler';

    public function handle(): int
    {
        $deleted = IdempotencyKey::query()->where('expires_at', '<=', now()->toDateTimeString())->delete();

        $this->info("Silinen idempotency kaydı: {$deleted}");

        return self::SUCCESS;
    }
}
