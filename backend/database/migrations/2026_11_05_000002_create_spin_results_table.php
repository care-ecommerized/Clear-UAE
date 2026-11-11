<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Spin to Win play. The unique email is what limits each
 * address to a single spin, including two requests arriving together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spin_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('prize_label', 50);
            $table->string('code', 50);
            $table->uuid('discount_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spin_results');
    }
};
