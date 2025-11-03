<?php

/**
 * MovimientosController
 * Manejo de movimientos financieros (CRUD y consultas)
 */

require_once __DIR__ . '/../models/Movimiento.php';
require_once __DIR__ . '/../models/Concepto.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../utils/Response.php';

class MovimientosController
{
    private $movimientoModel;
    private $conceptoModel;

    public function __construct()
    {
        $this->movimientoModel = new Movimiento();
        $this->conceptoModel = new Concepto();
    }

    /**
     * Crear nuevo movimiento
     * POST /movimientos
     * Body: {
     *   "concepto_id": 1,
     *   "valor": 50000,
     *   "fecha": "2025-11-01",
     *   "circulos_ids": [1],
     *   "detalle": "...",
     *   "notas": "..."
     * }
     */
    public function create()
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        // Obtener datos del POST
        $data = json_decode(file_get_contents('php://input'), true);

        // Validar datos requeridos
        $errors = [];

        if (!isset($data['concepto_id']) || empty($data['concepto_id'])) {
            $errors['concepto_id'] = 'Concepto es requerido';
        }

        if (!isset($data['valor']) || $data['valor'] <= 0) {
            $errors['valor'] = 'Valor debe ser mayor a 0';
        }

        if (!isset($data['fecha']) || empty($data['fecha'])) {
            $errors['fecha'] = 'Fecha es requerida';
        }

        if (!isset($data['circulos_ids']) || !is_array($data['circulos_ids']) || empty($data['circulos_ids'])) {
            $errors['circulos_ids'] = 'Debe seleccionar al menos un círculo';
        }

        if (!empty($errors)) {
            Response::validationError('Errores de validación', $errors);
        }

        // Validar que el concepto existe
        $concepto = $this->conceptoModel->getById($data['concepto_id']);

        if (!$concepto) {
            Response::validationError('Concepto no encontrado');
        }

        // Validar detalle si el concepto lo requiere
        if ($concepto['requiere_detalle'] && empty($data['detalle'])) {
            Response::validationError('Este concepto requiere detalle', [
                'detalle' => 'Detalle es requerido para este concepto'
            ]);
        }

        // Crear movimiento
        $movimiento = $this->movimientoModel->create(
            $userId,
            $data['concepto_id'],
            $data['valor'],
            $data['fecha'],
            $data['circulos_ids'],
            $data['detalle'] ?? null,
            $data['notas'] ?? null
        );

        if (!$movimiento) {
            Response::serverError('Error al crear el movimiento');
        }

        Response::success($movimiento, 'Movimiento creado exitosamente', 201);
    }

    /**
     * Obtener movimientos con filtros
     * GET /movimientos?tipo_mov_id={1|2}&circulo_id={id}&anio={2025}&mes={11}&limit={10}
     */
    public function getMovimientos()
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        // Obtener parámetros
        $tipoMovId = isset($_GET['tipo_mov_id']) ? intval($_GET['tipo_mov_id']) : null;
        $circuloId = isset($_GET['circulo_id']) ? intval($_GET['circulo_id']) : null;
        $anio = isset($_GET['anio']) ? intval($_GET['anio']) : null;
        $mes = isset($_GET['mes']) ? intval($_GET['mes']) : null;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;

        // Validar límite
        if ($limit < 1 || $limit > 100) {
            $limit = 10;
        }

        // CAMBIO: Ya no pasar userId como primer parámetro
        // Obtener movimientos
        $movimientos = $this->movimientoModel->getMovimientos(
            $tipoMovId,
            $circuloId,
            $anio,
            $mes,
            $limit
        );

        Response::success([
            'movimientos' => $movimientos,
            'total' => count($movimientos)
        ], 'Movimientos obtenidos correctamente');
    }

    /**
     * Obtener movimiento por ID
     * GET /movimientos/{id}
     */
    public function getById($id)
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        $movimiento = $this->movimientoModel->getById($id);

        if (!$movimiento) {
            Response::notFound('Movimiento no encontrado');
        }

        // Validar que el movimiento pertenece al usuario
        if ($movimiento['user_id'] != $userId) {
            Response::unauthorized('No tienes permiso para ver este movimiento');
        }

        Response::success($movimiento, 'Movimiento obtenido correctamente');
    }

    /**
     * Eliminar movimiento
     * DELETE /movimientos/{id}
     */
    public function delete($id)
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        // Verificar que el movimiento existe y pertenece al usuario
        $movimiento = $this->movimientoModel->getById($id);

        if (!$movimiento) {
            Response::notFound('Movimiento no encontrado');
        }

        if ($movimiento['user_id'] != $userId) {
            Response::unauthorized('No tienes permiso para eliminar este movimiento');
        }

        // Eliminar
        $deleted = $this->movimientoModel->delete($id, $userId);

        if (!$deleted) {
            Response::serverError('Error al eliminar el movimiento');
        }

        Response::success(null, 'Movimiento eliminado exitosamente');
    }

    /**
     * Obtener balance (totales)
     * GET /movimientos/balance?circulo_id={id}&anio={2025}&mes={11}
     */
    public function getBalance()
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        // Obtener parámetros
        $circuloId = isset($_GET['circulo_id']) ? intval($_GET['circulo_id']) : null;
        $anio = isset($_GET['anio']) ? intval($_GET['anio']) : null;
        $mes = isset($_GET['mes']) ? intval($_GET['mes']) : null;

        // CAMBIO: Ya no pasar userId
        $balance = $this->movimientoModel->getBalance($circuloId, $anio, $mes);

        Response::success($balance, 'Balance obtenido correctamente');
    }

    /**
     * Obtener balance detallado por concepto
     * GET /movimientos/balance/detalle?circulo_id={id}&anio={2025}&mes={11}
     */
    public function getBalanceDetallado()
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        // Obtener parámetros
        $circuloId = isset($_GET['circulo_id']) ? intval($_GET['circulo_id']) : null;
        $anio = isset($_GET['anio']) ? intval($_GET['anio']) : null;
        $mes = isset($_GET['mes']) ? intval($_GET['mes']) : null;

        // CAMBIO: Ya no pasar userId
        $detalle = $this->movimientoModel->getBalanceDetallado($circuloId, $anio, $mes);

        Response::success([
            'detalle' => $detalle
        ], 'Balance detallado obtenido correctamente');
    }

    /**
     * Obtener evolución mensual (para gráfico)
     * GET /movimientos/evolucion?circulo_id={id}&anio={2025}
     */
    public function getEvolucion()
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        // Obtener parámetros
        $circuloId = isset($_GET['circulo_id']) ? intval($_GET['circulo_id']) : null;
        $anio = isset($_GET['anio']) ? intval($_GET['anio']) : date('Y');

        // CAMBIO: Ya no pasar userId
        $evolucion = $this->movimientoModel->getEvolucionMensual($circuloId, $anio);

        Response::success([
            'anio' => $anio,
            'datos' => $evolucion
        ], 'Evolución mensual obtenida correctamente');
    }

    /**
     * Validar autenticación del usuario
     */
    private function validateAuth()
    {
        $token = getBearerToken();

        if (!$token) {
            Response::unauthorized('Token no proporcionado');
        }

        $payload = validateJWT($token);

        if (!$payload) {
            Response::unauthorized('Token inválido o expirado');
        }

        return $payload;
    }
}
