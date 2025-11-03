<?php
/**
 * Modelo Usuario
 * Gestión de usuarios del sistema
 */

require_once __DIR__ . '/Database.php';

class Usuario {
    private $db;
    private $conn;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->conn = $this->db->getConnection();
    }
    
    /**
     * Autenticar usuario por email y password
     */
    public function authenticate($email, $password) {
        try {
            // DEBUG: Verificar conexión y BD
            $dbName = $this->conn->query("SELECT DATABASE()")->fetchColumn();
            file_put_contents(__DIR__ . '/../debug.log', 
                date('Y-m-d H:i:s') . " - CONEXIÓN INFO\n" .
                "Base de datos activa: $dbName\n" .
                "---\n",
                FILE_APPEND
            );
            
            $query = "SELECT 
                        u.id,
                        u.nombre,
                        u.email,
                        u.password_hash,
                        u.created_at
                      FROM usuarios u
                      WHERE u.email = :email
                      LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->execute();
            
            // DEBUG: Ver cuántas filas retornó
            $rowCount = $stmt->rowCount();
            
            $user = $stmt->fetch();
            
            file_put_contents(__DIR__ . '/../debug.log', 
                date('Y-m-d H:i:s') . " - authenticate() called\n" .
                "Email buscado: $email\n" .
                "Rows retornadas: $rowCount\n" .
                "User found: " . ($user ? 'YES' : 'NO') . "\n" .
                ($user ? "User ID: {$user['id']}, Email en BD: {$user['email']}\n" : "") .
                "---\n",
                FILE_APPEND
            );
            
            if (!$user) {
                return null;
            }
            
            // Verificar password
            $password_matches = password_verify($password, $user['password_hash']);
            
            file_put_contents(__DIR__ . '/../debug.log', 
                "Password verify: " . ($password_matches ? 'TRUE' : 'FALSE') . "\n" .
                "=============================\n",
                FILE_APPEND
            );
            
            if (!$password_matches) {
                return null;
            }
            
            // Remover hash de la respuesta
            unset($user['password_hash']);
            
            return $user;
            
        } catch (PDOException $e) {
            file_put_contents(__DIR__ . '/../debug.log', 
                "ERROR PDO: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return null;
        }
    }
    
    /**
     * Obtener usuario por ID
     */
    public function getById($userId) {
        try {
            $query = "SELECT 
                        id,
                        nombre,
                        email,
                        created_at,
                        updated_at
                      FROM usuarios
                      WHERE id = :user_id
                      LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetch();
            
        } catch (PDOException $e) {
            error_log("Error en getById: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtener círculos del usuario
     */
    public function getCirculos($userId) {
        try {
            $query = "SELECT 
                        c.id,
                        c.nombre,
                        c.icono,
                        c.color,
                        c.descripcion,
                        uc.es_admin,
                        c.created_at
                      FROM circulos c
                      INNER JOIN usuarios_circulos uc ON c.id = uc.circulo_id
                      WHERE uc.user_id = :user_id
                      ORDER BY c.nombre ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Error en getCirculos: " . $e->getMessage());
            return [];
        }
    }
}