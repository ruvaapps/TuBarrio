<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PushController {
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

    public function guardarSuscripcion($user) {
        header('Content-Type: application/json');

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            $endpoint = $input['endpoint'] ?? '';
            $p256dh   = $input['keys']['p256dh'] ?? '';
            $auth     = $input['keys']['auth'] ?? '';

            if (empty($endpoint) || empty($p256dh) || empty($auth)) {
                http_response_code(400);
                echo json_encode(['error' => 'Datos de suscripción incompletos.']);
                return;
            }

            $db = Database::getConnection();

            // Evitar duplicados del mismo endpoint
            $stmtCheck = $db->prepare("SELECT id FROM push_subscriptions WHERE endpoint = :endpoint");
            $stmtCheck->execute([':endpoint' => $endpoint]);
            
            if ($stmtCheck->fetch()) {
                // Actualizar usuario si cambió de cuenta en el mismo navegador
                $stmtUpd = $db->prepare("UPDATE push_subscriptions SET usuario_id = :usuario_id WHERE endpoint = :endpoint");
                $stmtUpd->execute([':usuario_id' => $user['user_id'], ':endpoint' => $endpoint]);
            } else {
                // Insertar nueva suscripción
                $stmtIns = $db->prepare("
                    INSERT INTO push_subscriptions (usuario_id, endpoint, keys_p256dh, keys_auth)
                    VALUES (:usuario_id, :endpoint, :p256dh, :auth)
                ");
                $stmtIns->execute([
                    ':usuario_id' => $user['user_id'],
                    ':endpoint'   => $endpoint,
                    ':p256dh'     => $p256dh,
                    ':auth'       => $auth
                ]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Suscripción guardada.',
                'usuario_id' => $user['user_id'],
                'endpoint' => $endpoint
            ]);

        } catch (Exception $e) {
            error_log('PushController::guardarSuscripcion error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    public function eliminarSuscripcion($user) {
        header('Content-Type: application/json');

        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $endpoint = $input['endpoint'] ?? '';

            if (empty($endpoint)) {
                http_response_code(400);
                echo json_encode(['error' => 'Endpoint de suscripción requerido.']);
                return;
            }

            $db = Database::getConnection();
            $stmtDel = $db->prepare("DELETE FROM push_subscriptions WHERE usuario_id = :usuario_id AND endpoint = :endpoint");
            $stmtDel->execute([
                ':usuario_id' => $user['user_id'],
                ':endpoint' => $endpoint
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Suscripción eliminada.',
                'usuario_id' => $user['user_id'],
                'endpoint' => $endpoint,
                'eliminadas' => $stmtDel->rowCount()
            ]);
        } catch (Exception $e) {
            error_log('PushController::eliminarSuscripcion error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    public function enviarPrueba($user) {
        header('Content-Type: application/json');

        try {
            $db = Database::getConnection();
            $usuarioId = $user['user_id'] ?? null;

            if (!$usuarioId) {
                http_response_code(400);
                echo json_encode(['error' => 'No se pudo identificar el usuario.']);
                return;
            }

            $titulo = 'Notificacion de prueba';
            $cuerpo = 'Push activo correctamente en TuBarrio.';
            $url = ($user['rol'] ?? '') === 'vecino' ? 'vecino' : 'intendente';

            $resultado = $this->enviarPushAUsuario($db, $usuarioId, $titulo, $cuerpo, $url);

            if ($resultado['sin_suscripciones']) {
                http_response_code(404);
                echo json_encode(['error' => 'El usuario no tiene suscripciones push activas.']);
                return;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Push de prueba enviado.',
                'enviadas' => $resultado['ok'],
                'fallidas' => $resultado['fail']
            ]);
        } catch (Exception $e) {
            error_log('PushController::enviarPrueba error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }

    private function enviarPushAUsuario($db, $usuarioId, $titulo, $cuerpo, $url) {
        $stmtPush = $db->prepare("SELECT * FROM push_subscriptions WHERE usuario_id = :usuario_id");
        $stmtPush->execute([':usuario_id' => $usuarioId]);
        $suscripciones = $stmtPush->fetchAll(PDO::FETCH_ASSOC);

        if (empty($suscripciones)) {
            return ['sin_suscripciones' => true, 'ok' => 0, 'fail' => 0];
        }

        $auth = $this->getVapidAuth();

        $webPush = new WebPush($auth);

        $payload = json_encode([
            'title' => $titulo,
            'body' => $cuerpo,
            'url' => $url
        ]);

        foreach ($suscripciones as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys' => [
                    'p256dh' => $sub['keys_p256dh'] ?? '',
                    'auth' => $sub['keys_auth'] ?? ''
                ]
            ]);

            $webPush->queueNotification($subscription, $payload);
        }

        $ok = 0;
        $fail = 0;

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $ok++;
            } else {
                $fail++;
                if ($report->isSubscriptionExpired()) {
                    $endpoint = $report->getRequest()->getUri()->__toString();
                    $stmtDel = $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = :endpoint");
                    $stmtDel->execute([':endpoint' => $endpoint]);
                }
            }
        }

        return ['sin_suscripciones' => false, 'ok' => $ok, 'fail' => $fail];
    }
}