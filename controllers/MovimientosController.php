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
     * Si limit=0 o no se especifica limit, trae todos los movimientos
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

        // Si limit=0 o no viene, significa "sin límite" (traer todos)
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : null;
        if ($limit === 0) {
            $limit = null; // null = sin límite
        }

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
     * Verificar si el usuario es admin de alguno de los círculos especificados
     * 
     * @param int $userId ID del usuario
     * @param array $circulosIds Array de IDs de círculos
     * @return bool True si es admin de al menos uno
     */
    public function esAdminDeCirculos($userId, $circulosIds)
    {
        try {
            if (empty($circulosIds)) {
                return false;
            }

            // Limpiar y convertir a enteros
            $circulosIds = array_map('intval', $circulosIds);

            // Crear placeholders para la consulta IN
            $placeholders = str_repeat('?,', count($circulosIds) - 1) . '?';

            $query = "SELECT COUNT(*) as count
                      FROM usuarios_circulos 
                      WHERE user_id = ? 
                        AND circulo_id IN ($placeholders)
                        AND es_admin = 1";

            $stmt = $this->conn->prepare($query);

            // Bind del user_id primero
            $params = [$userId];
            // Luego los círculos
            $params = array_merge($params, $circulosIds);

            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result['count'] > 0;
        } catch (PDOException $e) {
            error_log("Error en esAdminDeCirculos: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Actualizar movimiento existente
     * PUT/PATCH /movimientos/{id}
     * 
     * Puede editar si:
     * 1. Es el creador del movimiento (user_id)
     * 2. Es ADMIN de algún círculo asociado al movimiento
     * 
     * Body: {
     *   "concepto_id": 1, (opcional)
     *   "valor": 50000, (opcional)
     *   "fecha": "2025-11-01", (opcional)
     *   "detalle": "...", (opcional)
     *   "notas": "...", (opcional)
     *   "circulos_ids": [1] (opcional)
     * }
     */
    public function update($id)
    {
        error_log("=== INICIO UPDATE ===");

        $payload = $this->validateAuth();
        $userId = $payload['user_id'];
        error_log("1. Auth OK - userId: " . $userId);

        // Verificar que el movimiento existe
        $movimiento = $this->movimientoModel->getById($id);
        error_log("2. Movimiento obtenido");

        if (!$movimiento) {
            error_log("ERROR: Movimiento no encontrado");
            Response::notFound('Movimiento no encontrado');
        }

        error_log("3. Movimiento existe - user_id: " . $movimiento['user_id']);

        // VALIDACIÓN DE PERMISOS
        $esCreador = ($movimiento['user_id'] == $userId);
        error_log("4. ¿Es creador? " . ($esCreador ? 'SI' : 'NO'));

        if ($esCreador) {
            error_log("5. Permiso: Es creador");
        } else {
            error_log("5. No es creador, verificando admin...");
            $circulosMovimiento = explode(',', $movimiento['circulos_ids']);
            error_log("6. Círculos: " . implode(', ', $circulosMovimiento));

            $esAdminDeAlgunCirculo = $this->movimientoModel->esAdminDeCirculos($userId, $circulosMovimiento);
            error_log("7. ¿Es admin? " . ($esAdminDeAlgunCirculo ? 'SI' : 'NO'));

            if (!$esAdminDeAlgunCirculo) {
                error_log("ERROR: Sin permisos");
                Response::unauthorized('No tienes permiso para actualizar este movimiento');
            }

            error_log("8. Permiso concedido: Admin del círculo");
        }

        error_log("9. Permisos OK, obteniendo datos del body...");

        // Obtener datos del body
        $rawBody = file_get_contents('php://input');
        error_log("10. Raw body length: " . strlen($rawBody));

        $data = json_decode($rawBody, true);
        error_log("11. JSON decoded. Keys: " . implode(', ', array_keys($data ?? [])));

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("ERROR: JSON inválido - " . json_last_error_msg());
            Response::validationError('JSON inválido');
        }

        error_log("12. Iniciando validaciones...");

        // Validaciones
        $errors = [];

        if (isset($data['concepto_id'])) {
            error_log("13. Validando concepto_id: " . $data['concepto_id']);

            if (empty($data['concepto_id'])) {
                $errors['concepto_id'] = 'Concepto es requerido';
            } else {
                error_log("14. Buscando concepto en BD...");
                $concepto = $this->conceptoModel->getById($data['concepto_id']);
                error_log("15. Concepto encontrado: " . ($concepto ? 'SI' : 'NO'));

                if (!$concepto) {
                    $errors['concepto_id'] = 'Concepto no encontrado';
                } else if ($concepto['requiere_detalle']) {
                    error_log("16. Concepto requiere detalle");
                    $detalleActualizado = $data['detalle'] ?? $movimiento['detalle'];
                    if (empty($detalleActualizado)) {
                        $errors['detalle'] = 'Este concepto requiere detalle';
                    }
                }
            }
        }

        if (isset($data['valor']) && $data['valor'] <= 0) {
            error_log("17. ERROR: Valor inválido");
            $errors['valor'] = 'Valor debe ser mayor a 0';
        }

        if (isset($data['fecha']) && empty($data['fecha'])) {
            error_log("18. ERROR: Fecha vacía");
            $errors['fecha'] = 'Fecha es requerida';
        }

        if (!empty($errors)) {
            error_log("19. Errores de validación: " . json_encode($errors));
            Response::validationError('Errores de validación', $errors);
        }

        error_log("20. Validaciones OK, llamando a modelo->update()...");

        // Actualizar movimiento
        try {
            $movimientoActualizado = $this->movimientoModel->update(
                $id,
                $userId,
                $data['concepto_id'] ?? null,
                $data['valor'] ?? null,
                $data['fecha'] ?? null,
                $data['detalle'] ?? null,
                $data['notas'] ?? null,
                $data['circulos_ids'] ?? null
            );

            error_log("21. Modelo->update() ejecutado");
        } catch (Exception $e) {
            error_log("ERROR en modelo->update(): " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            Response::serverError('Error al actualizar: ' . $e->getMessage());
        }

        if (!$movimientoActualizado) {
            error_log("22. ERROR: modelo->update() retornó NULL o FALSE");
            Response::serverError('Error al actualizar el movimiento');
        }

        error_log("23. Update exitoso, enviando respuesta...");
        Response::success($movimientoActualizado, 'Movimiento actualizado exitosamente');
        error_log("24. === FIN UPDATE ===");
    }

    /**
     * Eliminar movimiento
     * DELETE /movimientos/{id}
     * 
     * Puede eliminar si:
     * 1. Es el creador del movimiento (user_id)
     * 2. Es ADMIN de algún círculo asociado al movimiento
     */
    public function delete($id)
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        // LOG DE DEBUG
        error_log("=== DELETE MOVIMIENTO DEBUG ===");
        error_log("Movimiento ID: " . $id);
        error_log("User ID del token: " . $userId);

        // Verificar que el movimiento existe
        $movimiento = $this->movimientoModel->getById($id);

        if (!$movimiento) {
            error_log("ERROR: Movimiento no encontrado");
            Response::notFound('Movimiento no encontrado');
        }

        error_log("Movimiento encontrado - creado por user_id: " . $movimiento['user_id']);

        // VALIDACIÓN DE PERMISOS MEJORADA
        $esCreador = ($movimiento['user_id'] == $userId);
        error_log("¿Es creador del movimiento? " . ($esCreador ? 'SI' : 'NO'));

        if ($esCreador) {
            error_log("✅ Permiso concedido: Es el creador");
        } else {
            // Verificar si es admin de algún círculo del movimiento
            $circulosMovimiento = explode(',', $movimiento['circulos_ids']);
            error_log("Círculos del movimiento: " . implode(', ', $circulosMovimiento));

            $esAdminDeAlgunCirculo = $this->movimientoModel->esAdminDeCirculos($userId, $circulosMovimiento);
            error_log("¿Es admin de algún círculo? " . ($esAdminDeAlgunCirculo ? 'SI' : 'NO'));

            if (!$esAdminDeAlgunCirculo) {
                error_log("❌ PERMISO DENEGADO: No es creador ni admin del círculo");
                Response::unauthorized('No tienes permiso para eliminar este movimiento');
            }

            error_log("✅ Permiso concedido: Es admin del círculo");
        }

        // Eliminar
        $deleted = $this->movimientoModel->delete($id, $userId);

        if (!$deleted) {
            error_log("ERROR: No se pudo eliminar el movimiento");
            Response::serverError('Error al eliminar el movimiento');
        }

        error_log("✅ Movimiento eliminado exitosamente");
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

        $evolucion = $this->movimientoModel->getEvolucionMensual($circuloId, $anio);

        Response::success([
            'anio' => $anio,
            'datos' => $evolucion
        ], 'Evolución mensual obtenida correctamente');
    }
    /**
     * Obtener totales agrupados por día
     * GET /movimientos/totales/dia?circulo_id={id}&anio={2025}&mes={11}
     */
    public function getTotalesPorDia()
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        // Obtener parámetros
        $circuloId = isset($_GET['circulo_id']) ? intval($_GET['circulo_id']) : null;
        $anio = isset($_GET['anio']) ? intval($_GET['anio']) : null;
        $mes = isset($_GET['mes']) ? intval($_GET['mes']) : null;

        $totales = $this->movimientoModel->getTotalesPorDia($circuloId, $anio, $mes);

        Response::success([
            'totales' => $totales
        ], 'Totales por día obtenidos correctamente');
    }

    /**
     * Obtener totales agrupados por categoría
     * GET /movimientos/totales/categoria?circulo_id={id}&anio={2025}&mes={11}
     */
    public function getTotalesPorCategoria()
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        // Obtener parámetros
        $circuloId = isset($_GET['circulo_id']) ? intval($_GET['circulo_id']) : null;
        $anio = isset($_GET['anio']) ? intval($_GET['anio']) : null;
        $mes = isset($_GET['mes']) ? intval($_GET['mes']) : null;

        $totales = $this->movimientoModel->getTotalesPorCategoria($circuloId, $anio, $mes);

        Response::success([
            'totales' => $totales
        ], 'Totales por categoría obtenidos correctamente');
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
    /**
     * Obtener datos para gráfico de barras por categoría
     * GET /movimientos/grafico/categoria?circulo_id={id}&anio={2025}&mes={11}
     */
    public function getGraficoCategoria()
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        // Obtener parámetros
        $circuloId = isset($_GET['circulo_id']) ? intval($_GET['circulo_id']) : null;
        $anio = isset($_GET['anio']) ? intval($_GET['anio']) : null;
        $mes = isset($_GET['mes']) ? intval($_GET['mes']) : null;

        if (!$circuloId) {
            Response::validationError('circulo_id es requerido');
        }

        $datos = $this->movimientoModel->getGraficoCategoria($circuloId, $anio, $mes);

        Response::success([
            'categorias' => $datos
        ], 'Datos de gráfico por categoría obtenidos correctamente');
    }
    /**
     * Obtener periodos disponibles (años y meses con registros)
     * GET /movimientos/periodos/disponibles?circulo_id={id}&tipo_mov_id={1|2}
     */
    public function getPeriodosDisponibles()
    {
        $payload = $this->validateAuth();
        $userId = $payload['user_id'];

        // Obtener parámetros
        $circuloId = isset($_GET['circulo_id']) ? intval($_GET['circulo_id']) : null;
        $tipoMovId = isset($_GET['tipo_mov_id']) ? intval($_GET['tipo_mov_id']) : null;

        $periodos = $this->movimientoModel->getPeriodosDisponibles($circuloId, $tipoMovId);

        Response::success([
            'periodos' => $periodos
        ], 'Periodos disponibles obtenidos correctamente');
    }
}
