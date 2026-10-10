<?php
declare(strict_types=1);

/**
 * Google sign-in for the people allowed to start scorecards.
 *
 * Uses the OAuth 2.0 authorization code flow with PKCE. After Google sends the
 * person back to auth.php, the code is exchanged for an ID token directly with
 * Google over HTTPS, the token's claims are checked, and a signed cookie records
 * the signed-in email for 30 days. No PHP sessions or database are needed.
 */
final class Auth
{
    private const ADMIN_COOKIE = 'swim_admin';
    private const LOGIN_COOKIE = 'swim_login';
    private const SIGN_IN_DAYS = 30;
    private const PLACEHOLDERS = ['ENTER-YOUR', 'your-server.example.com'];

    public function __construct(private array $config)
    {
    }

    /** True once the Google settings in config.php have been filled in. */
    public function googleReady(): bool
    {
        foreach (['google_client_id', 'google_client_secret', 'google_redirect_uri'] as $key) {
            $value = trim((string) ($this->config[$key] ?? ''));
            if ($value === '') {
                return false;
            }
            foreach (self::PLACEHOLDERS as $placeholder) {
                if (str_contains($value, $placeholder)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** The signed-in organizer's email, or null if nobody allowed is signed in. */
    public function adminEmail(): ?string
    {
        $data = $this->unseal($_COOKIE[self::ADMIN_COOKIE] ?? null);
        if ($data === null || (int) ($data['x'] ?? 0) < time()) {
            return null;
        }
        $email = strtolower((string) ($data['e'] ?? ''));
        return $this->isAllowed($email) ? $email : null;
    }

    public function isAllowed(string $email): bool
    {
        $allowed = array_map(
            static fn ($e): string => strtolower(trim((string) $e)),
            (array) ($this->config['admin_emails'] ?? [])
        );
        return $email !== '' && in_array(strtolower($email), $allowed, true);
    }

    /** Sends the browser to Google's sign-in page. */
    public function startSignIn(string $returnTo): never
    {
        if (!$this->googleReady()) {
            $this->backToApp('setup', $returnTo);
        }
        $state = self::token(24);
        $nonce = self::token(24);
        $verifier = self::token(48);
        $this->setCookie(self::LOGIN_COOKIE, $this->seal([
            's' => $state, 'n' => $nonce, 'v' => $verifier, 'r' => $returnTo, 'x' => time() + 600,
        ]), time() + 600);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $url = ($this->config['google_auth_url'] ?? 'https://accounts.google.com/o/oauth2/v2/auth') . '?' . http_build_query([
            'client_id' => $this->config['google_client_id'],
            'redirect_uri' => $this->config['google_redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);
        self::redirect($url);
    }

    /** Handles Google sending the browser back to auth.php. */
    public function finishSignIn(): never
    {
        $login = $this->unseal($_COOKIE[self::LOGIN_COOKIE] ?? null);
        $this->clearCookie(self::LOGIN_COOKIE);
        $returnTo = is_array($login) ? (string) ($login['r'] ?? '') : '';

        if (isset($_GET['error'])) {
            $this->backToApp('cancelled', $returnTo);
        }
        $state = $_GET['state'] ?? '';
        if ($login === null || (int) ($login['x'] ?? 0) < time() || !is_string($state) || !hash_equals((string) $login['s'], $state)) {
            $this->backToApp('expired', $returnTo);
        }
        $code = $_GET['code'] ?? '';
        if (!is_string($code) || $code === '' || !$this->googleReady()) {
            $this->backToApp('error', $returnTo);
        }

        try {
            $tokens = $this->postForm($this->config['google_token_url'] ?? 'https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => $this->config['google_client_id'],
                'client_secret' => $this->config['google_client_secret'],
                'redirect_uri' => $this->config['google_redirect_uri'],
                'grant_type' => 'authorization_code',
                'code_verifier' => (string) $login['v'],
            ]);
            $claims = $this->checkIdToken((string) ($tokens['id_token'] ?? ''), (string) $login['n']);
        } catch (RuntimeException $e) {
            error_log('[swim-scorecard] Google sign-in failed: ' . $e->getMessage());
            $this->backToApp('error', $returnTo);
        }

        $email = strtolower((string) $claims['email']);
        if (!$this->isAllowed($email)) {
            $this->backToApp('denied', $returnTo);
        }
        $expires = time() + self::SIGN_IN_DAYS * 86400;
        $this->setCookie(self::ADMIN_COOKIE, $this->seal(['e' => $email, 'x' => $expires]), $expires);
        $this->backToApp('ok', $returnTo);
    }

    public function signOut(): void
    {
        $this->clearCookie(self::ADMIN_COOKIE);
    }

    /**
     * Checks the ID token Google returned from the token endpoint.
     * The token came straight from Google over a verified HTTPS connection, so per
     * OpenID Connect Core 3.1.3.7 the connection vouches for the issuer and the
     * signature check can be skipped. Every claim that matters is still checked.
     */
    private function checkIdToken(string $jwt, string $nonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Google did not return an ID token.');
        }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        if (!is_array($claims)) {
            throw new RuntimeException('The ID token could not be read.');
        }
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        $now = time();
        $checks = [
            'issuer' => in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true),
            'audience' => in_array($this->config['google_client_id'], $audiences, true),
            'expiry' => is_numeric($claims['exp'] ?? null) && (int) $claims['exp'] > $now - 60,
            'issued' => !isset($claims['iat']) || (is_numeric($claims['iat']) && (int) $claims['iat'] < $now + 300),
            'nonce' => is_string($claims['nonce'] ?? null) && hash_equals($nonce, $claims['nonce']),
            'email' => is_string($claims['email'] ?? null) && $claims['email'] !== '',
            'verified email' => ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? '') === 'true',
        ];
        foreach ($checks as $name => $passed) {
            if (!$passed) {
                throw new RuntimeException("ID token failed the $name check.");
            }
        }
        return $claims;
    }

    private function postForm(string $url, array $fields): array
    {
        $body = http_build_query($fields);
        $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
        $status = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($raw === false) {
                throw new RuntimeException('Could not reach Google: ' . curl_error($ch));
            }
        } else {
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body,
                'timeout' => 15,
                'ignore_errors' => true,
            ]]);
            $raw = @file_get_contents($url, false, $context);
            $responseHeaders = function_exists('http_get_last_response_headers')
                ? (http_get_last_response_headers() ?? [])
                : ($http_response_header ?? []);
            if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s?/', $responseHeaders[0], $m) === 1) {
                $status = (int) $m[1];
            }
            if ($raw === false) {
                throw new RuntimeException('Could not reach Google. Check that PHP can make outgoing HTTPS requests.');
            }
        }
        $data = json_decode((string) $raw, true);
        if ($status !== 200 || !is_array($data)) {
            $detail = is_array($data) ? (string) ($data['error_description'] ?? $data['error'] ?? '') : '';
            throw new RuntimeException("Google answered with status $status. $detail");
        }
        return $data;
    }

    private function backToApp(string $result, string $returnTo): never
    {
        $query = ['signin' => $result];
        if (Store::isValidId($returnTo)) {
            $query['s'] = $returnTo;
        }
        self::redirect('./?' . http_build_query($query));
    }

    private static function redirect(string $url): never
    {
        header('Cache-Control: no-store');
        header('Location: ' . $url, true, 302);
        exit;
    }

    // ---- signed cookies ----

    private function seal(array $data): string
    {
        $payload = self::b64(json_encode($data, JSON_THROW_ON_ERROR));
        return $payload . '.' . self::b64(hash_hmac('sha256', $payload, $this->secret(), true));
    }

    private function unseal(mixed $value): ?array
    {
        if (!is_string($value) || substr_count($value, '.') !== 1) {
            return null;
        }
        [$payload, $mac] = explode('.', $value);
        $expected = self::b64(hash_hmac('sha256', $payload, $this->secret(), true));
        if (!hash_equals($expected, $mac)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        return is_array($data) ? $data : null;
    }

    /** Key for signing cookies, created once and kept in the data folder. */
    private function secret(): string
    {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }
        $configured = (string) ($this->config['auth_secret'] ?? '');
        if (strlen($configured) >= 32) {
            return $secret = $configured;
        }
        $file = rtrim((string) $this->config['data_dir'], '/') . '/auth-secret.key';
        if (is_file($file)) {
            $stored = trim((string) file_get_contents($file));
            if (strlen($stored) >= 32) {
                return $secret = $stored;
            }
        }
        $new = bin2hex(random_bytes(32));
        $handle = @fopen($file, 'x');
        if ($handle !== false) {
            fwrite($handle, $new);
            fclose($handle);
            @chmod($file, 0600);
            return $secret = $new;
        }
        // Another request created the file first, or the folder isn't writable.
        $stored = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if (strlen($stored) < 32) {
            throw new ApiError(500, 'The web server cannot save to the data folder. See step 3 in INSTALL.md.');
        }
        return $secret = $stored;
    }

    private function setCookie(string $name, string $value, int $expires): void
    {
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => self::cookiePath(),
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearCookie(string $name): void
    {
        $this->setCookie($name, '', time() - 3600);
    }

    private static function cookiePath(): string
    {
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
        return rtrim($dir, '/') . '/';
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function token(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $last = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $last)];
        }
        return $out;
    }
}
