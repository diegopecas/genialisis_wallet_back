<?php
/**
 * Clase Database
 * Manejo de conexión PDO con MySQL
 */

require_once __DIR__ . '/../config/database.php';

class Database {
    private static $instance = null;
    private $connection;
    
    /**
     * Constructor privado (Singleton)
     */
    private function __construct() {
        try {
            $this->connection = new PDO(
                getDSN(),
                DB_USERNAME,
                DB_PASSWORD,
                getPDOOptions()
            );
        } catch (PDOException $e) {
            error_log("Error de conexión a BD: " . $e->getMessage());
            throw new Exception("Error al conectar con la base de datos");
        }
    }
    
    /**
     * Obtener instancia única (Singleton)
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Obtener conexión PDO
     */
    public function getConnection() {
        return $this->connection;
    }
    
    /**
     * Prevenir clonación
     */
    private function __clone() {}
    
    /**
     * Prevenir deserialización
     */
    public function __wakeup() {
        throw new Exception("No se puede deserializar un singleton");
    }
}
