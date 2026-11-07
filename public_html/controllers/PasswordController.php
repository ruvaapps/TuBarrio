<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/rate_limiter.php';

class PasswordController
{
    private const CODE_EXP_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;

    private function getClientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    private function enforceRateLimit(string $bucket, int $maxAttempts, int $windowSeconds, string $errorMessage): bool
    {
        $result = RateLimiter::consume($bucket, $maxAttempts, $windowSeconds);
        if (!$result['allowed']) {
            header('Retry-After: ' . (string) $result['retry_after']);
            http_response_code(429);
            echo json_encode(['error' => $errorMessage]);
            return false;
        }

        return true;
    }

    public function requestCode()
    {
        header('Content-Type: application/json');
        $input = $this->readJson();

        $email = trim($input['email'] ?? '');

        $emailHash = hash('sha256', strtolower($email));
        if (!$this->enforceRateLimit('pwd_request:ip:' . $this->getClientIp(), 8, 300, 'Demasiadas solicitudes. Intentalo más tarde.')) {
            return;
        }
        if (!$this->enforceRateLimit('pwd_request:email:' . $emailHash, 5, 300, 'Demasiadas solicitudes para este email. Intentalo más tarde.')) {
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email inválido.']);
            return;
        }

        try {
            $db = Database::getConnection();
            $this->ensureResetTable($db);

            $stmt = $db->prepare("
                SELECT id, nombre, apellido, email
                FROM usuarios
                WHERE email = :email
                LIMIT 1
            ");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Respuesta genérica para no filtrar existencia de cuenta.
            $genericResponse = ['success' => true, 'message' => 'Si el email existe, se envió un código de verificación.'];

            if (!$user) {
                echo json_encode($genericResponse);
                return;
            }

            $db->beginTransaction();

            // Invalidar códigos previos no usados.
            $stmtInvalidate = $db->prepare("
                UPDATE password_reset_codes
                SET used_at = NOW()
                WHERE usuario_id = :usuario_id AND used_at IS NULL
            ");
            $stmtInvalidate->execute([':usuario_id' => $user['id']]);

            $code = (string) random_int(100000, 999999);
            $codeHash = hash('sha256', $code);
            $expiresAt = date('Y-m-d H:i:s', time() + (self::CODE_EXP_MINUTES * 60));

            $stmtInsert = $db->prepare("
                INSERT INTO password_reset_codes (usuario_id, codigo_hash, expires_at)
                VALUES (:usuario_id, :codigo_hash, :expires_at)
            ");
            $stmtInsert->execute([
                ':usuario_id' => $user['id'],
                ':codigo_hash' => $codeHash,
                ':expires_at' => $expiresAt
            ]);

            $db->commit();

            $subject = 'Codigo de verificacion - TuBarrio';
            $message = "Hola {$user['nombre']} {$user['apellido']},\n\n"
                     . "Tu codigo para cambiar la contrasena es: {$code}\n"
                     . "Vence en " . self::CODE_EXP_MINUTES . " minutos.\n\n"
                     . "Si no solicitaste este cambio, ignora este mensaje.\n";
            $headers = "From: no-reply@tubarrio.lovar.com.ar\r\n"
                     . "X-Mailer: PHP/" . phpversion();

            @mail($user['email'], $subject, $message, $headers);

            echo json_encode($genericResponse);
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('PasswordController::requestCode error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'No se pudo procesar la solicitud.']);
        }
    }

    public function verifyCode()
    {
        header('Content-Type: application/json');
        $input = $this->readJson();

        $email = trim($input['email'] ?? '');
        $code = trim($input['codigo'] ?? '');

        $emailHash = hash('sha256', strtolower($email));
        if (!$this->enforceRateLimit('pwd_verify:ip:' . $this->getClientIp(), 20, 300, 'Demasiados intentos. Esperá unos minutos.')) {
            return;
        }
        if (!$this->enforceRateLimit('pwd_verify:email:' . $emailHash, 10, 300, 'Demasiados intentos para este email. Esperá unos minutos.')) {
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos inválidos.']);
            return;
        }

        try {
            $db = Database::getConnection();
            $this->ensureResetTable($db);
            $record = $this->getActiveCodeRecord($db, $email);

            if (!$record) {
                http_response_code(400);
                echo json_encode(['error' => 'Código inválido o expirado.']);
                return;
            }

            if ((int)$record['attempts'] >= self::MAX_ATTEMPTS) {
                http_response_code(429);
                echo json_encode(['error' => 'Se alcanzó el máximo de intentos. Solicitá un nuevo código.']);
                return;
            }

            $hash = hash('sha256', $code);

            if (!hash_equals($record['codigo_hash'], $hash)) {
                $stmtAttempt = $db->prepare("
                    UPDATE password_reset_codes
                    SET attempts = attempts + 1
                    WHERE id = :id
                ");
                $stmtAttempt->execute([':id' => $record['id']]);

                http_response_code(400);
                echo json_encode(['error' => 'Código inválido o expirado.']);
                return;
            }

            echo json_encode(['success' => true, 'message' => 'Código válido.']);
        } catch (Exception $e) {
            error_log('PasswordController::verifyCode error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'No se pudo verificar el código.']);
        }
    }

    public function resetPassword()
    {
        header('Content-Type: application/json');
        $input = $this->readJson();

        $email = trim($input['email'] ?? '');
        $code = trim($input['codigo'] ?? '');
        $newPassword = trim($input['new_password'] ?? '');
        $confirmPassword = trim($input['confirm_password'] ?? '');

        $emailHash = hash('sha256', strtolower($email));
        if (!$this->enforceRateLimit('pwd_reset:ip:' . $this->getClientIp(), 10, 300, 'Demasiados intentos. Esperá unos minutos.')) {
            return;
        }
        if (!$this->enforceRateLimit('pwd_reset:email:' . $emailHash, 5, 300, 'Demasiados intentos para este email. Esperá unos minutos.')) {
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos inválidos.']);
            return;
        }

        if (!$this->isStrongPassword($newPassword)) {
            http_response_code(400);
            echo json_encode(['error' => 'La contraseña debe tener al menos 8 caracteres, mayúscula, minúscula y número.']);
            return;
        }

        if ($newPassword !== $confirmPassword) {
            http_response_code(400);
            echo json_encode(['error' => 'Las contraseñas no coinciden.']);
            return;
        }

        try {
            $db = Database::getConnection();
            $this->ensureResetTable($db);
            $record = $this->getActiveCodeRecord($db, $email);

            if (!$record) {
                http_response_code(400);
                echo json_encode(['error' => 'Código inválido o expirado.']);
                return;
            }

            if ((int)$record['attempts'] >= self::MAX_ATTEMPTS) {
                http_response_code(429);
                echo json_encode(['error' => 'Se alcanzó el máximo de intentos. Solicitá un nuevo código.']);
                return;
            }

            $hash = hash('sha256', $code);

            if (!hash_equals($record['codigo_hash'], $hash)) {
                $stmtAttempt = $db->prepare("
                    UPDATE password_reset_codes
                    SET attempts = attempts + 1
                    WHERE id = :id
                ");
                $stmtAttempt->execute([':id' => $record['id']]);

                http_response_code(400);
                echo json_encode(['error' => 'Código inválido o expirado.']);
                return;
            }

            $db->beginTransaction();

            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmtUpdateUser = $db->prepare("
                UPDATE usuarios
                SET password_hash = :password_hash
                WHERE id = :usuario_id
            ");
            $stmtUpdateUser->execute([
                ':password_hash' => $passwordHash,
                ':usuario_id' => $record['usuario_id']
            ]);

            $stmtUseCode = $db->prepare("
                UPDATE password_reset_codes
                SET used_at = NOW()
                WHERE id = :id
            ");
            $stmtUseCode->execute([':id' => $record['id']]);

            // Opcional: invalidar todos los códigos activos del usuario.
            $stmtInvalidateAll = $db->prepare("
                UPDATE password_reset_codes
                SET used_at = NOW()
                WHERE usuario_id = :usuario_id AND used_at IS NULL
            ");
            $stmtInvalidateAll->execute([':usuario_id' => $record['usuario_id']]);

            $db->commit();

            echo json_encode(['success' => true, 'message' => 'Contraseña actualizada con éxito.']);
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('PasswordController::resetPassword error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'No se pudo actualizar la contraseña.']);
        }
    }

    private function ensureResetTable(PDO $db)
    {
        // Crea la tabla si todavía no existe para evitar 500 por migración faltante.
        $db->exec("CREATE TABLE IF NOT EXISTS password_reset_codes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            codigo_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_prc_usuario_estado (usuario_id, used_at, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function getActiveCodeRecord(PDO $db, string $email)
    {
        $stmt = $db->prepare("
            SELECT prc.id, prc.usuario_id, prc.codigo_hash, prc.expires_at, prc.attempts
            FROM password_reset_codes prc
            INNER JOIN usuarios u ON u.id = prc.usuario_id
            WHERE u.email = :email
              AND prc.used_at IS NULL
              AND prc.expires_at >= NOW()
            ORDER BY prc.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function isStrongPassword(string $password): bool
    {
        if (strlen($password) < 8) return false;
        if (!preg_match('/[A-Z]/', $password)) return false;
        if (!preg_match('/[a-z]/', $password)) return false;
        if (!preg_match('/\d/', $password)) return false;
        return true;
    }

    private function readJson(): array
    {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }
}
