<?php
require_once '../config/cors.php';
require_once '../models/Database.php';
require_once '../models/Usuario.php';
require_once '../models/Circulo.php';
require_once '../models/Categoria.php';
require_once '../models/Concepto.php';
require_once '../models/Movimiento.php';

class SyncController
{
    private $conn;
    private $usuario;
    private $circulo;
    private $categoria;
    private $concepto;
    private $movimiento;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->usuario = new Usuario();
        $this->circulo = new Circulo();
        $this->categoria = new Categoria();
        $this->concepto = new Concepto();
        $this->movimiento = new Movimiento();
    }

    /**
     * Endpoint principal de sincronización batch
     * POST /sync/batch
     */
    public function batch()
    {
        header('Content-Type: application/json');
        
        // Validar autenticación
        $userId = $this->validateAuth();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        // Obtener datos del body
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos inválidos']);
            return;
        }

        $response = [
            'success' => true,
            'mappings' => [
                'circulos' => [],
                'categorias' => [],
                'conceptos' => [],
                'movimientos' => []
            ],
            'errors' => []
        ];

        try {
            $this->conn->beginTransaction();

            // Procesar círculos
            if (!empty($data['circulos'])) {
                $response['mappings']['circulos'] = $this->syncCirculos($data['circulos'], $userId);
            }

            // Procesar categorías
            if (!empty($data['categorias'])) {
                $response['mappings']['categorias'] = $this->syncCategorias($data['categorias']);
            }

            // Procesar conceptos
            if (!empty($data['conceptos'])) {
                $response['mappings']['conceptos'] = $this->syncConceptos($data['conceptos']);
            }

            // Procesar movimientos
            if (!empty($data['movimientos'])) {
                $response['mappings']['movimientos'] = $this->syncMovimientos($data['movimientos'], $userId);
            }

            $this->conn->commit();
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode([
                'error' => 'Error en sincronización',
                'message' => $e->getMessage()
            ]);
            return;
        }

        echo json_encode($response);
    }

    /**
     * Sincronizar círculos
     */
    private function syncCirculos($circulos, $userId)
    {
        $mappings = [];
        
        foreach ($circulos as $circulo) {
            try {
                $id = $this->circulo->create(
                    $circulo['uuid'],
                    $userId,
                    $circulo['nombre'],
                    $circulo['descripcion'] ?? null
                );
                
                if ($id) {
                    $mappings[$circulo['uuid']] = $id;
                }
            } catch (Exception $e) {
                error_log("Error sincronizando círculo: " . $e->getMessage());
            }
        }
        
        return $mappings;
    }

    /**
     * Sincronizar categorías
     */
    private function syncCategorias($categorias)
    {
        $mappings = [];
        
        foreach ($categorias as $categoria) {
            try {
                $id = $this->categoria->create(
                    $categoria['uuid'],
                    $categoria['nombre'],
                    $categoria['icono'] ?? '📁',
                    $categoria['descripcion'] ?? null
                );
                
                if ($id) {
                    $mappings[$categoria['uuid']] = $id;
                }
            } catch (Exception $e) {
                error_log("Error sincronizando categoría: " . $e->getMessage());
            }
        }
        
        return $mappings;
    }

    /**
     * Sincronizar conceptos
     */
    private function syncConceptos($conceptos)
    {
        $mappings = [];
        
        foreach ($conceptos as $concepto) {
            try {
                $id = $this->concepto->create(
                    $concepto['uuid'],
                    $concepto['categoria_uuid'],
                    $concepto['tipo_mov_id'],
                    $concepto['nombre'],
                    $concepto['icono'] ?? '➕',
                    $concepto['es_real'] ?? true,
                    $concepto['requiere_detalle'] ?? false,
                    $concepto['descripcion'] ?? null
                );
                
                if ($id) {
                    $mappings[$concepto['uuid']] = $id;
                }
            } catch (Exception $e) {
                error_log("Error sincronizando concepto: " . $e->getMessage());
            }
        }
        
        return $mappings;
    }

    /**
     * Sincronizar movimientos
     */
    private function syncMovimientos($movimientos, $userId)
    {
        $mappings = [];
        
        foreach ($movimientos as $movimiento) {
            try {
                $id = $this->movimiento->create(
                    $movimiento['uuid'],
                    $userId,
                    $movimiento['concepto_uuid'],
                    $movimiento['valor'],
                    $movimiento['fecha'],
                    $movimiento['circulos_uuids'] ?? [],
                    $movimiento['detalle'] ?? null,
                    $movimiento['notas'] ?? null
                );
                
                if ($id) {
                    $mappings[$movimiento['uuid']] = $id;
                }
            } catch (Exception $e) {
                error_log("Error sincronizando movimiento: " . $e->getMessage());
            }
        }
        
        return $mappings;
    }

    /**
     * Validar autenticación y obtener user_id
     */
    private function validateAuth()
    {
        $headers = getallheaders();
        $token = null;
        
        if (isset($headers['Authorization'])) {
            $matches = [];
            if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
                $token = $matches[1];
            }
        }
        
        if (!$token) {
            return false;
        }
        
        // Validar token y obtener user_id
        $userData = $this->usuario->validateToken($token);
        return $userData ? $userData['id'] : false;
    }
}

// Manejo de la petición
$controller = new SyncController();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['REQUEST_URI'] === '/sync/batch') {
    $controller->batch();
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint no encontrado']);
}