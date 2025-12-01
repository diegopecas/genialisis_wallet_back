<?php

/**
 * Modelo Movimiento - ACTUALIZADO CON SISTEMA DE CUENTAS
 * Gestión de movimientos financieros con soporte para cuentas y traslados
 */

require_once __DIR__ . '/Database.php';

class Movimiento
{
    private $db;
    private $conn;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->conn = $this->db->getConnection();
    }

    /**
     * Crear nuevo movimiento
     * ACTUALIZADO: Ahora incluye campos de cuentas
     * 
     * @param int $userId Usuario que registra
     * @param int $conceptoId Concepto seleccionado
     * @param float $valor Monto del movimiento
     * @param string $fecha Fecha del movimiento (Y-m-d)
     * @param array $circulosIds IDs de círculos a asociar
     * @param string $detalle Detalle adicional (opcional)
     * @param string $notas Notas adicionales (opcional)
     * @param int $cuentaId Cuenta para ingreso/gasto (opcional)
     * @param int $cuentaOrigenId Cuenta origen para traslado (opcional)
     * @param int $cuentaDestinoId Cuenta destino para traslado (opcional)
     * @return array|null Movimiento creado o null si falla
     */
    public function create($userId, $conceptoId, $valor, $fecha, $circulosIds, $detalle = null, $notas = null, $cuentaId = null, $cuentaOrigenId = null, $cuentaDestinoId = null)
    {
        try {
            $this->conn->beginTransaction();

            // Insertar movimiento
            $query = "INSERT INTO movimientos 
                      (user_id, concepto_id, valor, fecha, detalle, notas, creado_por_ia, cuenta_id, cuenta_origen_id, cuenta_destino_id)
                      VALUES 
                      (:user_id, :concepto_id, :valor, :fecha, :detalle, :notas, FALSE, :cuenta_id, :cuenta_origen_id, :cuenta_destino_id)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':concepto_id', $conceptoId, PDO::PARAM_INT);
            $stmt->bindParam(':valor', $valor);
            $stmt->bindParam(':fecha', $fecha);
            $stmt->bindParam(':detalle', $detalle);
            $stmt->bindParam(':notas', $notas);
            $stmt->bindParam(':cuenta_id', $cuentaId, PDO::PARAM_INT);
            $stmt->bindParam(':cuenta_origen_id', $cuentaOrigenId, PDO::PARAM_INT);
            $stmt->bindParam(':cuenta_destino_id', $cuentaDestinoId, PDO::PARAM_INT);
            $stmt->execute();

            $movimientoId = $this->conn->lastInsertId();

            // Asociar círculos
            if (!empty($circulosIds)) {
                $queryCirculos = "INSERT INTO movimientos_circulos (movimiento_id, circulo_id) VALUES (:mov_id, :circ_id)";
                $stmtCirculos = $this->conn->prepare($queryCirculos);

                foreach ($circulosIds as $circuloId) {
                    $stmtCirculos->bindParam(':mov_id', $movimientoId, PDO::PARAM_INT);
                    $stmtCirculos->bindParam(':circ_id', $circuloId, PDO::PARAM_INT);
                    $stmtCirculos->execute();
                }
            }

            $this->conn->commit();

            // Retornar movimiento completo
            return $this->getById($movimientoId);
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error en create: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtener movimiento por ID
     * ACTUALIZADO: Incluye información de cuentas
     * 
     * @param int $movimientoId ID del movimiento
     * @return array|null Movimiento con información completa
     */
    public function getById($movimientoId)
    {
        try {
            $query = "SELECT 
                        m.id,
                        m.user_id,
                        u.nombre as usuario_nombre,
                        m.concepto_id,
                        c.nombre as concepto_nombre,
                        c.icono as concepto_icono,
                        c.es_real as concepto_es_real,
                        c.requiere_detalle as concepto_requiere_detalle,
                        cat.nombre as categoria_nombre,
                        cat.icono as categoria_icono,
                        cat.color as categoria_color,
                        tm.id as tipo_mov_id,
                        tm.nombre as tipo_movimiento,
                        tm.icono as tipo_icono,
                        m.valor,
                        m.fecha,
                        m.detalle,
                        m.notas,
                        m.creado_por_ia,
                        m.cuenta_id,
                        m.cuenta_origen_id,
                        m.cuenta_destino_id,
                        cuenta.nombre as cuenta_nombre,
                        cuenta.icono as cuenta_icono,
                        cuenta_origen.nombre as cuenta_origen_nombre,
                        cuenta_origen.icono as cuenta_origen_icono,
                        cuenta_destino.nombre as cuenta_destino_nombre,
                        cuenta_destino.icono as cuenta_destino_icono,
                        m.created_at,
                        m.updated_at,
                        GROUP_CONCAT(circ.id ORDER BY circ.nombre) as circulos_ids,
                        GROUP_CONCAT(circ.nombre ORDER BY circ.nombre SEPARATOR ', ') as circulos_nombres,
                        COUNT(DISTINCT mc.circulo_id) > 1 as es_compartido
                      FROM movimientos m
                      INNER JOIN usuarios u ON m.user_id = u.id
                      INNER JOIN conceptos c ON m.concepto_id = c.id
                      INNER JOIN categorias cat ON c.categoria_id = cat.id
                      INNER JOIN tipos_movimiento tm ON c.tipo_mov_id = tm.id
                      LEFT JOIN movimientos_circulos mc ON m.id = mc.movimiento_id
                      LEFT JOIN circulos circ ON mc.circulo_id = circ.id
                      LEFT JOIN cuentas cuenta ON m.cuenta_id = cuenta.id
                      LEFT JOIN cuentas cuenta_origen ON m.cuenta_origen_id = cuenta_origen.id
                      LEFT JOIN cuentas cuenta_destino ON m.cuenta_destino_id = cuenta_destino.id
                      WHERE m.id = :movimiento_id
                      GROUP BY m.id
                      LIMIT 1";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':movimiento_id', $movimientoId, PDO::PARAM_INT);
            $stmt->execute();

            $movimiento = $stmt->fetch();

            if ($movimiento) {
                $movimiento = $this->formatMovimiento($movimiento);
            }

            return $movimiento;
        } catch (PDOException $e) {
            error_log("Error en getById: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtener movimientos filtrados
     * ACTUALIZADO: Incluye información de cuentas
     * 
     * @param int $tipoMovId Tipo de movimiento (1=Ingreso, 2=Gasto, 3=Traslado, null=Todos)
     * @param int $circuloId ID del círculo (opcional)
     * @param int $anio Año (opcional)
     * @param int $mes Mes (opcional)
     * @param int|null $limit Límite de resultados (null = sin límite)
     * @return array Lista de movimientos
     */
    public function getMovimientos($tipoMovId = null, $circuloId = null, $anio = null, $mes = null, $limit = null)
    {
        try {
            $query = "SELECT 
            m.id,
            m.user_id,
            m.concepto_id,
            m.valor,
            m.fecha,
            m.detalle,
            m.notas,
            m.cuenta_id,
            m.cuenta_origen_id,
            m.cuenta_destino_id,
            m.created_at,
            c.nombre as concepto_nombre,
            c.icono as concepto_icono,
            c.tipo_mov_id,
            cat.nombre as categoria_nombre,
            cat.icono as categoria_icono,
            cat.color as categoria_color,
            tm.nombre as tipo_movimiento,
            u.nombre as usuario_nombre,
            cuenta.nombre as cuenta_nombre,
            cuenta.icono as cuenta_icono,
            cuenta_origen.nombre as cuenta_origen_nombre,
            cuenta_origen.icono as cuenta_origen_icono,
            cuenta_destino.nombre as cuenta_destino_nombre,
            cuenta_destino.icono as cuenta_destino_icono
          FROM movimientos m
          INNER JOIN conceptos c ON m.concepto_id = c.id
          INNER JOIN categorias cat ON c.categoria_id = cat.id
          INNER JOIN tipos_movimiento tm ON c.tipo_mov_id = tm.id
          INNER JOIN usuarios u ON m.user_id = u.id
          INNER JOIN movimientos_circulos mc ON m.id = mc.movimiento_id
          LEFT JOIN cuentas cuenta ON m.cuenta_id = cuenta.id
          LEFT JOIN cuentas cuenta_origen ON m.cuenta_origen_id = cuenta_origen.id
          LEFT JOIN cuentas cuenta_destino ON m.cuenta_destino_id = cuenta_destino.id
          WHERE 1=1";

            $params = [];

            // Filtrar por círculo
            if ($circuloId) {
                $query .= " AND mc.circulo_id = :circulo_id";
                $params[':circulo_id'] = $circuloId;
            }

            if ($tipoMovId) {
                $query .= " AND c.tipo_mov_id = :tipo_mov_id";
                $params[':tipo_mov_id'] = $tipoMovId;
            }

            if ($anio) {
                $query .= " AND YEAR(m.fecha) = :anio";
                $params[':anio'] = $anio;
            }

            if ($mes) {
                $query .= " AND MONTH(m.fecha) = :mes";
                $params[':mes'] = $mes;
            }

            $query .= " ORDER BY m.fecha DESC, m.created_at DESC";

            // Solo agregar LIMIT si se especifica
            if ($limit !== null) {
                $query .= " LIMIT :limit";
                $params[':limit'] = $limit;
            }

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                if ($key === ':limit') {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }

            $stmt->execute();

            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error en getMovimientos: " . $e->getMessage());
            return [];
        }
    }
    /**
     * Obtener balance (totales) con filtros
     * NOTA: Los traslados NO afectan el balance
     * 
     * @param int $userId ID del usuario
     * @param int $circuloId ID del círculo (opcional)
     * @param int $anio Año (opcional)
     * @param int $mes Mes (opcional)
     * @return array Balance con totales
     */
    public function getBalance($circuloId = null, $anio = null, $mes = null)
    {

        try {
            $query = "SELECT 
                    COALESCE(SUM(CASE WHEN tm.nombre = 'Ingreso' THEN m.valor ELSE 0 END), 0) as total_ingresos,
                    COALESCE(SUM(CASE WHEN tm.nombre = 'Gasto' THEN m.valor ELSE 0 END), 0) as total_gastos,
                    COALESCE(SUM(CASE WHEN tm.nombre = 'Ingreso' THEN m.valor WHEN tm.nombre = 'Gasto' THEN -m.valor ELSE 0 END), 0) as balance_neto
                  FROM movimientos m
                  INNER JOIN conceptos c ON m.concepto_id = c.id
                  INNER JOIN tipos_movimiento tm ON c.tipo_mov_id = tm.id
                  INNER JOIN movimientos_circulos mc ON m.id = mc.movimiento_id
                  WHERE 1=1";

            $params = [];

            // CAMBIO: Filtrar por círculo
            if ($circuloId) {
                $query .= " AND mc.circulo_id = :circulo_id";
                $params[':circulo_id'] = $circuloId;
            }

            if ($anio) {
                $query .= " AND YEAR(m.fecha) = :anio";
                $params[':anio'] = $anio;
            }

            if ($mes) {
                $query .= " AND MONTH(m.fecha) = :mes";
                $params[':mes'] = $mes;
            }

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error en getBalance: " . $e->getMessage());
            return [
                'total_ingresos' => 0,
                'total_gastos' => 0,
                'balance_neto' => 0
            ];
        }
    }

    /**
     * Obtener balance detallado por concepto
     * 
     * @param int $userId ID del usuario
     * @param int $circuloId ID del círculo (opcional)
     * @param int $anio Año (opcional)
     * @param int $mes Mes (opcional)
     * @return array Balance detallado por concepto
     */
    public function getBalanceDetallado($circuloId = null, $anio = null, $mes = null)
    {
        try {
            $query = "SELECT 
                    c.id as concepto_id,
                    c.nombre as concepto_nombre,
                    c.icono as concepto_icono,
                    tm.nombre as tipo_movimiento,
                    cat.nombre as categoria_nombre,
                    COUNT(m.id) as cantidad,
                    SUM(m.valor) as total
                  FROM movimientos m
                  INNER JOIN conceptos c ON m.concepto_id = c.id
                  INNER JOIN tipos_movimiento tm ON c.tipo_mov_id = tm.id
                  INNER JOIN categorias cat ON c.categoria_id = cat.id
                  INNER JOIN movimientos_circulos mc ON m.id = mc.movimiento_id
                  WHERE 1=1";

            $params = [];

            // CAMBIO: Filtrar por círculo
            if ($circuloId) {
                $query .= " AND mc.circulo_id = :circulo_id";
                $params[':circulo_id'] = $circuloId;
            }

            if ($anio) {
                $query .= " AND YEAR(m.fecha) = :anio";
                $params[':anio'] = $anio;
            }

            if ($mes) {
                $query .= " AND MONTH(m.fecha) = :mes";
                $params[':mes'] = $mes;
            }

            $query .= " GROUP BY c.id, c.nombre, c.icono, tm.nombre, cat.nombre
                    ORDER BY cat.nombre, c.nombre";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error en getBalanceDetallado: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtener evolución mensual (para gráfico)
     * 
     * @param int $userId ID del usuario
     * @param int $circuloId ID del círculo (opcional)
     * @param int $anio Año (opcional, por defecto año actual)
     * @return array Datos mensuales para gráfico
     */
    public function getEvolucionMensual($circuloId = null, $anio = null)
    {
        try {
            if (!$anio) {
                $anio = date('Y');
            }

            $query = "SELECT 
                    MONTH(m.fecha) as mes,
                    MONTHNAME(m.fecha) as mes_nombre,
                    COALESCE(SUM(CASE WHEN tm.nombre = 'Ingreso' THEN m.valor ELSE 0 END), 0) as ingresos,
                    COALESCE(SUM(CASE WHEN tm.nombre = 'Gasto' THEN m.valor ELSE 0 END), 0) as gastos
                  FROM movimientos m
                  INNER JOIN conceptos c ON m.concepto_id = c.id
                  INNER JOIN tipos_movimiento tm ON c.tipo_mov_id = tm.id
                  INNER JOIN movimientos_circulos mc ON m.id = mc.movimiento_id
                  WHERE YEAR(m.fecha) = :anio";

            $params = [':anio' => $anio];

            // CAMBIO: Filtrar por círculo
            if ($circuloId) {
                $query .= " AND mc.circulo_id = :circulo_id";
                $params[':circulo_id'] = $circuloId;
            }

            $query .= " GROUP BY MONTH(m.fecha), MONTHNAME(m.fecha)
                    ORDER BY mes";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $resultados = $stmt->fetchAll();

            // Llenar meses sin datos
            $meses = [
                1 => 'Enero',
                2 => 'Febrero',
                3 => 'Marzo',
                4 => 'Abril',
                5 => 'Mayo',
                6 => 'Junio',
                7 => 'Julio',
                8 => 'Agosto',
                9 => 'Septiembre',
                10 => 'Octubre',
                11 => 'Noviembre',
                12 => 'Diciembre'
            ];

            $evolucion = [];
            $resultadosMap = [];

            foreach ($resultados as $row) {
                $resultadosMap[$row['mes']] = $row;
            }

            for ($mes = 1; $mes <= 12; $mes++) {
                if (isset($resultadosMap[$mes])) {
                    $evolucion[] = $resultadosMap[$mes];
                } else {
                    $evolucion[] = [
                        'mes' => $mes,
                        'mes_nombre' => $meses[$mes],
                        'ingresos' => 0,
                        'gastos' => 0
                    ];
                }
            }

            return $evolucion;
        } catch (PDOException $e) {
            error_log("Error en getEvolucionMensual: " . $e->getMessage());
            return [];
        }
    }
    /**
     * Obtener totales agrupados por día
     * 
     * @param int $circuloId ID del círculo (opcional)
     * @param int $anio Año (opcional)
     * @param int $mes Mes (opcional)
     * @return array Totales por día
     */
    public function getTotalesPorDia($circuloId = null, $anio = null, $mes = null)
    {
        try {
            $query = "SELECT 
                m.fecha,
                COALESCE(SUM(CASE WHEN tm.nombre = 'Ingreso' THEN m.valor ELSE 0 END), 0) as total_ingresos,
                COALESCE(SUM(CASE WHEN tm.nombre = 'Gasto' THEN m.valor ELSE 0 END), 0) as total_gastos,
                COUNT(CASE WHEN tm.nombre = 'Ingreso' THEN 1 END) as cantidad_ingresos,
                COUNT(CASE WHEN tm.nombre = 'Gasto' THEN 1 END) as cantidad_gastos
              FROM movimientos m
              INNER JOIN conceptos c ON m.concepto_id = c.id
              INNER JOIN tipos_movimiento tm ON c.tipo_mov_id = tm.id
              INNER JOIN movimientos_circulos mc ON m.id = mc.movimiento_id
              WHERE 1=1";

            $params = [];

            if ($circuloId) {
                $query .= " AND mc.circulo_id = :circulo_id";
                $params[':circulo_id'] = $circuloId;
            }

            if ($anio) {
                $query .= " AND YEAR(m.fecha) = :anio";
                $params[':anio'] = $anio;
            }

            if ($mes) {
                $query .= " AND MONTH(m.fecha) = :mes";
                $params[':mes'] = $mes;
            }

            $query .= " GROUP BY m.fecha
                ORDER BY m.fecha DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error en getTotalesPorDia: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtener totales agrupados por categoría
     * 
     * @param int $circuloId ID del círculo (opcional)
     * @param int $anio Año (opcional)
     * @param int $mes Mes (opcional)
     * @return array Totales por categoría
     */
    public function getTotalesPorCategoria($circuloId = null, $anio = null, $mes = null)
    {
        try {
            $query = "SELECT 
                cat.id as categoria_id,
                cat.nombre as categoria_nombre,
                cat.icono as categoria_icono,
                cat.color as categoria_color,
                tm.nombre as tipo_movimiento,
                COUNT(m.id) as cantidad,
                SUM(m.valor) as total
              FROM movimientos m
              INNER JOIN conceptos c ON m.concepto_id = c.id
              INNER JOIN categorias cat ON c.categoria_id = cat.id
              INNER JOIN tipos_movimiento tm ON c.tipo_mov_id = tm.id
              INNER JOIN movimientos_circulos mc ON m.id = mc.movimiento_id
              WHERE 1=1";

            $params = [];

            if ($circuloId) {
                $query .= " AND mc.circulo_id = :circulo_id";
                $params[':circulo_id'] = $circuloId;
            }

            if ($anio) {
                $query .= " AND YEAR(m.fecha) = :anio";
                $params[':anio'] = $anio;
            }

            if ($mes) {
                $query .= " AND MONTH(m.fecha) = :mes";
                $params[':mes'] = $mes;
            }

            $query .= " GROUP BY cat.id, cat.nombre, cat.icono, cat.color, tm.nombre
                ORDER BY tm.nombre ASC, total DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error en getTotalesPorCategoria: " . $e->getMessage());
            return [];
        }
    }
    /**
     * Actualizar movimiento existente
     * ACTUALIZADO: Permite actualizar cuentas
     * 
     * @param int $movimientoId ID del movimiento a actualizar
     * @param int $userId Usuario que actualiza (para validar permisos)
     * @param int $conceptoId Nuevo concepto (opcional)
     * @param float $valor Nuevo valor (opcional)
     * @param string $fecha Nueva fecha (opcional)
     * @param string $detalle Nuevo detalle (opcional)
     * @param string $notas Nuevas notas (opcional)
     * @param array $circulosIds Nuevos círculos (opcional)
     * @param int $cuentaId Nueva cuenta (opcional)
     * @param int $cuentaOrigenId Nueva cuenta origen (opcional)
     * @param int $cuentaDestinoId Nueva cuenta destino (opcional)
     * @return array|null Movimiento actualizado o null si falla
     */
    public function update($movimientoId, $userId, $conceptoId = null, $valor = null, $fecha = null, $detalle = null, $notas = null, $circulosIds = null, $cuentaId = null, $cuentaOrigenId = null, $cuentaDestinoId = null)
    {
        try {
            $this->conn->beginTransaction();

            // Verificar que el movimiento existe y pertenece al usuario
            $movimientoActual = $this->getById($movimientoId);

            if (!$movimientoActual || $movimientoActual['user_id'] != $userId) {
                $this->conn->rollBack();
                return null;
            }

            // Construir query dinámico según campos a actualizar
            $campos = [];
            $params = [':movimiento_id' => $movimientoId, ':user_id' => $userId];

            if ($conceptoId !== null) {
                $campos[] = "concepto_id = :concepto_id";
                $params[':concepto_id'] = $conceptoId;
            }

            if ($valor !== null) {
                $campos[] = "valor = :valor";
                $params[':valor'] = $valor;
            }

            if ($fecha !== null) {
                $campos[] = "fecha = :fecha";
                $params[':fecha'] = $fecha;
            }

            if ($detalle !== null) {
                $campos[] = "detalle = :detalle";
                $params[':detalle'] = $detalle;
            }

            if ($notas !== null) {
                $campos[] = "notas = :notas";
                $params[':notas'] = $notas;
            }

            // Actualizar cuentas
            if ($cuentaId !== null) {
                $campos[] = "cuenta_id = :cuenta_id";
                $params[':cuenta_id'] = $cuentaId;
            }

            if ($cuentaOrigenId !== null) {
                $campos[] = "cuenta_origen_id = :cuenta_origen_id";
                $params[':cuenta_origen_id'] = $cuentaOrigenId;
            }

            if ($cuentaDestinoId !== null) {
                $campos[] = "cuenta_destino_id = :cuenta_destino_id";
                $params[':cuenta_destino_id'] = $cuentaDestinoId;
            }

            // Solo actualizar si hay campos para actualizar
            if (!empty($campos)) {
                $query = "UPDATE movimientos 
                          SET " . implode(", ", $campos) . "
                          WHERE id = :movimiento_id 
                            AND user_id = :user_id";

                $stmt = $this->conn->prepare($query);

                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value);
                }

                $stmt->execute();
            }

            // Actualizar círculos si se proporcionaron
            if ($circulosIds !== null && is_array($circulosIds)) {
                // Eliminar círculos actuales
                $deleteQuery = "DELETE FROM movimientos_circulos WHERE movimiento_id = :mov_id";
                $deleteStmt = $this->conn->prepare($deleteQuery);
                $deleteStmt->bindParam(':mov_id', $movimientoId, PDO::PARAM_INT);
                $deleteStmt->execute();

                // Insertar nuevos círculos
                if (!empty($circulosIds)) {
                    $insertQuery = "INSERT INTO movimientos_circulos (movimiento_id, circulo_id) VALUES (:mov_id, :circ_id)";
                    $insertStmt = $this->conn->prepare($insertQuery);

                    foreach ($circulosIds as $circuloId) {
                        $insertStmt->bindParam(':mov_id', $movimientoId, PDO::PARAM_INT);
                        $insertStmt->bindParam(':circ_id', $circuloId, PDO::PARAM_INT);
                        $insertStmt->execute();
                    }
                }
            }

            $this->conn->commit();

            // Retornar movimiento actualizado
            return $this->getById($movimientoId);
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error en update: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return null;
        }
    }
    /**
     * Eliminar movimiento
     * NOTA: Los permisos se validan en el CONTROLLER (creador o admin)
     * Este método solo ejecuta el delete
     * 
     * @param int $movimientoId ID del movimiento
     * @param int $userId ID del usuario (ya validado en controller)
     * @return bool True si se eliminó correctamente
     */
    public function delete($movimientoId, $userId)
    {
        try {
            // NO validar user_id porque el controller ya validó permisos (creador o admin)
            $query = "DELETE FROM movimientos 
                      WHERE id = :movimiento_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':movimiento_id', $movimientoId, PDO::PARAM_INT);
            $stmt->execute();

            $rowsDeleted = $stmt->rowCount();
            error_log("Delete ejecutado - Rows deleted: " . $rowsDeleted);

            return $rowsDeleted > 0;
        } catch (PDOException $e) {
            error_log("Error en delete: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }
    /**
     * Formatear movimiento (convertir tipos)
     */
    private function formatMovimiento($movimiento)
    {
        $movimiento['valor'] = floatval($movimiento['valor']);
        $movimiento['es_compartido'] = (bool)$movimiento['es_compartido'];
        $movimiento['creado_por_ia'] = (bool)$movimiento['creado_por_ia'];
        $movimiento['concepto_es_real'] = (bool)($movimiento['concepto_es_real'] ?? true);

        return $movimiento;
    }
    /**
     * Obtener datos para gráfico de barras por categoría
     * 
     * @param int $circuloId ID del círculo (opcional)
     * @param int $anio Año (opcional)
     * @param int $mes Mes (opcional)
     * @return array Datos para gráfico
     */
    public function getGraficoCategoria($circuloId = null, $anio = null, $mes = null)
    {
        try {
            $query = "SELECT 
                cat.id as categoria_id,
                cat.nombre as categoria_nombre,
                cat.icono as categoria_icono,
                cat.color as categoria_color,
                COALESCE(SUM(CASE WHEN tm.nombre = 'Ingreso' THEN m.valor ELSE 0 END), 0) as total_ingresos,
                COALESCE(SUM(CASE WHEN tm.nombre = 'Gasto' THEN m.valor ELSE 0 END), 0) as total_gastos
              FROM categorias cat
              LEFT JOIN conceptos c ON cat.id = c.categoria_id
              LEFT JOIN movimientos m ON c.id = m.concepto_id
              LEFT JOIN tipos_movimiento tm ON c.tipo_mov_id = tm.id
              LEFT JOIN movimientos_circulos mc ON m.id = mc.movimiento_id
              WHERE cat.circulo_id = :circulo_id";

            $params = [':circulo_id' => $circuloId];

            if ($anio) {
                $query .= " AND YEAR(m.fecha) = :anio";
                $params[':anio'] = $anio;
            }

            if ($mes) {
                $query .= " AND MONTH(m.fecha) = :mes";
                $params[':mes'] = $mes;
            }

            $query .= " GROUP BY cat.id, cat.nombre, cat.icono, cat.color
                ORDER BY cat.nombre ASC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error en getGraficoCategoria: " . $e->getMessage());
            return [];
        }
    }
    /**
     * Obtener años y meses disponibles (con registros)
     * 
     * @param int $circuloId ID del círculo
     * @param int $tipoMovId Tipo de movimiento (1=Ingreso, 2=Gasto, 3=Traslado, null=Todos)
     * @return array Años y meses disponibles
     */
    public function getPeriodosDisponibles($circuloId = null, $tipoMovId = null)
    {
        try {
            $query = "SELECT DISTINCT 
                    YEAR(m.fecha) as anio,
                    MONTH(m.fecha) as mes
                  FROM movimientos m
                  INNER JOIN conceptos c ON m.concepto_id = c.id
                  INNER JOIN movimientos_circulos mc ON m.id = mc.movimiento_id
                  WHERE 1=1";

            $params = [];

            if ($circuloId) {
                $query .= " AND mc.circulo_id = :circulo_id";
                $params[':circulo_id'] = $circuloId;
            }

            if ($tipoMovId) {
                $query .= " AND c.tipo_mov_id = :tipo_mov_id";
                $params[':tipo_mov_id'] = $tipoMovId;
            }

            $query .= " ORDER BY anio DESC, mes DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $periodos = $stmt->fetchAll();

            // Agrupar por año
            $anios = [];
            foreach ($periodos as $periodo) {
                $anio = intval($periodo['anio']);
                $mes = intval($periodo['mes']);

                if (!isset($anios[$anio])) {
                    $anios[$anio] = [];
                }

                if (!in_array($mes, $anios[$anio])) {
                    $anios[$anio][] = $mes;
                }
            }

            // Formatear respuesta
            $resultado = [];
            foreach ($anios as $anio => $meses) {
                sort($meses);
                $resultado[] = [
                    'anio' => $anio,
                    'meses' => $meses
                ];
            }

            return $resultado;
        } catch (PDOException $e) {
            error_log("Error en getPeriodosDisponibles: " . $e->getMessage());
            return [];
        }
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
}