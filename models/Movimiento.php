<?php

/**
 * Modelo Movimiento
 * Gestión de movimientos financieros (ingresos y gastos)
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
     * 
     * @param int $userId Usuario que registra
     * @param int $conceptoId Concepto seleccionado
     * @param float $valor Monto del movimiento
     * @param string $fecha Fecha del movimiento (Y-m-d)
     * @param array $circulosIds IDs de círculos a asociar
     * @param string $detalle Detalle adicional (opcional)
     * @param string $notas Notas adicionales (opcional)
     * @return array|null Movimiento creado o null si falla
     */
    public function create($userId, $conceptoId, $valor, $fecha, $circulosIds, $detalle = null, $notas = null)
    {
        try {
            $this->conn->beginTransaction();

            // Insertar movimiento
            $query = "INSERT INTO movimientos 
                      (user_id, concepto_id, valor, fecha, detalle, notas, creado_por_ia)
                      VALUES 
                      (:user_id, :concepto_id, :valor, :fecha, :detalle, :notas, FALSE)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':concepto_id', $conceptoId, PDO::PARAM_INT);
            $stmt->bindParam(':valor', $valor);
            $stmt->bindParam(':fecha', $fecha);
            $stmt->bindParam(':detalle', $detalle);
            $stmt->bindParam(':notas', $notas);
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
     * 
     * @param int $userId ID del usuario
     * @param int $tipoMovId Tipo de movimiento (1=Ingreso, 2=Gasto, null=Todos)
     * @param int $circuloId ID del círculo (opcional)
     * @param int $anio Año (opcional)
     * @param int $mes Mes (opcional)
     * @param int $limit Límite de resultados
     * @return array Lista de movimientos
     */
    public function getMovimientos($tipoMovId = null, $circuloId = null, $anio = null, $mes = null, $limit = 10)
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
            m.created_at,
            c.nombre as concepto_nombre,
            c.icono as concepto_icono,
            c.tipo_mov_id,
            cat.nombre as categoria_nombre,
            cat.icono as categoria_icono,
            cat.color as categoria_color,
            tm.nombre as tipo_movimiento,
            u.nombre as usuario_nombre
          FROM movimientos m
          INNER JOIN conceptos c ON m.concepto_id = c.id
          INNER JOIN categorias cat ON c.categoria_id = cat.id
          INNER JOIN tipos_movimiento tm ON c.tipo_mov_id = tm.id
          INNER JOIN usuarios u ON m.user_id = u.id
          INNER JOIN movimientos_circulos mc ON m.id = mc.movimiento_id
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

            $query .= " ORDER BY m.fecha DESC, m.created_at DESC LIMIT :limit";
            $params[':limit'] = $limit;

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
                    COALESCE(SUM(CASE WHEN tm.nombre = 'Ingreso' THEN m.valor ELSE -m.valor END), 0) as balance_neto
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
     * Eliminar movimiento
     * 
     * @param int $movimientoId ID del movimiento
     * @param int $userId ID del usuario (para validar permisos)
     * @return bool True si se eliminó correctamente
     */
    public function delete($movimientoId, $userId)
    {
        try {
            $query = "DELETE FROM movimientos 
                      WHERE id = :movimiento_id 
                        AND user_id = :user_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':movimiento_id', $movimientoId, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en delete: " . $e->getMessage());
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
}
