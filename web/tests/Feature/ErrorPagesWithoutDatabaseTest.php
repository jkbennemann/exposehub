<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Error pages are shown exactly when something is broken, so they must not
 * need the database. This class deliberately does not set one up.
 */
class ErrorPagesWithoutDatabaseTest extends TestCase
{
    public function test_error_pages_render_while_the_database_is_unreachable(): void
    {
        config(['database.connections.sqlite.database' => '/nonexistent/exposehub.sqlite']);
        DB::purge();

        $this->get('/no/such/page')->assertNotFound()->assertSee('This page does not exist');
    }
}
