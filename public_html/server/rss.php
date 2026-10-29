<?php
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/rss+xml; charset=utf-8');
$items = db()->query('SELECT a.title,a.subtitle,a.slug,a.body,a.published_at,c.name AS category_name FROM articles a JOIN categories c ON c.id=a.category_id WHERE a.status="published" ORDER BY a.published_at DESC LIMIT 30')->fetchAll();
function xml(string $text): string { return htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8'); }
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<rss version="2.0"><channel><title>Дайджест — новости</title><link><?= xml(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?>/</link><description>Новостной портал: проверенные материалы по рубрикам</description><language>ru</language><?php foreach ($items as $item): ?><item><title><?= xml($item['title']) ?></title><link><?= xml(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?>/article/<?= rawurlencode($item['slug']) ?></link><guid isPermaLink="false"><?= (int) crc32($item['slug']) ?></guid><category><?= xml($item['category_name']) ?></category><description><?= xml($item['subtitle'] . ' ' . excerpt($item['body'], 450)) ?></description><pubDate><?= date(DATE_RSS, strtotime($item['published_at'])) ?></pubDate></item><?php endforeach; ?></channel></rss>
