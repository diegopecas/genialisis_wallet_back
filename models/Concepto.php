<?php
/**
 * Modelo Concepto
 * Gestión de categorías y conceptos por círculo
 */

require_once __DIR__ . '/Database.php';

class Concepto {
    private $db;
    private $conn;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->conn = $this->db->getConnection();
    }
    
    /**
     * Obtener conceptos agrupados por categoría para un círculo y tipo de movimiento
     * 
     * @param int $circuloId ID del círculo
     * @param int $tipoMovId ID del tipo de movimiento (1=Ingreso, 2=Gasto)
     * @return array Categorías con sus conceptos
     */
    public function getConceptosPorCirculo($circuloId, $tipoMovId) {
        try {
            $query = "SELECT 
                        cat.id as categoria_id,
                        cat.nombre as categoria_nombre,
                        cat.icono as categoria_icono,
                        cat.color as categoria_color,
                        cat.orden as categoria_orden,
                        c.id as concepto_id,
                        c.nombre as concepto_nombre,
                        c.icono as concepto_icono,
                        c.es_real as concepto_es_real,
                        c.requiere_detalle as concepto_requiere_detalle,
                        c.descripcion as concepto_descripcion
                      FROM categorias cat
                      INNER JOIN conceptos c ON cat.id = c.categoria_id
                      WHERE cat.circulo_id = :circulo_id
                        AND cat.activo = 1
                        AND c.activo = 1
                        AND c.tipo_mov_id = :tipo_mov_id
                      ORDER BY cat.orden ASC, cat.nombre ASC, c.nombre ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':circulo_id', $circuloId, PDO::PARAM_INT);
            $stmt->bindParam(':tipo_mov_id', $tipoMovId, PDO::PARAM_INT);
            $stmt->execute();
            
            $rows = $stmt->fetchAll();
            
            // Agrupar por categoría
            $categorias = [];
            foreach ($rows as $row) {
                $categoriaId = $row['categoria_id'];
                
                if (!isset($categorias[$categoriaId])) {
                    $categorias[$categoriaId] = [
                        'id' => $row['categoria_id'],
                        'nombre' => $row['categoria_nombre'],
                        'icono' => $row['categoria_icono'],
                        'color' => $row['categoria_color'],
                        'orden' => $row['categoria_orden'],
                        'conceptos' => []
                    ];
                }
                
                $categorias[$categoriaId]['conceptos'][] = [
                    'id' => $row['concepto_id'],
                    'nombre' => $row['concepto_nombre'],
                    'icono' => $row['concepto_icono'],
                    'es_real' => (bool)$row['concepto_es_real'],
                    'requiere_detalle' => (bool)$row['concepto_requiere_detalle'],
                    'descripcion' => $row['concepto_descripcion']
                ];
            }
            
            return array_values($categorias);
            
        } catch (PDOException $e) {
            error_log("Error en getConceptosPorCirculo: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtener concepto por ID
     * 
     * @param int $conceptoId ID del concepto
     * @return array|null Datos del concepto
     */
    public function getById($conceptoId) {
        try {
            $query = "SELECT 
                        c.id,
                        c.uuid,
                        c.categoria_id,
                        c.categoria_uuid,
                        c.tipo_mov_id,
                        c.nombre,
                        c.icono,
                        c.es_real,
                        c.requiere_detalle,
                        c.descripcion,
                        c.activo,
                        cat.circulo_id
                      FROM conceptos c
                      INNER JOIN categorias cat ON c.categoria_id = cat.id
                      WHERE c.id = :concepto_id
                      LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':concepto_id', $conceptoId, PDO::PARAM_INT);
            $stmt->execute();
            
            $concepto = $stmt->fetch();
            
            if ($concepto) {
                $concepto['es_real'] = (bool)$concepto['es_real'];
                $concepto['requiere_detalle'] = (bool)$concepto['requiere_detalle'];
                $concepto['activo'] = (bool)$concepto['activo'];
            }
            
            return $concepto;
            
        } catch (PDOException $e) {
            error_log("Error en getById: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtener todos los conceptos de un círculo (sin agrupar)
     * 
     * @param int $circuloId ID del círculo
     * @return array Lista de conceptos
     */
    public function getAllByCirculo($circuloId) {
        try {
            $query = "SELECT 
                        c.id,
                        c.uuid,
                        c.nombre,
                        c.icono,
                        c.tipo_mov_id,
                        tm.nombre as tipo_movimiento,
                        c.es_real,
                        c.requiere_detalle,
                        cat.nombre as categoria_nombre,
                        cat.icono as categoria_icono
                      FROM conceptos c
                      INNER JOIN categorias cat ON c.categoria_id = cat.id
                      INNER JOIN tipos_movimiento tm ON c.tipo_mov_id = tm.id
                      WHERE cat.circulo_id = :circulo_id
                        AND cat.activo = 1
                        AND c.activo = 1
                      ORDER BY tm.nombre ASC, cat.nombre ASC, c.nombre ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':circulo_id', $circuloId, PDO::PARAM_INT);
            $stmt->execute();
            
            $conceptos = $stmt->fetchAll();
            
            foreach ($conceptos as &$concepto) {
                $concepto['es_real'] = (bool)$concepto['es_real'];
                $concepto['requiere_detalle'] = (bool)$concepto['requiere_detalle'];
            }
            
            return $conceptos;
            
        } catch (PDOException $e) {
            error_log("Error en getAllByCirculo: " . $e->getMessage());
            return [];
        }
    }

    // =====================================
    // MÉTODOS PARA SINCRONIZACIÓN OFFLINE
    // =====================================

    /**
     * Crear concepto para sincronización offline (con UUID)
     * Nota: Este método es diferente al create tradicional porque maneja UUID
     */
    public function createWithUuid($uuid, $categoriaUuid, $tipoMovId, $nombre, $icono = '➕', $esReal = true, $requiereDetalle = false, $descripcion = null) {
        try {
            // Verificar si el UUID ya existe
            $existing = $this->getIdByUuid($uuid);
            if ($existing) {
                return $existing;
            }

            // Obtener categoria_id del UUID
            $categoriaId = $this->getCategoriaIdByUuid($categoriaUuid);
            if (!$categoriaId) {
                throw new Exception("Categoría no encontrada con UUID: $categoriaUuid");
            }

            $query = "INSERT INTO conceptos 
                      (uuid, categoria_id, categoria_uuid, tipo_mov_id, nombre, icono, es_real, requiere_detalle, descripcion, activo)
                      VALUES 
                      (:uuid, :categoria_id, :categoria_uuid, :tipo_mov_id, :nombre, :icono, :es_real, :requiere_detalle, :descripcion, 1)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':uuid', $uuid);
            $stmt->bindParam(':categoria_id', $categoriaId, PDO::PARAM_INT);
            $stmt->bindParam(':categoria_uuid', $categoriaUuid);
            $stmt->bindParam(':tipo_mov_id', $tipoMovId, PDO::PARAM_INT);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':icono', $icono);
            $stmt->bindValue(':es_real', $esReal ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':requiere_detalle', $requiereDetalle ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindParam(':descripcion', $descripcion);
            
            if ($stmt->execute()) {
                return $this->conn->lastInsertId();
            }
            
            return null;
        } catch (Exception $e) {
            error_log("Error en createWithUuid (Concepto): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Actualizar concepto por UUID
     */
    public function updateByUuid($uuid, $nombre, $icono = null, $esReal = null, $requiereDetalle = null, $descripcion = null) {
        try {
            $query = "UPDATE conceptos SET nombre = :nombre";
            
            if ($icono !== null) {
                $query .= ", icono = :icono";
            }
            if ($esReal !== null) {
                $query .= ", es_real = :es_real";
            }
            if ($requiereDetalle !== null) {
                $query .= ", requiere_detalle = :requiere_detalle";
            }
            
            $query .= ", descripcion = :descripcion WHERE uuid = :uuid";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':uuid', $uuid);
            $stmt->bindParam(':nombre', $nombre);
            
            if ($icono !== null) {
                $stmt->bindParam(':icono', $icono);
            }
            if ($esReal !== null) {
                $stmt->bindValue(':es_real', $esReal ? 1 : 0, PDO::PARAM_INT);
            }
            if ($requiereDetalle !== null) {
                $stmt->bindValue(':requiere_detalle', $requiereDetalle ? 1 : 0, PDO::PARAM_INT);
            }
            
            $stmt->bindParam(':descripcion', $descripcion);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error en updateByUuid (Concepto): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Eliminar (desactivar) concepto por UUID
     */
    public function deleteByUuid($uuid) {
        try {
            $query = "UPDATE conceptos SET activo = 0 WHERE uuid = :uuid";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':uuid', $uuid);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error en deleteByUuid (Concepto): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener ID por UUID (método auxiliar)
     */
    public function getIdByUuid($uuid) {
        try {
            $query = "SELECT id FROM conceptos WHERE uuid = :uuid";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':uuid', $uuid);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['id'] : null;
        } catch (PDOException $e) {
            error_log("Error en getIdByUuid (Concepto): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtener categoria_id por UUID (método auxiliar privado)
     */
    private function getCategoriaIdByUuid($categoriaUuid) {
        try {
            $query = "SELECT id FROM categorias WHERE uuid = :uuid";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':uuid', $categoriaUuid);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['id'] : null;
        } catch (PDOException $e) {
            error_log("Error en getCategoriaIdByUuid (Concepto): " . $e->getMessage());
            return null;
        }
    }
}