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
        // Named lists of addresses and ranges a team allows tunnels to be reached
        // from, such as the exit addresses of a company VPN.
        Schema::create('networks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            // [{cidr: "203.0.113.0/24", note: "Münster office"}, ...]
            $table->json('entries');
            $table->timestamps();
            $table->unique(['team_id', 'name']);
        });

        // Subdomains a team keeps for itself, with their access rule.
        Schema::create('saved_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hostname', 253)->unique();
            // default (the team's rule), anyone, or networks
            $table->string('access', 16)->default('default');
            $table->timestamps();
        });

        Schema::table('custom_domains', function (Blueprint $table) {
            $table->string('access', 16)->default('default')->after('routed_at');
        });

        // The team's rule for every address without a rule of its own.
        Schema::table('teams', function (Blueprint $table) {
            $table->string('access', 16)->default('anyone')->after('is_personal');
        });

        // Which networks a rule allows, for a team default, a saved address or a domain.
        Schema::create('access_networks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained()->cascadeOnDelete();
            $table->morphs('accessible');
            $table->unique(['network_id', 'accessible_type', 'accessible_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_networks');
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('access'));
        Schema::table('custom_domains', fn (Blueprint $table) => $table->dropColumn('access'));
        Schema::dropIfExists('saved_addresses');
        Schema::dropIfExists('networks');
    }
};
