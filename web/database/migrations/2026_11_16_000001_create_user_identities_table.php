<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who an account is at the identity provider (single sign-on). The
     * provider's own id for the person ties the two together, never the email
     * address, which can change or be given to someone else.
     */
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('issuer', 255);
            $table->string('subject', 255);
            // The last sign-in through the provider; agent tokens are good for a set time after it.
            $table->timestamp('signed_in_at');
            // When the person was last told that their tokens are about to stop working.
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['issuer', 'subject']);
            $table->unique(['user_id', 'issuer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};
