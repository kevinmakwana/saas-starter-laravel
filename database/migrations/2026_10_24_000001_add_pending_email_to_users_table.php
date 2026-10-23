<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A new address waits here until its owner opens the link mailed to it; only then does it replace `email`.
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email_verified_at');
            $table->timestamp('pending_email_expires_at')->nullable()->after('pending_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pending_email', 'pending_email_expires_at']);
        });
    }
};
