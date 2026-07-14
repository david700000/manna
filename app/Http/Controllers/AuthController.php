<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use App\Notifications\WelcomeUser;
use App\Notifications\PasswordResetNotification;

class AuthController extends Controller
{
    // ── Register ──────────────────────────────────────────────────────────────

    public function register(Request $request)
    {
        // Rate limit: 3 registrations per IP per minute
        $key = 'register:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['message' => 'Too many registration attempts. Please try again later.'], 429);
        }
        RateLimiter::hit($key, 60);

        $validated = $request->validate([
            'name'     => 'required|string|max:255|regex:/^[\p{L}\s\'\-\.]+$/u',
            'email'    => 'required|string|email:rfc,dns|max:255|unique:users',
            'password' => [
                'required', 'string', 'min:8',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
            ],
        ], [
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.',
            'name.regex'     => 'Name contains invalid characters.',
        ]);

        $user = User::create([
            'name'     => htmlspecialchars(strip_tags(trim($validated['name'])), ENT_QUOTES, 'UTF-8'),
            'email'    => strtolower(trim($validated['email'])),
            'password' => $validated['password'],
            'role'     => 'customer', // Always customer — never trust client input for role
        ]);

        try { (new WelcomeUser())->send($user); } catch (\Throwable $e) {}

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
        ], 201);
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    public function login(Request $request)
    {
        // Rate limit: 5 attempts per IP per minute
        $key = 'login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ], 429);
        }

        $validated = $request->validate([
            'email'    => 'required|string|email|max:255',
            'password' => 'required|string|max:255',
        ]);

        $user = User::where('email', strtolower(trim($validated['email'])))->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            // Increment limiter only on failure
            RateLimiter::hit($key, 60);
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        // Clear limiter on success
        RateLimiter::clear($key);

        // Revoke old tokens to prevent session accumulation
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
        ]);
    }

    // ── Current User ──────────────────────────────────────────────────────────

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    // ── Update Profile ────────────────────────────────────────────────────────

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'name'  => 'sometimes|string|max:255|regex:/^[\p{L}\s\'\-\.]+$/u',
            'email' => 'sometimes|string|email:rfc|max:255|unique:users,email,' . $user->id,
            'phone' => 'sometimes|string|max:20|regex:/^[\+\d\s\-\(\)]+$/',
        ]);
        if (isset($validated['name'])) {
            $validated['name'] = htmlspecialchars(strip_tags(trim($validated['name'])), ENT_QUOTES, 'UTF-8');
        }
        if (isset($validated['email'])) {
            $validated['email'] = strtolower(trim($validated['email']));
        }
        $user->update($validated);
        return response()->json($user);
    }

    // ── Logout ────────────────────────────────────────────────────────────────

    public function logout(Request $request)
    {
        // Revoke only the current token
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Successfully logged out.']);
    }

    // ── Forgot Password (OTP) ─────────────────────────────────────────────────

    public function forgotPassword(Request $request)
    {
        // Rate limit: 3 per IP per 15 minutes
        $key = 'forgot:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['message' => 'Too many requests. Please wait before trying again.'], 429);
        }
        RateLimiter::hit($key, 900);

        $request->validate(['email' => 'required|email|max:255']);

        $user = User::where('email', strtolower(trim($request->email)))->first();

        // Always return the same message to prevent email enumeration
        if ($user) {
            // Generate 6-digit OTP
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($otp), 'created_at' => now()]
            );

            try {
                (new PasswordResetNotification($otp, true))->send($user);
            } catch (\Throwable $e) {}
        }

        return response()->json(['message' => 'If that email is registered, a 6-digit OTP has been sent.']);
    }

    // ── Verify OTP ────────────────────────────────────────────────────────────

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255',
            'otp'   => 'required|string|size:6',
        ]);

        $reset = DB::table('password_reset_tokens')
            ->where('email', strtolower(trim($request->email)))
            ->first();

        if (!$reset || !Hash::check($request->otp, $reset->token)) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 400);
        }

        // OTP expires after 10 minutes
        if (now()->diffInMinutes($reset->created_at) > 10) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json(['message' => 'OTP has expired. Please request a new one.'], 400);
        }

        return response()->json(['message' => 'OTP verified.', 'valid' => true]);
    }

    // ── Reset Password ────────────────────────────────────────────────────────

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'    => 'required|email|max:255',
            'otp'      => 'required|string|size:6',
            'password' => [
                'required', 'string', 'min:8', 'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
            ],
        ], [
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.',
        ]);

        $reset = DB::table('password_reset_tokens')
            ->where('email', strtolower(trim($request->email)))
            ->first();

        if (!$reset || !Hash::check($request->otp, $reset->token)) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 400);
        }

        // OTP expires after 10 minutes
        if (now()->diffInMinutes($reset->created_at) > 10) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json(['message' => 'OTP has expired. Please request a new one.'], 400);
        }

        $user = User::where('email', strtolower(trim($request->email)))->first();
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->password = $request->password;
        $user->must_change_password = false;
        $user->save();

        // Revoke ALL active tokens — forces re-login everywhere
        $user->tokens()->delete();

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Password has been successfully reset. Please log in with your new password.']);
    }
}
