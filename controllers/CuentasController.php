<?php

/**
 * CuentasController
 * Manejo de cuentas financieras (CRUD y consultas)
 */

require_once __DIR__ . '/../models/Cuenta.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../utils/Response.php';

class CuentasController
{
    private $cuentaModel;

    public function __construct()
    {
        $this->cuentaModel = new Cuenta();
    }

    /**
     * Obtener cuentas por círculo
     * GET /cuentas?circulo_id={id}
     */
    public function getCuentas()
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        $circuloId = isset($_GET['circulo_id']) ? intval($_GET['circulo_id']) : null;

        if (!$circuloId) {
            Response::validationError('circulo_id es requerido');
        }

        $cuentas = $this->cuentaModel->getCuentasPorCirculo($circuloId);

        Response::success([
            'cuentas' => $cuentas,
            'total' => count($cuentas)
        ], 'Cuentas obtenidas correctamente');
    }

    /**
     * Obtener cuenta por ID
     * GET /cuentas/{id}
     */
    public function getById($id)
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        $cuenta = $this->cuentaModel->getById($id);

        if (!$cuenta) {
            Response::notFound('Cuenta no encontrada');
        }

        Response::success($cuenta, 'Cuenta obtenida correctamente');
    }

    /**
     * Obtener saldo de una cuenta
     * GET /cuentas/{id}/saldo
     */
    public function getSaldo($id)
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        $cuenta = $this->cuentaModel->getById($id);

        if (!$cuenta) {
            Response::notFound('Cuenta no encontrada');
        }

        Response::success([
            'cuenta_id' => $id,
            'nombre' => $cuenta['nombre'],
            'saldo_actual' => $cuenta['saldo_actual']
        ], 'Saldo obtenido correctamente');
    }

    /**
     * Obtener resumen de saldos por círculo
     * GET /cuentas/resumen?circulo_id={id}
     */
    public function getResumen()
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        $circuloId = isset($_GET['circulo_id']) ? intval($_GET['circulo_id']) : null;

        if (!$circuloId) {
            Response::validationError('circulo_id es requerido');
        }

        $resumen = $this->cuentaModel->getResumenSaldosPorCirculo($circuloId);

        Response::success($resumen, 'Resumen de saldos obtenido correctamente');
    }

    /**
     * Crear nueva cuenta
     * POST /cuentas
     * Body: {
     *   "circulo_id": 1,
     *   "nombre": "Efectivo",
     *   "icono": "💵",
     *   "color": "#4CAF50",
     *   "descripcion": "Dinero en efectivo",
     *   "orden": 0
     * }
     */
    public function create()
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        $data = json_decode(file_get_contents('php://input'), true);

        $errors = [];

        if (!isset($data['circulo_id']) || empty($data['circulo_id'])) {
            $errors['circulo_id'] = 'Círculo es requerido';
        }

        if (!isset($data['nombre']) || empty(trim($data['nombre']))) {
            $errors['nombre'] = 'Nombre es requerido';
        }

        if (!empty($errors)) {
            Response::validationError('Errores de validación', $errors);
        }

        $cuenta = $this->cuentaModel->create(
            $data['circulo_id'],
            $data['nombre'],
            $data['icono'] ?? '💳',
            $data['color'] ?? '#4CAF50',
            $data['descripcion'] ?? null,
            $data['orden'] ?? 0
        );

        if (!$cuenta) {
            Response::serverError('Error al crear la cuenta');
        }

        Response::success($cuenta, 'Cuenta creada exitosamente', 201);
    }

    /**
     * Actualizar cuenta
     * PUT/PATCH /cuentas/{id}
     * Body: {
     *   "nombre": "Nuevo nombre",
     *   "icono": "💰",
     *   "color": "#FF5722",
     *   "descripcion": "Nueva descripción",
     *   "orden": 1
     * }
     */
    public function update($id)
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        $cuenta = $this->cuentaModel->getById($id);

        if (!$cuenta) {
            Response::notFound('Cuenta no encontrada');
        }

        $data = json_decode(file_get_contents('php://input'), true);

        $cuentaActualizada = $this->cuentaModel->update(
            $id,
            $data['nombre'] ?? null,
            $data['icono'] ?? null,
            $data['color'] ?? null,
            $data['descripcion'] ?? null,
            $data['orden'] ?? null
        );

        if (!$cuentaActualizada) {
            Response::serverError('Error al actualizar la cuenta');
        }

        Response::success($cuentaActualizada, 'Cuenta actualizada exitosamente');
    }

    /**
     * Eliminar cuenta (soft delete)
     * DELETE /cuentas/{id}
     */
    public function delete($id)
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        $cuenta = $this->cuentaModel->getById($id);

        if (!$cuenta) {
            Response::notFound('Cuenta no encontrada');
        }

        // TODO: Validar que no tenga movimientos asociados

        $deleted = $this->cuentaModel->delete($id);

        if (!$deleted) {
            Response::serverError('Error al eliminar la cuenta');
        }

        Response::success(null, 'Cuenta eliminada exitosamente');
    }

    /**
     * Obtener saldos anteriores por cuenta para un período
     * GET /cuentas/saldos/anteriores?circulo_id={id}&anio={2025}&mes={12}
     * 
     * Retorna el saldo acumulado de cada cuenta HASTA ANTES del período seleccionado.
     */
    public function getSaldosAnteriores()
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        $circuloId = isset($_GET['circulo_id']) ? intval($_GET['circulo_id']) : null;
        $anio = isset($_GET['anio']) ? intval($_GET['anio']) : null;
        $mes = isset($_GET['mes']) ? intval($_GET['mes']) : null;

        if (!$circuloId) {
            Response::validationError('circulo_id es requerido');
        }

        if (!$anio || !$mes) {
            Response::validationError('anio y mes son requeridos');
        }

        if ($mes < 1 || $mes > 12) {
            Response::validationError('mes debe estar entre 1 y 12');
        }

        $saldos = $this->cuentaModel->getSaldosAnterioresPorCirculo($circuloId, $anio, $mes);

        Response::success([
            'saldos' => $saldos,
            'periodo_hasta' => date('Y-m-d', strtotime(sprintf('%04d-%02d-01', $anio, $mes) . ' -1 day')),
            'total_cuentas' => count($saldos)
        ], 'Saldos anteriores por cuenta obtenidos correctamente');
    }

    /**
     * Validar autenticación
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