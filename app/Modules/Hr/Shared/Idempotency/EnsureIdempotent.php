<?php

namespace App\Modules\Hr\Shared\Idempotency;

use App\Modules\Hr\Shared\HrException;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureIdempotent
{
    public function __construct(private IdempotentResourceRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null) {
            return $next($request);
        }

        if (! preg_match('/\A[\x21-\x7E]{8,100}\z/', $key)) {
            throw new HrException('idempotency.invalid_key', 'Idempotency-Key 8-100 karakter olmalı.', 422);
        }

        $scope = [
            'user_id' => $request->user()->id,
            'key' => $key,
            'route' => $request->method().' '.$request->route()->uri(),
        ];
        $hash = $this->requestHash($request);
        $expiresAt = now()->addDay()->toDateTimeString();

        // Otomatik commit edilir; handler'ın transaction'ından bağımsızdır.
        $reserved = IdempotencyKey::query()->insertOrIgnore([
            ...$scope, 'request_hash' => $hash, 'expires_at' => $expiresAt,
        ]) === 1;

        if (! $reserved) {
            $row = IdempotencyKey::query()->where($scope)->first();

            if ($row === null) {
                throw $this->inProgress();
            }
            if (! hash_equals($row->request_hash, $hash)) {
                throw new HrException('idempotency.key_reused', 'Bu anahtar farklı bir istekle kullanılmış.', 422);
            }
            if ($row->status_code !== null) {
                return $this->replay($row, $request);
            }
            if (! $this->takeOver($row, $expiresAt)) {
                throw $this->inProgress();
            }
        }

        // Süresi dolup devralınan rezervasyona eski istek yazamasın diye expires_at sahiplik işaretidir.
        $owned = fn (): Builder => IdempotencyKey::query()
            ->where($scope)->whereNull('status_code')->where('expires_at', $expiresAt);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $owned()->delete();

            throw $e;
        }

        // Router, handler istisnalarını çoğunlukla burada hata yanıtına çevirmiş olur.
        if (! $response->isSuccessful()) {
            $owned()->delete();

            return $response;
        }

        $resource = $request->attributes->get('hr.resource');

        if (! is_array($resource) || ! is_string($resource['type'] ?? null) || ! is_int($resource['id'] ?? null)) {
            $owned()->delete();

            throw new HrException('idempotency.resource_missing', 'İşlem sonucu tekrar için kaydedilemedi.', 500);
        }

        $owned()->update([
            'status_code' => $response->getStatusCode(),
            'resource_type' => $resource['type'],
            'resource_id' => $resource['id'],
        ]);

        return $response;
    }

    /** Yarım kalmış ve süresi dolmuş rezervasyonu tek koşullu UPDATE ile devralır. */
    private function takeOver(IdempotencyKey $row, string $expiresAt): bool
    {
        if ($row->expires_at > now()->toDateTimeString()) {
            return false;
        }

        return IdempotencyKey::query()
            ->whereKey($row->id)
            ->whereNull('status_code')
            ->where('expires_at', $row->expires_at)
            ->update(['expires_at' => $expiresAt]) === 1;
    }

    private function replay(IdempotencyKey $row, Request $request): Response
    {
        $resolver = $this->registry->resolver((string) $row->resource_type);

        if ($resolver === null) {
            throw new HrException('idempotency.resolver_missing', 'Önceki işlem sonucu okunamadı.', 500);
        }

        $response = $resolver((int) $row->resource_id, $request);

        return $response->isSuccessful() ? $response->setStatusCode((int) $row->status_code) : $response;
    }

    private function inProgress(): HrException
    {
        return new HrException('idempotency.in_progress', 'Bu istek hâlâ işleniyor.', 409);
    }

    private function requestHash(Request $request): string
    {
        return hash('sha256', $request->method()."\n".$request->path()."\n".$this->canonicalBody($request));
    }

    private function canonicalBody(Request $request): string
    {
        if ($request->isJson()) {
            $decoded = json_decode($request->getContent(), false, 512, JSON_BIGINT_AS_STRING);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $request->getContent();
            }

            return $this->encode($this->canonicalize($decoded));
        }

        $input = $request->request->all();
        $files = $request->files->all();

        return $this->encode($this->canonicalize([
            'input' => $input,
            'files' => $this->hashFiles($files),
            'raw' => $input === [] && $files === [] ? $request->getContent() : '',
        ]));
    }

    private function hashFiles(array $files): array
    {
        return array_map(fn ($file) => match (true) {
            is_array($file) => $this->hashFiles($file),
            $file instanceof UploadedFile && $file->isValid() => hash_file('sha256', $file->getRealPath()),
            default => null,
        }, $files);
    }

    /** Nesne anahtarlarını özyinelemeli sıralar; liste sırası anlamlı olduğu için korunur. */
    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $items = (array) $value;
            ksort($items, SORT_STRING);

            return (object) array_map($this->canonicalize(...), $items);
        }

        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($this->canonicalize(...), $value);
        }

        return $value;
    }

    private function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
