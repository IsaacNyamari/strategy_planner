<?php

return [
    // Country code used to turn local numbers (0712...) into +254712...
    'phone_country_code' => env('WORSHIP_PHONE_COUNTRY_CODE', '254'),

    // Currency shown on contributions, statements and exports.
    'currency' => env('WORSHIP_CURRENCY', 'KES'),

    // Shown at the top of every PDF download.
    'team_name' => env('WORSHIP_TEAM_NAME', 'Praise & Worship Team'),

    /*
    | Default service times used when the monthly roster is generated.
    | Keys are ISO weekday numbers (1 = Monday ... 7 = Sunday).
    */
    'services' => [
        7 => ['type' => 'sunday', 'starts_at' => '09:00', 'ends_at' => '12:00'],
        3 => ['type' => 'wednesday', 'starts_at' => '17:30', 'ends_at' => '19:30'],
        5 => ['type' => 'friday', 'starts_at' => '17:30', 'ends_at' => '19:30'],
    ],

    // How many backup ministers join the lead minister at each service.
    'backups_per_service' => (int) env('WORSHIP_BACKUPS_PER_SERVICE', 3),

    // "Deploy" button (Deployments page). Off unless enabled on the server by
    // deploy/install-web-deploy.sh, and only for the listed admin emails.
    'deploy' => [
        'enabled' => (bool) env('WEB_DEPLOY_ENABLED', false),
        'admins' => array_values(array_filter(array_map(
            fn ($email) => strtolower(trim($email)),
            explode(',', (string) env('WEB_DEPLOY_ADMINS', '')),
        ))),
        'command' => env('WEB_DEPLOY_COMMAND', 'sudo -n /usr/local/bin/strategy-planner-deploy'),
        'repository' => env('WEB_DEPLOY_REPOSITORY', 'https://github.com/IsaacNyamari/strategy_planner'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        // Tried in order; the next model is used when one is rate-limited or unavailable.
        'models' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEMINI_MODELS', 'gemini-flash-lite-latest,gemini-flash-latest'))))),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 45),
    ],
];
