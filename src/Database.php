<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

use Illuminate\Database\Capsule\Manager as Capsule;

final class Database
{
    /**
     * Bootstrap
     *
     * @return void
     */
    public static function bootstrap(): void
    {
        $capsule = new Capsule;
        $capsule->addConnection([
            'driver' => defined('DBDRIVER') ? DBDRIVER : 'mysql',
            'host' => defined('DBHOST') ? DBHOST : 'localhost',
            'database' => defined('DBNAME') ? DBNAME : 'app',
            'username' => defined('DBUSER') ? DBUSER : 'root',
            'password' => defined('DBPASS') ? DBPASS : '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);

        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }
}
