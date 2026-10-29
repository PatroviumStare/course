<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$items = db()->query('SELECT c.id,c.name,c.slug,COUNT(a.id) AS article_count FROM categories c LEFT JOIN articles a ON a.category_id=c.id AND a.status="published" AND a.ban_hold=0 GROUP BY c.id ORDER BY c.name')->fetchAll();
$authors = db()->query('SELECT u.id,u.name,u.avatar_url,u.role,(SELECT COUNT(*) FROM follows f WHERE f.target_type="author" AND f.target_id=u.id) AS follower_count,(SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ",") FROM profile_tags pt JOIN tags t ON t.id=pt.tag_id WHERE pt.user_id=u.id) AS profile_tags FROM users u WHERE u.banned_until IS NULL OR u.banned_until<=NOW() ORDER BY follower_count DESC,u.name LIMIT 6')->fetchAll();
json_response(['data' => $items, 'popular_authors' => $authors]);
