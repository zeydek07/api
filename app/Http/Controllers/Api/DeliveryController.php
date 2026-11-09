<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\User;
use App\Services\DeliveryDocument;
use App\Services\DeliveryService;
use App\Support\CapturedLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Konsinye teslimat belgeleri.
 * Görme: kendi belgeleri her zaman; ekibinki movements.view_team, herkesinki movements.view_all ile.
 */
class DeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryService $deliveries,
        private readonly DeliveryDocument $document,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::in([Delivery::TYPE_DELIVERY, Delivery::TYPE_RETURN])],
            'status' => ['nullable', Rule::in([Delivery::STATUS_COMPLETED, Delivery::STATUS_CANCELLED])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'location' => ['nullable', Rule::in(['ok', 'missing'])],
        ]);

        $page = self::scoped($request->user())
            ->with(['hospital:id,name,city', 'location:id,name,path', 'user:id,name'])
            ->withCount('lines')
            ->withSum('lines as units', 'quantity')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('hospital_id'), fn ($q) => $q->where('hospital_id', $request->hospital_id))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            // Konumsuz kayıtlar ayrıca listelenebilir
            ->when($request->location === 'ok', fn ($q) => $q->whereNotNull('latitude'))
            ->when($request->location === 'missing', fn ($q) => $q->whereNull('latitude'))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', '%'.$request->q.'%')
                ->orWhere('receiver_name', 'like', '%'.$request->q.'%')))
            ->when($request->filled('from'), fn ($q) => $q->where('delivered_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('delivered_at', '<=', $request->date('to')->endOfDay()))
            ->latest('id')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json($page);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $delivery = self::scoped($request->user())->findOrFail($id);
        $delivery->load([
            'hospital:id,name,code,city,district,address',
            'location:id,name,path,code',
            'vehicle:id,name,code',
            'contact:id,name,title,phone,email',
            'user:id,name,email,phone',
            'canceller:id,name',
            'lines' => fn ($q) => $q->orderBy('product_id')->orderBy('id'),
            'lines.product:id,name,code,gtin,unit,tracking',
        ]);

        return response()->json($delivery);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([Delivery::TYPE_DELIVERY, Delivery::TYPE_RETURN])],
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'location_id' => ['required', 'integer', 'exists:warehouses,id'],
            'vehicle_id' => ['required', 'integer', 'exists:warehouses,id'],
            'contact_id' => ['nullable', 'integer'],
            'receiver_name' => ['nullable', 'string', 'max:255'],
            'receiver_title' => ['nullable', 'string', 'max:120'],
            'receiver_email' => ['nullable', 'email', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.stock_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'signature' => ['nullable', 'string', 'max:900000'],   // data:image/png;base64,...
            'photo' => ['nullable', 'string', 'max:8500000'],
            'note' => ['nullable', 'string', 'max:1000'],
            ...CapturedLocation::rules(),
            'device_time' => ['nullable', 'date'],
        ], CapturedLocation::messages());

        $key = $request->header('Idempotency-Key');
        if ($key !== null && (strlen($key) < 8 || strlen($key) > 100)) {
            return response()->json(['message' => 'Idempotency-Key 8-100 karakter olmalı.'], 422);
        }

        $result = $this->deliveries->create($request->user(), $data, $key);

        return response()->json([
            'message' => $result['replayed'] ? 'Bu teslimat daha önce kaydedilmişti.' : 'Teslimat kaydedildi.',
            'delivery' => $result['delivery']->fresh(['hospital:id,name', 'location:id,name,path']),
        ], $result['replayed'] ? 200 : 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $delivery = self::scoped($request->user())->findOrFail($id);

        $this->deliveries->cancel($request->user(), $delivery, $data['reason']);

        return response()->json(['message' => "{$delivery->number} iptal edildi; ürünler geri taşındı."]);
    }

    /** Belgenin PDF kopyası */
    public function pdf(Request $request, int $id): Response
    {
        $delivery = self::scoped($request->user())->findOrFail($id);

        return response($this->document->pdf($delivery), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->document->filename($delivery).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Belge kopyasını e-postayla (yeniden) gönder */
    public function email(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['email' => ['nullable', 'email', 'max:255']]);
        $delivery = self::scoped($request->user())->with('contact:id,email')->findOrFail($id);
        if ($delivery->status !== Delivery::STATUS_COMPLETED) {
            return response()->json(['message' => 'İptal edilmiş belge gönderilemez.'], 422);
        }
        $to = $data['email'] ?? $delivery->contact?->email ?? $delivery->email_to;
        if (! $to) {
            return response()->json(['message' => 'Gönderilecek e-posta adresi yok; adres yazın.', 'errors' => ['email' => ['E-posta adresi gerekli.']]], 422);
        }

        $ok = $this->document->email($delivery, mb_strtolower(trim($to)));
        $delivery->refresh();

        return response()->json([
            'message' => $ok ? "Belge {$delivery->email_to} adresine gönderildi." : 'E-posta gönderilemedi: '.$delivery->email_error,
            'delivery' => $delivery->only(['id', 'email_to', 'email_status', 'emailed_at', 'email_error']),
        ], $ok ? 200 : 502);
    }

    /** İmza / fotoğraf: token'lı istekle görsel olarak döner */
    public function proof(Request $request, int $id, string $kind): StreamedResponse|JsonResponse
    {
        abort_unless(in_array($kind, ['signature', 'photo'], true), 404);
        $delivery = self::scoped($request->user())->findOrFail($id);
        $path = $kind === 'signature' ? $delivery->signature_path : $delivery->photo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    /** Görme kapsamı */
    public static function scoped(User $user): Builder
    {
        $q = Delivery::query();
        if ($user->hasPermission('movements.view_all')) {
            return $q;
        }
        if ($user->hasPermission('movements.view_team')) {
            return $q->whereIn('user_id', $user->teamUserIds());
        }

        return $q->where('user_id', $user->id);
    }
}
