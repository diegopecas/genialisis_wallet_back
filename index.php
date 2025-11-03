<?php

/**
 * Circle Finance API - Router Principal
 * Backend PHP con API REST
 */

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400'); // 24 horas

// Responder a preflight requests (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configuración de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Autoload de controladores
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ConceptosController.php';
require_once __DIR__ . '/controllers/MovimientosController.php';
require_once __DIR__ . '/utils/Response.php';

// Obtener método HTTP y URI
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remover el prefijo de la ruta si existe (ajustar según tu configuración)
// Ejemplo: si tu API está en /circle-finance-backend/index.php
$basePath = dirname($_SERVER['SCRIPT_NAME']);
if ($basePath !== '/') {
    $uri = str_replace($basePath, '', $uri);
}

// Limpiar URI
$uri = trim($uri, '/');
$uriParts = explode('/', $uri);

// Router principal
try {
    // ============================================================
    // RUTAS DE AUTENTICACIÓN
    // ============================================================

    if ($uriParts[0] === 'auth') {
        $authController = new AuthController();

        // POST /auth/login
        if ($method === 'POST' && $uriParts[1] === 'login') {
            $authController->login();
            exit;
        }

        // GET /auth/me
        if ($method === 'GET' && $uriParts[1] === 'me') {
            $authController->me();
            exit;
        }
    }

    // ============================================================
    // RUTAS DE CONCEPTOS
    // ============================================================

    if ($uriParts[0] === 'conceptos') {
        $conceptosController = new ConceptosController();

        // GET /conceptos/all
        if ($method === 'GET' && isset($uriParts[1]) && $uriParts[1] === 'all') {
            $conceptosController->getAllConceptos();
            exit;
        }

        // GET /conceptos
        if ($method === 'GET') {
            $conceptosController->getConceptos();
            exit;
        }
    }

    // ============================================================
    // RUTAS DE MOVIMIENTOS
    // ============================================================

    if ($uriParts[0] === 'movimientos') {
        $movimientosController = new MovimientosController();

        // GET /movimientos/balance/detalle
        if (
            $method === 'GET' && isset($uriParts[1]) && $uriParts[1] === 'balance'
            && isset($uriParts[2]) && $uriParts[2] === 'detalle'
        ) {
            $movimientosController->getBalanceDetallado();
            exit;
        }
        // GET /movimientos/totales/categoria
        if (
            $method === 'GET' && isset($uriParts[1]) && $uriParts[1] === 'totales'
            && isset($uriParts[2]) && $uriParts[2] === 'categoria'
        ) {
            $movimientosController->getTotalesPorCategoria();
            exit;
        }

        // GET /movimientos/totales/dia
        if (
            $method === 'GET' && isset($uriParts[1]) && $uriParts[1] === 'totales'
            && isset($uriParts[2]) && $uriParts[2] === 'dia'
        ) {
            $movimientosController->getTotalesPorDia();
            exit;
        }
        // GET /movimientos/balance
        if ($method === 'GET' && isset($uriParts[1]) && $uriParts[1] === 'balance') {
            $movimientosController->getBalance();
            exit;
        }
        // GET /movimientos/grafico/categoria
        if (
            $method === 'GET' && isset($uriParts[1]) && $uriParts[1] === 'grafico'
            && isset($uriParts[2]) && $uriParts[2] === 'categoria'
        ) {
            $movimientosController->getGraficoCategoria();
            exit;
        }
        // GET /movimientos/evolucion
        if ($method === 'GET' && isset($uriParts[1]) && $uriParts[1] === 'evolucion') {
            $movimientosController->getEvolucion();
            exit;
        }

        // GET /movimientos/{id}
        if ($method === 'GET' && isset($uriParts[1]) && is_numeric($uriParts[1])) {
            $movimientosController->getById($uriParts[1]);
            exit;
        }

        // GET /movimientos
        if ($method === 'GET') {
            $movimientosController->getMovimientos();
            exit;
        }

        // POST /movimientos
        if ($method === 'POST') {
            $movimientosController->create();
            exit;
        }

        // DELETE /movimientos/{id}
        if ($method === 'DELETE' && isset($uriParts[1]) && is_numeric($uriParts[1])) {
            $movimientosController->delete($uriParts[1]);
            exit;
        }
    }

    // ============================================================
    // RUTA NO ENCONTRADA
    // ============================================================

    Response::notFound('Endpoint no encontrado');
} catch (Exception $e) {
    error_log("Error en router: " . $e->getMessage());
    Response::serverError('Error interno del servidor');
}
