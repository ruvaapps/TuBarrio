<?php
// config/cors.php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$sameOrigin = $host !== '' ? ($scheme . '://' . $host) : '';

$envOrigins = getenv('CORS_ALLOWED_ORIGINS') ?: '';
$allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $envOrigins))));

if (empty($allowedOrigins) && $sameOrigin !== '') {
    // Fallback seguro: solo mismo origen si no hay configuración explícita.
    $allowedOrigins[] = $sameOrigin;
}

$isAllowedOrigin = $origin !== '' && in_array($origin, $allowedOrigins, true);

if ($isAllowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (!$isAllowedOrigin) {
        http_response_code(403);
        exit();
    }
    http_response_code(204);
    exit();
}