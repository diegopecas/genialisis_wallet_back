<?php

/**
 * MovimientosController - ACTUALIZADO CON SISTEMA DE CUENTAS
 * Manejo de movimientos financieros (CRUD y consultas)
 * Soporta: Ingresos, Gastos y Traslados entre cuentas
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
     * 
     * ACTUALIZADO: Ahora soporta cuentas
     * 
     * Body para INGRESO (tipo_mov_id=1):
     * {
     *   "concepto_id": 1,
     *   "valor": 50000,
     *   "fecha": "2025-11-01",
     *   "circulos_ids": [1],
     *   "cuenta_id": 1,          // REQUERIDO para ingreso
     *   "detalle": "...",
     *   "notas": "..."
     * }
     * 
     * Body para GASTO (tipo_mov_id=2):
     * {
     *   "concepto_id": 15,
     *   "valor": 50000,
     *   "fecha": "2025-11-01",
     *   "circulos_ids": [1],
     *   "cuenta_id": 2,          // REQUERIDO para gasto
     *   "detalle": "...",
     *   "notas": "..."
     * }
     * 
     * Body para TRASLADO (tipo_mov_id=3):
     * {
     *   "concepto_id": 50,
     *   "valor": 100000,
     *   "fecha": "2025-11-01",
     *   "circulos_ids": [1],
     *   "cuenta_origen_id": 1,   // REQUERIDO para traslado
     *   "cuenta_destino_id": 2,  // REQUERIDO para traslado
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

        // === VALIDACIONES DE CUENTAS SEGÚN TIPO DE MOVIMIENTO ===
        $tipoMovId = $concepto['tipo_mov_id'];

        if ($tipoMovId == 1 || $tipoMovId == 2) {
            // INGRESO o GASTO: requiere cuenta_id
            if (empty($data['cuenta_id'])) {
                Response::validationError('Cuenta es requerida para ' . ($tipoMovId == 1 ? 'ingresos' : 'gastos'), [
                    'cuenta_id' => 'Debe seleccionar una cuenta'
                ]);
            }

            // Validar que NO vengan cuenta_origen_id ni cuenta_destino_id
            if (!empty($data['cuenta_origen_id']) || !empty($data['cuenta_destino_id'])) {
                Response::validationError('Ingreso/Gasto solo debe tener cuenta_id', [
                    'cuenta_origen_id' => 'No debe enviarse para ingreso/gasto',
                    'cuenta_destino_id' => 'No debe enviarse para ingreso/gasto'
                ]);
            }
        } elseif ($tipoMovId == 3) {
            // TRASLADO: requiere cuenta_origen_id y cuenta_destino_id
            if (empty($data['cuenta_origen_id'])) {
                $errors['cuenta_origen_id'] = 'Cuenta origen es requerida para traslados';
            }

            if (empty($data['cuenta_destino_id'])) {
                $errors['cuenta_destino_id'] = 'Cuenta destino es requerida para traslados';
            }

            if (!empty($errors)) {
                Response::validationError('Errores de validación', $errors);
            }

            // Validar que cuenta origen y destino sean diferentes
            if ($data['cuenta_origen_id'] == $data['cuenta_destino_id']) {
                Response::validationError('Las cuentas de origen y destino deben ser diferentes', [
                    'cuenta_destino_id' => 'Debe seleccionar una cuenta diferente'
                ]);
            }

            // Validar que NO venga cuenta_id
            if (!empty($data['cuenta_id'])) {
                Response::validationError('Traslado no debe tener cuenta_id', [
                    'cuenta_id' => 'No debe enviarse para traslados'
                ]);
            }
        }

        // Crear movimiento con cuentas
        $movimiento = $this->movimientoModel->create(
            $userId,
            $data['concepto_id'],
            $data['valor'],
            $data['fecha'],
            $data['circulos_ids'],
            $data['detalle'] ?? null,
            $data['notas'] ?? null,
            $data['cuenta_id'] ?? null,
            $data['cuenta_origen_id'] ?? null,
            $data['cuenta_destino_id'] ?? null
        );

        if (!$movimiento) {
            Response::serverError('Error al crear el movimiento');
        }

        Response::success($movimiento, 'Movimiento creado exitosamente', 201);
    }

    /**
     * Obtener movimientos con filtros
     * GET /movimientos?tipo_mov_id={1|2|3}&circulo_id={id}&anio={2025}&mes={11}&limit={10}
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
     * ACTUALIZADO: Ahora permite actualizar cuentas
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
     *   "circulos_ids": [1], (opcional)
     *   "cuenta_id": 2, (opcional - para ingreso/gasto)
     *   "cuenta_origen_id": 1, (opcional - para traslado)
     *   "cuenta_destino_id": 3 (opcional - para traslado)
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
            error_log("5. ✅ Permiso concedido: Es el creador");
        } else {
            // Verificar si es admin de algún círculo del movimiento
            $circulosMovimiento = explode(',', $movimiento['circulos_ids']);
            error_log("6. Verificando admin en círculos: " . implode(', ', $circulosMovimiento));

            $esAdminDeAlgunCirculo = $this->movimientoModel->esAdminDeCirculos($userId, $circulosMovimiento);
            error_log("7. ¿Es admin de algún círculo? " . ($esAdminDeAlgunCirculo ? 'SI' : 'NO'));

            if (!$esAdminDeAlgunCirculo) {
                error_log("8. ❌ PERMISO DENEGADO: No es creador ni admin");
                Response::unauthorized('No tienes permiso para editar este movimiento');
            }

            error_log("9. ✅ Permiso concedido: Es admin del círculo");
        }

        // Obtener datos del body
        $data = json_decode(file_get_contents('php://input'), true);
        error_log("10. Datos recibidos: " . json_encode($data));

        // Validar concepto si se envía
        if (isset($data['concepto_id'])) {
            error_log("11. Validando concepto_id: " . $data['concepto_id']);
            $concepto = $this->conceptoModel->getById($data['concepto_id']);

            if (!$concepto) {
                error_log("12. ERROR: Concepto no encontrado");
                Response::validationError('Concepto no encontrado');
            }

            error_log("13. Concepto válido");

            // Validar detalle si el concepto lo requiere
            if ($concepto['requiere_detalle']) {
                if (isset($data['detalle']) && empty($data['detalle'])) {
                    error_log("14. ERROR: Detalle requerido pero vacío");
                    Response::validationError('Este concepto requiere detalle', [
                        'detalle' => 'Detalle es requerido para este concepto'
                    ]);
                }
                error_log("15. Detalle OK");
            }

            // === VALIDACIONES DE CUENTAS SI SE CAMBIA EL CONCEPTO ===
            $tipoMovId = $concepto['tipo_mov_id'];

            if ($tipoMovId == 1 || $tipoMovId == 2) {
                // INGRESO o GASTO
                if (isset($data['cuenta_id']) && empty($data['cuenta_id'])) {
                    Response::validationError('Cuenta es requerida para ' . ($tipoMovId == 1 ? 'ingresos' : 'gastos'));
                }

                // Si se envían cuenta_origen_id o cuenta_destino_id, es error
                if (isset($data['cuenta_origen_id']) || isset($data['cuenta_destino_id'])) {
                    Response::validationError('Ingreso/Gasto solo debe tener cuenta_id');
                }
            } elseif ($tipoMovId == 3) {
                // TRASLADO
                if (isset($data['cuenta_origen_id']) && empty($data['cuenta_origen_id'])) {
                    Response::validationError('Cuenta origen es requerida para traslados');
                }

                if (isset($data['cuenta_destino_id']) && empty($data['cuenta_destino_id'])) {
                    Response::validationError('Cuenta destino es requerida para traslados');
                }

                // Validar que cuenta origen y destino sean diferentes
                if (isset($data['cuenta_origen_id']) && isset($data['cuenta_destino_id'])) {
                    if ($data['cuenta_origen_id'] == $data['cuenta_destino_id']) {
                        Response::validationError('Las cuentas de origen y destino deben ser diferentes');
                    }
                }

                // Si se envía cuenta_id, es error
                if (isset($data['cuenta_id'])) {
                    Response::validationError('Traslado no debe tener cuenta_id');
                }
            }
        }

        // Validar valor si se envía
        if (isset($data['valor']) && $data['valor'] <= 0) {
            error_log("16. ERROR: Valor inválido");
            Response::validationError('Valor debe ser mayor a 0', [
                'valor' => 'Valor debe ser mayor a 0'
            ]);
        }

        error_log("17. Validaciones OK, procediendo a actualizar");

        // Actualizar movimiento (incluyendo cuentas)
        $movimientoActualizado = $this->movimientoModel->update(
            $id,
            $userId,
            $data['concepto_id'] ?? null,
            $data['valor'] ?? null,
            $data['fecha'] ?? null,
            $data['detalle'] ?? null,
            $data['notas'] ?? null,
            $data['circulos_ids'] ?? null,
            $data['cuenta_id'] ?? null,
            $data['cuenta_origen_id'] ?? null,
            $data['cuenta_destino_id'] ?? null
        );

        error_log("18. Llamada a modelo->update() ejecutada");

        if (!$movimientoActualizado) {
            error_log("19. ERROR: modelo->update() retornó NULL o FALSE");
            Response::serverError('Error al actualizar el movimiento');
        }

        error_log("20. Update exitoso, enviando respuesta...");
        Response::success($movimientoActualizado, 'Movimiento actualizado exitosamente');
        error_log("21. === FIN UPDATE ===");
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
     * GET /movimientos/periodos/disponibles?circulo_id={id}&tipo_mov_id={1|2|3}
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