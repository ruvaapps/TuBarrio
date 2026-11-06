<?php
// controllers/UsuarioController.php
require_once __DIR__ . '/../config/database.php';

class UsuarioController {

    // Dar de alta a un vecino con Manzana y Lote
public function storeVecino($user) {
        header('Content-Type: application/json');

        if (!in_array($user['rol'], ['intendente', 'admin_general'])) {
            http_response_code(403);
            echo json_encode(["error" => "No tenés permisos para dar de alta vecinos"]);
            return;
        }

        $db = Database::getConnection();
        $barrio_id = $user['barrio_id'];

        $input = json_decode(file_get_contents('php://input'), true);
        
        $nombre = trim($input['nombre'] ?? '');
        $apellido = trim($input['apellido'] ?? '');
        $email = trim($input['email'] ?? '');
        $telefono = trim($input['telefono'] ?? '');
        $password = trim($input['password'] ?? '');
        $manzana = trim($input['manzana'] ?? '');
        $lote_identificador = trim($input['lote_identificador'] ?? '');
        $tipo_relacion = $input['tipo_relacion'] ?? 'propietario';

        if (empty($nombre) || empty($apellido) || empty($email) || empty($password) || empty($lote_identificador)) {
            http_response_code(400);
            echo json_encode(["error" => "Faltan datos obligatorios (nombre, apellido, email, contraseña y lote)"]);
            return;
        }

        try {
            $db->beginTransaction();

            // 1. Verificar si el lote ya existe usando parámetros posicionales (?)
            $stmtLoteCheck = $db->prepare("
                SELECT id FROM lotes 
                WHERE barrio_id = ? AND identificador = ? AND (manzana = ? OR ? = '')
            ");
            $stmtLoteCheck->execute([$barrio_id, $lote_identificador, $manzana, $manzana]);
            $lote = $stmtLoteCheck->fetch(PDO::FETCH_ASSOC);

            if ($lote) {
                $lote_id = $lote['id'];
            } else {
                // Si el lote no existe, lo creamos automáticamente
                $stmtNewLote = $db->prepare("
                    INSERT INTO lotes (barrio_id, manzana, identificador)
                    VALUES (?, ?, ?)
                ");
                $stmtNewLote->execute([$barrio_id, $manzana, $lote_identificador]);
                $lote_id = $db->lastInsertId();
            }

            // 2. Crear el usuario vecino
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmtUser = $db->prepare("
                INSERT INTO usuarios (barrio_id, nombre, apellido, email, telefono, password_hash, rol, estado)
                VALUES (?, ?, ?, ?, ?, ?, 'vecino', 'activo')
            ");
            $stmtUser->execute([$barrio_id, $nombre, $apellido, $email, $telefono, $password_hash]);

            $usuario_id = $db->lastInsertId();

            // 3. Vincular usuario al lote en la tabla pivote
            $stmtPivot = $db->prepare("
                INSERT INTO usuario_lote (usuario_id, lote_id, tipo_relacion)
                VALUES (?, ?, ?)
            ");
            $stmtPivot->execute([$usuario_id, $lote_id, $tipo_relacion]);

            $db->commit();

            http_response_code(201);
            echo json_encode([
                "success" => true,
                "message" => "Vecino dado de alta y vinculado al lote con éxito",
                "usuario_id" => $usuario_id
            ]);

        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(["error" => "Error al registrar el vecino: " . $e->getMessage()]);
        }
    }

    // Listar vecinos incluyendo la manzana del lote
    public function listarVecinos($user) {
        header('Content-Type: application/json');
        
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT u.id AS usuario_id, u.nombre, u.apellido, u.email, 
                       l.id AS lote_id, l.manzana, l.identificador AS lote_identificador, 
                       ul.tipo_relacion
                FROM usuarios u
                LEFT JOIN usuario_lote ul ON u.id = ul.usuario_id
                LEFT JOIN lotes l ON ul.lote_id = l.id
                WHERE u.barrio_id = :barrio_id AND u.rol = 'vecino'
            ");
            $stmt->execute([':barrio_id' => $user['barrio_id']]);
            $vecinos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true, 
                'data' => $vecinos
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false, 
                'error' => 'Error al listar vecinos: ' . $e->getMessage()
            ]);
        }
    }
}