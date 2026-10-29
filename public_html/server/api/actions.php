<?php
require_once __DIR__ . '/../includes/bootstrap.php';
set_exception_handler(static function (Throwable $exception): never {
    error_log($exception->getMessage());
    json_response(['error' => 'Не удалось сохранить изменения. Проверьте структуру базы данных и повторите попытку.'], 500);
});
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Метод не поддерживается.'], 405);
$data = request_data();
verify_api_csrf($data);
$user = require_api_login();
$action = $data['action'] ?? '';
$pdo = db();

if ($action === 'comment') {
    $body = trim($data['body'] ?? '');
    $article = (int) ($data['article_id'] ?? 0);
    if ($body === '' || mb_strlen($body) > 2000) json_response(['error' => 'Комментарий должен содержать от 1 до 2000 символов.'], 422);
    $insert = $pdo->prepare('INSERT INTO comments (article_id,user_id,body) SELECT id,:user,:body FROM articles WHERE id=:article AND status="published" AND ban_hold=0');
    $insert->execute(['user' => $user['id'], 'body' => $body, 'article' => $article]);
    json_response(['ok' => true]);
}

if ($action === 'rate') {
    $article = (int) ($data['article_id'] ?? 0);
    $value = filter_var($data['value'] ?? null, FILTER_VALIDATE_INT);
    if (!$value || $value < 1 || $value > 5) json_response(['error' => 'Оценка должна быть от 1 до 5.'], 422);
    $save = $pdo->prepare('INSERT INTO ratings (article_id,user_id,value) SELECT id,:user,:value FROM articles WHERE id=:article AND status="published" AND ban_hold=0 ON DUPLICATE KEY UPDATE value=VALUES(value)');
    $save->execute(['user' => $user['id'], 'value' => $value, 'article' => $article]);
    json_response(['ok' => true]);
}

if ($action === 'follow') {
    $type = $data['target_type'] ?? '';
    $target = (int) ($data['target_id'] ?? 0);
    if (!in_array($type, ['category','author'], true) || $target < 1) json_response(['error' => 'Некорректная подписка.'], 422);
    $check = $pdo->prepare('SELECT 1 FROM follows WHERE user_id=:user AND target_type=:type AND target_id=:target');
    $check->execute(['user' => $user['id'], 'type' => $type, 'target' => $target]);
    if ($check->fetchColumn()) {
        $pdo->prepare('DELETE FROM follows WHERE user_id=:user AND target_type=:type AND target_id=:target')->execute(['user' => $user['id'], 'type' => $type, 'target' => $target]);
        json_response(['ok' => true, 'followed' => false]);
    }
    $pdo->prepare('INSERT IGNORE INTO follows (user_id,target_type,target_id) VALUES (:user,:type,:target)')->execute(['user' => $user['id'], 'type' => $type, 'target' => $target]);
    json_response(['ok' => true, 'followed' => true]);
}

if ($action === 'publish') {
    require_api_role(['author','admin']);
    $title = trim($data['title'] ?? '');
    $subtitle = trim($data['subtitle'] ?? '');
    $category = (int) ($data['category_id'] ?? 0);
    $body = clean_article_html($data['body'] ?? '');
    $source = trim($data['source_url'] ?? '');
    $image = trim($data['image_url'] ?? '');
    $articleId = (int) ($data['article_id'] ?? 0);
    if (mb_strlen($title) < 8 || mb_strlen($title) > 220) json_response(['error' => 'Заголовок должен содержать от 8 до 220 символов.'], 422);
    if (mb_strlen($subtitle) < 10 || mb_strlen($subtitle) > 300) json_response(['error' => 'Подзаголовок должен содержать от 10 до 300 символов.'], 422);
    if (!$category || trim(strip_tags($body)) === '') json_response(['error' => 'Укажите рубрику и текст статьи.'], 422);
    foreach (['Ссылка на источник' => $source, 'Ссылка на изображение' => $image] as $label => $url) {
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http','https'], true))) json_response(['error' => $label . ' должна использовать HTTP или HTTPS.'], 422);
    }
    $tags = array_slice(array_values(array_unique(array_filter(array_map('trim', explode(',', $data['tags'] ?? ''))))), 0, 12);
    $pdo->beginTransaction();
    $slug = slugify($title) . '-' . bin2hex(random_bytes(3));
    if ($articleId) {
        if ($user['role'] === 'admin') {
            $save = $pdo->prepare('UPDATE articles SET title=:title,subtitle=:subtitle,category_id=:category,slug=:slug,body=:body,source_url=:source,image_url=:image,published_at=COALESCE(published_at,NOW()),moderation_note=NULL WHERE id=:id AND ban_hold=0');
            $save->execute(['title' => $title, 'subtitle' => $subtitle, 'category' => $category, 'slug' => $slug, 'body' => $body, 'source' => $source ?: null, 'image' => $image ?: null, 'id' => $articleId]);
        } else {
            $save = $pdo->prepare('UPDATE articles SET title=:title,subtitle=:subtitle,category_id=:category,slug=:slug,body=:body,source_url=:source,image_url=:image,status="review",published_at=NULL,moderation_note=NULL,reviewed_by=NULL,reviewed_at=NULL WHERE id=:id AND author_id=:author AND ban_hold=0');
            $save->execute(['title' => $title, 'subtitle' => $subtitle, 'category' => $category, 'slug' => $slug, 'body' => $body, 'source' => $source ?: null, 'image' => $image ?: null, 'id' => $articleId, 'author' => $user['id']]);
        }
        if (!$save->rowCount()) { $pdo->rollBack(); json_response(['error' => 'Статья недоступна для редактирования.'], 409); }
    } else {
        $status = $user['role'] === 'admin' ? 'published' : 'review';
        $save = $pdo->prepare('INSERT INTO articles (author_id,category_id,title,subtitle,slug,body,source_url,image_url,status,published_at) VALUES (:author,:category,:title,:subtitle,:slug,:body,:source,:image,:status,IF(:publish_status="published",NOW(),NULL))');
        $save->execute(['author' => $user['id'], 'category' => $category, 'title' => $title, 'subtitle' => $subtitle, 'slug' => $slug, 'body' => $body, 'source' => $source ?: null, 'image' => $image ?: null, 'status' => $status, 'publish_status' => $status]);
        $articleId = (int) $pdo->lastInsertId();
    }
    $pdo->prepare('DELETE FROM article_tags WHERE article_id=:id')->execute(['id' => $articleId]);
    foreach ($tags as $name) {
        if ($name === '' || mb_strlen($name) > 80) continue;
        $pdo->prepare('INSERT INTO tags (name,slug) VALUES (:name,:slug) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')->execute(['name' => $name, 'slug' => slugify($name)]);
        $tagId = (int) $pdo->lastInsertId();
        if (!$tagId) { $find = $pdo->prepare('SELECT id FROM tags WHERE name=:name'); $find->execute(['name' => $name]); $tagId = (int) $find->fetchColumn(); }
        $pdo->prepare('INSERT IGNORE INTO article_tags (article_id,tag_id) VALUES (:article,:tag)')->execute(['article' => $articleId, 'tag' => $tagId]);
    }
    $pdo->commit();
    json_response(['ok' => true, 'article_id' => $articleId], 201);
}

if ($action === 'delete-article') {
    $actor = require_api_login();
    $articleId = (int) ($data['article_id'] ?? 0);
    if (in_array($actor['role'], ['moderator', 'admin'], true)) {
        $delete = $pdo->prepare('DELETE FROM articles WHERE id=:id');
        $delete->execute(['id' => $articleId]);
    } elseif ($actor['role'] === 'author') {
        $delete = $pdo->prepare('DELETE FROM articles WHERE id=:id AND author_id=:author');
        $delete->execute(['id' => $articleId, 'author' => $actor['id']]);
    } else {
        json_response(['error' => 'Недостаточно прав.'], 403);
    }
    if (!$delete->rowCount()) json_response(['error' => 'Статья не найдена.'], 404);
    json_response(['ok' => true]);
}

if ($action === 'moderate') {
    $reviewer = require_api_role(['moderator','admin']);
    $articleId = (int) ($data['article_id'] ?? 0);
    $decision = $data['decision'] ?? '';
    if (!in_array($decision, ['approved','rejected'], true)) json_response(['error' => 'Некорректное решение.'], 422);
    $pdo->beginTransaction();
    $check = $pdo->prepare('SELECT id FROM articles WHERE id=:id AND status="review" AND ban_hold=0 FOR UPDATE');
    $check->execute(['id' => $articleId]);
    if (!$check->fetchColumn()) { $pdo->rollBack(); json_response(['error' => 'Статья уже обработана.'], 409); }
    $status = $decision === 'approved' ? 'published' : 'rejected';
    $note = trim($data['note'] ?? '') ?: null;
    $updated = $pdo->prepare('UPDATE articles SET status=:status,published_at=IF(:decision="approved",NOW(),published_at),reviewed_by=:reviewer,reviewed_at=NOW(),moderation_note=:note WHERE id=:id AND ban_hold=0');
    $updated->execute(['status' => $status, 'decision' => $decision, 'reviewer' => $reviewer['id'], 'note' => $note, 'id' => $articleId]);
    $pdo->prepare('INSERT INTO moderation_log (article_id,reviewer_id,decision,note) VALUES (:article,:reviewer,:decision,:note)')->execute(['article' => $articleId, 'reviewer' => $reviewer['id'], 'decision' => $decision, 'note' => $note]);
    $pdo->commit();
    json_response(['ok' => true]);
}

if ($action === 'role') {
    require_api_role(['admin']);
    $target = (int) ($data['user_id'] ?? 0);
    $role = $data['role'] ?? '';
    if (!in_array($role, ['viewer','author','moderator'], true) || $target === (int) $user['id']) json_response(['error' => 'Эту роль нельзя назначить.'], 422);
    $pdo->prepare('UPDATE users SET role=:role WHERE id=:id')->execute(['role' => $role, 'id' => $target]);
    json_response(['ok' => true]);
}

if ($action === 'hide-comment') {
    require_api_role(['moderator','admin']);
    $pdo->prepare('UPDATE comments SET status="hidden" WHERE id=:id')->execute(['id' => (int) ($data['comment_id'] ?? 0)]);
    json_response(['ok' => true]);
}

json_response(['error' => 'Неизвестное действие.'], 400);
