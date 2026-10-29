<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_api_login();
$pdo = db();
$data = ['user' => $user, 'server' => server_metrics()];

if ($user['role'] === 'admin') {
    $data['stats'] = [
        'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'published' => (int) $pdo->query('SELECT COUNT(*) FROM articles WHERE status="published" AND ban_hold=0')->fetchColumn(),
        'pending' => (int) $pdo->query('SELECT COUNT(*) FROM articles WHERE status="review" AND ban_hold=0')->fetchColumn(),
        'comments' => (int) $pdo->query('SELECT COUNT(*) FROM comments WHERE status="visible" AND ban_hold=0')->fetchColumn(),
    ];
    $data['popular'] = $pdo->query('SELECT a.id,a.title,a.subtitle,a.slug,a.views,a.published_at,c.name AS category_name,c.slug AS category_slug,
        (SELECT ROUND(AVG(r.value),1) FROM ratings r WHERE r.article_id=a.id) AS rating_average,
        (SELECT COUNT(*) FROM ratings r WHERE r.article_id=a.id) AS rating_count,
        (SELECT COUNT(*) FROM comments cm WHERE cm.article_id=a.id AND cm.status="visible" AND cm.ban_hold=0) AS comment_count
        FROM articles a JOIN categories c ON c.id=a.category_id WHERE a.status="published" AND a.ban_hold=0 ORDER BY a.views DESC LIMIT 5')->fetchAll();
    $data['users'] = $pdo->query('SELECT id,name,email,role,banned_until,created_at FROM users ORDER BY created_at DESC LIMIT 100')->fetchAll();
} else {
    $count = $pdo->prepare('SELECT status,COUNT(*) AS total FROM articles WHERE author_id=:id GROUP BY status');
    $count->execute(['id' => $user['id']]);
    $data['stats'] = $count->fetchAll();
}

if ($user['role'] === 'moderator') $data['users'] = $pdo->query('SELECT id,name,email,role,banned_until,created_at FROM users ORDER BY created_at DESC LIMIT 100')->fetchAll();

if (in_array($user['role'], ['moderator','admin'], true)) {
    $data['pending'] = $pdo->query('SELECT a.id,a.title,a.subtitle,a.slug,a.created_at,a.published_at,a.views,a.image_url,u.name AS author_name,u.id AS author_user_id,u.role AS author_role,c.name AS category_name,c.slug AS category_slug,
        (SELECT ROUND(AVG(r.value),1) FROM ratings r WHERE r.article_id=a.id) AS rating_average,
        (SELECT COUNT(*) FROM ratings r WHERE r.article_id=a.id) AS rating_count,
        (SELECT COUNT(*) FROM comments cm WHERE cm.article_id=a.id AND cm.status="visible" AND cm.ban_hold=0) AS comment_count
        FROM articles a JOIN users u ON u.id=a.author_id JOIN categories c ON c.id=a.category_id WHERE a.status="review" AND a.ban_hold=0 ORDER BY a.created_at ASC')->fetchAll();
    $data['comments_pending'] = $pdo->query('SELECT c.id,c.body,c.created_at,u.id AS user_id,u.name AS user_name,a.title AS article_title FROM comments c JOIN users u ON u.id=c.user_id JOIN articles a ON a.id=c.article_id WHERE c.status="visible" AND c.ban_hold=0 ORDER BY c.created_at DESC LIMIT 30')->fetchAll();
    $data['banned_content'] = [
        'articles' => $pdo->query('SELECT a.id,a.title,a.subtitle,a.slug,a.ban_previous_status AS previous_status,u.name AS author_name,c.name AS category_name FROM articles a JOIN users u ON u.id=a.author_id JOIN categories c ON c.id=a.category_id WHERE a.ban_hold=1 ORDER BY a.created_at')->fetchAll(),
        'comments' => $pdo->query('SELECT cm.id,cm.body,cm.created_at,u.name AS author_name,a.title AS article_title FROM comments cm JOIN users u ON u.id=cm.user_id JOIN articles a ON a.id=cm.article_id WHERE cm.ban_hold=1 ORDER BY cm.created_at')->fetchAll(),
    ];
    $data['manage_articles'] = $pdo->query('SELECT a.id,a.title,a.subtitle,a.slug,a.status,a.moderation_note,a.created_at,a.published_at,a.views,a.image_url,a.author_id,u.name AS author_name,u.id AS author_user_id,u.role AS author_role,c.name AS category_name,c.slug AS category_slug,
        (SELECT ROUND(AVG(r.value),1) FROM ratings r WHERE r.article_id=a.id) AS rating_average,
        (SELECT COUNT(*) FROM ratings r WHERE r.article_id=a.id) AS rating_count,
        (SELECT COUNT(*) FROM comments cm WHERE cm.article_id=a.id AND cm.status="visible" AND cm.ban_hold=0) AS comment_count
        FROM articles a JOIN users u ON u.id=a.author_id JOIN categories c ON c.id=a.category_id ORDER BY a.updated_at DESC LIMIT 100')->fetchAll();
    $data['articles'] = $data['manage_articles'];
}

if (in_array($user['role'], ['author','moderator','admin'], true)) {
    $mine = $pdo->prepare('SELECT a.id,a.title,a.subtitle,a.slug,a.status,a.moderation_note,a.created_at,a.published_at,a.views,a.image_url,a.author_id,u.name AS author_name,u.role AS author_role,c.name AS category_name,c.slug AS category_slug,
        (SELECT ROUND(AVG(r.value),1) FROM ratings r WHERE r.article_id=a.id) AS rating_average,
        (SELECT COUNT(*) FROM ratings r WHERE r.article_id=a.id) AS rating_count,
        (SELECT COUNT(*) FROM comments cm WHERE cm.article_id=a.id AND cm.status="visible" AND cm.ban_hold=0) AS comment_count
        FROM articles a JOIN categories c ON c.id=a.category_id JOIN users u ON u.id=a.author_id WHERE a.author_id=:id ORDER BY a.updated_at DESC LIMIT 30');
    $mine->execute(['id' => $user['id']]);
    $data['own_articles'] = $mine->fetchAll();
    if ($user['role'] === 'author') $data['articles'] = $data['own_articles'];
}

$history = $pdo->prepare('SELECT a.title,a.slug,a.subtitle,a.published_at,a.views,c.name AS category,c.slug AS category_slug,MAX(v.viewed_at) AS viewed_at FROM article_views v JOIN articles a ON a.id=v.article_id JOIN categories c ON c.id=a.category_id WHERE v.user_id=:id AND a.status="published" GROUP BY a.id ORDER BY viewed_at DESC LIMIT 8');
$history->execute(['id' => $user['id']]);
$data['history'] = $history->fetchAll();
$data['csrf'] = csrf_token();
json_response($data);
