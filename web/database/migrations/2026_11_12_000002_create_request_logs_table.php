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
        Schema::create('request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // The person whose tunnel received the request: the only one who sees it,
            // unless they share their logs.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('host', 253);
            $table->string('method', 16);
            $table->string('path', 2048);
            $table->unsignedSmallInteger('status');
            $table->double('duration_ms');
            $table->unsignedBigInteger('request_bytes')->default(0);
            $table->unsignedBigInteger('response_bytes')->default(0);
            // The visitor's network, never the full address.
            $table->string('remote', 45)->nullable();
            $table->timestamp('started_at', 3);
            // Headers and bodies, only for tunnels started with --log-bodies.
            $table->json('details')->nullable();

            $table->index(['team_id', 'user_id', 'started_at']);
            $table->index('started_at');
        });

        Schema::create('log_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('viewer_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'owner_id', 'viewer_id']);
            $table->index(['team_id', 'viewer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('log_grants');
        Schema::dropIfExists('request_logs');
    }
};
