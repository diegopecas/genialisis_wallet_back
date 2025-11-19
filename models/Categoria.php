<?php
require_once 'Database.php';

class Categoria
{
    private $conn;
    private $table = 'categorias';

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    /**
     * Crear nueva categoría
     */
    public function create($uuid, $nombre, $icono = '📁', $descripcion = null)
    {
        try {
            // Verificar si el UUID ya existe
            $checkQuery = "SELECT id FROM {$this->table} WHERE uuid = :uuid";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':uuid', $uuid);
            $checkStmt->execute();
            
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return $existing['id'];
            }

            $query = "INSERT INTO {$this->table} 
                      (uuid, nombre, icono, descripcion, activo) 
                      VALUES 
                      (:uuid, :nombre, :icono, :descripcion, 1)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':uuid', $uuid);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':icono', $icono);
            $stmt->bindParam(':descripcion', $descripcion);
            
            if ($stmt->execute()) {
                return $this->conn->lastInsertId();
            }
            
            return null;
        } catch (PDOException $e) {
            error_log("Error en create (Categoria): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Actualizar categoría por UUID
     */
    public function update($uuid, $nombre, $icono = null, $descripcion = null)
    {
        try {
            $query = "UPDATE {$this->table} 
                      SET nombre = :nombre";
            
            if ($icono !== null) {
                $query .= ", icono = :icono";
            }
            
            $query .= ", descripcion = :descripcion 
                       WHERE uuid = :uuid";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':uuid', $uuid);
            $stmt->bindParam(':nombre', $nombre);
            
            if ($icono !== null) {
                $stmt->bindParam(':icono', $icono);
            }
            
            $stmt->bindParam(':descripcion', $descripcion);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error en update (Categoria): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Eliminar (desactivar) categoría por UUID
     */
    public function delete($uuid)
    {
        try {
            $query = "UPDATE {$this->table} 
                      SET activo = 0 
                      WHERE uuid = :uuid";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':uuid', $uuid);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error en delete (Categoria): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener ID por UUID (método auxiliar)
     */
    public function getIdByUuid($uuid)
    {
        try {
            $query = "SELECT id FROM {$this->table} WHERE uuid = :uuid";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':uuid', $uuid);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['id'] : null;
        } catch (PDOException $e) {
            error_log("Error en getIdByUuid (Categoria): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtener categoría por ID
     */
    public function getById($id)
    {
        try {
            $query = "SELECT * FROM {$this->table} WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error en getById (Categoria): " . $e->getMessage());
            return null;
        }
    }
}