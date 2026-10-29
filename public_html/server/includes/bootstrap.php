<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Omsk');

function db(): PDO
{
    static $pdo;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = '127.0.0.1';
    $port = '3308';
    $name = 'esenalex0g';
    $user = 'esenalex0g';
    $pass = 'P#2tSkVJwHN3WT6G';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function auth_session_id(): ?string
{
    return $_SESSION['auth_session_id'] ?? null;
}

function device_label(): string
{
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/(Edg|Edge)\/([\d.]+)/i', $agent, $match)) return 'Microsoft Edge ' . $match[2];
    if (preg_match('/Chrome\/([\d.]+)/i', $agent, $match)) return 'Google Chrome ' . $match[1];
    if (preg_match('/Firefox\/([\d.]+)/i', $agent, $match)) return 'Mozilla Firefox ' . $match[1];
    if (preg_match('/Safari\/([\d.]+)/i', $agent, $match)) return 'Safari ' . $match[1];
    return 'Браузер';
}

function start_auth_session(array $user): void
{
    $id = bin2hex(random_bytes(32));
    $device = $_COOKIE['digest_device'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $device)) {
        $device = bin2hex(random_bytes(32));
        setcookie('digest_device', $device, ['expires' => time() + 31536000, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['digest_device'] = $device;
    }
    $switchToken = bin2hex(random_bytes(32));
    $_SESSION['auth_session_id'] = $id;
    $query = db()->prepare('INSERT INTO auth_sessions (id,user_id,device_label,user_agent,ip_address,device_key,switch_token) VALUES (:id,:user,:device,:agent,:ip,:device_key,:switch_token) ON DUPLICATE KEY UPDATE id=VALUES(id),device_label=VALUES(device_label),user_agent=VALUES(user_agent),ip_address=VALUES(ip_address),switch_token=VALUES(switch_token),last_seen=CURRENT_TIMESTAMP');
    $query->execute([
        'id' => $id,
        'user' => $user['id'],
        'device' => device_label(),
        'agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        'ip' => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        'device_key' => $device,
        'switch_token' => $switchToken,
    ]);
}

function touch_auth_session(): void
{
    if (!current_user() || !auth_session_id()) return;
    refresh_expired_bans();
    $active = db()->prepare('SELECT 1 FROM auth_sessions WHERE id=:id AND user_id=:user');
    $active->execute(['id' => auth_session_id(), 'user' => current_user()['id']]);
    if (!$active->fetchColumn()) {
        $_SESSION = [];
        session_destroy();
        json_response(['error' => 'Эта сессия была завершена. Войдите повторно.'], 401);
    }
    db()->prepare('UPDATE auth_sessions SET last_seen=CURRENT_TIMESTAMP WHERE id=:id AND user_id=:user')
        ->execute(['id' => auth_session_id(), 'user' => current_user()['id']]);
}

function refresh_expired_bans(): void
{
    $expired = db()->query('SELECT id FROM users WHERE banned_until IS NOT NULL AND banned_until<=NOW()')->fetchAll(PDO::FETCH_COLUMN);
    if (!$expired) return;
    $ids = implode(',', array_map('intval', $expired));
    db()->exec("UPDATE articles SET status=COALESCE(ban_previous_status,status),ban_previous_status=NULL,ban_hold=0,ban_resolution=NULL WHERE author_id IN ({$ids}) AND ban_hold=1 AND ban_resolution='restore'");
    db()->exec("UPDATE comments SET status='visible',ban_hold=0,ban_resolution=NULL WHERE user_id IN ({$ids}) AND ban_hold=1 AND ban_resolution='restore'");
    db()->exec("UPDATE users SET name=COALESCE(ban_previous_name,name),avatar_url=ban_previous_avatar,banned_until=NULL,ban_reason=NULL,ban_previous_name=NULL,ban_previous_avatar=NULL WHERE id IN ({$ids})");
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('Для продолжения войдите в аккаунт.', 'error');
        header('Location: /login');
        exit;
    }
    return $user;
}

function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('Недостаточно прав для выполнения действия.');
    }
    return $user;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Сессия формы истекла. Обновите страницу и повторите действие.');
    }
}

function flash(?string $message = null, string $type = 'success'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $value;
}

function slugify(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($transliterated ?: $text));
    return trim($slug, '-') ?: bin2hex(random_bytes(4));
}

function excerpt(string $html, int $limit = 180): string
{
    $plain = trim(strip_tags($html));
    return mb_strlen($plain, 'UTF-8') > $limit ? mb_substr($plain, 0, $limit, 'UTF-8') . '…' : $plain;
}

function clean_article_html(string $html): string
{
    $html = strip_tags($html, '<p><br><strong><b><em><i><ul><ol><li><h2><h3><blockquote>');
    $html = preg_replace('/<(p|br|strong|b|em|i|ul|ol|li|h2|h3|blockquote)\b[^>]*>/i', '<$1>', $html);
    return $html ?: '<p></p>';
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_data(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function require_api_login(): array
{
    $user = current_user();
    if (!$user) json_response(['error' => 'Требуется авторизация.'], 401);
    touch_auth_session();
    $check = db()->prepare('SELECT banned_until FROM users WHERE id=:id');
    $check->execute(['id' => $user['id']]);
    $bannedUntil = $check->fetchColumn();
    if ($bannedUntil && (!$bannedUntil || strtotime($bannedUntil) > time())) json_response(['error' => 'Аккаунт временно заблокирован до ' . $bannedUntil . '.'], 403);
    return $user;
}

function server_metrics(): array
{
    $metrics = [
        'system' => PHP_OS_FAMILY,
        'php' => PHP_VERSION,
        'web_server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Не определен',
        'disk_free_gb' => is_numeric(disk_free_space(__DIR__)) ? round(disk_free_space(__DIR__) / 1073741824, 2) : null,
        'disk_total_gb' => is_numeric(disk_total_space(__DIR__)) ? round(disk_total_space(__DIR__) / 1073741824, 2) : null,
        'php_memory_mb' => round(memory_get_usage(true) / 1048576, 1),
        'memory_limit' => ini_get('memory_limit'),
        'sampled_at' => gmdate(DATE_ATOM),
        'cpu_percent' => null,
        'ram_percent' => null,
        'ram_used_gb' => null,
        'ram_total_gb' => null,
    ];
    if (PHP_OS_FAMILY === 'Windows') {
        $cpu = @shell_exec('powershell.exe -NoProfile -NonInteractive -Command "(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average" 2>$null');
        $memory = @shell_exec('powershell.exe -NoProfile -NonInteractive -Command "$os=Get-CimInstance Win32_OperatingSystem; [string]::Format(\"{0};{1}\", $os.TotalVisibleMemorySize, $os.FreePhysicalMemory)" 2>$null');
        if (is_numeric(trim((string) $cpu))) $metrics['cpu_percent'] = round((float) trim($cpu), 1);
        $parts = array_map('trim', explode(';', trim((string) $memory)));
        if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1]) && (float) $parts[0] > 0) {
            $total = (float) $parts[0] / 1048576;
            $free = (float) $parts[1] / 1048576;
            $metrics['ram_total_gb'] = round($total, 2);
            $metrics['ram_used_gb'] = round($total - $free, 2);
            $metrics['ram_percent'] = round((($total - $free) / $total) * 100, 1);
        }
    }
    return $metrics;
}

function require_api_role(array $roles): array
{
    $user = require_api_login();
    if (!in_array($user['role'], $roles, true)) json_response(['error' => 'Недостаточно прав.'], 403);
    return $user;
}

function verify_api_csrf(array $data): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), (string) ($data['csrf'] ?? ''))) {
        json_response(['error' => 'Сессия формы истекла. Обновите страницу.'], 419);
    }
}