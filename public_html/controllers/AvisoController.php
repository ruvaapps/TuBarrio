<?php
require_once __DIR__ . '/../config/database.php';

class AvisoController {

    // Listar avisos (tipo = 'aviso')
    public function index($user) {
        header('Content-Type: application/json');

        if (!in_array($user['rol'] ?? '', ['intendente', 'admin_general'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'No tenés permisos para listar avisos']);
            return;
        }

        try {
            $barrio_id = $user['barrio_id'] ?? null;
            if (!$barrio_id) {
                http_response_code(400);
                echo json_encode(['error' => 'No se pudo identificar el barrio del usuario.']);
                return;
            }

            $db = Database::getConnection();

            $stmt = $db->prepare("SELECT m.*, l.identificador AS lote_identificador, u.nombre, u.apellido, u.email
                FROM multas m
                JOIN lotes l ON m.lote_id = l.id
                LEFT JOIN usuarios u ON m.usuario_id = u.id
                WHERE m.barrio_id = :barrio_id AND m.tipo = 'aviso'
                ORDER BY m.created_at DESC");
            $stmt->execute([':barrio_id' => $barrio_id]);
            $avisos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $avisos]);
        } catch (Exception $e) {
            error_log('AvisoController::index error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    // Registrar un nuevo aviso
    public function store($user) {
        header('Content-Type: application/json');

        if (!in_array($user['rol'] ?? '', ['intendente', 'admin_general'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'No tenés permisos para registrar avisos']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $usuario_id = $input['usuario_id'] ?? null;
        $tipo = $input['tipo'] ?? null;
        $observaciones = $input['observaciones'] ?? null;

        if (!$usuario_id || !$tipo || !$observaciones) {
            http_response_code(400);
            echo json_encode(['error' => 'Faltan datos obligatorios para registrar el aviso']);
            return;
        }

        try {
            $barrio_id = $user['barrio_id'] ?? null;
            if (!$barrio_id) {
                http_response_code(400);
                echo json_encode(['error' => 'No se pudo identificar el barrio del usuario']);
                return;
            }

            $db = Database::getConnection();

            // Verificar si ya existe un aviso pendiente del mismo tipo para el vecino
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM multas WHERE usuario_id = :usuario_id AND tipo_multa = 'aviso' AND estado = 'pendiente' AND observaciones = :observaciones");
            $stmtCheck->execute([':usuario_id' => $usuario_id, ':observaciones' => $observaciones]);
            $count = $stmtCheck->fetchColumn();

            if ($count > 0) {
                // Generar multa relacionada
                $stmtGetAviso = $db->prepare("SELECT * FROM multas WHERE usuario_id = :usuario_id AND tipo_multa = 'aviso' AND estado = 'pendiente' AND observaciones = :observaciones LIMIT 1");
                $stmtGetAviso->execute([':usuario_id' => $usuario_id, ':observaciones' => $observaciones]);
                $aviso = $stmtGetAviso->fetch(PDO::FETCH_ASSOC);

                if ($aviso) {
                    $this->generarMultaRelacionada($user, $aviso);
                    echo json_encode(['success' => true, 'message' => 'Multa generada por aviso previo']);
                    return;
                }
            }

            // Registrar nuevo aviso
            $stmtInsert = $db->prepare("INSERT INTO multas (barrio_id, lote_id, usuario_id, usuario_intendente_id, tipo, monto, observaciones, estado) VALUES (:barrio_id, :lote_id, :usuario_id, :intendente_id, 'aviso', NULL, :observaciones, 'pendiente')");

            // Obtener lote_id del usuario
            $stmtLote = $db->prepare("SELECT lote_id FROM usuario_lote WHERE usuario_id = :usuario_id LIMIT 1");
            $stmtLote->execute([':usuario_id' => $usuario_id]);
            $lote = $stmtLote->fetch(PDO::FETCH_ASSOC);
            $lote_id = $lote['lote_id'] ?? null;

            $stmtInsert->execute([
                ':barrio_id' => $barrio_id,
                ':lote_id' => $lote_id,
                ':usuario_id' => $usuario_id,
                ':intendente_id' => $user['user_id'],
                ':observaciones' => $observaciones
            ]);

            echo json_encode(['success' => true, 'message' => 'Aviso registrado correctamente']);

        } catch (Exception $e) {
            error_log('AvisoController::store error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    private function generarMultaRelacionada($user, $aviso) {
        $db = Database::getConnection();

        $stmtInsert = $db->prepare("INSERT INTO multas (barrio_id, lote_id, usuario_id, usuario_intendente_id, tipo, monto, observaciones, estado, id_aviso_relacionado) VALUES (:barrio_id, :lote_id, :usuario_id, :intendente_id, 'multa', :monto, :observaciones, 'pendiente', :id_aviso_relacionado)");

        $stmtInsert->execute([
            ':barrio_id' => $user['barrio_id'],
            ':lote_id' => $aviso['lote_id'],
            ':usuario_id' => $aviso['usuario_id'],
            ':intendente_id' => $user['user_id'],
            ':monto' => 0, // o algún valor por defecto
            ':observaciones' => $aviso['observaciones'],
            ':id_aviso_relacionado' => $aviso['id']
        ]);
    }

}
