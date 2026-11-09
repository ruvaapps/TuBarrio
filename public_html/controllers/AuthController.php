<?php
// controllers/AuthController.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/jwt_helper.php';
require_once __DIR__ . '/../helpers/rate_limiter.php';

class AuthController {

    private const REFRESH_COOKIE_NAME = 'refresh_token';
    private const REFRESH_DEFAULT_DAYS = 14;

    private function isHttpsRequest() {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        return false;
    }

    private function setAuthCookie($token) {
        $secure = $this->isHttpsRequest();
        setcookie('auth_token', $token, [
            'expires' => time() + (60 * 30),
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    private function clearAuthCookie() {
        $secure = $this->isHttpsRequest();
        setcookie('auth_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    private function setRefreshCookie($refreshToken) {
        $secure = $this->isHttpsRequest();
        $days = (int) (getenv('REFRESH_TOKEN_DAYS') ?: self::REFRESH_DEFAULT_DAYS);
        if ($days <= 0) {
            $days = self::REFRESH_DEFAULT_DAYS;
        }

        setcookie(self::REFRESH_COOKIE_NAME, $refreshToken, [
            'expires' => time() + ($days * 24 * 60 * 60),
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    private function clearRefreshCookie() {
        $secure = $this->isHttpsRequest();
        setcookie(self::REFRESH_COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    private function getRefreshTokenFromCookie() {
        return $_COOKIE[self::REFRESH_COOKIE_NAME] ?? '';
    }

    private function hashRefreshToken($token) {
        return hash('sha256', $token);
    }

    private function ensureAuthTokenTables(PDO $db) {
        $db->exec("CREATE TABLE IF NOT EXISTS jwt_revoked_tokens (
            jti CHAR(64) PRIMARY KEY,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_jrt_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS auth_refresh_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_art_usuario (usuario_id),
            INDEX idx_art_expires (expires_at),
            UNIQUE KEY uk_art_token_hash (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS auth_refresh_reuse_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NULL,
            token_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_arr_usuario (usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function getClientIp() {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    private const MAX_LOGIN_ATTEMPTS = 3;
    private const LOGIN_LOCK_MINUTES = 5;

    // Asegura las columnas de control de intentos en la tabla usuarios
    private function ensureLoginAttemptColumns(PDO $db) {
        $stmt = $db->query("SHOW COLUMNS FROM usuarios LIKE 'intentos_fallidos'");
        if (!$stmt->fetch()) {
            $db->exec("ALTER TABLE usuarios ADD COLUMN intentos_fallidos INT NOT NULL DEFAULT 0");
        }
        $stmt = $db->query("SHOW COLUMNS FROM usuarios LIKE 'bloqueado_hasta'");
        if (!$stmt->fetch()) {
            $db->exec("ALTER TABLE usuarios ADD COLUMN bloqueado_hasta DATETIME NULL DEFAULT NULL");
        }
    }

    // Devuelve segundos restantes de bloqueo (0 = no bloqueado)
    private function getLockRemainingSeconds(array $user) {
        if (empty($user['bloqueado_hasta'])) {
            return 0;
        }
        $until = strtotime((string) $user['bloqueado_hasta']);
        if ($until === false || $until <= time()) {
            return 0;
        }
        return $until - time();
    }

    // Registra un intento fallido y bloquea temporalmente al llegar al limite
    private function registerFailedAttempt(array $user) {
        $db = Database::getConnection();
        $this->ensureLoginAttemptColumns($db);

        $intentos = ((int) ($user['intentos_fallidos'] ?? 0)) + 1;

        if ($intentos >= self::MAX_LOGIN_ATTEMPTS) {
            $lockUntil = date('Y-m-d H:i:s', time() + (self::LOGIN_LOCK_MINUTES * 60));
            $stmt = $db->prepare("UPDATE usuarios
                                  SET intentos_fallidos = 0, bloqueado_hasta = :bloqueado_hasta
                                  WHERE id = :id");
            $stmt->execute([
                ':bloqueado_hasta' => $lockUntil,
                ':id' => (int) $user['id']
            ]);
            return ['blocked' => true, 'lock_seconds' => self::LOGIN_LOCK_MINUTES * 60];
        }

        $stmt = $db->prepare("UPDATE usuarios SET intentos_fallidos = :intentos WHERE id = :id");
        $stmt->execute([
            ':intentos' => $intentos,
            ':id' => (int) $user['id']
        ]);

        $restantes = self::MAX_LOGIN_ATTEMPTS - $intentos;
        return [
            'blocked' => false,
            'restantes' => $restantes
        ];
    }

    // Reinicia los intentos fallidos tras un login exitoso
    private function resetFailedAttempts($userId) {
        $db = Database::getConnection();
        $this->ensureLoginAttemptColumns($db);
        $stmt = $db->prepare("UPDATE usuarios
                              SET intentos_fallidos = 0, bloqueado_hasta = NULL
                              WHERE id = :id");
        $stmt->execute([':id' => (int) $userId]);
    }

    private function enforceLoginRateLimit($email) {
        $ip = $this->getClientIp();
        $emailHash = hash('sha256', strtolower(trim((string) $email)));

        $ipBucket = RateLimiter::consume('login:ip:' . $ip, 20, 300);
        $accountBucket = RateLimiter::consume('login:email:' . $emailHash, 10, 300);

        if (!$ipBucket['allowed'] || !$accountBucket['allowed']) {
            $retryAfter = max($ipBucket['retry_after'], $accountBucket['retry_after']);
            header('Retry-After: ' . (string) $retryAfter);
            http_response_code(429);
            echo json_encode(['error' => 'Demasiados intentos. Intentalo nuevamente en unos minutos.']);
            return false;
        }

        return true;
    }

    private function revokeJwtByPayload($payload) {
        if (!is_array($payload) || empty($payload['jti']) || empty($payload['exp'])) {
            return;
        }

        $db = Database::getConnection();
        $this->ensureAuthTokenTables($db);
        $expiresAt = date('Y-m-d H:i:s', (int) $payload['exp']);

        $stmt = $db->prepare("INSERT INTO jwt_revoked_tokens (jti, expires_at)
                              VALUES (:jti, :expires_at)
                              ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)");
        $stmt->execute([
            ':jti' => (string) $payload['jti'],
            ':expires_at' => $expiresAt
        ]);
    }

    private function isRevokedJwtPayload($payload) {
        if (!is_array($payload) || empty($payload['jti'])) {
            return false;
        }

        $db = Database::getConnection();
        $this->ensureAuthTokenTables($db);
        $stmt = $db->prepare('SELECT 1 FROM jwt_revoked_tokens WHERE jti = :jti LIMIT 1');
        $stmt->execute([':jti' => (string) $payload['jti']]);
        return (bool) $stmt->fetchColumn();
    }

    private function revokeCurrentJwtIfPresent() {
        $token = $this->getTokenFromRequest();
        if ($token === '') {
            return;
        }

        $payload = JWTHelper::verifyToken($token);
        if (!$payload) {
            return;
        }

        try {
            $this->revokeJwtByPayload($payload);
        } catch (Exception $e) {
            error_log('AuthController::revokeCurrentJwtIfPresent error: ' . $e->getMessage());
        }
    }

    private function issueRefreshTokenForUser($userId) {
        $days = (int) (getenv('REFRESH_TOKEN_DAYS') ?: self::REFRESH_DEFAULT_DAYS);
        if ($days <= 0) {
            $days = self::REFRESH_DEFAULT_DAYS;
        }

        $refreshToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $tokenHash = $this->hashRefreshToken($refreshToken);
        $expiresAt = date('Y-m-d H:i:s', time() + ($days * 24 * 60 * 60));

        $db = Database::getConnection();
        $this->ensureAuthTokenTables($db);
        $stmt = $db->prepare("INSERT INTO auth_refresh_tokens (usuario_id, token_hash, expires_at)
                              VALUES (:usuario_id, :token_hash, :expires_at)");
        $stmt->execute([
            ':usuario_id' => (int) $userId,
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt
        ]);

        return $refreshToken;
    }

    private function revokeRefreshToken($refreshToken) {
        if ($refreshToken === '') {
            return;
        }

        $db = Database::getConnection();
        $this->ensureAuthTokenTables($db);
        $stmt = $db->prepare("UPDATE auth_refresh_tokens
                              SET revoked_at = NOW()
                              WHERE token_hash = :token_hash
                                AND revoked_at IS NULL");
        $stmt->execute([':token_hash' => $this->hashRefreshToken($refreshToken)]);
    }

    private function getActiveRefreshRecord($refreshToken) {
        if ($refreshToken === '') {
            return null;
        }

        $db = Database::getConnection();
        $this->ensureAuthTokenTables($db);

        $stmt = $db->prepare("SELECT id, usuario_id
                              FROM auth_refresh_tokens
                              WHERE token_hash = :token_hash
                                AND revoked_at IS NULL
                                AND expires_at >= NOW()
                              LIMIT 1");
        $stmt->execute([':token_hash' => $this->hashRefreshToken($refreshToken)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function revokeRefreshById($id) {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE auth_refresh_tokens
                              SET revoked_at = NOW()
                              WHERE id = :id AND revoked_at IS NULL");
        $stmt->execute([':id' => (int) $id]);
    }

    private function revokeAllRefreshTokensForUser($userId) {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE auth_refresh_tokens
                              SET revoked_at = NOW()
                              WHERE usuario_id = :usuario_id
                                AND revoked_at IS NULL");
        $stmt->execute([':usuario_id' => (int) $userId]);
    }

    private function detectRefreshReuseAndRevokeFamily($refreshToken) {
        if ($refreshToken === '') {
            return;
        }

        $db = Database::getConnection();
        $this->ensureAuthTokenTables($db);
        $tokenHash = $this->hashRefreshToken($refreshToken);

        $stmt = $db->prepare("SELECT usuario_id, revoked_at, expires_at
                              FROM auth_refresh_tokens
                              WHERE token_hash = :token_hash
                              LIMIT 1");
        $stmt->execute([':token_hash' => $tokenHash]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return;
        }

        $isExpired = strtotime((string) $record['expires_at']) < time();
        $isRevoked = !empty($record['revoked_at']);

        if ($isExpired || $isRevoked) {
            $stmtEvent = $db->prepare("INSERT INTO auth_refresh_reuse_events (usuario_id, token_hash)
                                       VALUES (:usuario_id, :token_hash)");
            $stmtEvent->execute([
                ':usuario_id' => $record['usuario_id'] ? (int) $record['usuario_id'] : null,
                ':token_hash' => $tokenHash
            ]);

            if (!empty($record['usuario_id'])) {
                $this->revokeAllRefreshTokensForUser((int) $record['usuario_id']);
            }
        }
    }

    private function getTokenFromRequest() {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        if (!empty($authHeader) && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return $matches[1];
        }

        if (!empty($_COOKIE['auth_token'])) {
            return $_COOKIE['auth_token'];
        }

        return '';
    }

    private function getUserProfileById($userId) {
        $db = Database::getConnection();
        $stmt = $db->prepare("\n            SELECT u.id, u.barrio_id, u.nombre, u.apellido, u.email, u.rol, u.estado,\n                   b.nombre AS barrio_nombre,\n                   ul.lote_id\n            FROM usuarios u\n            INNER JOIN barrios b ON u.barrio_id = b.id\n            LEFT JOIN usuario_lote ul ON u.id = ul.usuario_id\n            WHERE u.id = :id\n            LIMIT 1\n        ");
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch();
    }

public function login() {
        header('Content-Type: application/json');

        // Leer datos JSON del body
        $input = json_decode(file_get_contents('php://input'), true);

        $email = trim($input['email'] ?? '');
        $password = trim($input['password'] ?? '');

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(["error" => "Email y contraseña son obligatorios"]);
            return;
        }

        if (!$this->enforceLoginRateLimit($email)) {
            return;
        }

        $db = Database::getConnection();

        // Asegurar columnas de control de intentos antes de consultarlas
        $this->ensureLoginAttemptColumns($db);

        // 1. Buscar al usuario junto con su barrio y su lote asignado
        $stmt = $db->prepare("
            SELECT u.id, u.barrio_id, u.nombre, u.apellido, u.email, u.password_hash, u.rol, u.estado, 
                   u.intentos_fallidos, u.bloqueado_hasta,
                   b.nombre AS barrio_nombre,
                   ul.lote_id
            FROM usuarios u
            INNER JOIN barrios b ON u.barrio_id = b.id
            LEFT JOIN usuario_lote ul ON u.id = ul.usuario_id
            WHERE u.email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        // 2. Validar si la cuenta esta activa (antes de validar clave)
        if (!$user) {
            http_response_code(401);
            echo json_encode(["error" => "Credenciales inv\u00e1lidas"]);
            return;
        }

        if ($user['estado'] === 'bloqueado') {
            http_response_code(403);
            echo json_encode(["error" => "Tu cuenta est\u00e1 bloqueada. Contacta al administrador del barrio."]);
            return;
        }

        if ($user['estado'] !== 'activo') {
            http_response_code(403);
            echo json_encode(["error" => "Tu cuenta est\u00e1 pendiente de aprobaci\u00f3n o inactiva"]);
            return;
        }

        // 2b. Verificar bloqueo temporal por intentos fallidos
        $lockSeconds = $this->getLockRemainingSeconds($user);
        if ($lockSeconds > 0) {
            $minutos = (int) ceil($lockSeconds / 60);
            http_response_code(423);
            echo json_encode([
                "error" => "Cuenta temporalmente bloqueada por intentos fallidos. Intenta nuevamente en {$minutos} minuto(s)."
            ]);
            return;
        }

        // 3. Validar clave
        if (!password_verify($password, $user['password_hash'])) {
            $resultado = $this->registerFailedAttempt($user);

            if ($resultado['blocked']) {
                http_response_code(423);
                echo json_encode([
                    "error" => "Superaste el limite de " . self::MAX_LOGIN_ATTEMPTS . " intentos. Tu cuenta queda bloqueada por " . self::LOGIN_LOCK_MINUTES . " minutos."
                ]);
            } else {
                http_response_code(401);
                echo json_encode([
                    "error" => "Credenciales inv\u00e1lidas. Te quedan " . $resultado['restantes'] . " intento(s) antes del bloqueo."
                ]);
            }
            return;
        }

        // 3b. Login exitoso: reiniciar intentos fallidos
        $this->resetFailedAttempts($user['id']);

        // 4. GENERAR PAYLOAD Y TOKEN JWT (¡Esta parte es la que me consultabas y sí va!)
        $payload = [
            "user_id" => $user['id'],
            "barrio_id" => $user['barrio_id'],
            "rol" => $user['rol'],
            "nombre" => $user['nombre'] . ' ' . $user['apellido'],
            "email" => $user['email']
        ];

        $token = JWTHelper::createToken($payload);
        $refreshToken = $this->issueRefreshTokenForUser($user['id']);
        $this->setAuthCookie($token);
        $this->setRefreshCookie($refreshToken);

        // 5. Respuesta exitosa enviando el token y el lote_id dentro del usuario
        echo json_encode([
            "message" => "Login exitoso",
            "usuario" => [
                "id" => $user['id'],
                "nombre" => $user['nombre'],
                "apellido" => $user['apellido'],
                "email" => $user['email'],
                "rol" => $user['rol'],
                "barrio_id" => $user['barrio_id'],
                "barrio_nombre" => $user['barrio_nombre'],
                "lote_id" => $user['lote_id']
            ]
        ]);
    }

    public function logout() {
        header('Content-Type: application/json');
        $this->revokeCurrentJwtIfPresent();

        try {
            $refreshToken = $this->getRefreshTokenFromCookie();
            $this->revokeRefreshToken($refreshToken);
        } catch (Exception $e) {
            error_log('AuthController::logout revoke refresh error: ' . $e->getMessage());
        }

        $this->clearAuthCookie();
        $this->clearRefreshCookie();
        echo json_encode([
            'success' => true,
            'message' => 'Sesion cerrada'
        ]);
    }

    public function refresh() {
        header('Content-Type: application/json');

        $refreshToken = $this->getRefreshTokenFromCookie();
        if ($refreshToken === '') {
            http_response_code(401);
            echo json_encode(['error' => 'Refresh token no proporcionado']);
            return;
        }

        try {
            $this->detectRefreshReuseAndRevokeFamily($refreshToken);
            $record = $this->getActiveRefreshRecord($refreshToken);
            if (!$record) {
                $this->clearAuthCookie();
                $this->clearRefreshCookie();
                http_response_code(401);
                echo json_encode(['error' => 'Refresh token invalido o expirado']);
                return;
            }

            $user = $this->getUserProfileById($record['usuario_id']);
            if (!$user || ($user['estado'] ?? '') !== 'activo') {
                $this->revokeRefreshById($record['id']);
                $this->clearAuthCookie();
                $this->clearRefreshCookie();
                http_response_code(403);
                echo json_encode(['error' => 'Cuenta no disponible']);
                return;
            }

            $payload = [
                'user_id' => $user['id'],
                'barrio_id' => $user['barrio_id'],
                'rol' => $user['rol'],
                'nombre' => $user['nombre'] . ' ' . $user['apellido'],
                'email' => $user['email']
            ];

            $this->revokeRefreshById($record['id']);
            $newRefreshToken = $this->issueRefreshTokenForUser($user['id']);
            $newAccessToken = JWTHelper::createToken($payload);

            $this->setAuthCookie($newAccessToken);
            $this->setRefreshCookie($newRefreshToken);

            echo json_encode([
                'success' => true,
                'message' => 'Sesion renovada'
            ]);
        } catch (Exception $e) {
            error_log('AuthController::refresh error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    public function me() {
        header('Content-Type: application/json');

        $token = $this->getTokenFromRequest();
        if ($token === '') {
            http_response_code(401);
            echo json_encode(['error' => 'No autenticado']);
            return;
        }

        $payload = JWTHelper::verifyToken($token);
        if (!$payload || empty($payload['user_id']) || $this->isRevokedJwtPayload($payload)) {
            http_response_code(401);
            echo json_encode(['error' => 'Sesion invalida o expirada']);
            return;
        }

        try {
            $user = $this->getUserProfileById($payload['user_id']);
            if (!$user || ($user['estado'] ?? '') !== 'activo') {
                http_response_code(403);
                echo json_encode(['error' => 'Cuenta no disponible']);
                return;
            }

            echo json_encode([
                'success' => true,
                'usuario' => [
                    'id' => $user['id'],
                    'nombre' => $user['nombre'],
                    'apellido' => $user['apellido'],
                    'email' => $user['email'],
                    'rol' => $user['rol'],
                    'barrio_id' => $user['barrio_id'],
                    'barrio_nombre' => $user['barrio_nombre'],
                    'lote_id' => $user['lote_id']
                ]
            ]);
        } catch (Exception $e) {
            error_log('AuthController::me error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }
}