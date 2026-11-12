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
        Schema::create('custom_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('hostname');
            // Published by the team in a TXT record to prove it controls the domain's DNS.
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            // Several teams may claim a hostname; only one can ever verify it.
            $table->unique(['team_id', 'hostname']);
            $table->index(['hostname', 'verified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_domains');
    }
};
