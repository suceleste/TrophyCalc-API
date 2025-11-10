<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_game_scores', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('app_id');

            $table->integer('xp_score')->default(0);
            $table->boolean('is_completed')->default(false);
            $table->integer('unlocked_count')->default(0);
            $table->integer('total_count')->default(0);

            $table->timestamps();

            $table->primary(['user_id', 'app_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_game_scores');
    }
};
