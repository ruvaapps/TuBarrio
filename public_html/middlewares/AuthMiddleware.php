<?php
// middlewares/AuthMiddleware.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/jwt_helper.php';

class AuthMiddleware {

    private static function isRevokedJwtPayload($payload) {
        if (!is_array($payload) || empty($payload['jti'])) {
            return false;
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT 1 FROM jwt_revoked_tokens WHERE jti = :jti LIMIT 1');
            $stmt->execute([':jti' => (string) $payload['jti']]);
            return (bool) $stmt->fetchColumn();
        } catch (Exception $e) {
            error_log('AuthMiddleware::isRevokedJwtPayload error: ' . $e->getMessage());
            return true;
        }
    }

    public static function verify() {
        header('Content-Type: application/json');
        
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $jwt = '';

        if (!empty($authHeader) && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $jwt = $matches[1];
        } elseif (!empty($_COOKIE['auth_token'])) {
            $jwt = $_COOKIE['auth_token'];
        }

        if ($jwt === '') {
            http_response_code(401);
            echo json_encode(["error" => "Token de autenticación no proporcionado o inválido"]);
            exit;
        }

        $payload = JWTHelper::verifyToken($jwt);

        if (!$payload || self::isRevokedJwtPayload($payload)) {
            http_response_code(401);
            echo json_encode(["error" => "Token inválido o expirado"]);
            exit;
        }

        // Devuelve los datos del usuario decodificados del token
        return $payload;
    }
}