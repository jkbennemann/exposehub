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
        Schema::create('tunnel_disconnects', function (Blueprint $table) {
            $table->id();
            // The id the tunnel server gave the agent's session in its status report.
            $table->string('session', 64)->unique();
            // The token the session was seen under; the server closes it only under that token.
            $table->foreignId('agent_token_id')->constrained()->cascadeOnDelete();
            // Who asked. Rows live for minutes, so this is no history.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tunnel_disconnects');
    }
};
