<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            // The client who made the booking
            $table->foreignId('client_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // The photographer being booked
            $table->foreignId('photographer_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // When the session is scheduled
            $table->timestamp('booking_date');

            // pending | confirmed | completed | cancelled
            $table->string('status', 20)->default('pending');

            // Optional notes from the client
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes for fast lookups
            $table->index('client_id');
            $table->index('photographer_id');
            $table->index('status');
            $table->index('booking_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};