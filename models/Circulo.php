<?php
require_once 'Database.php';

class Circulo
{
    private $conn;
    private $table = 'circulos';

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    /**
     * Crear nuevo círculo
     */
    public function create($uuid, $userId, $nombre, $descripcion = null)
    {
        try {
            // Verificar si el UUID ya existe
            $checkQuery = "SELECT id FROM {$this->table} WHERE uuid = :uuid";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':uuid', $uuid);
            $checkStmt->execute();
            
            if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
                return $checkStmt->fetch(PDO::FETCH_ASSOC)['id'];
            }

            $query = "INSERT INTO {$this->table} 
                      (uuid, user_id, nombre, descripcion, activo) 
                      VALUES 
                      (:uuid, :user_id, :nombre, :descripcion, 1)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':uuid', $uuid);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':descripcion', $descripcion);
            
            if ($stmt->execute()) {
                return $this->conn->lastInsertId();
            }
            
            return null;
        } catch (PDOException $e) {
            error_log("Error en create (Circulo): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Actualizar círculo por UUID
     */
    public function update($uuid, $nombre, $descripcion = null)
    {
        try {
            $query = "UPDATE {$this->table} 
                      SET nombre = :nombre, 
                          descripcion = :descripcion 
                      WHERE uuid = :uuid";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':uuid', $uuid);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':descripcion', $descripcion);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error en update (Circulo): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Eliminar (desactivar) círculo por UUID
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
            error_log("Error en delete (Circulo): " . $e->getMessage());
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
            error_log("Error en getIdByUuid (Circulo): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtener círculo por ID
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
            error_log("Error en getById (Circulo): " . $e->getMessage());
            return null;
        }
    }
}