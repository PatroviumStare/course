<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$article = db()->query('SELECT slug FROM articles WHERE status="published" ORDER BY RAND() LIMIT 1')->fetch();
if (!$article) json_response(['error' => 'Опубликованных статей пока нет.'], 404);
json_response(['slug' => $article['slug']]);
