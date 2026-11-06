<?php
// controllers/ReclamoController.php
require_once __DIR__ . '/../config/database.php';

class ReclamoController {

    // Listar reclamos filtrados por barrio (y por usuario si es vecino)
    public function index($user) {
        header('Content-Type: application/json');
        
        try {
            $db = Database::getConnection();

            $barrio_id = $user['barrio_id'] ?? null;
            $rol = $user['rol'] ?? '';
            $user_id = $user['user_id'] ?? null;

            // Consulta con subconsulta segura para el adjunto
            $sql = "
                SELECT r.*, 
                       c.nombre AS categoria_nombre, 
                       l.identificador AS lote_identificador,
                       u.nombre AS vecino_nombre, u.apellido AS vecino_apellido,
                       (SELECT ra.url_archivo FROM reclamo_adjuntos ra WHERE ra.reclamo_id = r.id LIMIT 1) AS imagen_url
                FROM reclamos r
                LEFT JOIN categorias_reclamo c ON r.categoria_id = c.id
                LEFT JOIN lotes l ON r.lote_id = l.id
                LEFT JOIN usuarios u ON r.usuario_id = u.id
                WHERE r.barrio_id = :barrio_id
            ";

            $params = [':barrio_id' => $barrio_id];

            // Si es vecino, solo ve sus propios reclamos
            if ($rol === 'vecino') {
                $sql .= " AND r.usuario_id = :user_id";
                $params[':user_id'] = $user_id;
            }

            $sql .= " ORDER BY r.created_at DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $reclamos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "success" => true,
                "data" => $reclamos
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "error" => "Error en base de datos o servidor: " . $e->getMessage()
            ]);
        }
    }

    // Crear un nuevo reclamo con soporte de imagen y tabla reclamo_adjuntos
    public function store($user) {
        header('Content-Type: application/json');
        $db = Database::getConnection();

        $barrio_id = $user['barrio_id'];
        $user_id = $user['user_id'];

        $categoria_id = $_POST['categoria_id'] ?? null;
        $lote_id = $_POST['lote_id'] ?? null;
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $prioridad = $_POST['prioridad'] ?? 'media';

        if (empty($categoria_id) || empty($lote_id) || empty($titulo) || empty($descripcion)) {
            http_response_code(400);
            echo json_encode(["error" => "Faltan datos obligatorios para crear el reclamo"]);
            return;
        }

        try {
            $db->beginTransaction();

            // 1. Insertar el reclamo principal
            $stmt = $db->prepare("
                INSERT INTO reclamos (barrio_id, categoria_id, lote_id, usuario_id, titulo, descripcion, prioridad, estado)
                VALUES (:barrio_id, :categoria_id, :lote_id, :usuario_id, :titulo, :descripcion, :prioridad, 'pendiente')
            ");
            
            $stmt->execute([
                ':barrio_id' => $barrio_id,
                ':categoria_id' => $categoria_id,
                ':lote_id' => $lote_id,
                ':usuario_id' => $user_id,
                ':titulo' => $titulo,
                ':descripcion' => $descripcion,
                ':prioridad' => $prioridad
            ]);

            $reclamo_id = $db->lastInsertId();

            // 2. Procesar imágenes/archivos adjuntos y guardarlos en `reclamo_adjuntos`
            if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../uploads/reclamos/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
                $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'heic'];

                if (in_array($ext, $extensionesPermitidas)) {
                    $nombreArchivo = 'reclamo_' . $reclamo_id . '_' . time() . '.' . $ext;
                    $rutaDestino = $uploadDir . $nombreArchivo;

                    if (move_uploaded_file($_FILES['imagen']['tmp_name'], $rutaDestino)) {
                        $url_archivo = 'uploads/reclamos/' . $nombreArchivo;

                        $stmtAdj = $db->prepare("
                            INSERT INTO reclamo_adjuntos (reclamo_id, usuario_id, url_archivo, tipo)
                            VALUES (:reclamo_id, :usuario_id, :url_archivo, 'foto_inicial')
                        ");
                        $stmtAdj->execute([
                            ':reclamo_id' => $reclamo_id,
                            ':usuario_id' => $user_id,
                            ':url_archivo' => $url_archivo
                        ]);
                    }
                }
            }

            // 3. Registrar historial inicial
            $stmtHist = $db->prepare("
                INSERT INTO reclamo_historial (reclamo_id, usuario_id, comentario, estado_nuevo)
                VALUES (:reclamo_id, :usuario_id, 'Reclamo creado por el residente con archivo adjunto', 'pendiente')
            ");
            $stmtHist->execute([
                ':reclamo_id' => $reclamo_id,
                ':usuario_id' => $user_id
            ]);

            $db->commit();

            http_response_code(201);
            echo json_encode([
                "success" => true,
                "message" => "Reclamo creado exitosamente con su adjunto",
                "reclamo_id" => $reclamo_id
            ]);

        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(["error" => "Error al guardar el reclamo: " . $e->getMessage()]);
        }
    }

    // Actualizar el estado y asignación de un reclamo (Solo intendente o personal)
    public function updateEstado($user, $reclamo_id) {
        header('Content-Type: application/json');
        
        if (!in_array($user['rol'], ['intendente', 'personal', 'admin_general'])) {
            http_response_code(403);
            echo json_encode(["error" => "No tenés permisos para realizar esta acción"]);
            return;
        }

        $db = Database::getConnection();
        $barrio_id = $user['barrio_id'];
        $input = json_decode(file_get_contents('php://input'), true);

        $nuevo_estado = $input['estado'] ?? null;
        $asignado_a = $input['asignado_a_usuario_id'] ?? null;
        $comentario = trim($input['comentario'] ?? '');

        if (!in_array($nuevo_estado, ['pendiente', 'en_proceso', 'resuelto', 'rechazado'])) {
            http_response_code(400);
            echo json_encode(["error" => "Estado inválido"]);
            return;
        }

        try {
            $db->beginTransaction();

            $stmtCheck = $db->prepare("SELECT estado FROM reclamos WHERE id = :id AND barrio_id = :barrio_id");
            $stmtCheck->execute([':id' => $reclamo_id, ':barrio_id' => $barrio_id]);
            $reclamoActual = $stmtCheck->fetch();

            if (!$reclamoActual) {
                http_response_code(404);
                echo json_encode(["error" => "Reclamo no encontrado"]);
                return;
            }

            $estado_anterior = $reclamoActual['estado'];

            $sqlUpdate = "UPDATE reclamos SET estado = :estado";
            $params = [':estado' => $nuevo_estado, ':id' => $reclamo_id, ':barrio_id' => $barrio_id];

            if ($asignado_a !== null) {
                $sqlUpdate .= ", asignado_a_usuario_id = :asignado";
                $params[':asignado'] = $asignado_a;
            }

            if ($nuevo_estado === 'resuelto') {
                $sqlUpdate .= ", closed_at = NOW()";
            }

            $sqlUpdate .= " WHERE id = :id AND barrio_id = :barrio_id";
            
            $stmtUpdate = $db->prepare($sqlUpdate);
            $stmtUpdate->execute($params);

            $stmtHist = $db->prepare("
                INSERT INTO reclamo_historial (reclamo_id, usuario_id, comentario, estado_anterior, estado_nuevo)
                VALUES (:reclamo_id, :usuario_id, :comentario, :estado_anterior, :estado_nuevo)
            ");
            $stmtHist->execute([
                ':reclamo_id' => $reclamo_id,
                ':usuario_id' => $user['user_id'],
                ':comentario' => empty($comentario) ? "Cambio de estado a: {$nuevo_estado}" : $comentario,
                ':estado_anterior' => $estado_anterior,
                ':estado_nuevo' => $nuevo_estado
            ]);

            $db->commit();

            echo json_encode([
                "success" => true,
                "message" => "Reclamo actualizado correctamente"
            ]);

        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(["error" => "Error al actualizar el reclamo"]);
        }
    }
// Obtener el historial de comentarios de un reclamo
    public function obtenerHistorial($user, $reclamoId) {
        header('Content-Type: application/json');

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT rh.*, u.nombre, u.apellido
                FROM reclamo_historial rh
                LEFT JOIN usuarios u ON rh.usuario_id = u.id
                WHERE rh.reclamo_id = :reclamo_id
                ORDER BY rh.created_at ASC
            ");
            $stmt->execute([':reclamo_id' => $reclamoId]);
            $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $historial]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error al obtener historial: ' . $e->getMessage()]);
        }
    }

    // Agregar un comentario de gestión por parte del intendente
    public function agregarComentario($user, $reclamoId) {
        header('Content-Type: application/json');

        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $comentario = trim($input['comentario'] ?? '');

            if (empty($comentario)) {
                http_response_code(400);
                echo json_encode(['error' => 'El comentario no puede estar vacío.']);
                return;
            }

            $db = Database::getConnection();

            $stmt = $db->prepare("
                INSERT INTO reclamo_historial (reclamo_id, usuario_id, comentario, es_interno)
                VALUES (:reclamo_id, :usuario_id, :comentario, 0)
            ");
            $stmt->execute([
                ':reclamo_id' => $reclamoId,
                ':usuario_id' => $user['user_id'],
                ':comentario' => $comentario
            ]);

            echo json_encode(['success' => true, 'message' => 'Comentario registrado exitosamente.']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error al guardar comentario: ' . $e->getMessage()]);
        }
    }
}