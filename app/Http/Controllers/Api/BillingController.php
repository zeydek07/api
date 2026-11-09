<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SurgeryCase;
use App\Models\SurgeryCaseItem;
use App\Services\CaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Faturalama: onaylı vakaların kullanılan / açılan ürün satırları.
 * pending: faturalanacak · invoiced: faturalandı (fatura no ve tarihle).
 */
class BillingController extends Controller
{
    public function __construct(private readonly CaseService $cases) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'invoiced'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $invoiced = $request->input('status') === 'invoiced';

        $items = SurgeryCaseItem::query()
            ->whereIn('status', SurgeryCaseItem::BILLABLE)
            ->when($invoiced, fn ($q) => $q->whereNotNull('invoice_number'), fn ($q) => $q->whereNull('invoice_number'))
            ->whereHas('surgeryCase', fn ($c) => $c->where('status', SurgeryCase::APPROVED)
                ->when($request->filled('hospital_id'), fn ($q) => $q->where('hospital_id', $request->hospital_id))
                ->when($request->filled('from'), fn ($q) => $q->where('performed_at', '>=', $request->date('from')->startOfDay()))
                ->when($request->filled('to'), fn ($q) => $q->where('performed_at', '<=', $request->date('to')->endOfDay())))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('invoice_number', 'like', '%'.$request->q.'%')
                ->orWhereHas('surgeryCase', fn ($c) => $c->where('number', 'like', '%'.$request->q.'%'))))
            ->with([
                'product:id,name,code,gtin,unit',
                'surgeryCase:id,number,hospital_id,doctor_id,user_id,performed_at,procedure,protocol_no',
                'surgeryCase.hospital:id,name',
                'surgeryCase.doctor:id,name,title',
                'surgeryCase.user:id,name',
                'invoicer:id,name',
            ])
            ->latest('id')
            ->limit(1000)
            ->get();
        // Faturalanacaklar hastane ve vaka tarihine göre; faturalananlar en yeni önce
        $items = $invoiced
            ? $items->sortByDesc(fn ($i) => [$i->invoiced_at?->timestamp, $i->id])->values()
            : $items->sortBy(fn ($i) => [$i->surgeryCase->hospital?->name, $i->surgeryCase->performed_at?->timestamp, $i->id])->values();

        return response()->json([
            'items' => $items,
            'summary' => [
                'lines' => $items->count(),
                'units' => round((float) $items->sum('quantity'), 2),
                'cases' => $items->pluck('surgery_case_id')->unique()->count(),
                'hospitals' => $items->pluck('surgeryCase.hospital_id')->unique()->count(),
            ],
        ]);
    }

    public function invoice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'item_ids.*' => ['integer'],
            'invoice_number' => ['required', 'string', 'max:60'],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $n = $this->cases->invoice($request->user(), $data['item_ids'], trim($data['invoice_number']), $data['invoice_date']);

        return response()->json(['message' => "{$n} satır {$data['invoice_number']} ile faturalandı olarak işaretlendi."]);
    }

    public function uninvoice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'item_ids.*' => ['integer'],
        ]);
        $n = $this->cases->uninvoice($data['item_ids']);

        return response()->json(['message' => "{$n} satırın fatura işareti kaldırıldı; tekrar faturalanacaklarda."]);
    }
}
