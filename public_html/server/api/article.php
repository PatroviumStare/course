<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$query = db()->prepare('SELECT a.*,u.name AS author_name,u.id AS author_user_id,u.role AS author_role,u.avatar_url AS author_avatar,c.name AS category_name,c.slug AS category_slug FROM articles a JOIN users u ON u.id=a.author_id JOIN categories c ON c.id=a.category_id WHERE a.slug=:slug AND a.status="published" AND a.ban_hold=0');
$query->execute(['slug' => trim($_GET['slug'] ?? '')]);
$article = $query->fetch();
if (!$article) json_response(['error' => 'Материал не найден.'], 404);

$user = current_user();
$visitor = hash('sha256', session_id());
db()->prepare('INSERT INTO article_views (article_id,user_id,visitor_key) VALUES (:article,:user,:visitor)')->execute(['article' => $article['id'], 'user' => $user['id'] ?? null, 'visitor' => $visitor]);
db()->prepare('UPDATE articles SET views=views+1 WHERE id=:id')->execute(['id' => $article['id']]);

$tags = db()->prepare('SELECT t.name,t.slug FROM tags t JOIN article_tags atg ON atg.tag_id=t.id WHERE atg.article_id=:id ORDER BY t.name');
$tags->execute(['id' => $article['id']]);
$related = db()->prepare('SELECT a.id,a.title,a.slug,a.subtitle,a.published_at,a.views,c.name AS category_name,c.slug AS category_slug,(SELECT ROUND(AVG(r.value),1) FROM ratings r WHERE r.article_id=a.id) AS rating_average,(SELECT COUNT(*) FROM ratings r WHERE r.article_id=a.id) AS rating_count,(SELECT COUNT(*) FROM comments cm WHERE cm.article_id=a.id AND cm.status="visible" AND cm.ban_hold=0) AS comment_count FROM articles a JOIN categories c ON c.id=a.category_id WHERE a.status="published" AND a.ban_hold=0 AND a.id<>:id AND (a.category_id=:category OR EXISTS (SELECT 1 FROM article_tags x JOIN article_tags y ON y.tag_id=x.tag_id WHERE x.article_id=a.id AND y.article_id=:match_id)) ORDER BY (SELECT COUNT(*) FROM article_tags x JOIN article_tags y ON y.tag_id=x.tag_id WHERE x.article_id=a.id AND y.article_id=:score_id) DESC,(SELECT COUNT(*) FROM article_views hv JOIN article_tags vt ON vt.article_id=hv.article_id JOIN article_tags ct ON ct.tag_id=vt.tag_id AND ct.article_id=a.id WHERE hv.user_id=:user_id) DESC,a.published_at DESC LIMIT 4');
$related->execute(['id' => $article['id'], 'category' => $article['category_id'], 'match_id' => $article['id'], 'score_id' => $article['id'], 'user_id' => $user['id'] ?? null]);
$comments = db()->prepare('SELECT c.id,c.body,c.created_at,u.id AS user_id,u.name,u.role FROM comments c JOIN users u ON u.id=c.user_id WHERE c.article_id=:id AND c.status="visible" AND c.ban_hold=0 ORDER BY c.created_at DESC LIMIT 50');
$comments->execute(['id' => $article['id']]);
$rating = db()->prepare('SELECT ROUND(AVG(value),1) AS average,COUNT(*) AS total FROM ratings WHERE article_id=:id');
$rating->execute(['id' => $article['id']]);
$followed = ['category' => false, 'author' => false];
$myRating = null;
if ($user) {
    $follow = db()->prepare('SELECT target_type FROM follows WHERE user_id=:user AND target_type IN ("category","author") AND ((target_type="category" AND target_id=:category) OR (target_type="author" AND target_id=:author))');
    $follow->execute(['user' => $user['id'], 'category' => $article['category_id'], 'author' => $article['author_user_id']]);
    foreach ($follow->fetchAll(PDO::FETCH_COLUMN) as $type) $followed[$type] = true;
    $my = db()->prepare('SELECT value FROM ratings WHERE user_id=:user AND article_id=:article');
    $my->execute(['user' => $user['id'], 'article' => $article['id']]);
    $myRating = $my->fetchColumn();
}
$article['views'] = (int) $article['views'] + 1;
json_response(['article' => $article, 'tags' => $tags->fetchAll(), 'related' => $related->fetchAll(), 'comments' => $comments->fetchAll(), 'rating' => $rating->fetch(), 'my_rating' => $myRating ? (int) $myRating : null, 'followed' => $followed, 'user' => $user, 'csrf' => csrf_token()]);