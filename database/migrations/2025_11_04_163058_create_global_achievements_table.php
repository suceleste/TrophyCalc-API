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
        Schema::create('global_achievements', function (Blueprint $table) {
            $table->unsignedBigInteger('app_id');
            $table->string('api_name', 255);

            $table->decimal('global_percent', 8, 5);
            $table->integer('xp_value');

            $table->timestamps();

            $table->primary(["app_id", "api_name"]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('global_achievements');
    }
};
