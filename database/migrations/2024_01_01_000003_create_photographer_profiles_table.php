<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photographer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('gender')->nullable();
            $table->string('location')->nullable();
            $table->unsignedBigInteger('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->text('bio')->nullable();
            $table->string('profile_photo')->nullable();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->json('service_rates')->nullable();
            $table->string('subscription_status')->default('inactive'); // active | inactive | expired | cancelled
            $table->timestamp('subscription_end_date')->nullable();
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('total_ratings')->default(0);
            $table->json('badges')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photographer_profiles');
    }
};
