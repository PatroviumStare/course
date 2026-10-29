<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (preg_match('#^/(assets|readme|database|tools|partials|storage|login|register|dashboard|editor|article|tag|profile|subscriptions|settings)/.*\.(css|js|png|jpe?g|gif|webp|ico|svg|md|html|docx)$#i', $path)) {
    return false;
}

$apiRoutes = [
    '/api/articles' => 'articles.php',
    '/api/articles.json' => 'articles.php',
    '/api/categories' => 'categories.php',
    '/api/auth' => 'auth.php',
    '/api/article' => 'article.php',
    '/api/dashboard' => 'dashboard.php',
    '/api/actions' => 'actions.php',
    '/api/edit-article' => 'edit-article.php',
    '/api/account' => 'account.php',
    '/api/random-article' => 'random.php',
    '/api/metrics' => 'metrics.php',
];
if (isset($apiRoutes[$path])) {
    require __DIR__ . '/api/' . $apiRoutes[$path];
    return true;
}
if ($path === '/rss.xml') {
    require __DIR__ . '/rss.php';
    return true;
}

$staticRoutes = [
    '/' => '/index.html',
    '/login' => '/login/index.html',
    '/register' => '/register/index.html',
    '/dashboard' => '/dashboard/index.html',
    '/editor' => '/editor/index.html',
    '/profile' => '/profile/index.html',
    '/subscriptions' => '/subscriptions/index.html',
    '/settings' => '/settings/index.html',
];
if (preg_match('#^/article/[^/]+/?$#', $path)) $staticRoutes[$path] = '/article/index.html';
if (preg_match('#^/tag/[^/]+/?$#', $path)) $staticRoutes[$path] = '/tag/index.html';
if (preg_match('#^/profile/[^/]+/?$#', $path)) $staticRoutes[$path] = '/profile/index.html';

if (isset($staticRoutes[$path])) {
    readfile($root . $staticRoutes[$path]);
    return true;
}

if (str_ends_with(strtolower($path), '.html') && is_file($root . $path)) return false;

http_response_code(404);
readfile($root . '/404.html');
return true;
