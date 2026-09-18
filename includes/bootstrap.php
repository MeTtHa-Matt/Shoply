<?php
declare(strict_types=1);

const ROOT_PATH = __DIR__ . '/..';

function load_env(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            $value = substr($value, 1, -1);
        }
        if (getenv(trim($key)) === false) {
            putenv(trim($key) . '=' . $value);
        }
    }
}

load_env(ROOT_PATH . '/.env');

$secure = filter_var(getenv('SESSION_SECURE') ?: 'false', FILTER_VALIDATE_BOOLEAN);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; script-src 'self'; manifest-src 'self'; worker-src 'self'");

function env_value(string $key, ?string $fallback = null): ?string
{
    $value = getenv($key);
    return $value === false ? $fallback : $value;
}

function retention_days(string $key, int $fallback): int
{
    $value = filter_var(env_value($key, (string) $fallback), FILTER_VALIDATE_INT);
    return $value !== false ? max(1, min($value, 3650)) : $fallback;
}

function cleanup_expired_data(PDO $pdo): void
{
    $pdo->exec('DELETE FROM email_verification_tokens WHERE expires_at < NOW() OR (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 1 DAY))');
    $pdo->exec('DELETE FROM remember_tokens WHERE expires_at < NOW()');

    $notificationDays = retention_days('NOTIFICATION_RETENTION_DAYS', 90);
    $pdo->exec(sprintf('DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)', $notificationDays));

    $friendRequestDays = retention_days('DECLINED_REQUEST_RETENTION_DAYS', 180);
    $pdo->exec(sprintf("DELETE FROM friendship_requests WHERE status = 'declined' AND responded_at IS NOT NULL AND responded_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $friendRequestDays));

    $unverifiedUserDays = retention_days('UNVERIFIED_USER_RETENTION_DAYS', 7);
    $pdo->exec(sprintf('DELETE FROM users WHERE email_verified_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)', $unverifiedUserDays));
}

function ensure_retention_indexes(PDO $pdo): void
{
    $indexes = [
        ['notifications', 'idx_notification_created_at', 'created_at'],
        ['friendship_requests', 'idx_friend_request_status_response', 'status, responded_at'],
    ];
    foreach ($indexes as [$table, $index, $columns]) {
        $query = $pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1');
        $query->execute([$table, $index]);
        if (!$query->fetchColumn()) {
            $pdo->exec(sprintf('CREATE INDEX %s ON %s (%s)', $index, $table, $columns));
        }
    }
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env_value('DB_HOST', '127.0.0.1'), env_value('DB_PORT', '3306'), env_value('DB_NAME', ''));
    $pdo = new PDO($dsn, env_value('DB_USER', ''), env_value('DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS friend_groups (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, name VARCHAR(80) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_friend_group_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, UNIQUE KEY uq_friend_group_name (user_id, name)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS friend_group_members (group_id INT UNSIGNED NOT NULL, friend_id INT UNSIGNED NOT NULL, assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (group_id, friend_id), CONSTRAINT fk_friend_group_member_group FOREIGN KEY (group_id) REFERENCES friend_groups(id) ON DELETE CASCADE, CONSTRAINT fk_friend_group_member_user FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS remember_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_remember_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX idx_remember_token_user (user_id), INDEX idx_remember_token_expiry (expires_at)) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_onboarding (user_id INT UNSIGNED PRIMARY KEY, completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_onboarding_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
    ensure_retention_indexes($pdo);
    cleanup_expired_data($pdo);
    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
}

function verify_csrf(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token']);
}

function app_base_url(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $scheme = $forwarded !== '' ? $forwarded : (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'));
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $basePath = $script === '/' || $script === '.' ? '' : rtrim($script, '/');
    return $scheme . '://' . $host . $basePath;
}

function app_url(string $path = ''): string
{
    return app_base_url() . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : app_url($path)));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pull_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function old(string $key): string
{
    return e($_SESSION['old'][$key] ?? '');
}

function remember_old(array $values): void
{
    $_SESSION['old'] = $values;
}

function clear_old(): void
{
    unset($_SESSION['old']);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function remember_cookie_name(): string
{
    return 'shoply_remember';
}

function set_remember_cookie(string $token, bool $delete = false): void
{
    $secure = filter_var(env_value('SESSION_SECURE', 'false'), FILTER_VALIDATE_BOOLEAN);
    setcookie(remember_cookie_name(), $delete ? '' : $token, [
        'expires' => $delete ? time() - 3600 : time() + (86400 * 365),
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function restore_remembered_user(): void
{
    if (current_user() || empty($_COOKIE[remember_cookie_name()])) {
        return;
    }
    $token = (string) $_COOKIE[remember_cookie_name()];
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        set_remember_cookie('', true);
        return;
    }
    $query = db()->prepare('SELECT u.id, u.first_name, u.last_name, u.email FROM remember_tokens rt INNER JOIN users u ON u.id = rt.user_id WHERE rt.token_hash = ? AND rt.expires_at > NOW() LIMIT 1');
    $query->execute([hash('sha256', $token)]);
    $user = $query->fetch();
    if (!$user) {
        set_remember_cookie('', true);
        return;
    }
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int) $user['id'], 'name' => trim($user['first_name'] . ' ' . $user['last_name']), 'email' => $user['email']];
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

restore_remembered_user();