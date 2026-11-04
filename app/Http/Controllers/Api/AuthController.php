<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Giriş yap ve token al
     *
     * Token gerektirmeyen tek uç nokta. Dönen token'ı diğer isteklerde
     * `Authorization: Bearer <token>` başlığıyla gönderin.
     *
     * @unauthenticated
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'], // ör. "Ahmet - Samsung A54" ya da "web-panel"
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'E-posta veya şifre hatalı.']);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Hesabınız pasif. Yöneticinizle iletişime geçin.'], 403);
        }

        $minutes = config('sanctum.expiration');
        $token = $user->createToken($data['device_name'], ['*'], $minutes ? now()->addMinutes((int) $minutes) : null);

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => $this->profile($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->profile($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Çıkış yapıldı.']);
    }

    /** Tüm cihazlardan çıkış */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Tüm cihazlardan çıkış yapıldı.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Mevcut şifre hatalı.']);
        }

        $user->update(['password' => $data['password']]);

        // Şifre değişince bu cihaz hariç tüm oturumları kapat.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Şifre güncellendi.']);
    }

    /** Mobil uygulama ve panel hangi sayfaları göstereceğine bu listeye bakarak karar verir. */
    private function profile(User $user): array
    {
        $user->loadMissing(['role:id,name,slug', 'manager:id,name', 'warehouses:id,name,code,type']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'manager' => $user->manager,
            'is_super_admin' => $user->isSuperAdmin(),
            'permissions' => $user->permissionKeys(),
            'warehouses' => $user->warehouses,
        ];
    }
}
