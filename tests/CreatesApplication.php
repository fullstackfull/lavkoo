<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // DB_STRICT_TESTS=1 runs the suite with MySQL/MariaDB in strict mode (the application's own connection setting is `strict => false`), to see what a strict server would reject.
        if (getenv('DB_STRICT_TESTS') === '1') {
            $app['config']->set('database.connections.mysql.strict', true);
            $app['db']->purge('mysql');
        }

        return $app;
    }
}
