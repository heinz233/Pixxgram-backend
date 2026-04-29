<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\PhotographerProfileController;
use App\Http\Controllers\RatingController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResendEmailVerificationController;
use App\Http\Controllers\VerifyEmailController;

// ─────────────────────────────────────────────────────────────────────
// PUBLIC ROUTES (no auth required)
// ─────────────────────────────────────────────────────────────────────


Route::delete('/photographer/portfolio/{id}', [PhotographerProfileController::class, 'deletePortfolioItem']);
Route::get('/photographer/portfolio', [PhotographerProfileController::class, 'getPortfolio']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// Email verification (signed URL — no token needed)
Route::get('/email/verify/{id}/{hash}', [VerifyEmailController::class, 'verify'])
    ->middleware(['signed'])
    ->name('api.verification.verify');

    // STK callback (client payment)
    Route::post('/bookings/mpesa/callback',   [BookingController::class, 'mpesaCallback'])
        ->name('bookings.mpesa.callback');

    // B2C callbacks (photographer payout)
    Route::post('/bookings/payout/callback',  [BookingController::class, 'payoutCallback'])
        ->name('bookings.payout.callback');
    Route::post('/bookings/payout/timeout',   [BookingController::class, 'payoutTimeout'])
        ->name('bookings.payout.timeout');

    // M-Pesa callback — Safaricom calls this, no auth
    Route::post('/subscriptions/mpesa/callback', [SubscriptionController::class, 'mpesaCallback'])
        ->name('subscriptions.mpesa.callback');

// Public listing
Route::get('/subscriptions/plans', [SubscriptionController::class, 'plans']);
Route::get('/photographers',       [PhotographerProfileController::class, 'index']);
Route::get('/photographers/{id}',  [PhotographerProfileController::class, 'show']);
Route::get('/categories',          [CategoryController::class, 'index']);
Route::get('/locations',           [LocationController::class, 'index']);

// ─────────────────────────────────────────────────────────────────────
// AUTHENTICATED ROUTES
// ─────────────────────────────────────────────────────────────────────

        Route::middleware('auth:sanctum')->group(function () {

        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user',    [AuthController::class, 'userInfo']);

    // Resend verification email
        Route::post('/email/verification-notification', [ResendEmailVerificationController::class, 'resend'])
        ->name('api.verification.send');

    // ── Ratings ──────────────────────────────────────────────────────
        Route::get('/photographers/{id}/ratings', [RatingController::class, 'index']);
        Route::post('/photographers/{id}/rate',   [RatingController::class, 'store']);

    // ── Bookings ─────────────────────────────────────────────────────
        Route::get('/bookings',                    [BookingController::class, 'getBookings']);
        Route::get('/bookings/{id}',               [BookingController::class, 'show']);
        Route::post('/bookings',                   [BookingController::class, 'store']);
        Route::patch('/bookings/{id}/status',      [BookingController::class, 'updateStatus']);
        Route::post('/bookings/{id}/pay',          [BookingController::class, 'initiatePayment']);
        Route::get('/bookings/{id}/payment-status',[BookingController::class, 'paymentStatus']);

    // ── Messages ─────────────────────────────────────────────────────
        Route::prefix('messages')->group(function () {
        Route::get('/conversations',          [MessageController::class, 'getConversations']);
        Route::get('/conversations/{userId}', [MessageController::class, 'getConversation']);
        Route::post('/send',                  [MessageController::class, 'sendMessage']);
        Route::get('/unread',                 [MessageController::class, 'unreadCount']);
        Route::patch('/{id}/read',            [MessageController::class, 'markAsRead']);
        Route::delete('/{id}',                [MessageController::class, 'deleteMessage']);
    });

    // ── Subscriptions ─────────────────────────────────────────────────
        Route::prefix('subscriptions')->group(function () {
        Route::get('/current',                          [SubscriptionController::class, 'current']);
        Route::get('/history',                          [SubscriptionController::class, 'history']);
        Route::post('/subscribe',                       [SubscriptionController::class, 'subscribe']);
        Route::post('/{id}/cancel',                     [SubscriptionController::class, 'cancel']);
        Route::get('/mpesa/status/{checkoutRequestId}', [SubscriptionController::class, 'mpesaStatus']);
    });

    //Reports
        Route::post('/photographers/{id}/report', [ReportController::class, 'store']);
        Route::get('/my-reports', [ReportController::class, 'myReports']);

    // ── Profile update (client) ───────────────────────────────────────
        Route::put('/photographer/profile',  [PhotographerProfileController::class, 'updateProfile']);
        Route::post('/photographer/profile', [PhotographerProfileController::class, 'updateProfile']);

    // ─────────────────────────────────────────────────────────────────
    // PHOTOGRAPHER ROUTES
    // No role middleware here — we check inside the controller instead
    // to avoid the RoleMiddleware token-loading issue
    // ─────────────────────────────────────────────────────────────────
     Route::get('/photographer/dashboard',   [PhotographerProfileController::class, 'getDashboard']);
     Route::put('/photographer/profile',  [PhotographerProfileController::class, 'updateProfile']);
     Route::post('/photographer/profile', [PhotographerProfileController::class, 'updateProfile']); // ← add this
     Route::post('/photographer/portfolio',  [PhotographerProfileController::class, 'uploadPortfolio']);

    // ─────────────────────────────────────────────────────────────────
    // ADMIN ROUTES
    // ─────────────────────────────────────────────────────────────────
    Route::prefix('admin')->group(function () {

        // Dashboard
        Route::get('/dashboard', [AdminController::class, 'getDashboardStats']);

        // Photographers
        Route::get('/photographers',                    [AdminController::class, 'getPhotographers']);
        Route::patch('/photographers/{id}/status',      [AdminController::class, 'updatePhotographerStatus']);
        Route::post('/photographers/{id}/subscription', [AdminController::class, 'manageSubscription']);

        // All users (for admin users view)
        Route::get('/users',                   [AdminController::class, 'getUsers']);
        Route::patch('/users/{id}/toggle-active', [AdminController::class, 'toggleUserActive']);

        // Reports
        Route::get('/reports',              [AdminController::class, 'getReports']);
        Route::patch('/reports/{id}/resolve', [AdminController::class, 'resolveReport']);
        Route::patch('/reports/{id}/dismiss', [AdminController::class, 'dismissReport']);

        // Ratings (admin can view all & delete)
        Route::get('/ratings',        [AdminController::class, 'getRatings']);
        Route::delete('/ratings/{id}',[AdminController::class, 'deleteRating']);

        // Subscriptions list
        Route::get('/subscriptions',  [AdminController::class, 'getSubscriptions']);

        // Bookings list (for activity feed)
        Route::get('/bookings',       [AdminController::class, 'getBookings']);

        // Categories
        Route::get('/categories',           [AdminController::class, 'manageCategories']);
        Route::post('/categories',          [AdminController::class, 'manageCategories']);
        Route::put('/categories/{id}',      [AdminController::class, 'manageCategories']);
        Route::patch('/categories/{id}',    [AdminController::class, 'manageCategories']);
        Route::delete('/categories/{id}',   [AdminController::class, 'manageCategories']);

        // Locations
        Route::get('/locations',            [AdminController::class, 'manageLocations']);
        Route::post('/locations',           [AdminController::class, 'manageLocations']);
        Route::put('/locations/{id}',       [AdminController::class, 'manageLocations']);
        Route::patch('/locations/{id}',     [AdminController::class, 'manageLocations']);
        Route::delete('/locations/{id}',    [AdminController::class, 'manageLocations']);

        // Roles
        Route::get('/roles', [RoleController::class, 'index']);
    });
});