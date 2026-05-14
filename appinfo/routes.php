<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'migrate#migrate_file', 'url' => '/migrate/{fileId}', 'verb' => 'POST'],
        // Settings routes
        ['name' => 'settings#save',    'url' => '/settings', 'verb' => 'POST'],
        ['name' => 'settings#status',  'url' => '/status',   'verb' => 'GET'],
        ['name' => 'settings#trigger', 'url' => '/trigger',  'verb' => 'POST'],
        // Client-side vault decryptor page
        ['name' => 'decrypt#index',    'url' => '/decrypt',  'verb' => 'GET'],
    ]
];

