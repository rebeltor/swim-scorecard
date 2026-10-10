<?php
declare(strict_types=1);

/*
 * Google sign-in. auth.php?action=start sends the browser to Google; Google sends it
 * back here with ?code=... (this file's address is the OAuth redirect URI).
 */
require __DIR__ . '/src/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$auth = new Auth(app_config());

try {
    if (isset($_GET['code']) || isset($_GET['error'])) {
        $auth->finishSignIn();
    }
    if (($_GET['action'] ?? '') === 'start') {
        $returnTo = $_GET['return'] ?? '';
        $auth->startSignIn(is_string($returnTo) ? $returnTo : '');
    }
    header('Location: ./', true, 302);
} catch (Throwable $e) {
    error_log('[swim-scorecard] ' . $e);
    header('Location: ./?signin=error', true, 302);
}
