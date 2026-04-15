<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photographer_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image_url');
            $table->string('thumbnail_url')->nullable();
            $table->string('category')->nullable();
            $table->json('tags')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('saves')->default(0);
            $table->unsignedInteger('inquiries')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolios');
    }
};
