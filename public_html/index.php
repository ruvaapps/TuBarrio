<?php
// index.php - Router principal de la API

require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/config/security_headers.php';
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// --- DETECCIÓN ROBUSTA DE RUTA ---
// Si pasas la ruta por parámetro (?route=/auth/login) o la toma de la URI
$route = $_GET['route'] ?? $uri;

// Limpiar la ruta de cualquier prefijo de carpeta o /api si lo tuviera
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if ($scriptDir !== '/' && strpos($route, $scriptDir) === 0) {
    $route = substr($route, strlen($scriptDir));
}
$route = preg_replace('#^/api#', '', $route);
if ($route === '' || $route === false) { $route = '/'; }
// ---------------------------------

// Router principal utilizando switch(true)
switch (true) {

    // 1. AUTENTICACIÓN: Login
    case ($route === '/auth/login' && $method === 'POST'):
        require_once __DIR__ . '/controllers/AuthController.php';
        (new AuthController())->login();
        break;

    case ($route === '/auth/logout' && $method === 'POST'):
        require_once __DIR__ . '/controllers/AuthController.php';
        (new AuthController())->logout();
        break;

    case ($route === '/auth/me' && $method === 'GET'):
        require_once __DIR__ . '/controllers/AuthController.php';
        (new AuthController())->me();
        break;

    case ($route === '/auth/refresh' && $method === 'POST'):
        require_once __DIR__ . '/controllers/AuthController.php';
        (new AuthController())->refresh();
        break;

    // 2. RECLAMOS: Listar (GET) y Crear (POST)
    case ($route === '/reclamos'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/ReclamoController.php';
        $controller = new ReclamoController();

        if ($method === 'GET') {
            $controller->index($user);
        } elseif ($method === 'POST') {
            $controller->store($user);
        } else {
            http_response_code(405);
            header('Allow: GET, POST');
            echo json_encode(['error' => 'Metodo HTTP no permitido']);
        }
        break;

    // 3. RECLAMOS: Actualizar estado (/reclamos/{id}/estado)
    case (preg_match('#^/reclamos/(\d+)/estado$#', $route, $matches) && $method === 'PUT'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/ReclamoController.php';
        $controller = new ReclamoController();
        $controller->updateEstado($user, $matches[1]);
        break;

    // 4. COMUNICADOS: Listar (GET) y Publicar (POST)
    case ($route === '/comunicados'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/ComunicadoController.php';
        $controller = new ComunicadoController();

        if ($method === 'GET') {
            $controller->index($user);
        } elseif ($method === 'POST') {
            $controller->store($user);
        } else {
            http_response_code(405);
            header('Allow: GET, POST');
            echo json_encode(['error' => 'Metodo HTTP no permitido']);
        }
        break;

    // 5. VECINOS: Alta de vecinos (POST)
    case ($route === '/vecinos' && $method === 'POST'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/UsuarioController.php';
        (new UsuarioController())->storeVecino($user);
        break;
// 6. INTENDENTE: Gestión de Multas (GET, POST y Enviar Aviso)
    case ($route === '/avisos' && ($method === 'GET' || $method === 'POST')):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/AvisoController.php';
        $controller = new AvisoController();

        if ($method === 'GET') {
            $controller->index($user);
        } elseif ($method === 'POST') {
            $controller->store($user);
        } else {
            http_response_code(405);
            header('Allow: GET, POST');
            echo json_encode(['error' => 'Metodo HTTP no permitido']);
        }
        break;

    case ($route === '/multas/mias' && $method === 'GET'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/MultaController.php';
        (new MultaController())->listarMisMultas($user);
        break;

    case (preg_match('#^/multas/?$#', $route)):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/MultaController.php';
        $controller = new MultaController();

        if ($method === 'GET') {
            $controller->index($user);
        } elseif ($method === 'POST') {
            $controller->store($user);
        } else {
            http_response_code(405);
            header('Allow: GET, POST');
            echo json_encode(['error' => 'Metodo HTTP no permitido']);
        }
        break;

    case (preg_match('#^/multas/(\d+)/enviar-aviso$#', $route, $matches) && $method === 'POST'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/MultaController.php';
        (new MultaController())->enviarAviso($user, $matches[1]);
        break; 
    case (preg_match('#^/avisos/(\d+)/enviar-aviso$#', $route, $matches) && $method === 'POST'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/AvisoController.php';
        (new AvisoController())->enviarAviso($user, $matches[1]);
        break;

    case (preg_match('#^/avisos/(\d+)/generar-multa$#', $route, $matches) && $method === 'POST'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/AvisoController.php';
        (new AvisoController())->generarMultaDesdeAviso($user, $matches[1]);
        break;
// 7. LOTES: Obtener vecinos vinculados a un lote (GET)
    case (preg_match('#^/lotes/(\d+)/vecinos$#', $route, $matches) && $method === 'GET'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/MultaController.php';
        (new MultaController())->obtenerVecinosPorLote($user, $matches[1]);
        break;
// 8	
	case ($route === '/vecinos/listar' && $method === 'GET'):
	    require_once __DIR__ . '/middlewares/AuthMiddleware.php';
	    $user = AuthMiddleware::verify();
	    require_once __DIR__ . '/controllers/UsuarioController.php';
	    (new UsuarioController())->listarVecinos($user);
	    break;

    case (preg_match('#^/vecinos/(\d+)/estado$#', $route, $matches) && $method === 'PUT'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();
        require_once __DIR__ . '/controllers/UsuarioController.php';
        (new UsuarioController())->cambiarEstadoVecino($user, $matches[1]);
        break;
// 9. Historial y comentarios de un reclamo
    case (preg_match('#^/reclamos/(\d+)/historial$#', $route, $matches) && $method === 'GET'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/ReclamoController.php';
        (new ReclamoController())->obtenerHistorial($user, $matches[1]);
        break;

    case (preg_match('#^/reclamos/(\d+)/comentarios$#', $route, $matches) && $method === 'POST'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/ReclamoController.php';
        (new ReclamoController())->agregarComentario($user, $matches[1]);
        break;	
// 10. Push
	case ($route === '/push/suscripcion' && $method === 'POST'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/PushController.php';
        (new PushController())->guardarSuscripcion($user);
        break;
	case ($route === '/push/desuscripcion' && $method === 'POST'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/PushController.php';
        (new PushController())->eliminarSuscripcion($user);
        break;
	case ($route === '/push/prueba' && $method === 'POST'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/PushController.php';
        (new PushController())->enviarPrueba($user);
        break;
    // 11. DOCUMENTOS: Administración de documentación para vecinos
    case ($route === '/documentos/vecino' && $method === 'GET'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/DocumentoController.php';
        (new DocumentoController())->listarParaVecino($user);
        break;

    case ($route === '/documentos' && ($method === 'GET' || $method === 'POST')):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/DocumentoController.php';
        $controller = new DocumentoController();

        if ($method === 'GET') {
            $controller->index($user);
        } elseif ($method === 'POST') {
            $controller->store($user);
        }
        break;

    case (preg_match('#^/documentos/(\d+)/disponibilidad$#', $route, $matches) && $method === 'PUT'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/DocumentoController.php';
        (new DocumentoController())->toggleDisponibilidad($user, $matches[1]);
        break;

    case (preg_match('#^/documentos/(\d+)/archivo$#', $route, $matches) && $method === 'POST'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/DocumentoController.php';
        (new DocumentoController())->actualizarArchivo($user, $matches[1]);
        break;

    case (preg_match('#^/documentos/(\d+)$#', $route, $matches) && $method === 'DELETE'):
        require_once __DIR__ . '/middlewares/AuthMiddleware.php';
        $user = AuthMiddleware::verify();

        require_once __DIR__ . '/controllers/DocumentoController.php';
        (new DocumentoController())->destroy($user, $matches[1]);
        break;

    // AUTH - Password reset por código
    case ($route === '/auth/password/request-code' && $method === 'POST'):
        require_once __DIR__ . '/controllers/PasswordController.php';
        (new PasswordController())->requestCode();
        break;

    case ($route === '/auth/password/verify-code' && $method === 'POST'):
        require_once __DIR__ . '/controllers/PasswordController.php';
        (new PasswordController())->verifyCode();
        break;

    case ($route === '/auth/password/reset' && $method === 'POST'):
        require_once __DIR__ . '/controllers/PasswordController.php';
        (new PasswordController())->resetPassword();
        break;

	// Ruta por defecto (404 Not Found)
    default:
        http_response_code(404);
        echo json_encode([
            "error" => "Ruta no encontrada o método HTTP no permitido"
        ]);
        break;
}