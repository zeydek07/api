<?php

namespace App\Modules\Hr\Shared\Idempotency;

use Closure;

/** Tekrar isteklerde kaydı yetkiyi yeniden kontrol ederek okuyan çözücüler. */
class IdempotentResourceRegistry
{
    /** @var array<string, Closure> */
    private array $resolvers = [];

    /** @param Closure(int, \Illuminate\Http\Request): \Illuminate\Http\JsonResponse $resolver */
    public function register(string $type, Closure $resolver): void
    {
        $this->resolvers[$type] = $resolver;
    }

    public function resolver(string $type): ?Closure
    {
        return $this->resolvers[$type] ?? null;
    }
}
