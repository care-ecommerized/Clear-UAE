<?php

// Adds recovery-tracking columns so admins can run an abandoned-cart
// follow-up workflow:
//   recovery_email_sent_at — last time a recovery reminder went out
//   recovery_email_count   — how many reminders the customer received
//   recovered_at           — admin marked the checkout as recovered
//                            (e.g. customer paid via WhatsApp/manual)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_checkouts', function (Blueprint $table) {
            $table->timestamp('recovery_email_sent_at')->nullable()->after('expires_at');
            $table->unsignedSmallInteger('recovery_email_count')->default(0)->after('recovery_email_sent_at');
            $table->timestamp('recovered_at')->nullable()->after('recovery_email_count');
        });
    }

    public function down(): void
    {
        Schema::table('pending_checkouts', function (Blueprint $table) {
            $table->dropColumn(['recovery_email_sent_at', 'recovery_email_count', 'recovered_at']);
        });
    }
};
