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
        Schema::table('users', function (Blueprint $table) {
            // Admins granted in the admin area. Platform owners come from
            // config (EXPOSEHUB_ADMIN_EMAILS) and need no flag.
            $table->boolean('is_admin')->default(false)->after('current_team_id');
            // A suspended person cannot sign in and their tokens stop working.
            $table->timestamp('suspended_at')->nullable()->after('is_admin');
            $table->string('suspension_reason', 500)->nullable()->after('suspended_at');
        });

        // Every action an admin takes, kept for a year. Names and addresses are
        // copied in, so an entry still says who and whom after an account is gone.
        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('admin_name');
            $table->string('admin_email');
            $table->string('action', 64);
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['subject_type', 'subject_id']);
        });

        // Service settings changed in the admin area. A key that is not here
        // keeps the value from config (the environment).
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('admin_actions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'suspended_at', 'suspension_reason']);
        });
    }
};
