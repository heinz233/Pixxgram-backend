<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class ResendEmailVerificationController extends Controller
{
    public function resend(Request $request){
        $request->validate([
            'email'=>'required|email'
        ]);

        $user = User::where('email', $request->email)->first();

        if(!$user) {
            return response()->json([
                'message'=>'User not found'
            ], 404);
        }

        if($user->hasVerifiedEmail()) {
            return response()->json([
                'message'=>'Email is already verified'
            ], 200);
        }

        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id'=>$user->id,
                'hash'=>sha1($user->email)
            ]
        );

        $user->notify(new VerifyEmailNotification($signedUrl));

        return response()->json([
            'message'=>'Verification Email resent successfully.'
        ], 200);
    }
}
