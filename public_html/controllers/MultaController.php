<?php

// Asegurate de requerir el autoloader de Composer al inicio o en tu index principal
require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class MultaController {

    private function getVapidAuth() {
        $subject = getenv('VAPID_SUBJECT') ?: '';
        $publicKey = getenv('VAPID_PUBLIC_KEY') ?: '';
        $privateKey = getenv('VAPID_PRIVATE_KEY') ?: '';

        if ($subject === '' || $publicKey === '' || $privateKey === '') {
            throw new RuntimeException('VAPID environment variables are not configured.');
        }

        return [
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ];
    }

    private function requireMultaManagerRole($user): bool {
        if (!in_array($user['rol'] ?? '', ['intendente', 'admin_general'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'No tenes permisos para gestionar multas.']);
            return false;
        }
        return true;
    }

    // 1. Listar multas del barrio cruzando con lotes y usuarios (vecino sancionado)
    // Listar las multas del propio vecino autenticado
    public function listarMisMultas($user) {
        header('Content-Type: application/json');

        try {
            $barrio_id = $user['barrio_id'] ?? null;
            $user_id = $user['user_id'] ?? null;

            if (!$barrio_id || !$user_id) {
                http_response_code(400);
                echo json_encode(['error' => 'No se pudo identificar el usuario o su barrio.']);
                return;
            }

            $db = Database::getConnection();

            // Solo multas (tipo_multa = 'multa')
            $stmt = $db->prepare("
                SELECT m.id, m.tipo, m.tipo_multa, m.monto, m.estado, m.observaciones, m.created_at,
                       l.identificador AS lote_identificador
                FROM multas m
                LEFT JOIN lotes l ON m.lote_id = l.id
                WHERE m.barrio_id = :barrio_id AND m.usuario_id = :user_id AND m.tipo_multa = 'multa'
                ORDER BY m.created_at DESC
            ");
            $stmt->execute([':barrio_id' => $barrio_id, ':user_id' => $user_id]);
            $multas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Avisos (tipo_multa = 'aviso'), con los datos de la multa generada si existe
            $stmtAvisos = $db->prepare("
                SELECT m.id, m.tipo, m.tipo_multa, m.estado, m.observaciones, m.created_at,
                       l.identificador AS lote_identificador,
                       mg.id AS multa_id, mg.monto AS multa_monto, mg.estado AS multa_estado, mg.created_at AS multa_fecha
                FROM multas m
                LEFT JOIN lotes l ON m.lote_id = l.id
                LEFT JOIN multas mg ON mg.id_aviso_relacionado = m.id AND mg.tipo_multa = 'multa'
                WHERE m.barrio_id = :barrio_id AND m.usuario_id = :user_id AND m.tipo_multa = 'aviso'
                ORDER BY m.created_at DESC
            ");
            $stmtAvisos->execute([':barrio_id' => $barrio_id, ':user_id' => $user_id]);
            $avisos = $stmtAvisos->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => $multas,
                'avisos' => $avisos
            ]);

        } catch (Exception $e) {
            error_log('MultaController::listarMisMultas error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    public function index($user) {
        header('Content-Type: application/json');

        if (!$this->requireMultaManagerRole($user)) {
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
                SELECT m.*, 
                       l.identificador AS lote_identificador,
                       u.nombre AS vecino_nombre, 
                       u.apellido AS vecino_apellido, 
                       u.email AS vecino_email
                FROM multas m
                JOIN lotes l ON m.lote_id = l.id
                LEFT JOIN usuarios u ON m.usuario_id = u.id
                WHERE m.barrio_id = :barrio_id AND m.tipo_multa = 'multa'
                ORDER BY m.created_at DESC
            ");
            
            $stmt->execute([':barrio_id' => $barrio_id]);
            $multas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => $multas
            ]);

        } catch (Exception $e) {
            error_log('MultaController::index error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    // 2. Registrar una nueva multa asociando el lote y el usuario_id del vecino
    public function store($user) {
        header('Content-Type: application/json');

        if (!$this->requireMultaManagerRole($user)) {
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            $lote_id = $input['lote_id'] ?? null;
            $usuario_id = $input['usuario_id'] ?? null;
            $tipo = $input['tipo'] ?? null;
            $monto = $input['monto'] ?? null;
            $observaciones = $input['observaciones'] ?? null;
            
            $barrio_id = $user['barrio_id'] ?? null;
            $intendente_id = $user['user_id'] ?? null;

            if (!$lote_id || !$usuario_id || !$tipo || !$monto || !$barrio_id || !$intendente_id) {
                http_response_code(400);
                echo json_encode(['error' => 'Faltan datos obligatorios para registrar la multa (incluyendo el vecino).']);
                return;
            }

            $db = Database::getConnection();

            $stmt = $db->prepare("
                INSERT INTO multas (barrio_id, lote_id, usuario_id, usuario_intendente_id, tipo_multa, tipo, monto, observaciones, estado)
                VALUES (:barrio_id, :lote_id, :usuario_id, :intendente_id, 'multa', :tipo, :monto, :observaciones, 'pendiente')
            ");

            $stmt->execute([
                ':barrio_id' => $barrio_id,
                ':lote_id' => $lote_id,
                ':usuario_id' => $usuario_id,
                ':intendente_id' => $intendente_id,
                ':tipo' => $tipo,
                ':monto' => $monto,
                ':observaciones' => $observaciones
            ]);

            $multaId = $db->lastInsertId();

            // Enviar Push automática al registrar la multa
            $this->enviarPushVecino($db, $usuario_id, "Nueva Multa Registrada", "Se te ha emitido una multa por {$tipo} (\$ " . number_format($monto, 2) . ").");

            http_response_code(200);
            echo json_encode([
                'success' => true, 
                'message' => 'Multa registrada con éxito',
                'multa_id' => $multaId
            ]);

        } catch (Exception $e) {
            error_log('MultaController::store error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    // 3. Obtener los vecinos vinculados a un lote para el select dinámico
    public function obtenerVecinosPorLote($user, $loteId) {
        header('Content-Type: application/json');

        if (!$this->requireMultaManagerRole($user)) {
            return;
        }

        try {
            $db = Database::getConnection();

            $stmt = $db->prepare("
                SELECT u.id, u.nombre, u.apellido, u.email, ul.tipo_relacion
                FROM usuario_lote ul
                JOIN usuarios u ON ul.usuario_id = u.id
                WHERE ul.lote_id = :lote_id AND u.barrio_id = :barrio_id
            ");
            $stmt->execute([
                ':lote_id' => $loteId,
                ':barrio_id' => $user['barrio_id']
            ]);
            $vecinos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $vecinos]);

        } catch (Exception $e) {
            error_log('MultaController::obtenerVecinosPorLote error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    // 4. Enviar aviso de multa por correo electrónico y Notificación Push al vecino sancionado
    public function enviarAviso($user, $multaId) {
        header('Content-Type: application/json');

        if (!$this->requireMultaManagerRole($user)) {
            return;
        }

        try {
            $db = Database::getConnection();

            $stmt = $db->prepare("
                SELECT m.*, l.identificador AS lote_identificador, u.id AS vecino_id, u.email, u.nombre, u.apellido
                FROM multas m
                JOIN lotes l ON m.lote_id = l.id
                LEFT JOIN usuarios u ON m.usuario_id = u.id
                WHERE m.id = :multa_id AND m.barrio_id = :barrio_id
            ");
            $stmt->execute([
                ':multa_id' => $multaId,
                ':barrio_id' => $user['barrio_id']
            ]);
            $multa = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$multa) {
                http_response_code(404);
                echo json_encode(['error' => 'Multa no encontrada.']);
                return;
            }

            if (empty($multa['email'])) {
                http_response_code(400);
                echo json_encode(['error' => 'El vecino seleccionado no tiene correo electrónico registrado.']);
                return;
            }

            // 1. Envío de Correo Electrónico Real (Descomentado)
            $destinatario = $multa['email'];
            $asunto = "Notificación de Infracción / Multa - Lote " . $multa['lote_identificador'];
            $mensaje = "Hola {$multa['nombre']} {$multa['apellido']},\n\n"
                     . "Se le ha registrado una multa en el barrio.\n"
                     . "Motivo: {$multa['tipo_multa']}\n"
                     . "Monto: \${$multa['monto']}\n"
                     . "Observaciones: {$multa['observaciones']}\n\n"
                     . "Por favor, comuníquese con la intendencia para regularizar su situación.\n\n"
                     . "Atentamente,\nAdministración del Barrio.";
            
            $headers = "From: no-reply@tubarrio.lovar.com.ar\r\n" .
                       "X-Mailer: PHP/" . phpversion();

            @mail($destinatario, $asunto, $mensaje, $headers);

            // 2. Envío de Notificación Push Real usando minishlink/web-push
            if (!empty($multa['vecino_id'])) {
                $this->enviarPushVecino(
                    $db, 
                    $multa['vecino_id'], 
                    "⚠️ Aviso de Multa - Lote {$multa['lote_identificador']}", 
                    "Motivo: {$multa['tipo_multa']} | Monto: \${$multa['monto']}"
                );
            }

            echo json_encode([
                'success' => true,
                'message' => 'Aviso enviado por correo y notificación push con éxito a ' . $destinatario
            ]);

        } catch (Exception $e) {
            error_log('MultaController::enviarAviso error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    // Método privado para disparar las notificaciones Push reales con la librería
// Método privado para disparar las notificaciones Push reales con la librería
    public function enviarPushVecino($db, $usuarioId, $titulo, $cuerpo) {
        try {
            $stmtPush = $db->prepare("SELECT * FROM push_subscriptions WHERE usuario_id = :usuario_id");
            $stmtPush->execute([':usuario_id' => $usuarioId]);
            $suscripciones = $stmtPush->fetchAll(PDO::FETCH_ASSOC);

            if (empty($suscripciones)) {
                return;
            }

            // Validamos que la clase exista para evitar errores fatales si la ruta del vendor falla
            if (!class_exists('Minishlink\WebPush\WebPush')) {
                return;
            }

            $auth = $this->getVapidAuth();

            // Envolvemos el cliente web-push en su propio try/catch para que no tire abajo el proceso principal
            try {
                $webPush = new WebPush($auth);

                $payload = json_encode([
                    'title' => $titulo,
                    'body' => $cuerpo,
                    'url' => 'vecino'
                ]);

                foreach ($suscripciones as $sub) {
                    // Verificamos si las llaves están separadas o vienen en un JSON de keys
                    $p256dh = $sub['keys_p256dh'] ?? '';
                    $auth_key = $sub['keys_auth'] ?? '';

                    if (empty($p256dh) && isset($sub['keys'])) {
                        $decodedKeys = json_decode($sub['keys'], true);
                        $p256dh = $decodedKeys['p256dh'] ?? '';
                        $auth_key = $decodedKeys['auth'] ?? '';
                    }

                    $subscription = Subscription::create([
                        'endpoint' => $sub['endpoint'],
                        'keys' => [
                            'p256dh' => $p256dh,
                            'auth' => $auth_key
                        ]
                    ]);

                    $webPush->queueNotification($subscription, $payload);
                }

                foreach ($webPush->flush() as $report) {
                    $endpoint = $report->getRequest()->getUri()->__toString();
                    if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
                        $stmtDel = $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = :endpoint");
                        $stmtDel->execute([':endpoint' => $endpoint]);
                    }
                }
            } catch (\Throwable $pushEx) {
                error_log('MultaController::enviarPushVecino webpush error: ' . $pushEx->getMessage());
            }

        } catch (Exception $ex) {
            error_log("Error general en enviarPushVecino: " . $ex->getMessage());
        }
    }
}