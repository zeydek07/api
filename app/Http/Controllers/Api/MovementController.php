<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Kim, ne zaman, hangi depodan, ne kullandı?" sorusunun cevabı.
 * Yetkiye göre: view_all -> herkes, view_team -> kendisi ve ekibi.
 */
class MovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::in(StockMovement::TYPES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $movements = self::scoped($request->user())
            ->with(['product:id,name,code,unit', 'warehouse:id,name,code,type,hospital_id', 'warehouse.hospital:id,name', 'delivery:id,number,type', 'user:id,name', 'hospital:id,name,city'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->product_id))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->warehouse_id))
            ->when($request->filled('hospital_id'), fn ($q) => $q->where('hospital_id', $request->hospital_id))
            ->when($request->filled('batch_uuid'), fn ($q) => $q->where('batch_uuid', $request->batch_uuid))
            ->when($request->filled('reference'), fn ($q) => $q->where('reference', 'like', '%'.$request->reference.'%'))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->latest('id')
            ->paginate(min(max((int) $request->input('per_page', 50), 1), 200));

        return response()->json($movements);
    }

    public static function scoped(User $user): Builder
    {
        $query = StockMovement::query();

        if ($user->hasPermission('movements.view_all')) {
            return $query;
        }

        return $query->whereIn('user_id', $user->teamUserIds());
    }
}
