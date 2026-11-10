@php
    $isReturn = $d->type === \App\Models\Delivery::TYPE_RETURN;
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $units = $d->lines->sum(fn ($l) => (float) $l->quantity);
    $address = $d->hospital?->address ?: collect([$d->hospital?->district, $d->hospital?->city])->filter()->join(', ');
    $tz = config('app.display_timezone');
@endphp
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>{{ $d->number }}</title>
<style>
    @page { margin: 28px 32px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1f2937; }
    h1 { font-size: 15px; margin: 0; }
    .muted { color: #6b7280; }
    .label { font-size: 8.5px; font-weight: bold; color: #6b7280; margin-bottom: 3px; }
    .mono { font-family: "DejaVu Sans Mono", monospace; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .parties td { vertical-align: top; width: 50%; padding: 6px 8px 6px 0; }
    .items th { background: #f3f4f6; text-align: left; font-size: 8.5px; padding: 5px 6px; border-bottom: 1px solid #d1d5db; }
    .items td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    .items .num { text-align: right; white-space: nowrap; }
    .items tfoot td { font-weight: bold; background: #f9fafb; }
    .box { border: 1px solid #d1d5db; border-radius: 6px; height: 130px; text-align: center; }
    .box img { max-height: 122px; max-width: 240px; margin-top: 4px; }
    .cancel { background: #fee2e2; color: #991b1b; padding: 8px; border-radius: 6px; margin-top: 10px; }
    .stamp { position: fixed; top: 300px; left: 150px; font-size: 80px; font-weight: bold; color: #fca5a5; transform: rotate(-20deg); }
    .note { background: #f3f4f6; padding: 8px; border-radius: 6px; font-size: 8.5px; color: #4b5563; }
</style>
</head>
<body>
@if ($d->status === \App\Models\Delivery::STATUS_CANCELLED)
    <div class="stamp">İPTAL</div>
@endif

<table class="head">
    <tr>
        <td>
            <h1>{{ $company }}</h1>
            <div class="muted">{{ $isReturn ? 'Konsinye Geri Alma Belgesi' : 'Konsinye Teslim Belgesi' }}</div>
        </td>
        <td style="text-align: right">
            <div class="mono" style="font-size: 14px; font-weight: bold">{{ $d->number }}</div>
            <div class="muted">{{ $d->delivered_at?->timezone($tz)->format('d.m.Y H:i') }}</div>
        </td>
    </tr>
</table>
<hr style="border: 0; border-top: 1px solid #d1d5db; margin: 10px 0">

@if ($d->status === \App\Models\Delivery::STATUS_CANCELLED)
    <div class="cancel">
        <strong>İptal edildi:</strong> {{ $d->cancelled_at?->timezone($tz)->format('d.m.Y H:i') }} · {{ $d->canceller?->name }} — {{ $d->cancel_reason }}
    </div>
@endif

<table class="parties">
    <tr>
        <td>
            <div class="label">Hastane</div>
            <strong>{{ $d->hospital?->name }}</strong>
            @if ($address)<div class="muted">{{ $address }}</div>@endif
            <div>Lokasyon: {{ $d->location?->path ?? $d->location?->name }}</div>
        </td>
        <td>
            <div class="label">{{ $isReturn ? 'Teslim eden (hastane)' : 'Teslim alan (hastane)' }}</div>
            <strong>{{ $d->receiver_name }}</strong>
            @if ($d->receiver_title)<div class="muted">{{ $d->receiver_title }}</div>@endif
            @if ($d->contact?->phone)<div class="muted">{{ $d->contact->phone }}</div>@endif
        </td>
    </tr>
    <tr>
        <td>
            <div class="label">{{ $isReturn ? 'Teslim alan (firma)' : 'Teslim eden (firma)' }}</div>
            <strong>{{ $d->user?->name }}</strong>
            @if ($d->user?->phone)<div class="muted">{{ $d->user->phone }}</div>@endif
        </td>
        <td>
            <div class="label">{{ $isReturn ? 'Ürünlerin gittiği yer' : 'Ürünlerin çıktığı yer' }}</div>
            <strong>{{ $d->vehicle?->name }}</strong>
            @if ($d->latitude !== null)
                <div class="muted">İşlem konumu: {{ $d->latitude }}, {{ $d->longitude }}@if ($d->location_accuracy) (±{{ $d->location_accuracy }} m)@endif</div>
            @else
                <div class="muted">İşlem konumu alınamadı</div>
            @endif
        </td>
    </tr>
</table>

<div class="label" style="margin-top: 10px">Ürünler</div>
<table class="items">
    <thead>
        <tr><th>Ürün</th><th>Karekod (GTIN)</th><th>Lot / Seri</th><th>SKT</th><th class="num">Adet</th></tr>
    </thead>
    <tbody>
        @foreach ($d->lines as $l)
            <tr>
                <td><strong>{{ $l->product?->name }}</strong><br><span class="muted mono">{{ $l->product?->code }}</span></td>
                <td class="mono">{{ $l->product?->gtin ?? '—' }}</td>
                <td class="mono">
                    @if ($l->serial_number)SN {{ $l->serial_number }}<br>@endif
                    @if ($l->lot_number)LOT {{ $l->lot_number }}@endif
                </td>
                <td>{{ $l->expiry_date?->format('d.m.Y') ?? '—' }}</td>
                <td class="num">{{ $fmt($l->quantity) }} {{ $l->product?->unit }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr><td colspan="4">Toplam · {{ $d->lines->count() }} kalem</td><td class="num">{{ $fmt($units) }}</td></tr>
    </tfoot>
</table>

@if ($d->note)
    <div class="label" style="margin-top: 10px">Not</div>
    <div>{{ $d->note }}</div>
@endif

<table style="margin-top: 14px">
    <tr>
        <td style="width: 50%; padding-right: 8px; vertical-align: top">
            <div class="label">İmza</div>
            <div class="box">
                @if ($signature)<img src="{{ $signature }}" alt="İmza">@else<div class="muted" style="padding-top: 55px">İmza alınmadı</div>@endif
            </div>
            <div class="muted" style="text-align: center; margin-top: 2px">{{ $d->receiver_name }}</div>
        </td>
        <td style="width: 50%; padding-left: 8px; vertical-align: top">
            @if (count($photos))
                <div class="label">Fotoğraf · {{ $photos[0]['label'] }}</div>
                <div class="box"><img src="{{ $photos[0]['src'] }}" alt="Fotoğraf"></div>
            @endif
        </td>
    </tr>
</table>

{{-- Birden fazla fotoğraf varsa kalanlar üçerli satırlarda --}}
@if (count($photos) > 1)
    <div class="label" style="margin-top: 12px">Diğer fotoğraflar ({{ count($photos) - 1 }})</div>
    <table>
        @foreach (array_chunk(array_slice($photos, 1), 3) as $row)
            <tr>
                @foreach ($row as $p)
                    <td style="width: 33%; padding: 3px; vertical-align: top">
                        <div class="box" style="height: 110px"><img src="{{ $p['src'] }}" alt="{{ $p['label'] }}" style="max-height: 102px; max-width: 160px"></div>
                        <div class="muted" style="text-align: center; margin-top: 2px">{{ $p['label'] }}</div>
                    </td>
                @endforeach
                @for ($i = count($row); $i < 3; $i++)<td style="width: 33%"></td>@endfor
            </tr>
        @endforeach
    </table>
@endif

@unless ($isReturn)
    <p class="note" style="margin-top: 14px">
        Bu belgede yer alan ürünler hastaneye <strong>konsinye</strong> olarak bırakılmıştır; mülkiyet firmada kalır.
        Ürünler vakada kullanıldığında faturalandırılır, kullanılmayanlar geri alınır.
    </p>
@endunless

<p class="muted" style="text-align: center; font-size: 8px; margin-top: 10px">
    Belge sistem tarafından oluşturulmuştur · {{ $d->number }} · Kayıt saati {{ $d->delivered_at?->timezone($tz)->format('d.m.Y H:i:s') }}
    @if ($d->device_time) · Cihaz saati {{ $d->device_time->timezone($tz)->format('d.m.Y H:i:s') }}@endif
</p>
</body>
</html>
