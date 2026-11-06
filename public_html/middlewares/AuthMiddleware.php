<?php
// middlewares/AuthMiddleware.php
require_once __DIR__ . '/../helpers/jwt_helper.php';

class AuthMiddleware {
    
    public static function verify() {
        header('Content-Type: application/json');
        
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            http_response_code(401);
            echo json_encode(["error" => "Token de autenticación no proporcionado o inválido"]);
            exit;
        }

        $jwt = $matches[1];
        $payload = JWTHelper::verifyToken($jwt);

        if (!$payload) {
            http_response_code(401);
            echo json_encode(["error" => "Token inválido o expirado"]);
            exit;
        }

        // Devuelve los datos del usuario decodificados del token
        return $payload;
    }
}