@use('App\Models\SurgeryCase')
@use('App\Models\SurgeryCaseItem')
@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $tz = config('app.display_timezone');
    $address = $c->hospital?->address ?: collect([$c->hospital?->district, $c->hospital?->city])->filter()->join(', ');
    $statusLabels = [SurgeryCaseItem::USED => 'Kullanıldı', SurgeryCaseItem::OPENED => 'Açıldı', SurgeryCaseItem::UNUSED => 'Kullanılmadı'];
    $billable = $c->items->whereIn('status', SurgeryCaseItem::BILLABLE);
    $unused = $c->items->where('status', SurgeryCaseItem::UNUSED);
    $source = fn ($w) => $w?->type === 'consignment' ? ($w->path ?? $w->name) : 'Saha personeli aracı';
@endphp
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>{{ $c->number }}</title>
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
    .tag { font-size: 8px; padding: 1px 5px; border-radius: 4px; }
    .used { background: #dcfce7; color: #166534; }
    .opened { background: #fef3c7; color: #92400e; }
    .unused { background: #f3f4f6; color: #4b5563; }
    .box { border: 1px solid #d1d5db; border-radius: 6px; height: 130px; text-align: center; }
    .box img { max-height: 122px; max-width: 240px; margin-top: 4px; }
    .cancel { background: #fee2e2; color: #991b1b; padding: 8px; border-radius: 6px; margin-top: 10px; }
    .stamp { position: fixed; top: 300px; left: 150px; font-size: 80px; font-weight: bold; color: #fca5a5; transform: rotate(-20deg); }
    .note { background: #f3f4f6; padding: 8px; border-radius: 6px; font-size: 8.5px; color: #4b5563; }
    .warn { color: #b45309; }
</style>
</head>
<body>
@if ($c->status === SurgeryCase::CANCELLED)
    <div class="stamp">İPTAL</div>
@endif

<table class="head">
    <tr>
        <td>
            <h1>{{ $company }}</h1>
            <div class="muted">Ameliyat Malzeme Kullanım Tutanağı</div>
        </td>
        <td style="text-align: right">
            <div class="mono" style="font-size: 14px; font-weight: bold">{{ $c->number }}</div>
            <div class="muted">{{ ($c->performed_at ?? $c->scheduled_at)?->timezone($tz)->format('d.m.Y H:i') }}</div>
        </td>
    </tr>
</table>
<hr style="border: 0; border-top: 1px solid #d1d5db; margin: 10px 0">

@if ($c->status === SurgeryCase::CANCELLED)
    <div class="cancel">
        <strong>İptal edildi:</strong> {{ $c->cancelled_at?->timezone($tz)->format('d.m.Y H:i') }} · {{ $c->canceller?->name }} — {{ $c->cancel_reason }}
    </div>
@endif

<table class="parties">
    <tr>
        <td>
            <div class="label">Hastane</div>
            <strong>{{ $c->hospital?->name }}</strong>
            @if ($address)<div class="muted">{{ $address }}</div>@endif
            @if ($c->location)<div>Bölüm: {{ $c->location->path ?? $c->location->name }}</div>@endif
        </td>
        <td>
            <div class="label">Ameliyat</div>
            <strong>{{ $c->procedure ?: '—' }}</strong>
            <div>Doktor: {{ $c->doctor?->display_name ?? '—' }}@if ($c->doctor?->branch) <span class="muted">({{ $c->doctor->branch }})</span>@endif</div>
            <div>Hasta protokol no: <span class="mono">{{ $c->protocol_no ?: '—' }}</span></div>
        </td>
    </tr>
    <tr>
        <td>
            <div class="label">Firma temsilcisi</div>
            <strong>{{ $c->user?->name }}</strong>
            @if ($c->user?->phone)<div class="muted">{{ $c->user->phone }}</div>@endif
        </td>
        <td>
            <div class="label">Tutanağı onaylayan (hastane)</div>
            <strong>{{ $c->signer_name ?? '—' }}</strong>
            @if ($c->signer_title)<div class="muted">{{ $c->signer_title }}</div>@endif
            @if ($c->latitude !== null)
                <div class="muted">İşlem konumu: {{ $c->latitude }}, {{ $c->longitude }}@if ($c->location_accuracy) (±{{ $c->location_accuracy }} m)@endif</div>
            @endif
        </td>
    </tr>
</table>

<div class="label" style="margin-top: 10px">Kullanılan ve açılan ürünler (faturalanır)</div>
<table class="items">
    <thead>
        <tr><th>Ürün</th><th>Karekod (GTIN)</th><th>Lot / Seri</th><th>SKT</th><th>Durum</th><th class="num">Adet</th></tr>
    </thead>
    <tbody>
        @forelse ($billable as $i)
            <tr>
                <td><strong>{{ $i->product?->name }}</strong><br><span class="muted mono">{{ $i->product?->code }}</span></td>
                <td class="mono">{{ $i->product?->gtin ?? '—' }}</td>
                <td class="mono">
                    @if ($i->serial_number)SN {{ $i->serial_number }}<br>@endif
                    @if ($i->lot_number)LOT {{ $i->lot_number }}@endif
                </td>
                <td>{{ $i->expiry_date?->format('d.m.Y') ?? '—' }}@if ($i->expired_at_use)<br><span class="warn">SKT geçmiş</span>@endif</td>
                <td><span class="tag {{ $i->status }}">{{ $statusLabels[$i->status] }}</span></td>
                <td class="num">{{ $fmt($i->quantity) }} {{ $i->product?->unit }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Faturalanacak ürün yok.</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr><td colspan="5">Toplam · {{ $billable->count() }} kalem</td><td class="num">{{ $fmt($billable->sum(fn ($i) => (float) $i->quantity)) }}</td></tr>
    </tfoot>
</table>

@if ($unused->isNotEmpty())
    <div class="label" style="margin-top: 10px">Salona götürülüp kullanılmayan ürünler (faturalanmaz)</div>
    <table class="items">
        <tbody>
            @foreach ($unused as $i)
                <tr>
                    <td>{{ $i->product?->name }}</td>
                    <td class="mono">@if ($i->serial_number)SN {{ $i->serial_number }}@elseif ($i->lot_number)LOT {{ $i->lot_number }}@endif</td>
                    <td class="num">{{ $fmt($i->quantity) }} {{ $i->product?->unit }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($c->note)
    <div class="label" style="margin-top: 10px">Not</div>
    <div>{{ $c->note }}</div>
@endif

<table style="margin-top: 14px">
    <tr>
        <td style="width: 50%; padding-right: 8px; vertical-align: top">
            <div class="label">İmza</div>
            <div class="box">
                @if ($signature)<img src="{{ $signature }}" alt="İmza">@else<div class="muted" style="padding-top: 55px">İmza alınmadı</div>@endif
            </div>
            <div class="muted" style="text-align: center; margin-top: 2px">{{ $c->signer_name }}</div>
        </td>
        <td style="width: 50%; padding-left: 8px; vertical-align: top">
            @if ($photo)
                <div class="label">Fotoğraf</div>
                <div class="box"><img src="{{ $photo }}" alt="Fotoğraf"></div>
            @endif
        </td>
    </tr>
</table>

<p class="note" style="margin-top: 14px">
    Bu tutanakta "Kullanıldı" ve "Açıldı" olarak işaretlenen ürünler hastaneye faturalandırılır.
    Ürünler firmanın konsinye stoğundan karşılanmıştır.
</p>

<p class="muted" style="text-align: center; font-size: 8px; margin-top: 10px">
    Tutanak sistem tarafından oluşturulmuştur · {{ $c->number }} · Kayıt saati {{ $c->performed_at?->timezone($tz)->format('d.m.Y H:i:s') }}
    @if ($c->device_time) · Cihaz saati {{ $c->device_time->timezone($tz)->format('d.m.Y H:i:s') }}@endif
</p>
</body>
</html>
