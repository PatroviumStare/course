<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conditions = ['a.status="published"', 'a.ban_hold=0'];
$parameters = [];
if (!empty($_GET['following'])) {
    $followingUser = require_api_login();
    $conditions[] = 'EXISTS (SELECT 1 FROM follows fw WHERE fw.user_id=:following_user AND fw.target_type="author" AND fw.target_id=a.author_id)';
    $parameters['following_user'] = $followingUser['id'];
}
if (!empty($_GET['category'])) {
    $conditions[] = 'c.slug=:category';
    $parameters['category'] = trim($_GET['category']);
}
if (!empty($_GET['tag'])) {
    $conditions[] = 'EXISTS (SELECT 1 FROM article_tags tag_link JOIN tags tag_item ON tag_item.id=tag_link.tag_id WHERE tag_link.article_id=a.id AND tag_item.slug=:tag_slug)';
    $parameters['tag_slug'] = trim($_GET['tag']);
}
if (!empty($_GET['q'])) {
    $searchText = trim($_GET['q']);
    preg_match_all('/#([\pL\pN_-]+)/u', $searchText, $matches);
    $hashtags = array_slice(array_unique($matches[1] ?? []), 0, 5);
    foreach ($hashtags as $index => $hashtag) {
        $parameter = 'hashtag_' . $index;
        $conditions[] = 'EXISTS (SELECT 1 FROM article_tags hlink JOIN tags htag ON htag.id=hlink.tag_id WHERE hlink.article_id=a.id AND htag.slug=:' . $parameter . ')';
        $parameters[$parameter] = slugify($hashtag);
    }
    $searchText = trim(preg_replace('/#[\pL\pN_-]+/u', '', $searchText));
    if ($searchText !== '') {
        $conditions[] = '(a.title LIKE :title OR a.subtitle LIKE :subtitle OR a.body LIKE :body OR EXISTS (SELECT 1 FROM article_tags ax JOIN tags tx ON tx.id=ax.tag_id WHERE ax.article_id=a.id AND tx.name LIKE :tag))';
        $like = '%' . $searchText . '%';
        $parameters += ['title' => $like, 'subtitle' => $like, 'body' => $like, 'tag' => $like];
    }
}
$sort = $_GET['sort'] ?? 'newest';
$order = match ($sort) {
    'popular' => 'a.views DESC,a.published_at DESC',
    'oldest' => 'a.published_at ASC',
    default => 'a.published_at DESC',
};
$limit = max(1, min(50, (int) ($_GET['limit'] ?? 30)));
$query = db()->prepare('SELECT a.id,a.title,a.subtitle,a.slug,a.published_at,a.views,a.image_url,a.category_id,a.author_id,c.name AS category,c.slug AS category_slug,u.name AS author,u.avatar_url AS author_avatar,u.role AS author_role,(SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ",") FROM profile_tags pt JOIN tags t ON t.id=pt.tag_id WHERE pt.user_id=u.id) AS author_tags,
    (SELECT ROUND(AVG(r.value),1) FROM ratings r WHERE r.article_id=a.id) AS rating_average,
    (SELECT COUNT(*) FROM ratings r WHERE r.article_id=a.id) AS rating_count,
    (SELECT COUNT(*) FROM comments cm WHERE cm.article_id=a.id AND cm.status="visible" AND cm.ban_hold=0) AS comment_count
    FROM articles a JOIN categories c ON c.id=a.category_id JOIN users u ON u.id=a.author_id WHERE ' . implode(' AND ', $conditions) . ' ORDER BY ' . $order . ' LIMIT ' . $limit);
$query->execute($parameters);
json_response(['data' => $query->fetchAll(), 'generated_at' => gmdate(DATE_ATOM)]);
