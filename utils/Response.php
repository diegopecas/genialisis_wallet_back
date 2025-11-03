<?php
/**
 * Clase Response
 * Manejo estandarizado de respuestas JSON
 */

class Response {
    
    /**
     * Enviar respuesta exitosa
     * 
     * @param mixed $data Datos a retornar
     * @param string $message Mensaje opcional
     * @param int $statusCode Código HTTP
     */
    public static function success($data = null, $message = 'Success', $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        
        $response = [
            'success' => true,
            'message' => $message
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Enviar respuesta de error
     * 
     * @param string $message Mensaje de error
     * @param int $statusCode Código HTTP
     * @param array $errors Errores detallados opcionales
     */
    public static function error($message = 'Error', $statusCode = 400, $errors = null) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        
        $response = [
            'success' => false,
            'message' => $message
        ];
        
        if ($errors !== null) {
            $response['errors'] = $errors;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Enviar respuesta no autorizada (401)
     */
    public static function unauthorized($message = 'No autorizado') {
        self::error($message, 401);
    }
    
    /**
     * Enviar respuesta no encontrado (404)
     */
    public static function notFound($message = 'Recurso no encontrado') {
        self::error($message, 404);
    }
    
    /**
     * Enviar respuesta de validación (422)
     */
    public static function validationError($message = 'Error de validación', $errors = []) {
        self::error($message, 422, $errors);
    }
    
    /**
     * Enviar respuesta de servidor (500)
     */
    public static function serverError($message = 'Error interno del servidor') {
        self::error($message, 500);
    }
}
