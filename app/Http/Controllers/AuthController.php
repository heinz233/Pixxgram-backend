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
            'name'        => 'required|string',
            'email'       => 'required|email|unique:users,email',
            'password'    => 'required|string|min:6|max:30',
            'role_id'     => 'required|integer|exists:roles,id',
            'phoneNumber' => 'nullable|string',
            'gender'      => 'nullable|string',
            'dob'         => 'nullable|date',
            'gymLocation' => 'nullable|string',
        ]);

        // Handle profile image upload separately (cannot use mimes with nullable|string)
        $filename = null;
        if ($request->hasFile('user_image')) {
            $filename = $request->file('user_image')->store('users', 'public');
        }

        try {
            $user = User::create([
                'name'        => $validated['name'],
                'email'       => $validated['email'],
                'password'    => Hash::make($validated['password']),
                'role_id'     => $validated['role_id'],
                'phoneNumber' => $validated['phoneNumber'] ?? null,
                'gender'      => $validated['gender'] ?? null,
                'dob'         => $validated['dob'] ?? null,
                'gymLocation' => $validated['gymLocation'] ?? null,
                'user_image'  => $filename,
                'is_active'   => false, // activate on email verification
            ]);

            $signedUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(60),
                ['id' => $user->id, 'hash' => sha1($user->email)]
            );

            $user->notify(new VerifyEmailNotification($signedUrl));

            return response()->json([
                'message' => 'Registration successful. Please verify your email.',
                'user'    => $user,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Registration failed',
                'message' => $e->getMessage(),
            ], 500);
        }
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