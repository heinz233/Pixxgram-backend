<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photographer_id')->constrained('users')->cascadeOnDelete();
            $table->string('plan');                        // monthly | quarterly | annual
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending'); // pending | active | cancelled | expired | failed
            $table->string('payment_method');             // mpesa | card | paypal
            $table->string('transaction_reference')->nullable();
            $table->string('mpesa_receipt')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
