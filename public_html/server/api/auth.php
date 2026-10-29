<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (current_user()) touch_auth_session();
    json_response(['user' => current_user(), 'csrf' => csrf_token()]);
}

$data = request_data();
verify_api_csrf($data);
$action = $data['action'] ?? '';

if ($action === 'register') {
    $name = trim($data['name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $password = $data['password'] ?? '';
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) json_response(['error' => 'Имя должно содержать от 2 до 100 символов.'], 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['error' => 'Укажите корректный адрес электронной почты.'], 422);
    if (strlen($password) < 10) json_response(['error' => 'Пароль должен содержать не менее 10 символов.'], 422);
    try {
        $query = db()->prepare('INSERT INTO users (name,email,password_hash) VALUES (:name,:email,:password)');
        $query->execute(['name' => $name, 'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') json_response(['error' => 'Аккаунт с таким адресом уже существует.'], 409);
        throw $exception;
    }
    // Email-code integration point: issue and persist a one-time code before activating the session.
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int) db()->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'viewer'];
    start_auth_session($_SESSION['user']);
    json_response(['user' => current_user(), 'csrf' => csrf_token()], 201);
}

if ($action === 'login') {
    $query = db()->prepare('SELECT id,name,email,password_hash,role,banned_until FROM users WHERE email=:email');
    $query->execute(['email' => strtolower(trim($data['email'] ?? ''))]);
    $account = $query->fetch();
    if (!$account || !password_verify($data['password'] ?? '', $account['password_hash'])) {
        json_response(['error' => 'Адрес электронной почты или пароль не подходят.'], 422);
    }
    if (!empty($account['banned_until']) && strtotime($account['banned_until']) > time()) json_response(['error' => 'Аккаунт заблокирован до ' . $account['banned_until'] . '.'], 403);
    session_regenerate_id(true);
    unset($account['password_hash']);
    $_SESSION['user'] = $account;
    start_auth_session($account);
    json_response(['user' => $account, 'csrf' => csrf_token()]);
}

if ($action === 'logout') {
    if (auth_session_id() && current_user()) {
        db()->prepare('DELETE FROM auth_sessions WHERE id=:id AND user_id=:user')->execute(['id' => auth_session_id(), 'user' => current_user()['id']]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    json_response(['ok' => true]);
}

json_response(['error' => 'Неизвестное действие.'], 400);
