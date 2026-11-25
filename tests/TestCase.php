<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase {
        migrateFreshUsing as defaultMigrateFreshUsing;
    }

    /** Migrations run as the table owner, like in production; tests then query as the restricted app role. */
    protected function migrateFreshUsing()
    {
        return [...$this->defaultMigrateFreshUsing(), '--database' => 'owner'];
    }
}
