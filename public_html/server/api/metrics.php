<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_api_role(['moderator', 'admin']);
json_response(['server' => server_metrics()]);
