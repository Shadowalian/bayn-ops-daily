<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env(
            'CORS_ALLOWED_ORIGINS',
            env('FRONTEND_URL', 'http://127.0.0.1:5173').',http://localhost:5173,http://127.0.0.1:5173,https://bayn-ops-daily.vercel.app'
        ))
    ))),
    'allowed_origins_patterns' => [
        '#^https://bayn-ops-daily(-[a-z0-9]+)?-shadowalians-projects\.vercel\.app$#',
        '#^https://.*\.vercel\.app$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
