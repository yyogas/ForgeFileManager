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
            if (function_exists('posix_geteuid') && posix_geteuid() === 0 && !IsolationGuard::localDevelopment()) {
                throw new FileError('Refusing to run the web file manager as root.', 403);
            }
            // Virtual demo is removed. The public, known demo/demo123 combination
            // is permitted only for an explicitly opted-in local test sandbox.
            if (getenv('FFM_DEMO_MODE') === '1') {
                throw new FileError('The old virtual demo mode was removed; configure a live account or an isolated local test sandbox.', 503);
            }
            $sandbox = DeploymentMode::sandboxEnabled();
            $showcase = PublicShowcase::enabled();
            if ($sandbox && $showcase) {
                throw new FileError('Local test sandbox and public showcase cannot be combined.', 503);
            }
            $username = ($sandbox || $showcase) ? 'demo' : (string) getenv('FFM_USERNAME');
            $hash = ($sandbox || $showcase) ? '' : (string) getenv('FFM_PASSWORD_HASH');
            $storageRoot = $showcase ? PublicShowcase::root() : ($sandbox ? DeploymentMode::sandboxRoot() : (string) getenv('FFM_STORAGE_ROOT'));
            if (!$sandbox && !$showcase && ($username === '' || $hash === '' || $storageRoot === '' || !is_dir($storageRoot))) {
                throw new FileError('Forge File Manager is not configured. Set FFM_USERNAME, FFM_PASSWORD_HASH and FFM_STORAGE_ROOT.', 503);
            }
            if (!$sandbox && !$showcase && $username === 'demo' && password_verify('demo123', $hash)) {
                throw new FileError('Public demo/demo123 credentials cannot access hosting files.', 503);
            }
            IsolationGuard::assertAccount($storageRoot, $sandbox || $showcase);
            $accountBinding = IsolationGuard::binding($username, $storageRoot, $hash, $sandbox, $showcase);
            $sessionMode = $showcase ? 'public-showcase' : ($sandbox ? 'sandbox' : 'live');
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
                    !(($sandbox || $showcase) ? hash_equals('demo123', $body['password']) : password_verify($body['password'], $hash))) {
                    $_SESSION['failed_login_count'] = $failures + 1;
                    $_SESSION['failed_login_at'] = time();
                    throw new FileError('Invalid credentials.', 401);
                }
                session_regenerate_id(true);
                $_SESSION = [
                    'username' => $username,
                    'mode' => $sessionMode,
                    'account_binding' => $accountBinding,
                    'csrf' => bin2hex(random_bytes(32)),
                    'authenticated_at' => time(),
                ];
                self::json(['ok' => true, 'username' => $username, 'csrf' => $_SESSION['csrf']]);
            }
            if (($_SESSION['username'] ?? '') !== $username ||
                ($_SESSION['mode'] ?? '') !== $sessionMode ||
                !hash_equals($accountBinding, (string) ($_SESSION['account_binding'] ?? '')) ||
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
            $fs = new Filesystem(
                $storageRoot,
                (int) (getenv('FFM_EDITOR_MAX_BYTES') ?: 1048576),
                (int) (getenv('FFM_MAX_ENTRIES') ?: 2000),
                (int) (getenv('FFM_UPLOAD_MAX_BYTES') ?: 104857600),
                (int) (getenv('FFM_LIST_SCAN_LIMIT') ?: 50000)
            );
            // A private recycle bin must be explicitly configured; absent this
            // capability, HTTP deletes fail closed and never become permanent.
            $trashSetting = $showcase ? '' : (string) getenv($sandbox ? 'FFM_TEST_TRASH_ROOT' : 'FFM_TRASH_ROOT');
            $trash = $trashSetting === '' ? null : new Trash($fs->root(), $trashSetting);
            if ($method === 'GET' && $action === 'trash-list') {
                if ($trash === null) throw new FileError('Private recycle bin is not configured.', 503);
                $page = (string) ($_GET['page'] ?? '1');
                if (!preg_match('/^[1-9][0-9]{0,6}$/D', $page) || (int) $page > 1000000) {
                    throw new FileError('Invalid recycle-bin page.', 400);
                }
                self::json(['ok' => true, 'data' => $trash->listing((int) $page)]);
            }
            if ($method === 'GET') {
                if ($action === 'me') {
                    self::json(['ok' => true, 'username' => $username, 'csrf' => $_SESSION['csrf'], 'version' => '0.2.8-alpha', 'capabilities' => [
                        'readOnly' => $showcase, 'publicShowcase' => $showcase,
                        'chmod' => !$showcase, 'upload' => !$showcase, 'zip' => !$showcase && class_exists(\ZipArchive::class),
                        'zipExtract' => !$showcase && class_exists(\ZipArchive::class), 'bulkTransfer' => !$showcase, 'trash' => $trash !== null, 'testSandbox' => $sandbox, 'copyDirectories' => !$showcase, 'checksum' => true,
                        'maxUploadBytes' => $showcase ? 0 : $fs->maxUploadBytes(),
                    ]]);
                }
                if ($action === 'list') {
                    $path = self::getString('path');
                    if ($showcase) PublicShowcase::assertAllowedPath($path);
                    $params = self::listParameters();
                    if ($showcase) $params[3] = false; // Never enumerate hidden showcase manifest.
                    self::json(['ok' => true, 'data' => $fs->listing($path, ...$params)]);
                }
                if ($action === 'checksum') {
                    if ($showcase) PublicShowcase::assertAllowedPath(self::getString('path'));
                    self::json(['ok' => true, 'data' => $fs->checksum(self::getString('path'))]);
                }
                if ($action === 'read') {
                    if ($showcase) PublicShowcase::assertAllowedPath(self::getString('path'));
                    self::json(['ok' => true, 'data' => $fs->readText(self::getString('path'))]);
                }
                if ($action === 'download') {
                    if ($showcase) PublicShowcase::assertAllowedPath(self::getString('path'));
                    [$handle, $name, $size] = $fs->openFileForRead(self::getString('path'));
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . addcslashes($name, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($name));
                    header('Content-Length: ' . (string) $size);
                    // Stream exactly the verified file size, even if a local
                    // process changes the file while the download is running.
                    $remaining = $size;
                    try {
                        while ($remaining > 0 && !feof($handle)) {
                            $chunk = fread($handle, min(65536, $remaining));
                            if ($chunk === false || $chunk === '') break;
                            echo $chunk;
                            $remaining -= strlen($chunk);
                        }
                    } finally {
                        fclose($handle);
                    }
                    exit;
                }
                if ($action === 'preview') {
                    if ($showcase) PublicShowcase::assertAllowedPath(self::getString('path'));
                    [$handle, , $size] = $fs->openFileForRead(self::getString('path'));
                    try {
                        if ($size > 10 * 1024 * 1024) {
                            throw new FileError('Image preview exceeds the 10 MiB limit.', 413);
                        }
                        $contents = stream_get_contents($handle, 10 * 1024 * 1024 + 1);
                        $mime = $contents === false ? false : (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
                        $types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        if ($contents === false || strlen($contents) !== $size || !in_array($mime, $types, true)) {
                            throw new FileError('Preview requires a valid JPEG, PNG, GIF or WebP under 10 MiB.', 415);
                        }
                    } finally {
                        fclose($handle);
                    }
                    header('Content-Type: ' . $mime);
                    header('Content-Security-Policy: sandbox');
                    echo $contents;
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
            // Publicly known credentials never authorize any filesystem mutation.
            // Keep this server-side guard ahead of all write handlers.
            if ($showcase) {
                throw new FileError('Public showcase is read-only; changes are forbidden.', 403);
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
                'delete' => $trash !== null ? $trash->discard($fs, $str('path')) : throw new FileError('Private recycle bin is not configured; deletion blocked.', 503),
                'trash-restore' => $trash !== null ? $trash->restore($fs, $str('id')) : throw new FileError('Private recycle bin is not configured.', 503),
                'copy' => $fs->transfer($str('path'), $str('directory'), 'copy'),
                'move' => $fs->transfer($str('path'), $str('directory'), 'move'),
                'zip' => $fs->zip($str('path')),
                'extract' => ArchiveExtractor::extract($fs, $str('path'), $str('directory')),
                'bulk-transfer' => BulkTransfer::execute($fs, $body['paths'] ?? null, $str('directory'), $str('mode')),
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

    /** @return array{int, string, string, bool} */
    private static function listParameters(): array
    {
        $page = (string) ($_GET['page'] ?? '1');
        $search = $_GET['search'] ?? '';
        $sort = $_GET['sort'] ?? 'name';
        $hidden = (string) ($_GET['hidden'] ?? '0');
        if (!preg_match('/^[1-9][0-9]{0,6}$/D', $page) || (int) $page > 1000000 ||
            !is_string($search) || strlen($search) > 200 || preg_match('//u', $search) !== 1 ||
            !is_string($sort) || !in_array($sort, DirectoryPager::SORTS, true) ||
            !in_array($hidden, ['0', '1'], true)) {
            throw new FileError('Invalid directory listing parameters.', 400);
        }
        return [(int) $page, $search, $sort, $hidden === '1'];
    }

    private static function getString(string $name): string
    {
        if (!is_string($_GET[$name] ?? null)) {
            throw new FileError('Missing query parameter: ' . $name, 400);
        }
        return $_GET[$name];
    }
}
