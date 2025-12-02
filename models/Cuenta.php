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