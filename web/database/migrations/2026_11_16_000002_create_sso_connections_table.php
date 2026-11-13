<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A team's own identity provider (single sign-on). The team's owner
     * enters it; it takes effect once a platform admin has approved it, and
     * people who sign in through it become members of the team.
     */
    public function up(): void
    {
        Schema::create('sso_connections', function (Blueprint $table) {
            $table->id();
            // One per team.
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            // What the button and messages call the provider, e.g. "Okta".
            $table->string('name', 60);
            $table->string('issuer', 255);
            $table->string('client_id', 255);
            // Encrypted with the application key.
            $table->text('client_secret');
            // The email domains the team asks the provider to answer for.
            $table->json('domains');
            $table->boolean('require_verified_email')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Set by a platform admin. Cleared when the issuer, the domains or
            // the trust in unconfirmed addresses change: what was approved is gone.
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_connections');
    }
};
