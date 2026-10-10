<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Swim Scorecard needs PHP 8.1 or newer. This server is running PHP ' . PHP_VERSION . '.';
    exit;
}

require __DIR__ . '/ApiError.php';
require __DIR__ . '/Store.php';
require __DIR__ . '/Scorecard.php';
require __DIR__ . '/Auth.php';

/**
 * Settings from config.php merged over the defaults.
 */
function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $defaults = [
            'data_dir' => dirname(__DIR__) . '/data',
            'max_scorecards' => 5000,
            'poll_seconds' => 3,
            'google_client_id' => '',
            'google_client_secret' => '',
            'google_redirect_uri' => '',
            'admin_emails' => [],
            'team_names' => ['JD', 'Opp'],
            'team_colors' => ['#C8102E', '#F58220'],
            'scorers_per_team' => ['individual' => 3, 'relay' => 2],
        ];
        $file = dirname(__DIR__) . '/config.php';
        $loaded = is_file($file) ? require $file : [];
        $config = array_merge($defaults, is_array($loaded) ? $loaded : []);
    }
    return $config;
}
