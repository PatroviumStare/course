<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_api_login();
if (!in_array($user['role'], ['author', 'admin'], true)) json_response(['error' => 'Редактирование доступно автору своих возвращённых материалов или администратору.'], 403);
$condition = $user['role'] === 'admin'
    ? 'a.id=:id'
    : 'a.id=:id AND a.author_id=:user AND a.ban_hold=0';
$query = db()->prepare('SELECT a.id,a.title,a.subtitle,a.category_id,a.body,a.source_url,a.image_url,GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ", ") AS tags FROM articles a LEFT JOIN article_tags atg ON atg.article_id=a.id LEFT JOIN tags t ON t.id=atg.tag_id WHERE ' . $condition . ' GROUP BY a.id');
$parameters = ['id' => (int) ($_GET['id'] ?? 0)];
if ($user['role'] !== 'admin') $parameters['user'] = $user['id'];
$query->execute($parameters);
$article = $query->fetch();
if (!$article) json_response(['error' => 'Статья не найдена или недоступна для изменения.'], 404);
json_response($article);
