<?php
// config/cors.php

// Permitir solicitudes desde cualquier origen (puedes cambiar '*' por tu dominio específico en producción)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Si es una solicitud preflight (OPTIONS), responde con 200 y termina la ejecución
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}