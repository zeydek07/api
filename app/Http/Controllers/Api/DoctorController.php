<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Hospital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Hastaneye bağlı doktorlar (vakada seçilir) */
class DoctorController extends Controller
{
    /** Tüm hastanelerin doktorları (panel listesi) */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Doctor::query()
                ->with('hospital:id,name')
                ->withCount('cases')
                ->when($request->filled('hospital_id'), fn ($q) => $q->where('hospital_id', $request->hospital_id))
                ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', '%'.$request->q.'%')
                    ->orWhere('branch', 'like', '%'.$request->q.'%')))
                ->orderBy('name')
                ->paginate(min(max((int) $request->input('per_page', 50), 1), 200))
        );
    }

    public function forHospital(Hospital $hospital): JsonResponse
    {
        return response()->json($hospital->doctors()->withCount('cases')->get());
    }

    public function store(Request $request, Hospital $hospital): JsonResponse
    {
        $data = $this->validated($request, $hospital);
        $doctor = $hospital->doctors()->create([...$data, 'created_by' => $request->user()->id]);

        return response()->json($doctor, 201);
    }

    public function update(Request $request, Hospital $hospital, Doctor $doctor): JsonResponse
    {
        abort_unless($doctor->hospital_id === $hospital->id, 404);
        $doctor->update($this->validated($request, $hospital, $doctor));

        return response()->json($doctor);
    }

    private function validated(Request $request, Hospital $hospital, ?Doctor $doctor = null): array
    {
        $data = $request->validate([
            'name' => [$doctor ? 'sometimes' : 'required', 'string', 'min:2', 'max:255'],
            'title' => ['nullable', 'string', 'max:40'],
            'branch' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            $exists = $hospital->doctors()->where('name', $data['name'])->when($doctor, fn ($q) => $q->whereKeyNot($doctor->id))->exists();
            if ($exists) {
                abort(response()->json([
                    'message' => "{$data['name']} bu hastanede zaten kayıtlı.",
                    'errors' => ['name' => ['Bu doktor bu hastanede zaten kayıtlı.']],
                ], 422));
            }
        }

        return $data;
    }
}
