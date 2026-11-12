<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The tunnel server finds a network by its name ignoring case and spaces at
     * the ends, so "VPN" and "vpn" in one team were one network there: a rule
     * naming one could let in the other's addresses. Rename such duplicates,
     * keeping every network and its rule links, and let the database refuse
     * new ones.
     */
    public function up(): void
    {
        $taken = [];

        DB::table('networks')->orderBy('id')->select(['id', 'team_id', 'name'])->get()
            ->each(function (object $network) use (&$taken) {
                $name = Str::trim($network->name);
                $base = $name;
                for ($n = 2; isset($taken[$network->team_id][Str::lower($name)]); $n++) {
                    $suffix = " ({$n})";
                    $name = Str::limit($base, 100 - mb_strlen($suffix), '').$suffix;
                }
                $taken[$network->team_id][Str::lower($name)] = true;

                if ($name !== $network->name) {
                    DB::table('networks')->where('id', $network->id)->update(['name' => $name]);
                }
            });

        // A backstop for the check on the access page. SQLite only folds ASCII
        // letters here, PostgreSQL follows its locale.
        match (DB::getDriverName()) {
            'sqlite', 'pgsql' => DB::statement('create unique index networks_team_id_lower_name_unique on networks (team_id, lower(name))'),
            'mysql', 'mariadb' => DB::statement('create unique index networks_team_id_lower_name_unique on networks (team_id, (lower(name)))'),
            default => null,
        };
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('networks', 'networks_team_id_lower_name_unique')) {
            DB::statement('drop index networks_team_id_lower_name_unique'.(in_array(DB::getDriverName(), ['mysql', 'mariadb']) ? ' on networks' : ''));
        }
    }
};
