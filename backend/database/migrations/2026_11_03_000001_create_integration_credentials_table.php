<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment / shipping credentials entered in Admin → Integrations.
 * Values are encrypted with APP_KEY; IntegrationConfigServiceProvider
 * overlays them onto config('services.<provider>.<field>') at boot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 50);
            $table->string('field', 50);
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_credentials');
    }
};
