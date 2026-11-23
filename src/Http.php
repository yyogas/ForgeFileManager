<?php
declare(strict_types=1);

namespace ForgeFileManager;

use Throwable;

final class Http
{
    public static function bootstrap(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_name('FFMSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api.php'))), '/') . '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, private');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if (!$https && getenv('FFM_ALLOW_HTTP_DEV') !== '1') {
            throw new FileError('HTTPS is mandatory. FFM_ALLOW_HTTP_DEV=1 is for local testing only.', 403);
        }
    }

    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function handle(): never
    {
        try {
            self::bootstrap();
            if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('FFM_ALLOW_ROOT_DEV') !== '1') {
                throw new FileError('Refusing to run the web file manager as root.', 403);
            }
            // Explicitly enabled demo uses *virtual*, read-only sample files.
            // The demo credentials never authorize access to FFM_STORAGE_ROOT.
            $demo = DeploymentMode::demoEnabled();
            $username = $demo ? 'demo' : (string) getenv('FFM_USERNAME');
            $hash = $demo ? '' : (string) getenv('FFM_PASSWORD_HASH');
            $storageRoot = $demo ? '' : (string) getenv('FFM_STORAGE_ROOT');
            if (!$demo && ($username === '' || $hash === '' || $storageRoot === '' || !is_dir($storageRoot))) {
                throw new FileError('Forge File Manager is not configured. Configure FFM_USERNAME, FFM_PASSWORD_HASH and FFM_STORAGE_ROOT.', 503);
            }
            if (!$demo && $username === 'demo' && password_verify('demo123', $hash)) {
                throw new FileError('demo/demo123 is reserved for the virtual read-only demo and cannot access real hosting files.', 503);
            }
            $action = (string) ($_GET['action'] ?? 'me');
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if ($action === 'login' && $method === 'POST') {
                $body = self::body();
                $failures = (int) ($_SESSION['failed_login_count'] ?? 0);
                $last = (int) ($_SESSION['failed_login_at'] ?? 0);
                if ($failures >= 5 && time() - $last < 300) {
                    throw new FileError('Too many attempts. Retry later.', 429);
                }
                if (!is_string($body['username'] ?? null) || !is_string($body['password'] ?? null) ||
                    !hash_equals($username, $body['username']) ||
                    !($demo ? hash_equals('demo123', $body['password']) : password_verify($body['password'], $hash))) {
                    $_SESSION['failed_login_count'] = $failures + 1;
                    $_SESSION['failed_login_at'] = time();
                    throw new FileError('Invalid credentials.', 401);
                }
                session_regenerate_id(true);
                $_SESSION = [
                    'username' => $username,
                    'mode' => $demo ? 'demo' : 'live',
                    'csrf' => bin2hex(random_bytes(32)),
                    'authenticated_at' => time(),
                ];
                self::json(['ok' => true, 'username' => $username, 'csrf' => $_SESSION['csrf']]);
            }
            if (($_SESSION['username'] ?? '') !== $username ||
                ($_SESSION['mode'] ?? '') !== ($demo ? 'demo' : 'live') ||
                !isset($_SESSION['authenticated_at'])) {
                throw new FileError('Authentication required.', 401);
            }
            if (time() - (int) $_SESSION['authenticated_at'] > 43200) {
                $_SESSION = [];
                session_regenerate_id(true);
                throw new FileError('Session expired.', 401);
            }
            if ($method === 'POST') {
                $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!is_string($csrf) || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $csrf)) {
                    throw new FileError('Invalid CSRF token.', 403);
                }
            }
            if ($demo) {
                // No real Filesystem object is constructed in demo mode, even when
                // an unsafe storage root is supplied in the environment.
                if ($method === 'GET' && $action === 'me') {
                    self::json(['ok' => true, 'username' => 'demo', 'csrf' => $_SESSION['csrf'],
                        'version' => '0.2.2-alpha', 'demo' => true,
                        'capabilities' => ['demo' => true, 'readOnly' => true, 'chmod' => false,
                            'upload' => false, 'zip' => false, 'zipExtract' => false, 'trash' => false,
                            'copyDirectories' => false, 'checksum' => true, 'maxUploadBytes' => 0]]);
                }
                if ($method === 'GET' && $action === 'list') {
                    self::json(['ok' => true, 'data' => DemoFilesystem::listing(self::getString('path'))]);
                }
                if ($method === 'GET' && $action === 'read') {
                    self::json(['ok' => true, 'data' => DemoFilesystem::readText(self::getString('path'))]);
                }
                if ($method === 'GET' && $action === 'checksum') {
                    self::json(['ok' => true, 'data' => DemoFilesystem::checksum(self::getString('path'))]);
                }
                if ($method === 'POST' && $action === 'logout') {
                    $_SESSION = [];
                    session_regenerate_id(true);
                    self::json(['ok' => true]);
                }
                throw new FileError('Demo mode is read-only; this operation is disabled.', 403);
            }
            $fs = new Filesystem(
                $storageRoot,
                (int) (getenv('FFM_EDITOR_MAX_BYTES') ?: 1048576),
                (int) (getenv('FFM_MAX_ENTRIES') ?: 2000),
                (int) (getenv('FFM_UPLOAD_MAX_BYTES') ?: 104857600)
            );
            if ($method === 'GET') {
                if ($action === 'me') {
                    self::json(['ok' => true, 'username' => $username, 'csrf' => $_SESSION['csrf'], 'version' => '0.2.2-alpha', 'capabilities' => [
                        'chmod' => true, 'upload' => true, 'zip' => class_exists(\ZipArchive::class),
                        'zipExtract' => false, 'trash' => false, 'copyDirectories' => true, 'checksum' => true,
                        'maxUploadBytes' => $fs->maxUploadBytes(),
                    ]]);
                }
                if ($action === 'list') {
                    self::json(['ok' => true, 'data' => $fs->listing(self::getString('path'))]);
                }
                if ($action === 'checksum') {
                    self::json(['ok' => true, 'data' => $fs->checksum(self::getString('path'))]);
                }
                if ($action === 'read') {
                    self::json(['ok' => true, 'data' => $fs->readText(self::getString('path'))]);
                }
                if ($action === 'download') {
                    [$path, $name] = $fs->downloadPath(self::getString('path'));
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . addcslashes($name, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($name));
                    header('Content-Length: ' . (string) filesize($path));
                    $handle = fopen($path, 'rb');
                    if ($handle === false) {
                        throw new FileError('Download failed.', 500);
                    }
                    fpassthru($handle);
                    fclose($handle);
                    exit;
                }
                if ($action === 'preview') {
                    [$path] = $fs->downloadPath(self::getString('path'));
                    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
                    $types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    if (!in_array($mime, $types, true) || filesize($path) > 10 * 1024 * 1024) {
                        throw new FileError('Preview is available for JPEG, PNG, GIF and WebP images up to 10 MB.', 415);
                    }
                    header('Content-Type: ' . $mime);
                    header('Content-Security-Policy: sandbox');
                    readfile($path);
                    exit;
                }
            }
            if ($method !== 'POST') {
                throw new FileError('Unsupported request.', 405);
            }
            if ($action === 'logout') {
                $_SESSION = [];
                session_regenerate_id(true);
                self::json(['ok' => true]);
            }
            if ($action === 'upload') {
                if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                    throw new FileError('Upload file is required.', 400);
                }
                self::json(['ok' => true, 'data' => $fs->upload((string) ($_POST['path'] ?? ''), $_FILES['file'])]);
            }
            $body = self::body();
            $str = static fn(string $field): string => is_string($body[$field] ?? null) ? $body[$field] : throw new FileError('Missing or invalid field: ' . $field, 400);
            $data = match ($action) {
                'create' => $fs->create($str('path'), $str('name'), $str('kind')),
                'rename' => $fs->renamePath($str('path'), $str('name')),
                'chmod' => $fs->chmodPath($str('path'), $str('mode'), getenv('FFM_ALLOW_WORLD_WRITABLE') === '1'),
                'save' => $fs->saveText($str('path'), $str('content'), $str('hash')),
                'delete' => $fs->remove($str('path')),
                'copy' => $fs->transfer($str('path'), $str('directory'), 'copy'),
                'move' => $fs->transfer($str('path'), $str('directory'), 'move'),
                'zip' => $fs->zip($str('path')),
                default => throw new FileError('Unknown operation.', 404),
            };
            self::json(['ok' => true, 'data' => $data]);
        } catch (FileError $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (Throwable $e) {
            error_log('[ForgeFileManager] Unexpected exception: ' . $e->getMessage());
            self::json(['ok' => false, 'error' => 'An internal error occurred.'], 500);
        }
    }

    /** @return array<string, mixed> */
    private static function body(): array
    {
        // A bounded read prevents oversized JSON requests exhausting PHP memory.
        $input = file_get_contents('php://input', false, null, 0, 4 * 1024 * 1024 + 1);
        if ($input === false || strlen($input) > 4 * 1024 * 1024) {
            throw new FileError('JSON request body exceeds the 4 MB limit.', 413);
        }
        $data = json_decode($input === false ? '' : $input, true);
        if (!is_array($data)) {
            throw new FileError('Request body must be valid JSON.', 400);
        }
        return $data;
    }

    private static function getString(string $name): string
    {
        if (!is_string($_GET[$name] ?? null)) {
            throw new FileError('Missing query parameter: ' . $name, 400);
        }
        return $_GET[$name];
    }
}
