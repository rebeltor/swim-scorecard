<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Changes must come from the app itself: POST, a JSON body and the app's own header.
 * Other sites can't send that combination, so a signed-in organizer's cookie can't be misused.
 */
function require_app_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new ApiError(405, 'Use POST for this request.');
    }
    if (($_SERVER['HTTP_X_SWIM'] ?? '') !== '1') {
        throw new ApiError(400, 'This request must come from the Swim Scorecard app.');
    }
}

function read_json_body(): array
{
    if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
        throw new ApiError(415, 'The request body must be JSON.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) {
        throw new ApiError(413, 'That request is too large.');
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        throw new ApiError(400, 'The request body must be JSON.');
    }
    return $body;
}

function requested_id(): string
{
    $id = $_GET['id'] ?? '';
    if (!is_string($id) || !Store::isValidId($id)) {
        throw new ApiError(400, 'That scorecard link is not valid.');
    }
    return $id;
}

/** Signed-in organizers can score every scorecard; anyone else needs the scorer key. */
function is_scorer(array $card, Auth $auth): bool
{
    if ($auth->adminEmail() !== null) {
        return true;
    }
    $key = $_SERVER['HTTP_X_EDIT_KEY'] ?? '';
    return is_string($key) && $key !== '' && is_string($card['editKey'] ?? null) && hash_equals($card['editKey'], $key);
}

/** Only the organizer who created a scorecard can lock or unlock it. */
function can_lock(array $card, Auth $auth): bool
{
    $email = $auth->adminEmail();
    if ($email === null) {
        return false;
    }
    $creator = $card['createdBy'] ?? null;
    // Scorecards from before locking existed have no creator on record; any organizer can lock those.
    return !is_string($creator) || $creator === '' || hash_equals(strtolower($creator), $email);
}

function output(array $card, Auth $auth, array $config): array
{
    $card = Scorecard::withDefaults($card, $config);
    return Scorecard::forOutput($card, is_scorer($card, $auth), can_lock($card, $auth));
}

$config = app_config();
$store = new Store((string) $config['data_dir']);
$auth = new Auth($config);

try {
    switch ($_GET['action'] ?? '') {
        case 'health':
            $dataDir = realpath((string) $config['data_dir']);
            $appDir = realpath(__DIR__);
            respond([
                'ok' => $store->isWritable(),
                'php' => PHP_VERSION,
                'dataFolderWritable' => $store->isWritable(),
                'dataFolderInsideApp' => $dataDir !== false && $appDir !== false && str_starts_with($dataDir, $appDir),
                'googleSignInConfigured' => $auth->googleReady(),
                'canReachGoogle' => function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN),
            ]);

        case 'session':
            $email = $auth->adminEmail();
            respond([
                'signedIn' => $email !== null,
                'email' => $email,
                'canCreate' => $email !== null,
                'googleReady' => $auth->googleReady(),
            ]);

        case 'signout':
            require_app_post();
            $auth->signOut();
            respond(['signedIn' => false]);

        case 'list':
            respond(['cards' => $store->list(50)]);

        case 'create':
            require_app_post();
            if ($auth->adminEmail() === null) {
                throw new ApiError(401, 'Sign in with Google to start a scorecard.');
            }
            $store->checkWritable();
            if ($store->count() >= (int) $config['max_scorecards']) {
                throw new ApiError(503, 'This server has reached its scorecard limit. Raise max_scorecards in config.php or remove old files from the data folder.');
            }
            $body = read_json_body();
            $card = Scorecard::create($store->newId(), $body['name'] ?? null, Auth::token(16), (string) $auth->adminEmail(), $config);
            $store->create($card);
            respond(output($card, $auth, $config), 201);

        case 'get':
            $id = requested_id();
            $card = $store->read($id);
            if ($card === null) {
                throw new ApiError(404, 'This scorecard does not exist. Check that the link was copied in full.');
            }
            $scorer = is_scorer($card, $auth);
            $tag = (string) $card['version'] . ($scorer ? 'e' : 'v') . (can_lock($card, $auth) ? 'c' : '');
            $known = $_GET['v'] ?? null;
            if (is_string($known) && $known === $tag) {
                respond(['unchanged' => true, 'version' => $card['version']]);
            }
            if ($scorer && !is_string($card['editKey'] ?? null)) {
                // Scorecards from before scorer links existed get a key the first time an organizer opens them.
                $card = $store->update($id, function (array $c): array {
                    $c['editKey'] ??= Auth::token(16);
                    return Scorecard::normalize($c);
                });
            }
            respond(output($card, $auth, $config));

        case 'change':
            require_app_post();
            $id = requested_id();
            $store->checkWritable();
            $body = read_json_body();
            $card = $store->update($id, function (array $card) use ($auth, $body, $config): array {
                if (!is_scorer($card, $auth)) {
                    throw new ApiError(403, 'This scorecard is view-only for you. Open the scorer link to enter places.');
                }
                if (($card['locked'] ?? false) === true) {
                    throw new ApiError(423, 'This scorecard is locked, so places can’t be changed. The organizer who created it can unlock it.');
                }
                return Scorecard::applyChanges(Scorecard::withDefaults($card, $config), $body['changes'] ?? null);
            });
            respond(output($card, $auth, $config));

        case 'lock':
            require_app_post();
            $id = requested_id();
            $store->checkWritable();
            $body = read_json_body();
            if (!is_bool($body['locked'] ?? null)) {
                throw new ApiError(400, 'Say whether to lock or unlock the scorecard.');
            }
            $card = $store->update($id, function (array $card) use ($auth, $body, $config): array {
                if (!can_lock($card, $auth)) {
                    throw new ApiError(403, 'Only the organizer who created this scorecard can lock or unlock it. Sign in with that Google account.');
                }
                return Scorecard::setLocked(Scorecard::withDefaults($card, $config), $body['locked']);
            });
            respond(output($card, $auth, $config));

        default:
            throw new ApiError(404, 'Unknown request.');
    }
} catch (ApiError $e) {
    respond(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('[swim-scorecard] ' . $e);
    respond(['error' => 'Something went wrong on the server. Check the PHP error log.'], 500);
}
