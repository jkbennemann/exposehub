<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Deleting a team used to leave its saved addresses and networks behind.
     * The team is only soft-deleted, so nothing cascaded, and the names stayed
     * taken for every other team. Release them.
     */
    public function up(): void
    {
        $deletedTeams = DB::table('teams')->whereNotNull('deleted_at')->select('id');

        // Deleting a network also deletes its rule links (access_networks).
        DB::table('networks')->whereIn('team_id', $deletedTeams)->delete();
        DB::table('saved_addresses')->whereIn('team_id', $deletedTeams)->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The released rows are gone for good.
    }
};
