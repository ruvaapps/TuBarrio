<?php

class DocumentoController {

    private const MAX_FILE_SIZE_BYTES = 10485760; // 10 MB
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv',
    ];

    private function requireDocumentoManagerRole($user): bool {
        if (!in_array($user['rol'] ?? '', ['intendente', 'admin_general'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'No tenes permisos para gestionar la documentacion.']);
            return false;
        }
        return true;
    }

    // Listar documentos DISPONIBLES para los vecinos (cualquier usuario autenticado)
    public function listarParaVecino($user) {
        header('Content-Type: application/json');

        try {
            $barrio_id = $user['barrio_id'] ?? null;
            if (!$barrio_id) {
                http_response_code(400);
                echo json_encode(['error' => 'No se pudo identificar el barrio del usuario.']);
                return;
            }

            $db = Database::getConnection();

            $stmt = $db->prepare("
                SELECT id, nombre, archivo_url, created_at
                FROM documentos
                WHERE barrio_id = :barrio_id AND disponible = 1
                ORDER BY nombre ASC
            ");
            $stmt->execute([':barrio_id' => $barrio_id]);
            $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => $documentos
            ]);

        } catch (Exception $e) {
            error_log('DocumentoController::listarParaVecino error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    // Listar documentos del barrio
    public function index($user) {
        header('Content-Type: application/json');

        if (!$this->requireDocumentoManagerRole($user)) {
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

            $stmt = $db->prepare("
                SELECT id, nombre, disponible, archivo_url, created_at
                FROM documentos
                WHERE barrio_id = :barrio_id
                ORDER BY created_at DESC, id DESC
            ");
            $stmt->execute([':barrio_id' => $barrio_id]);
            $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => $documentos
            ]);

        } catch (Exception $e) {
            error_log('DocumentoController::index error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    // Agregar un nuevo documento (nombre + archivo opcional vía multipart/form-data)
    public function store($user) {
        header('Content-Type: application/json');

        if (!$this->requireDocumentoManagerRole($user)) {
            return;
        }

        // Acepta multipart (con archivo) o JSON (solo nombre)
        if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false) {
            $nombre = trim($_POST['nombre'] ?? '');
            $disponible = !empty($_POST['disponible']) ? 1 : 0;
        } else {
            $input = json_decode(file_get_contents('php://input'), true);
            $nombre = trim($input['nombre'] ?? '');
            $disponible = !empty($input['disponible']) ? 1 : 0;
        }

        if ($nombre === '') {
            http_response_code(400);
            echo json_encode(['error' => 'El nombre del documento es obligatorio.']);
            return;
        }

        if (mb_strlen($nombre) > 255) {
            http_response_code(400);
            echo json_encode(['error' => 'El nombre del documento es demasiado largo (maximo 255 caracteres).']);
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

            // Evitar documentos duplicados con el mismo nombre en el mismo barrio
            $check = $db->prepare("SELECT COUNT(*) FROM documentos WHERE barrio_id = :barrio_id AND nombre = :nombre");
            $check->execute([':barrio_id' => $barrio_id, ':nombre' => $nombre]);

            if ($check->fetchColumn() > 0) {
                http_response_code(409);
                echo json_encode(['error' => 'Ya existe un documento con ese nombre en este barrio.']);
                return;
            }

            $archivo_url = null;

            // Procesar archivo adjunto si vino en la petición
            if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
                $tmpPath = $_FILES['archivo']['tmp_name'] ?? '';
                $fileSize = (int) ($_FILES['archivo']['size'] ?? 0);

                if ($tmpPath === '' || $fileSize <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'El archivo adjunto esta vacio o danado.']);
                    return;
                }

                if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
                    http_response_code(413);
                    echo json_encode(['error' => 'El archivo supera el tamano maximo permitido (10 MB).']);
                    return;
                }

                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: '';
                if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Formato de archivo no permitido. Permitidos: PDF, imagenes, Word, Excel, TXT o CSV.']);
                    return;
                }

                $uploadDir = __DIR__ . '/../uploads/documentos/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }

                $extension = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
                $nombreArchivo = 'documento_' . $barrio_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . ($extension !== '' ? '.' . $extension : '');

                if (!move_uploaded_file($tmpPath, $uploadDir . $nombreArchivo)) {
                    http_response_code(500);
                    echo json_encode(['error' => 'No se pudo guardar el archivo adjunto.']);
                    return;
                }

                $archivo_url = 'uploads/documentos/' . $nombreArchivo;
            }

            $stmt = $db->prepare("
                INSERT INTO documentos (barrio_id, nombre, disponible, archivo_url)
                VALUES (:barrio_id, :nombre, :disponible, :archivo_url)
            ");
            $stmt->execute([
                ':barrio_id' => $barrio_id,
                ':nombre' => $nombre,
                ':disponible' => $disponible,
                ':archivo_url' => $archivo_url
            ]);

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Documento agregado correctamente.',
                'id' => (int)$db->lastInsertId()
            ]);

        } catch (Exception $e) {
            error_log('DocumentoController::store error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    // Reemplazar el archivo adjunto de un documento existente
    public function actualizarArchivo($user, $documentoId) {
        header('Content-Type: application/json');

        if (!$this->requireDocumentoManagerRole($user)) {
            return;
        }

        try {
            $barrio_id = $user['barrio_id'] ?? null;
            if (!$barrio_id) {
                http_response_code(400);
                echo json_encode(['error' => 'No se pudo identificar el barrio del usuario.']);
                return;
            }

            if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['error' => 'No se recibio ningun archivo valido.']);
                return;
            }

            $tmpPath = $_FILES['archivo']['tmp_name'] ?? '';
            $fileSize = (int) ($_FILES['archivo']['size'] ?? 0);

            if ($tmpPath === '' || $fileSize <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'El archivo adjunto esta vacio o danado.']);
                return;
            }

            if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
                http_response_code(413);
                echo json_encode(['error' => 'El archivo supera el tamano maximo permitido (10 MB).']);
                return;
            }

            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: '';
            if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Formato de archivo no permitido. Permitidos: PDF, imagenes, Word, Excel, TXT o CSV.']);
                return;
            }

            $db = Database::getConnection();

            $stmtFind = $db->prepare("SELECT archivo_url FROM documentos WHERE id = :id AND barrio_id = :barrio_id");
            $stmtFind->execute([':id' => $documentoId, ':barrio_id' => $barrio_id]);
            $documento = $stmtFind->fetch(PDO::FETCH_ASSOC);

            if (!$documento) {
                http_response_code(404);
                echo json_encode(['error' => 'Documento no encontrado en este barrio.']);
                return;
            }

            $uploadDir = __DIR__ . '/../uploads/documentos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }

            $extension = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
            $nombreArchivo = 'documento_' . $barrio_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . ($extension !== '' ? '.' . $extension : '');

            if (!move_uploaded_file($tmpPath, $uploadDir . $nombreArchivo)) {
                http_response_code(500);
                echo json_encode(['error' => 'No se pudo guardar el archivo adjunto.']);
                return;
            }

            $nuevaUrl = 'uploads/documentos/' . $nombreArchivo;

            $stmt = $db->prepare("UPDATE documentos SET archivo_url = :archivo_url WHERE id = :id AND barrio_id = :barrio_id");
            $stmt->execute([':archivo_url' => $nuevaUrl, ':id' => $documentoId, ':barrio_id' => $barrio_id]);

            // Borrar el archivo anterior si existía
            if (!empty($documento['archivo_url'])) {
                $anterior = basename($documento['archivo_url']);
                $rutaAnterior = $uploadDir . $anterior;
                if (is_file($rutaAnterior)) {
                    @unlink($rutaAnterior);
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Archivo actualizado correctamente.',
                'archivo_url' => $nuevaUrl
            ]);

        } catch (Exception $e) {
            error_log('DocumentoController::actualizarArchivo error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    // Activar / desactivar disponibilidad para los vecinos (el tilde)
    public function toggleDisponibilidad($user, $documentoId) {
        header('Content-Type: application/json');

        if (!$this->requireDocumentoManagerRole($user)) {
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

            $stmt = $db->prepare("
                UPDATE documentos
                SET disponible = CASE WHEN disponible = 1 THEN 0 ELSE 1 END
                WHERE id = :id AND barrio_id = :barrio_id
            ");
            $stmt->execute([':id' => $documentoId, ':barrio_id' => $barrio_id]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Documento no encontrado en este barrio.']);
                return;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Disponibilidad actualizada correctamente.'
            ]);

        } catch (Exception $e) {
            error_log('DocumentoController::toggleDisponibilidad error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    // Quitar (eliminar) un documento
    public function destroy($user, $documentoId) {
        header('Content-Type: application/json');

        if (!$this->requireDocumentoManagerRole($user)) {
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

            // Obtener la URL del archivo antes de eliminar el registro
            $stmtFind = $db->prepare("SELECT archivo_url FROM documentos WHERE id = :id AND barrio_id = :barrio_id");
            $stmtFind->execute([':id' => $documentoId, ':barrio_id' => $barrio_id]);
            $documento = $stmtFind->fetch(PDO::FETCH_ASSOC);

            if (!$documento) {
                http_response_code(404);
                echo json_encode(['error' => 'Documento no encontrado en este barrio.']);
                return;
            }

            $stmt = $db->prepare("DELETE FROM documentos WHERE id = :id AND barrio_id = :barrio_id");
            $stmt->execute([':id' => $documentoId, ':barrio_id' => $barrio_id]);

            // Borrar el archivo físico si existe
            if (!empty($documento['archivo_url'])) {
                $uploadDir = __DIR__ . '/../uploads/documentos/';
                $rutaArchivo = $uploadDir . basename($documento['archivo_url']);
                if (is_file($rutaArchivo)) {
                    @unlink($rutaArchivo);
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Documento eliminado correctamente.'
            ]);

        } catch (Exception $e) {
            error_log('DocumentoController::destroy error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }
}
