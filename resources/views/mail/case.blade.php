@php
    $billable = $c->items->whereIn('status', \App\Models\SurgeryCaseItem::BILLABLE);
    $total = rtrim(rtrim(number_format($billable->sum(fn ($i) => (float) $i->quantity), 2, ',', '.'), '0'), ',');
@endphp
<!doctype html>
<html lang="tr">
<body style="font-family: Arial, sans-serif; font-size: 14px; color: #1f2937; line-height: 1.5">
    <p>Sayın {{ $c->signer_name }},</p>
    <p>
        {{ $c->performed_at?->timezone(config('app.display_timezone'))->format('d.m.Y H:i') }} tarihinde
        <strong>{{ $c->hospital?->name }}</strong>@if ($c->location) · {{ $c->location->path ?? $c->location->name }}@endif
        @if ($c->doctor) ({{ $c->doctor->display_name }})@endif
        yapılan vakada <strong>{{ $billable->count() }} kalem (toplam {{ $total }})</strong> ürün kullanılmıştır.
    </p>
    <p><strong>{{ $c->number }}</strong> numaralı kullanım tutanağının kopyası ektedir (PDF).</p>
    <p>Tutanakta bir yanlışlık olduğunu düşünüyorsanız lütfen bu e-postayı yanıtlayarak bize bildirin.</p>
    <p style="color: #6b7280">{{ $company }} · {{ $c->user?->name }}</p>
</body>
</html>
