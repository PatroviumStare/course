<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
$viewer = current_user();

function profile_can_see(?array $viewer, array $profile, string $field, bool $isFollower): bool
{
    if ($viewer && ($viewer['role'] ?? '') === 'admin') return true;
    if ($viewer && (int) $viewer['id'] === (int) $profile['id']) return true;
    return $profile[$field] === 'everyone' || ($profile[$field] === 'followers' && $isFollower);
}

function article_metrics_sql(string $alias = 'a'): string
{
    return "(SELECT ROUND(AVG(r.value),1) FROM ratings r WHERE r.article_id={$alias}.id) AS rating_average,
        (SELECT COUNT(*) FROM ratings r WHERE r.article_id={$alias}.id) AS rating_count,
        (SELECT COUNT(*) FROM comments cm WHERE cm.article_id={$alias}.id AND cm.status='visible' AND cm.ban_hold=0) AS comment_count";
}

function profile_tags(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT t.id,t.name,t.slug FROM profile_tags pt JOIN tags t ON t.id=pt.tag_id WHERE pt.user_id=:id ORDER BY t.name');
    $query->execute(['id' => $userId]);
    return $query->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$viewer && ($_GET['view'] ?? '') !== 'profile') json_response(['error' => 'Требуется авторизация.'], 401);
    $profileId = (int) ($_GET['id'] ?? 0);
    if ($profileId > 0) {
        $profileQuery = $pdo->prepare('SELECT id,name,role,bio,avatar_url,comments_visibility,follows_visibility,created_at,banned_until,(SELECT COALESCE(SUM(value),0) FROM profile_reputation pr WHERE pr.profile_id=users.id) AS reputation,(SELECT COUNT(*) FROM follows f WHERE f.target_type="author" AND f.target_id=users.id) AS follower_count FROM users WHERE id=:id');
        $profileQuery->execute(['id' => $profileId]);
        $profile = $profileQuery->fetch();
        if (!$profile) json_response(['error' => 'Профиль не найден.'], 404);
        if (!empty($profile['banned_until']) && strtotime($profile['banned_until']) > time()) {
            $profile['name'] = '[Пользователь заблокирован]';
            $profile['bio'] = null;
            $profile['avatar_url'] = '/assets/images/avatar-blocked.svg';
            json_response(['profile' => $profile, 'is_owner' => false, 'can_see_comments' => false, 'can_see_follows' => false, 'articles' => [], 'comments' => [], 'follows' => []]);
        }
        $follower = false;
        if ($viewer && (int) $viewer['id'] !== $profileId) {
            $check = $pdo->prepare('SELECT 1 FROM follows WHERE user_id=:viewer AND target_type="author" AND target_id=:profile');
            $check->execute(['viewer' => $viewer['id'], 'profile' => $profileId]);
            $follower = (bool) $check->fetchColumn();
        }
        $profileSort = match ($_GET['sort'] ?? 'newest') {
            'popular' => 'a.views DESC,a.published_at DESC',
            'oldest' => 'a.published_at ASC',
            default => 'a.published_at DESC',
        };
        $articles = $pdo->prepare('SELECT a.id,a.title,a.subtitle,a.slug,a.published_at,a.views,a.author_id,c.name AS category,c.slug AS category_slug,' . article_metrics_sql() . ' FROM articles a JOIN categories c ON c.id=a.category_id WHERE a.author_id=:id AND a.status="published" AND a.ban_hold=0 ORDER BY ' . $profileSort . ' LIMIT 50');
        $articles->execute(['id' => $profileId]);
        $isOwner = $viewer && (int) $viewer['id'] === $profileId;
        $canModerate = $viewer
            && in_array($viewer['role'] ?? '', ['moderator', 'admin'], true)
            && !$isOwner
            && !(($viewer['role'] ?? '') === 'moderator' && $profile['role'] === 'admin');
        $result = ['profile' => $profile, 'tags' => profile_tags($pdo, $profileId), 'is_owner' => $isOwner, 'can_moderate' => $canModerate, 'can_vote' => $viewer && !$isOwner, 'can_follow' => $viewer && !$isOwner, 'is_follower' => $follower, 'can_see_comments' => profile_can_see($viewer, $profile, 'comments_visibility', $follower), 'can_see_follows' => profile_can_see($viewer, $profile, 'follows_visibility', $follower), 'articles' => $articles->fetchAll(), 'csrf' => $viewer ? csrf_token() : null];
        if ($result['can_see_comments']) {
            $comments = $pdo->prepare('SELECT cm.id,cm.body,cm.created_at,a.id AS article_id,a.title AS article_title,a.slug FROM comments cm JOIN articles a ON a.id=cm.article_id WHERE cm.user_id=:id AND cm.status="visible" AND cm.ban_hold=0 ORDER BY cm.created_at DESC LIMIT 50');
            $comments->execute(['id' => $profileId]);
            $result['comments'] = $comments->fetchAll();
        } else $result['comments'] = [];
        if ($result['can_see_follows']) {
            $follows = $pdo->prepare('SELECT f.target_type,c.name AS category_name,c.slug AS category_slug,u.id AS author_id,u.name AS author_name,u.avatar_url AS author_avatar,u.role AS author_role,(SELECT COUNT(*) FROM follows fs WHERE fs.target_type="author" AND fs.target_id=u.id) AS follower_count,(SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ",") FROM profile_tags pt JOIN tags t ON t.id=pt.tag_id WHERE pt.user_id=u.id) AS profile_tags,EXISTS(SELECT 1 FROM follows mine WHERE mine.user_id=:viewer AND mine.target_type="author" AND mine.target_id=u.id) AS is_followed FROM follows f LEFT JOIN categories c ON f.target_type="category" AND c.id=f.target_id LEFT JOIN users u ON f.target_type="author" AND u.id=f.target_id WHERE f.user_id=:id ORDER BY f.created_at DESC');
            $follows->execute(['id' => $profileId, 'viewer' => $viewer['id'] ?? 0]);
            $result['follows'] = $follows->fetchAll();
        } else $result['follows'] = [];
        json_response($result);
    }

    if (($_GET['view'] ?? '') === 'switch-accounts') {
        $device = $_COOKIE['digest_device'] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/', $device)) json_response(['accounts' => []]);
        $switch = $pdo->prepare('SELECT s.id AS session_id,s.switch_token,u.id,u.name,u.email,u.role,u.avatar_url FROM auth_sessions s JOIN users u ON u.id=s.user_id WHERE s.device_key=:device AND (u.banned_until IS NULL OR u.banned_until<=NOW()) ORDER BY s.last_seen DESC');
        $switch->execute(['device' => $device]);
        json_response(['accounts' => $switch->fetchAll()]);
    }

    if (($_GET['view'] ?? '') === 'follows') {
        $follows = $pdo->prepare('SELECT f.target_type,c.name AS category_name,c.slug AS category_slug,u.id AS author_id,u.name AS author_name,u.avatar_url AS author_avatar,u.role AS author_role,(SELECT COUNT(*) FROM follows fs WHERE fs.target_type="author" AND fs.target_id=u.id) AS follower_count,(SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ",") FROM profile_tags pt JOIN tags t ON t.id=pt.tag_id WHERE pt.user_id=u.id) AS profile_tags FROM follows f LEFT JOIN categories c ON f.target_type="category" AND c.id=f.target_id LEFT JOIN users u ON f.target_type="author" AND u.id=f.target_id WHERE f.user_id=:id ORDER BY f.created_at DESC');
        $follows->execute(['id' => $viewer['id']]);
        $feedSort = match ($_GET['sort'] ?? 'newest') {
            'popular' => 'a.views DESC,a.published_at DESC',
            'oldest' => 'a.published_at ASC',
            default => 'a.published_at DESC',
        };
        $articles = $pdo->prepare('SELECT a.id,a.title,a.subtitle,a.slug,a.published_at,a.views,a.image_url,a.author_id,c.name AS category,c.slug AS category_slug,u.name AS author,u.role AS author_role,' . article_metrics_sql() . ' FROM articles a JOIN categories c ON c.id=a.category_id JOIN users u ON u.id=a.author_id WHERE a.status="published" AND a.ban_hold=0 AND a.author_id IN (SELECT target_id FROM follows WHERE user_id=:id AND target_type="author") ORDER BY ' . $feedSort . ' LIMIT 50');
        $articles->execute(['id' => $viewer['id']]);
        json_response(['follows' => $follows->fetchAll(), 'articles' => $articles->fetchAll(), 'csrf' => csrf_token()]);
    }

    $fullUser = $pdo->prepare('SELECT id,name,email,role,bio,avatar_url,comments_visibility,follows_visibility,two_factor_enabled,created_at,(SELECT COALESCE(SUM(value),0) FROM profile_reputation pr WHERE pr.profile_id=users.id) AS reputation FROM users WHERE id=:id');
    $fullUser->execute(['id' => $viewer['id']]);
    $viewer = $fullUser->fetch() ?: $viewer;
    $sessions = $pdo->prepare('SELECT id,device_label,ip_address,last_seen,created_at FROM auth_sessions WHERE user_id=:user ORDER BY last_seen DESC');
    $sessions->execute(['user' => $viewer['id']]);
    $sessionRows = $sessions->fetchAll();
    foreach ($sessionRows as &$sessionRow) $sessionRow['is_current'] = hash_equals((string) $sessionRow['id'], (string) auth_session_id());
    unset($sessionRow);
    $tagQuery = $pdo->query('SELECT id,name,slug FROM tags ORDER BY name');
    json_response(['user' => $viewer, 'tags' => profile_tags($pdo, (int) $viewer['id']), 'available_tags' => $tagQuery->fetchAll(), 'sessions' => $sessionRows, 'csrf' => csrf_token()]);
}

$data = request_data();
verify_api_csrf($data);
$user = require_api_login();
$action = $data['action'] ?? '';

if ($action === 'update-profile') {
    $name = trim($data['name'] ?? '');
    $bio = trim($data['bio'] ?? '');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) json_response(['error' => 'Имя должно содержать от 2 до 100 символов.'], 422);
    if (mb_strlen($bio) > 600) json_response(['error' => 'Описание не должно превышать 600 символов.'], 422);
    $avatar = null;
    if (!empty($_FILES['avatar']['tmp_name'])) {
        $file = $_FILES['avatar'];
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 4 * 1024 * 1024) json_response(['error' => 'Изображение должно быть меньше 4 МБ.'], 422);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($extensions[$mime])) json_response(['error' => 'Поддерживаются JPG, PNG, WEBP и GIF.'], 422);
        $directory = dirname(__DIR__, 2) . '/storage/avatars';
        if (!is_dir($directory)) mkdir($directory, 0775, true);
        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) json_response(['error' => 'Не удалось сохранить аватар.'], 500);
        $avatar = '/storage/avatars/' . $filename;
    }
    $selectedTags = $data['profile_tags'] ?? [];
    if (is_string($selectedTags)) {
        $decodedTags = json_decode($selectedTags, true);
        $selectedTags = is_array($decodedTags) ? $decodedTags : array_filter(array_map('trim', explode(',', $selectedTags)));
    }
    $selectedTags = array_slice(array_values(array_unique(array_map('intval', (array) $selectedTags))), 0, 3);
    $sql = 'UPDATE users SET name=:name,bio=:bio' . ($avatar ? ',avatar_url=:avatar' : '') . ' WHERE id=:id';
    $params = ['name' => $name, 'bio' => $bio ?: null, 'id' => $user['id']];
    if ($avatar) $params['avatar'] = $avatar;
    $pdo->prepare($sql)->execute($params);
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM profile_tags WHERE user_id=:id')->execute(['id' => $user['id']]);
    if ($selectedTags) {
        $placeholders = implode(',', array_fill(0, count($selectedTags), '?'));
        $tagCheck = $pdo->prepare("SELECT id FROM tags WHERE id IN ($placeholders)");
        $tagCheck->execute($selectedTags);
        $saveTag = $pdo->prepare('INSERT IGNORE INTO profile_tags (user_id,tag_id) VALUES (:user,:tag)');
        foreach ($tagCheck->fetchAll(PDO::FETCH_COLUMN) as $tagId) $saveTag->execute(['user' => $user['id'], 'tag' => $tagId]);
    }
    $pdo->commit();
    $_SESSION['user']['name'] = $name;
    if ($avatar) $_SESSION['user']['avatar_url'] = $avatar;
    json_response(['ok' => true, 'avatar_url' => $avatar]);
}

if ($action === 'reputation') {
    $profileId = (int) ($data['profile_id'] ?? 0);
    $value = (int) ($data['value'] ?? 0);
    if ($profileId < 1 || $profileId === (int) $user['id'] || !in_array($value, [-1, 0, 1], true)) json_response(['error' => 'Некорректная оценка профиля.'], 422);
    if ($value === 0) $pdo->prepare('DELETE FROM profile_reputation WHERE profile_id=:profile AND voter_id=:voter')->execute(['profile' => $profileId, 'voter' => $user['id']]);
    else $pdo->prepare('INSERT INTO profile_reputation (profile_id,voter_id,value) VALUES (:profile,:voter,:value) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute(['profile' => $profileId, 'voter' => $user['id'], 'value' => $value]);
    $score = $pdo->prepare('SELECT COALESCE(SUM(value),0) FROM profile_reputation WHERE profile_id=:profile');
    $score->execute(['profile' => $profileId]);
    json_response(['ok' => true, 'reputation' => (int) $score->fetchColumn()]);
}

if ($action === 'privacy') {
    $comments = $data['comments_visibility'] ?? 'everyone';
    $follows = $data['follows_visibility'] ?? 'everyone';
    if (!in_array($comments, ['everyone','followers','private'], true) || !in_array($follows, ['everyone','followers','private'], true)) json_response(['error' => 'Некорректная настройка видимости.'], 422);
    $pdo->prepare('UPDATE users SET comments_visibility=:comments,follows_visibility=:follows WHERE id=:id')->execute(['comments' => $comments, 'follows' => $follows, 'id' => $user['id']]);
    json_response(['ok' => true]);
}

if ($action === 'change-password') {
    if (!password_verify($data['current_password'] ?? '', $user['password_hash'] ?? '')) {
        $query = $pdo->prepare('SELECT password_hash FROM users WHERE id=:id');
        $query->execute(['id' => $user['id']]);
        if (!password_verify($data['current_password'] ?? '', (string) $query->fetchColumn())) json_response(['error' => 'Текущий пароль указан неверно.'], 422);
    }
    $password = (string) ($data['new_password'] ?? '');
    if (strlen($password) < 10) json_response(['error' => 'Новый пароль должен содержать не менее 10 символов.'], 422);
    $pdo->prepare('UPDATE users SET password_hash=:password WHERE id=:id')->execute(['password' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
    json_response(['ok' => true]);
}

if ($action === 'two-factor') {
    $enabled = filter_var($data['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $pdo->prepare('UPDATE users SET two_factor_enabled=:enabled WHERE id=:id')->execute(['enabled' => $enabled ? 1 : 0, 'id' => $user['id']]);
    json_response(['ok' => true, 'enabled' => $enabled, 'notice' => 'Двухфакторная аутентификация пока отображается как подготовленная опция.']);
}

if ($action === 'logout-other-sessions') {
    $pdo->prepare('DELETE FROM auth_sessions WHERE user_id=:user AND id<>:current')->execute(['user' => $user['id'], 'current' => auth_session_id() ?: '']);
    json_response(['ok' => true]);
}

if ($action === 'switch-account') {
    $token = (string) ($data['switch_token'] ?? '');
    $device = $_COOKIE['digest_device'] ?? '';
    $query = $pdo->prepare('SELECT u.*,s.id AS selected_session_id FROM auth_sessions s JOIN users u ON u.id=s.user_id WHERE s.switch_token=:token AND s.device_key=:device AND (u.banned_until IS NULL OR u.banned_until<=NOW())');
    $query->execute(['token' => $token, 'device' => $device]);
    $account = $query->fetch();
    if (!$account) json_response(['error' => 'Сессия аккаунта больше недоступна.'], 403);
    $selectedSessionId = $account['selected_session_id'];
    unset($account['selected_session_id']);
    session_regenerate_id(true);
    $public = $account;
    unset($public['password_hash']);
    $_SESSION['user'] = $public;
    $_SESSION['auth_session_id'] = $selectedSessionId;
    $pdo->prepare('UPDATE auth_sessions SET last_seen=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id' => $selectedSessionId]);
    json_response(['ok' => true, 'user' => $public, 'csrf' => csrf_token()]);
}

if ($action === 'delete-account') {
    if (($data['confirmation'] ?? '') !== 'DELETE') json_response(['error' => 'Введите DELETE для подтверждения удаления аккаунта.'], 422);
    $passwordQuery = $pdo->prepare('SELECT password_hash FROM users WHERE id=:id');
    $passwordQuery->execute(['id' => $user['id']]);
    if (!password_verify($data['password'] ?? '', (string) $passwordQuery->fetchColumn())) json_response(['error' => 'Пароль указан неверно.'], 422);
    $device = $_COOKIE['digest_device'] ?? '';
    $otherAccount = null;
    if (preg_match('/^[a-f0-9]{64}$/', $device)) {
        $other = $pdo->prepare('SELECT u.*,s.id AS selected_session_id FROM auth_sessions s JOIN users u ON u.id=s.user_id WHERE s.device_key=:device AND u.id<>:current AND (u.banned_until IS NULL OR u.banned_until<=NOW()) ORDER BY s.last_seen DESC LIMIT 1');
        $other->execute(['device' => $device, 'current' => $user['id']]);
        $otherAccount = $other->fetch() ?: null;
    }
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM comments WHERE user_id=:id')->execute(['id' => $user['id']]);
    $pdo->prepare('DELETE FROM ratings WHERE user_id=:id')->execute(['id' => $user['id']]);
    $pdo->prepare('UPDATE articles SET reviewed_by=NULL WHERE reviewed_by=:id')->execute(['id' => $user['id']]);
    $pdo->prepare('DELETE FROM moderation_log WHERE reviewer_id=:id')->execute(['id' => $user['id']]);
    $pdo->prepare('DELETE FROM articles WHERE author_id=:id')->execute(['id' => $user['id']]);
    $pdo->prepare('DELETE FROM follows WHERE user_id=:id')->execute(['id' => $user['id']]);
    $pdo->prepare('DELETE FROM follows WHERE target_type="author" AND target_id=:id')->execute(['id' => $user['id']]);
    $pdo->prepare('DELETE FROM auth_sessions WHERE user_id=:id')->execute(['id' => $user['id']]);
    $pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id' => $user['id']]);
    $pdo->commit();
    if ($otherAccount) {
        $selectedSessionId = $otherAccount['selected_session_id'];
        unset($otherAccount['selected_session_id']);
        $public = $otherAccount;
        unset($public['password_hash']);
        session_regenerate_id(true);
        $_SESSION['user'] = $public;
        $_SESSION['auth_session_id'] = $selectedSessionId;
        $pdo->prepare('UPDATE auth_sessions SET last_seen=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id' => $selectedSessionId]);
        json_response(['ok' => true, 'user' => $public, 'redirect' => '/dashboard']);
    }
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
    $_SESSION = [];
    session_destroy();
    json_response(['ok' => true, 'user' => null, 'redirect' => '/']);
}

if ($action === 'ban-user') {
    require_api_role(['moderator','admin']);
    $target = (int) ($data['user_id'] ?? 0);
    $days = max(0, (int) ($data['days'] ?? 0));
    if ($target < 1 || $target === (int) $user['id']) json_response(['error' => 'Нельзя заблокировать этот аккаунт.'], 422);
    $targetQuery = $pdo->prepare('SELECT id,role,banned_until FROM users WHERE id=:id');
    $targetQuery->execute(['id' => $target]);
    $targetUser = $targetQuery->fetch();
    if (!$targetUser || ($user['role'] === 'moderator' && $targetUser['role'] === 'admin')) json_response(['error' => 'Недостаточно прав для этой блокировки.'], 403);
    $until = $days === 0 ? '9999-12-31 23:59:59' : date('Y-m-d H:i:s', time() + $days * 86400);
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET ban_previous_name=COALESCE(ban_previous_name,name),ban_previous_avatar=COALESCE(ban_previous_avatar,avatar_url),name="[Пользователь заблокирован]",avatar_url="/assets/images/avatar-blocked.svg",banned_until=:until,ban_reason=:reason WHERE id=:id')->execute(['until' => $until, 'reason' => trim($data['reason'] ?? '') ?: null, 'id' => $target]);
    $pdo->prepare('UPDATE articles SET ban_hold=1,ban_previous_status=status,ban_resolution="pending" WHERE author_id=:id AND ban_hold=0')->execute(['id' => $target]);
    $pdo->prepare('UPDATE comments SET ban_hold=1,ban_resolution="pending",status="hidden" WHERE user_id=:id AND ban_hold=0')->execute(['id' => $target]);
    $pdo->commit();
    json_response(['ok' => true, 'banned_until' => $until]);
}

if ($action === 'unban-user') {
    require_api_role(['moderator','admin']);
    $target = (int) ($data['user_id'] ?? 0);
    $targetQuery = $pdo->prepare('SELECT role FROM users WHERE id=:id');
    $targetQuery->execute(['id' => $target]);
    $targetRole = $targetQuery->fetchColumn();
    if (!$targetRole || ($user['role'] === 'moderator' && $targetRole === 'admin')) json_response(['error' => 'Недостаточно прав для разблокировки.'], 403);
    $pdo->prepare('UPDATE users SET name=COALESCE(ban_previous_name,name),avatar_url=ban_previous_avatar,banned_until=NULL,ban_reason=NULL,ban_previous_name=NULL,ban_previous_avatar=NULL WHERE id=:id')->execute(['id' => $target]);
    $pdo->prepare('UPDATE articles SET status=COALESCE(ban_previous_status,status),ban_previous_status=NULL,ban_hold=0,ban_resolution=NULL WHERE author_id=:id AND ban_hold=1 AND ban_resolution="restore"')->execute(['id' => $target]);
    $pdo->prepare('UPDATE comments SET status="visible",ban_hold=0,ban_resolution=NULL WHERE user_id=:id AND ban_hold=1 AND ban_resolution="restore"')->execute(['id' => $target]);
    json_response(['ok' => true]);
}

if ($action === 'resolve-banned-content') {
    require_api_role(['moderator','admin']);
    $type = $data['content_type'] ?? '';
    $contentId = (int) ($data['content_id'] ?? 0);
    $decision = $data['decision'] ?? '';
    if (!in_array($decision, ['delete','hide','restore'], true) || !in_array($type, ['article','comment'], true)) json_response(['error' => 'Некорректное решение.'], 422);
    if ($type === 'article') {
        if ($decision === 'delete') $pdo->prepare('DELETE FROM articles WHERE id=:id AND ban_hold=1')->execute(['id' => $contentId]);
        else if ($decision === 'restore') $pdo->prepare('UPDATE articles SET ban_resolution="restore" WHERE id=:id AND ban_hold=1')->execute(['id' => $contentId]);
        else $pdo->prepare('UPDATE articles SET status="rejected",ban_hold=0,ban_resolution="hide",ban_previous_status=NULL WHERE id=:id AND ban_hold=1')->execute(['id' => $contentId]);
    } else {
        if ($decision === 'delete') $pdo->prepare('DELETE FROM comments WHERE id=:id AND ban_hold=1')->execute(['id' => $contentId]);
        else if ($decision === 'restore') $pdo->prepare('UPDATE comments SET ban_resolution="restore" WHERE id=:id AND ban_hold=1')->execute(['id' => $contentId]);
        else $pdo->prepare('UPDATE comments SET status="hidden",ban_hold=0,ban_resolution="hide" WHERE id=:id AND ban_hold=1')->execute(['id' => $contentId]);
    }
    json_response(['ok' => true]);
}

json_response(['error' => 'Неизвестное действие.'], 400);
