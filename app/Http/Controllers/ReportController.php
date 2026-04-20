<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/photographers/{id}/report
    // Client reports a photographer
    // ─────────────────────────────────────────────────────────────────
    public function store(Request $request, $photographerId)
    {
        $request->validate([
            'reason'      => 'required|string|in:' . implode(',', [
                'inappropriate_behavior',
                'scam_or_fraud',
                'no_show',
                'poor_quality',
                'harassment',
                'fake_profile',
                'other',
            ]),
            'description' => 'nullable|string|max:1000',
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        // Only clients can report
        if ($user->role_id !== 3) {
            return response()->json([
                'message' => 'Only clients can report photographers.',
            ], 403);
        }

        // Verify the target is a photographer
        $photographer = User::where('id', $photographerId)
            ->where('role_id', 2)
            ->first();

        if (!$photographer) {
            return response()->json(['message' => 'Photographer not found.'], 404);
        }

        // Cannot report yourself (edge case)
        if ($user->id == $photographerId) {
            return response()->json(['message' => 'You cannot report yourself.'], 422);
        }

        // Prevent duplicate pending reports for the same photographer
        $existing = Report::where('client_id', $user->id)
            ->where('photographer_id', $photographerId)
            ->where('status', 'pending')
            ->exists();

        if ($existing) {
            return response()->json([
                'message' => 'You already have a pending report against this photographer. Please wait for it to be reviewed.',
            ], 409);
        }

        $report = Report::create([
            'client_id'       => $user->id,
            'photographer_id' => $photographerId,
            'reason'          => $request->reason,
            'description'     => $request->description ?? null,
            'status'          => 'pending',
        ]);

        return response()->json([
            'message' => 'Report submitted successfully. Our team will review it within 24–48 hours.',
            'report'  => $report,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/my-reports — client can see their own reports
    // ─────────────────────────────────────────────────────────────────
    public function myReports()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $reports = Report::where('client_id', $user->id)
            ->with('photographer:id,name,user_image')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($reports);
    }
}
