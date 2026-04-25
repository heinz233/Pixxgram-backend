<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Commission tracking
            $table->decimal('platform_commission', 10, 2)->nullable()->after('amount');
            $table->decimal('photographer_payout', 10, 2)->nullable()->after('platform_commission');
            // Payout tracking
            $table->string('payout_status', 20)->default('pending')->after('photographer_payout');
            // pending | processing | paid | failed
            $table->string('payout_reference')->nullable()->after('payout_status');
            $table->string('payout_receipt')->nullable()->after('payout_reference');
            $table->timestamp('payout_at')->nullable()->after('payout_receipt');

            $table->index('payout_status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'platform_commission',
                'photographer_payout',
                'payout_status',
                'payout_reference',
                'payout_receipt',
                'payout_at',
            ]);
        });
    }
};
