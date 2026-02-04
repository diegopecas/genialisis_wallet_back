<?php

/**
 * Modelo Cuenta
 * Gestión de cuentas financieras por círculo
 */

require_once __DIR__ . '/Database.php';

class Cuenta
{
    private $db;
    private $conn;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->conn = $this->db->getConnection();
    }

    /**
     * Obtener cuentas por círculo
     */
    public function getCuentasPorCirculo($circuloId)
    {
        try {
            $query = "SELECT 
                        id,
                        circulo_id,
                        nombre,
                        icono,
                        color,
                        descripcion,
                        activo,
                        orden,
                        created_at,
                        updated_at
                      FROM cuentas
                      WHERE circulo_id = :circulo_id
                        AND activo = 1
                      ORDER BY orden ASC, nombre ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':circulo_id', $circuloId, PDO::PARAM_INT);
            $stmt->execute();

            $cuentas = $stmt->fetchAll();

            // Calcular saldo de cada cuenta
            foreach ($cuentas as &$cuenta) {
                $cuenta['saldo_actual'] = $this->getSaldoActual($cuenta['id']);
            }

            return $cuentas;
        } catch (PDOException $e) {
            error_log("Error en getCuentasPorCirculo: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtener cuenta por ID
     */
    public function getById($cuentaId)
    {
        try {
            $query = "SELECT 
                        id,
                        circulo_id,
                        nombre,
                        icono,
                        color,
                        descripcion,
                        activo,
                        orden,
                        created_at,
                        updated_at
                      FROM cuentas
                      WHERE id = :cuenta_id
                      LIMIT 1";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':cuenta_id', $cuentaId, PDO::PARAM_INT);
            $stmt->execute();

            $cuenta = $stmt->fetch();

            if ($cuenta) {
                $cuenta['saldo_actual'] = $this->getSaldoActual($cuenta['id']);
            }

            return $cuenta;
        } catch (PDOException $e) {
            error_log("Error en getById: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Calcular saldo actual de una cuenta en tiempo real
     * 
     * LÓGICA:
     * - INGRESOS (tipo_mov_id=1): SUMA al saldo
     * - GASTOS (tipo_mov_id=2): RESTA del saldo
     * - TRASLADOS (tipo_mov_id=3):
     *   - Si es cuenta_origen: RESTA del saldo
     *   - Si es cuenta_destino: SUMA al saldo
     */
    public function getSaldoActual($cuentaId)
    {
        try {
            $query = "SELECT 
                        COALESCE(
                          SUM(
                            CASE
                              -- INGRESOS: suma positiva
                              WHEN m.tipo_mov_id = 1 AND m.cuenta_id = :cuenta_id THEN m.valor
                              -- GASTOS: resta
                              WHEN m.tipo_mov_id = 2 AND m.cuenta_id = :cuenta_id THEN -m.valor
                              -- TRASLADOS: cuenta destino suma
                              WHEN m.tipo_mov_id = 3 AND m.cuenta_destino_id = :cuenta_id THEN m.valor
                              -- TRASLADOS: cuenta origen resta
                              WHEN m.tipo_mov_id = 3 AND m.cuenta_origen_id = :cuenta_id THEN -m.valor
                              ELSE 0
                            END
                          ), 0
                        ) as saldo
                      FROM movimientos m
                      WHERE (
                        m.cuenta_id = :cuenta_id
                        OR m.cuenta_origen_id = :cuenta_id
                        OR m.cuenta_destino_id = :cuenta_id
                      )";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':cuenta_id', $cuentaId, PDO::PARAM_INT);
            $stmt->execute();

            $result = $stmt->fetch();
            return floatval($result['saldo']);
        } catch (PDOException $e) {
            error_log("Error en getSaldoActual: " . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Crear cuenta nueva
     */
    public function create($circuloId, $nombre, $icono = '💳', $color = '#4CAF50', $descripcion = null, $orden = 0)
    {
        try {
            $query = "INSERT INTO cuentas 
                      (circulo_id, nombre, icono, color, descripcion, orden, activo)
                      VALUES 
                      (:circulo_id, :nombre, :icono, :color, :descripcion, :orden, 1)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':circulo_id', $circuloId, PDO::PARAM_INT);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':icono', $icono);
            $stmt->bindParam(':color', $color);
            $stmt->bindParam(':descripcion', $descripcion);
            $stmt->bindParam(':orden', $orden, PDO::PARAM_INT);
            $stmt->execute();

            $cuentaId = $this->conn->lastInsertId();

            return $this->getById($cuentaId);
        } catch (PDOException $e) {
            error_log("Error en create: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Actualizar cuenta
     */
    public function update($cuentaId, $nombre = null, $icono = null, $color = null, $descripcion = null, $orden = null)
    {
        try {
            $campos = [];
            $params = [':cuenta_id' => $cuentaId];

            if ($nombre !== null) {
                $campos[] = "nombre = :nombre";
                $params[':nombre'] = $nombre;
            }

            if ($icono !== null) {
                $campos[] = "icono = :icono";
                $params[':icono'] = $icono;
            }

            if ($color !== null) {
                $campos[] = "color = :color";
                $params[':color'] = $color;
            }

            if ($descripcion !== null) {
                $campos[] = "descripcion = :descripcion";
                $params[':descripcion'] = $descripcion;
            }

            if ($orden !== null) {
                $campos[] = "orden = :orden";
                $params[':orden'] = $orden;
            }

            if (empty($campos)) {
                return $this->getById($cuentaId);
            }

            $query = "UPDATE cuentas 
                      SET " . implode(", ", $campos) . "
                      WHERE id = :cuenta_id";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            return $this->getById($cuentaId);
        } catch (PDOException $e) {
            error_log("Error en update: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Eliminar cuenta (soft delete)
     */
    public function delete($cuentaId)
    {
        try {
            $query = "UPDATE cuentas 
                      SET activo = 0
                      WHERE id = :cuenta_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':cuenta_id', $cuentaId, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en delete: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Calcular saldo anterior de una cuenta hasta antes de un período
     * 
     * Misma lógica que getSaldoActual() pero filtrado por fecha < primer día del período.
     * 
     * @param int $cuentaId ID de la cuenta
     * @param string $fechaLimite Fecha límite (primer día del período, formato Y-m-d)
     * @return float Saldo acumulado hasta antes de la fecha
     */
    public function getSaldoAnteriorCuenta($cuentaId, $fechaLimite)
    {
        try {
            $query = "SELECT 
                        COALESCE(
                          SUM(
                            CASE
                              WHEN co.tipo_mov_id = 1 AND m.cuenta_id = :cid1 THEN m.valor
                              WHEN co.tipo_mov_id = 2 AND m.cuenta_id = :cid2 THEN -m.valor
                              WHEN co.tipo_mov_id = 3 AND m.cuenta_destino_id = :cid3 THEN m.valor
                              WHEN co.tipo_mov_id = 3 AND m.cuenta_origen_id = :cid4 THEN -m.valor
                              ELSE 0
                            END
                          ), 0
                        ) as saldo
                      FROM movimientos m
                      INNER JOIN conceptos co ON m.concepto_id = co.id
                      WHERE (
                        m.cuenta_id = :cid5
                        OR m.cuenta_origen_id = :cid6
                        OR m.cuenta_destino_id = :cid7
                      )
                      AND m.fecha < :fecha_limite";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':cid1', $cuentaId, PDO::PARAM_INT);
            $stmt->bindParam(':cid2', $cuentaId, PDO::PARAM_INT);
            $stmt->bindParam(':cid3', $cuentaId, PDO::PARAM_INT);
            $stmt->bindParam(':cid4', $cuentaId, PDO::PARAM_INT);
            $stmt->bindParam(':cid5', $cuentaId, PDO::PARAM_INT);
            $stmt->bindParam(':cid6', $cuentaId, PDO::PARAM_INT);
            $stmt->bindParam(':cid7', $cuentaId, PDO::PARAM_INT);
            $stmt->bindParam(':fecha_limite', $fechaLimite, PDO::PARAM_STR);
            $stmt->execute();

            $result = $stmt->fetch();
            
            error_log("getSaldoAnteriorCuenta: cuenta_id=$cuentaId, fecha_limite=$fechaLimite, saldo=" . $result['saldo']);
            
            return floatval($result['saldo']);
        } catch (PDOException $e) {
            error_log("Error en getSaldoAnteriorCuenta: " . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Obtener saldos anteriores de TODAS las cuentas de un círculo
     * 
     * Calcula el saldo acumulado de cada cuenta hasta antes del período seleccionado.
     * 
     * @param int $circuloId ID del círculo
     * @param int $anio Año del período actual
     * @param int $mes Mes del período actual
     * @return array Lista de cuentas con su saldo anterior
     */
    public function getSaldosAnterioresPorCirculo($circuloId, $anio, $mes)
    {
        try {
            $fechaLimite = sprintf('%04d-%02d-01', $anio, $mes);

            // Obtener cuentas activas del círculo
            $queryCuentas = "SELECT id, nombre, icono, color
                             FROM cuentas
                             WHERE circulo_id = :circulo_id
                               AND activo = 1
                             ORDER BY orden ASC, nombre ASC";

            $stmtCuentas = $this->conn->prepare($queryCuentas);
            $stmtCuentas->bindParam(':circulo_id', $circuloId, PDO::PARAM_INT);
            $stmtCuentas->execute();

            $cuentas = $stmtCuentas->fetchAll();
            $resultado = [];

            foreach ($cuentas as $cuenta) {
                $saldoAnterior = $this->getSaldoAnteriorCuenta($cuenta['id'], $fechaLimite);
                $resultado[] = [
                    'cuenta_id' => intval($cuenta['id']),
                    'cuenta_nombre' => $cuenta['nombre'],
                    'cuenta_icono' => $cuenta['icono'],
                    'cuenta_color' => $cuenta['color'],
                    'saldo_anterior' => $saldoAnterior
                ];
            }

            return $resultado;
        } catch (PDOException $e) {
            error_log("Error en getSaldosAnterioresPorCirculo: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtener resumen de saldos por círculo
     */
    public function getResumenSaldosPorCirculo($circuloId)
    {
        try {
            $cuentas = $this->getCuentasPorCirculo($circuloId);

            $totalSaldo = 0;
            foreach ($cuentas as $cuenta) {
                $totalSaldo += $cuenta['saldo_actual'];
            }

            return [
                'cuentas' => $cuentas,
                'total_saldo' => $totalSaldo,
                'total_cuentas' => count($cuentas)
            ];
        } catch (PDOException $e) {
            error_log("Error en getResumenSaldosPorCirculo: " . $e->getMessage());
            return [
                'cuentas' => [],
                'total_saldo' => 0,
                'total_cuentas' => 0
            ];
        }
    }
}