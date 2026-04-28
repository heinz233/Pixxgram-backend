<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
{
    $validated = $request->validate([
        'name'      => 'required|string|max:255',
        'email'     => 'required|email|unique:users,email',
        'password'  => 'required|string|min:6|max:30',
        'role_id'   => 'required|integer|exists:roles,id',
    ]);

    $user = User::create([
        'name'      => $validated['name'],
        'email'     => $validated['email'],
        'password'  => Hash::make($validated['password']),
        'role_id'   => $validated['role_id'],
        'is_active' => false, // ← back to false, wait for verification
    ]);

    // Re-enable email sending ↓
    $signedUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $user->notify(new VerifyEmailNotification($signedUrl));

    $token = $user->createToken('auth-token')->plainTextToken;

    return response()->json([
        'message' => 'Registration successful! Please check your email to verify your account.',
        'user'    => $user,
        'token'   => $token,
    ], 201);
}

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:6|max:30',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Account is not active. Please verify your email address.',
            ], 403);
        }

        // Revoke old tokens (single-session enforcement)
        $user->tokens()->delete();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $user->load(['role', 'photographerProfile']),
            'token'   => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout successful.']);
    }

    public function userInfo(Request $request)
    {
        return response()->json([
            'user' => $request->user()->load(['role', 'photographerProfile']),
        ]);
    }
}