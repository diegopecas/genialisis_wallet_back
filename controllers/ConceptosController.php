<?php
/**
 * ConceptosController
 * Manejo de conceptos y categorías
 */

require_once __DIR__ . '/../models/Concepto.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../utils/Response.php';

class ConceptosController {
    private $conceptoModel;
    
    public function __construct() {
        $this->conceptoModel = new Concepto();
    }
    
    /**
     * Obtener conceptos agrupados por categoría
     * GET /conceptos?circulo_id={id}&tipo_mov_id={1|2}
     * Header: Authorization: Bearer {token}
     */
    public function getConceptos() {
        // Validar autenticación
        $this->validateAuth();
        
        // Obtener parámetros
        $circuloId = isset($_GET['circulo_id']) ? intval($_GET['circulo_id']) : null;
        $tipoMovId = isset($_GET['tipo_mov_id']) ? intval($_GET['tipo_mov_id']) : null;
        
        // Validar parámetros requeridos
        if (!$circuloId) {
            Response::validationError('circulo_id es requerido');
        }
        
        if (!$tipoMovId || !in_array($tipoMovId, [1, 2])) {
            Response::validationError('tipo_mov_id debe ser 1 (Ingreso) o 2 (Gasto)');
        }
        
        // Obtener conceptos
        $conceptos = $this->conceptoModel->getConceptosPorCirculo($circuloId, $tipoMovId);
        
        Response::success([
            'categorias' => $conceptos
        ], 'Conceptos obtenidos correctamente');
    }
    
    /**
     * Obtener todos los conceptos de un círculo (sin agrupar)
     * GET /conceptos/all?circulo_id={id}
     * Header: Authorization: Bearer {token}
     */
    public function getAllConceptos() {
        // Validar autenticación
        $this->validateAuth();
        
        // Obtener parámetros
        $circuloId = isset($_GET['circulo_id']) ? intval($_GET['circulo_id']) : null;
        
        // Validar parámetros requeridos
        if (!$circuloId) {
            Response::validationError('circulo_id es requerido');
        }
        
        // Obtener conceptos
        $conceptos = $this->conceptoModel->getAllByCirculo($circuloId);
        
        Response::success([
            'conceptos' => $conceptos
        ], 'Conceptos obtenidos correctamente');
    }
    
    /**
     * Validar autenticación del usuario
     */
    private function validateAuth() {
        $token = getBearerToken();
        
        if (!$token) {
            Response::unauthorized('Token no proporcionado');
        }
        
        $payload = validateJWT($token);
        
        if (!$payload) {
            Response::unauthorized('Token inválido o expirado');
        }
        
        return $payload;
    }
}
