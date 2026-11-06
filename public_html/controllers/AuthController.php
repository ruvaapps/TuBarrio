<?php
// controllers/AuthController.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/jwt_helper.php';

class AuthController {
    
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

        $db = Database::getConnection();

        // 1. Buscar al usuario junto con su barrio y su lote asignado
        $stmt = $db->prepare("
            SELECT u.id, u.barrio_id, u.nombre, u.apellido, u.email, u.password_hash, u.rol, u.estado, 
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

        // 2. Validar existencia de usuario y clave
        if (!$user || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(["error" => "Credenciales inválidas"]);
            return;
        }

        // 3. Validar si la cuenta está activa
        if ($user['estado'] !== 'activo') {
            http_response_code(403);
            echo json_encode(["error" => "Tu cuenta está pendiente de aprobación o inactiva"]);
            return;
        }

        // 4. GENERAR PAYLOAD Y TOKEN JWT (¡Esta parte es la que me consultabas y sí va!)
        $payload = [
            "user_id" => $user['id'],
            "barrio_id" => $user['barrio_id'],
            "rol" => $user['rol'],
            "nombre" => $user['nombre'] . ' ' . $user['apellido'],
            "email" => $user['email']
        ];

        $token = JWTHelper::createToken($payload);

        // 5. Respuesta exitosa enviando el token y el lote_id dentro del usuario
        echo json_encode([
            "message" => "Login exitoso",
            "token" => $token,
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
}