<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // When Stripe created the newest subscription event applied to this team; older events that arrive late are ignored.
            $table->timestamp('stripe_event_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('stripe_event_at');
        });
    }
};
