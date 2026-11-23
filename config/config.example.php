<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'ACTLL · Gestió de socis',
        'base_url' => 'http://localhost:8080',
        'timezone' => 'Europe/Madrid',
        'session_name' => 'actll_session',
        'secure_cookies' => false,
    ],
    'database' => [
        'driver' => 'sqlite',
        'path' => dirname(__DIR__) . '/storage/actll.sqlite',
    ],
];

