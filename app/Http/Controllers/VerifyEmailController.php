<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\PhotographerProfile;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * GET /api/email/verify/{id}/{hash}  (signed route)
     */
    public function verify(Request $request, $id, $hash)
    {
        $user = User::findOrFail($id);

        // Validate hash
        if (!hash_equals((string) $hash, sha1($user->email))) {
            return response()->json(['message' => 'Invalid verification link.'], 400);
        }

        // Already verified
        if (!is_null($user->email_verified_at)) {
            return response()->json(['message' => 'Email already verified.'], 200);
        }

        // Verify and activate
        $user->email_verified_at = now();
        $user->is_active         = true;
        $user->status            = 'active';
        $user->save();

        event(new Verified($user));

        // Auto-create photographer profile if it doesn't exist yet
        if ($user->role_id === 2 && !$user->photographerProfile) {
            PhotographerProfile::create([
                'user_id'             => $user->id,
                'subscription_status' => 'inactive',
            ]);
        }

        // Issue token so user is immediately logged in after verifying
        $user->tokens()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Email verified successfully! You can now log in.',
            'token'   => $token,
            'user'    => $user->load(['role', 'photographerProfile']),
        ]);
    }
}