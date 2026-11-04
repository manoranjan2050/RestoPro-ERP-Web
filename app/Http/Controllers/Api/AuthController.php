<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Setting;
use App\Models\StaffPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** POST /auth/login — mobile, username or email + password → bearer token for this device. */
    public function login(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['required', 'string', 'max:80'],
        ]);

        $key = 'api-login:'.Str::lower(trim($data['identifier'])).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'identifier' => 'Too many attempts. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]);
        }

        $user = User::findByIdentifier($data['identifier']);
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 15 * 60);
            throw ValidationException::withMessages(['identifier' => 'These details do not match our records.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['identifier' => 'This account is disabled.']);
        }

        RateLimiter::clear($key);
        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken(Str::limit($data['device_name'], 80, ''))->plainTextToken;

        return response()->json(['token' => $token, 'token_type' => 'Bearer', 'user' => Fmt::user($user), 'meta' => self::meta()]);
    }

    /** POST /auth/logout — revokes only this device's token. */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    /** GET /me */
    public function me(Request $request)
    {
        return response()->json(['user' => Fmt::user($request->user()), 'meta' => self::meta()]);
    }

    /** GET /meta — lookup values the app needs to build forms. */
    public function metaEndpoint()
    {
        return response()->json(self::meta());
    }

    public static function meta(): array
    {
        return [
            'business_name' => Setting::get('business_name'),
            'business_logo_url' => Setting::get('business_logo') ? route('media', Setting::get('business_logo')) : null,
            'currency_symbol' => Setting::get('currency_symbol', '₹'),
            'fy_start_month' => (int) Setting::get('fy_start_month', '4'),
            'payment_modes' => collect(Expense::MODES)->map(fn ($l, $k) => ['code' => $k, 'label' => $l])->values(),
            'attendance_codes' => Fmt::attendanceCodes(),
            'staff_payment_types' => collect(StaffPayment::TYPES)->map(fn ($v, $k) => ['code' => $k, 'label' => $v[0]])->values(),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
