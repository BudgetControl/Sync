<?php
require_once __DIR__ . '/../bootstrap/app.php';
$secret = (string) env('SECRET_KEY', '');
$provided = $_SERVER['HTTP_X_API_SECRET'] ?? '';
if ($secret === '' || !hash_equals($secret, $provided)) {
    http_response_code(403);
    exit('Access denied');
}
$app = \Slim\Factory\AppFactory::create();
require_once __DIR__ . '/../config/middleware.php';
require_once __DIR__ . '/../routes/api.php';
$app->run();
