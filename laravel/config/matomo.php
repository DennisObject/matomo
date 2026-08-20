<?php

declare(strict_types=1);

$configPath = env('MATOMO_CONFIG_PATH', dirname(__DIR__, 2).'/config/config.ini.php');

if (! is_string($configPath)) {
    $configPath = dirname(__DIR__, 2).'/config/config.ini.php';
}

return [
    'config_path' => $configPath,
    'ai_providers' => [],
    'user_preference_names' => [],
    'newsletter_endpoint' => env(
        'MATOMO_NEWSLETTER_ENDPOINT',
        'https://api.matomo.org/1.0/subscribeNewsletter/',
    ),
    'marketplace_endpoint' => env('MATOMO_MARKETPLACE_ENDPOINT', 'https://plugins.matomo.org/api/2.0'),
    'allowed_email_domains' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('MATOMO_ALLOWED_EMAIL_DOMAINS', '')),
    ))),
];
