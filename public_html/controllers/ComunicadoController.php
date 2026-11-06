<?php
// controllers/ComunicadoController.php
require_once __DIR__ . '/../config/database.php';

class ComunicadoController {

    // Listar comunicados activos del barrio
    public function index($user) {
        header('Content-Type: application/json');
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT c.*, u.nombre AS autor_nombre, u.apellido AS autor_apellido
            FROM comunicados c
            INNER JOIN usuarios u ON c.autor_id = u.id
            WHERE c.barrio_id = :barrio_id AND c.estado = 'publicado'
            ORDER BY c.fecha_publicacion DESC
        ");
        $stmt->execute([':barrio_id' => $user['barrio_id']]);
        
        echo json_encode([
            "success" => true,
            "data" => $stmt->fetchAll()
        ]);
    }

    // Crear un comunicado (Solo intendente o admin)
    public function store($user) {
        header('Content-Type: application/json');

        if (!in_array($user['rol'], ['intendente', 'admin_general'])) {
            http_response_code(403);
            echo json_encode(["error" => "No tenés permisos para publicar avisos"]);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $titulo = trim($input['titulo'] ?? '');
        $contenido = trim($input['contenido'] ?? '');
        $prioridad = $input['prioridad'] ?? 'normal';

        if (empty($titulo) || empty($contenido)) {
            http_response_code(400);
            echo json_encode(["error" => "El título y contenido son obligatorios"]);
            return;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO comunicados (barrio_id, autor_id, titulo, contenido, prioridad, estado, fecha_publicacion)
            VALUES (:barrio_id, :autor_id, :titulo, :contenido, :prioridad, 'publicado', NOW())
        ");

        $stmt->execute([
            ':barrio_id' => $user['barrio_id'],
            ':autor_id' => $user['user_id'],
            ':titulo' => $titulo,
            ':contenido' => $contenido,
            ':prioridad' => $prioridad
        ]);

        http_response_code(201);
        echo json_encode([
            "success" => true,
            "message" => "Comunicado publicado con éxito"
        ]);
    }
}