<?php
/*
 * Swim Scorecard settings.
 * Edit this file to change how the app runs. Changes apply on the next page load.
 */
return [
    // Folder where scorecards are saved as JSON files. The web server must be able to write to it.
    // Safest is a folder outside the web root, for example '/var/lib/swim-scorecard'.
    'data_dir' => __DIR__ . '/data',
    
	// The most scorecards this server will keep. Starting a new one fails once this is reached.
    'max_scorecards' => 100,

    // How often (in seconds) an open scorecard checks for places entered by other people.
    'poll_seconds' => 3,

    // Team names and colors for new scorecards. Each scorecard can change them later
    // (gear icon > Teams). Keep names short: they appear on the scoring buttons.
    'team_names' => ['JD', 'Opp'],
    'team_colors' => ['#C8102E', '#F58220'],

    // How many swimmers (individual events and diving) or relays from each team can score in an
    // event. Anyone past that from the same team gets no points and the next finisher moves up.
    'scorers_per_team' => ['individual' => 3, 'relay' => 2],

    // ---------------------------------------------------------------------------------------
    // Google sign-in (needed to start a scorecard). See "Set up Google sign-in" in INSTALL.md.
    // Replace the three ENTER-YOUR / your-server values below with your own.
    // ---------------------------------------------------------------------------------------

    // OAuth client ID from Google Cloud console > APIs & Services > Credentials.
    'google_client_id' => 'YOUR_CLIENT_ID.apps.googleusercontent.com',

    // OAuth client secret from the same screen. Keep this private.
    'google_client_secret' => 'YOUR_CLIENT_SECRET',

    // Must match an "Authorized redirect URI" on the OAuth client exactly.
    // It is the address of auth.php in this folder.
    'google_redirect_uri' => 'https://yourserver.com/swim-scorecard/auth.php',
	
    // Google accounts allowed to start scorecards and edit any scorecard without its scorer link.
    'admin_emails' => ['your@email.com'],
];
