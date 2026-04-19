<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->nullable()->after('notes');
            $table->string('payment_status', 30)->default('unpaid')->after('amount');
            $table->string('mpesa_checkout_request_id')->nullable()->after('payment_status');
            $table->string('mpesa_receipt')->nullable()->after('mpesa_checkout_request_id');
            $table->timestamp('paid_at')->nullable()->after('mpesa_receipt');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['amount','payment_status','mpesa_checkout_request_id','mpesa_receipt','paid_at']);
        });
    }
};
