<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProofPhoto;
use App\Models\SurgeryCase;
use App\Models\SurgeryCaseItem;
use App\Models\User;
use App\Services\CaseDocument;
use App\Services\CaseService;
use App\Support\CapturedLocation;
use App\Support\ProofPhotos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vakalar. Görme: kendi vakaları; ekibinki movements.view_team, herkesinki cases.view_all / billing.manage ile.
 */
class SurgeryCaseController extends Controller
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly CaseDocument $document,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'scope' => ['nullable', Rule::in(['today', 'upcoming', 'past', 'overdue'])],
            'status' => ['nullable', Rule::in(SurgeryCase::STATUSES)],
            'billing' => ['nullable', Rule::in(['pending', 'partial', 'invoiced'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $scope = $request->input('scope');
        // "Bugün" Türkiye saatine göre
        $tz = config('app.display_timezone');
        $dayStart = now($tz)->startOfDay()->utc();
        $dayEnd = now($tz)->endOfDay()->utc();

        $query = self::scoped($request->user())
            ->with(['hospital:id,name,city', 'location:id,name,path', 'doctor:id,name,title,branch', 'user:id,name'])
            ->withCount([
                'items',
                'items as billable_count' => fn ($q) => $q->whereIn('status', SurgeryCaseItem::BILLABLE),
                'items as invoiced_count' => fn ($q) => $q->whereIn('status', SurgeryCaseItem::BILLABLE)->whereNotNull('invoice_number'),
            ])
            ->withSum(['items as billable_units' => fn ($q) => $q->whereIn('status', SurgeryCaseItem::BILLABLE)], 'quantity')
            ->when($scope === 'today', fn ($q) => $q->whereBetween('scheduled_at', [$dayStart, $dayEnd])->where('status', '!=', SurgeryCase::CANCELLED))
            ->when($scope === 'upcoming', fn ($q) => $q->where('scheduled_at', '>', $dayEnd)->where('status', SurgeryCase::PLANNED))
            ->when($scope === 'past', fn ($q) => $q->where('scheduled_at', '<', $dayStart))
            ->when($scope === 'overdue', fn ($q) => $q->where('status', SurgeryCase::PLANNED)->where('scheduled_at', '<', now()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('hospital_id'), fn ($q) => $q->where('hospital_id', $request->hospital_id))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            // Saha listesi: yalnızca sorumlusu olduğum vakalar (devrettiğim vaka listeden çıkar)
            ->when($request->boolean('mine'), fn ($q) => $q->where('user_id', $request->user()->id))
            ->when($request->filled('doctor_id'), fn ($q) => $q->where('doctor_id', $request->doctor_id))
            ->when($request->filled('from'), fn ($q) => $q->where('scheduled_at', '>=', $request->date('from', null, $tz)->startOfDay()->utc()))
            ->when($request->filled('to'), fn ($q) => $q->where('scheduled_at', '<=', $request->date('to', null, $tz)->endOfDay()->utc()))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', '%'.$request->q.'%')
                ->orWhere('protocol_no', 'like', '%'.$request->q.'%')
                ->orWhere('patient_name', 'like', '%'.$request->q.'%')
                ->orWhere('procedure', 'like', '%'.$request->q.'%')))
            ->when($request->billing === 'pending', fn ($q) => $q->where('status', SurgeryCase::APPROVED)
                ->whereHas('items', fn ($i) => $i->whereIn('status', SurgeryCaseItem::BILLABLE)->whereNull('invoice_number')))
            ->when($request->billing === 'invoiced', fn ($q) => $q->whereHas('items', fn ($i) => $i->whereNotNull('invoice_number'))
                ->whereDoesntHave('items', fn ($i) => $i->whereIn('status', SurgeryCaseItem::BILLABLE)->whereNull('invoice_number')));

        // Bugün ve yaklaşanlar saate göre; geçmiş en yeni önce
        in_array($scope, ['today', 'upcoming', 'overdue'], true)
            ? $query->orderBy('scheduled_at')
            : $query->orderByDesc('scheduled_at')->orderByDesc('id');

        return response()->json($query->paginate(min(max((int) $request->input('per_page', 25), 1), 100)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $case = self::scoped($request->user())->findOrFail($id);

        return response()->json($this->detail($case));
    }

    /** Planla */
    public function store(Request $request): JsonResponse
    {
        $case = $this->cases->plan($request->user(), $this->planRules($request));

        return response()->json(['message' => "{$case->number} planlandı.", 'case' => $this->detail($case)], 201);
    }

    /** Planı düzenle (kayıttan önce) */
    public function update(Request $request, int $id): JsonResponse
    {
        $case = self::scoped($request->user())->findOrFail($id);
        $case = $this->cases->update($request->user(), $case, $this->planRules($request, partial: true));

        return response()->json(['message' => 'Vaka güncellendi.', 'case' => $this->detail($case)]);
    }

    /** Ameliyat sonrası ürün kaydı */
    public function record(Request $request, int $id): JsonResponse
    {
        $case = self::scoped($request->user())->findOrFail($id);
        $data = $this->recordRules($request);

        $key = $this->idempotencyKey($request);
        $result = $this->cases->record($request->user(), $case, $data, $key);

        return response()->json([
            'message' => $result['replayed'] ? 'Bu kayıt daha önce alınmıştı.' : "{$case->number} kaydedildi; onaya gönderildi.",
            'case' => $this->detail($result['case']),
        ], $result['replayed'] ? 200 : 201);
    }

    /** Plansız (acil) vaka: plan olmadan hastane seçilip ürünler doğrudan kaydedilir */
    public function recordUnplanned(Request $request): JsonResponse
    {
        $data = [
            ...$request->validate([
                'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
                'location_id' => ['nullable', 'integer'],
                'doctor_id' => ['nullable', 'integer'],
                'user_id' => ['nullable', 'integer'],
                'procedure' => ['nullable', 'string', 'max:200'],
            ]),
            ...$this->recordRules($request),
        ];
        $result = $this->cases->recordUnplanned($request->user(), $data, $this->idempotencyKey($request));
        $case = $result['case'];

        return response()->json([
            'message' => $result['replayed'] ? 'Bu kayıt daha önce alınmıştı.' : "{$case->number} (plansız vaka) kaydedildi; onaya gönderildi.",
            'case' => $this->detail($case),
        ], $result['replayed'] ? 200 : 201);
    }

    /** Vakayı başka personele devret */
    public function transfer(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $case = self::scoped($request->user())->findOrFail($id);
        $case = $this->cases->transfer($request->user(), $case, (int) $data['user_id'], $data['note'] ?? null);

        return response()->json([
            'message' => "{$case->number} {$case->user->name} adlı personele devredildi.",
            'case' => $this->detail($case),
        ]);
    }

    /** Vakanın devredilebileceği kişiler */
    public function transferTargets(Request $request): JsonResponse
    {
        return response()->json($this->cases->transferTargets($request->user()));
    }

    /** Kaydedilmiş vakaya sonradan fotoğraf ekle */
    public function addPhotos(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(ProofPhotos::rules(), ProofPhotos::messages());
        $case = self::scoped($request->user())->findOrFail($id);
        $case = $this->cases->addPhotos($request->user(), $case, $data);

        return response()->json(['message' => 'Fotoğraf eklendi.', 'case' => $this->detail($case)], 201);
    }

    /** Tek fotoğraf dosyası */
    public function photo(Request $request, int $id, int $photoId): StreamedResponse
    {
        $case = self::scoped($request->user())->findOrFail($id);
        $photo = $case->photos()->findOrFail($photoId);
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response($photo->path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $case = self::scoped($request->user())->findOrFail($id);
        $this->cases->approve($request->user(), $case);

        return response()->json(['message' => "{$case->number} onaylandı; faturalanacaklara eklendi.", 'case' => $this->detail($case)]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $case = self::scoped($request->user())->findOrFail($id);
        $wasRecorded = $case->status !== SurgeryCase::PLANNED;
        $this->cases->cancel($request->user(), $case, $data['reason']);

        return response()->json([
            'message' => "{$case->number} iptal edildi".($wasRecorded ? '; düşülen ürünler yerlerine geri kondu.' : '.'),
            'case' => $this->detail($case->fresh()),
        ]);
    }

    public function pdf(Request $request, int $id): Response
    {
        $case = self::scoped($request->user())->findOrFail($id);
        abort_if($case->status === SurgeryCase::PLANNED, 404, 'Vakanın ürünleri henüz kaydedilmedi.');

        return response($this->document->pdf($case), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->document->filename($case).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function email(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['email' => ['nullable', 'email', 'max:255']]);
        $case = self::scoped($request->user())->with('contact:id,email')->findOrFail($id);
        if (! in_array($case->status, [SurgeryCase::RECORDED, SurgeryCase::APPROVED], true)) {
            return response()->json(['message' => 'Yalnızca ürünleri kaydedilmiş vakanın tutanağı gönderilebilir.'], 422);
        }
        $to = $data['email'] ?? $case->contact?->email ?? $case->email_to;
        if (! $to) {
            return response()->json(['message' => 'Gönderilecek e-posta adresi yok; adres yazın.', 'errors' => ['email' => ['E-posta adresi gerekli.']]], 422);
        }

        $ok = $this->document->email($case, mb_strtolower(trim($to)));
        $case->refresh();

        return response()->json([
            'message' => $ok ? "Tutanak {$case->email_to} adresine gönderildi." : 'E-posta gönderilemedi: '.$case->email_error,
            'case' => $case->only(['id', 'email_to', 'email_status', 'emailed_at', 'email_error']),
        ], $ok ? 200 : 502);
    }

    /** İmza / fotoğraf */
    public function proof(Request $request, int $id, string $kind): StreamedResponse
    {
        $case = self::scoped($request->user())->findOrFail($id);
        $path = $kind === 'signature' ? $case->signature_path : $case->photos()->value('path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    /** Görme kapsamı */
    public static function scoped(User $user): Builder
    {
        $q = SurgeryCase::query();
        if ($user->hasPermission('cases.view_all') || $user->hasPermission('billing.manage')) {
            return $q;
        }
        if ($user->hasPermission('movements.view_team')) {
            return $q->whereIn('user_id', $user->teamUserIds());
        }

        return $q->where(fn ($w) => $w->where('user_id', $user->id)->orWhere('created_by', $user->id));
    }

    private function recordRules(Request $request): array
    {
        return $request->validate([
            'protocol_no' => ['nullable', 'string', 'max:60'],
            'patient_name' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'contact_id' => ['nullable', 'integer'],
            'signer_name' => ['nullable', 'string', 'max:255'],
            'signer_title' => ['nullable', 'string', 'max:120'],
            'receiver_email' => ['nullable', 'email', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.stock_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'items.*.status' => ['required', Rule::in(SurgeryCaseItem::STATUSES)],
            'signature' => ['nullable', 'string', 'max:900000'],
            ...ProofPhotos::rules(),
            ...CapturedLocation::rules(),
            'device_time' => ['nullable', 'date'],
        ], [...CapturedLocation::messages(), ...ProofPhotos::messages()]);
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');
        if ($key !== null && (strlen($key) < 8 || strlen($key) > 100)) {
            abort(response()->json(['message' => 'Idempotency-Key 8-100 karakter olmalı.'], 422));
        }

        return $key;
    }

    private function planRules(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'hospital_id' => [$req, 'integer', 'exists:hospitals,id'],
            'location_id' => ['nullable', 'integer'],
            'doctor_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'scheduled_at' => [$req, 'date'],
            'procedure' => ['nullable', 'string', 'max:200'],
            'protocol_no' => ['nullable', 'string', 'max:60'],
            'patient_name' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function detail(SurgeryCase $case): SurgeryCase
    {
        return $case->load([
            'hospital:id,name,code,city,district,address',
            'location:id,name,path',
            'doctor:id,name,title,branch,phone',
            'user:id,name,email,phone',
            'creator:id,name',
            'recorder:id,name',
            'approver:id,name',
            'canceller:id,name',
            'contact:id,name,title,phone,email',
            'items' => fn ($q) => $q->orderByRaw("CASE status WHEN 'used' THEN 0 WHEN 'opened' THEN 1 ELSE 2 END")->orderBy('product_id')->orderBy('id'),
            'items.product:id,name,code,gtin,unit,tracking',
            'items.warehouse:id,name,path,type',
            'photos:id,owner_type,owner_id,kind,bytes,created_by,created_at',
            'photos.creator:id,name',
            'events.user:id,name',
        ]);
    }
}
