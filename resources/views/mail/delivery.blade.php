@php
    $isReturn = $d->type === \App\Models\Delivery::TYPE_RETURN;
    $total = rtrim(rtrim(number_format($units, 2, ',', '.'), '0'), ',');
@endphp
<!doctype html>
<html lang="tr">
<body style="font-family: Arial, sans-serif; font-size: 14px; color: #1f2937; line-height: 1.5">
    <p>Sayın {{ $d->receiver_name }},</p>
    <p>
        {{ $d->delivered_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }} tarihinde
        <strong>{{ $d->hospital?->name }}</strong> · {{ $d->location?->path ?? $d->location?->name }}
        @if ($isReturn)
            lokasyonundan <strong>{{ $d->lines->count() }} kalem (toplam {{ $total }})</strong> ürün firmamız tarafından geri alınmıştır.
        @else
            lokasyonuna <strong>{{ $d->lines->count() }} kalem (toplam {{ $total }})</strong> ürün konsinye olarak teslim edilmiştir.
        @endif
    </p>
    <p><strong>{{ $d->number }}</strong> numaralı belgenin kopyası ektedir (PDF).</p>
    <p>Belgede bir yanlışlık olduğunu düşünüyorsanız lütfen bu e-postayı yanıtlayarak bize bildirin.</p>
    <p style="color: #6b7280">{{ $company }} · {{ $d->user?->name }}</p>
</body>
</html>
