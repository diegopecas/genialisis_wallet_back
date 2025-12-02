<?php
/**
 * Configuración JWT
 * Circle Finance - Backend PHP
 */

// Clave secreta para firmar tokens (CAMBIAR EN PRODUCCIÓN)
define('JWT_SECRET_KEY', 'circle_finance_secret_key_2025_lumen');

// Algoritmo de encriptación
define('JWT_ALGORITHM', 'HS256');

// Tiempo de expiración del token (30 días en segundos)
define('JWT_EXPIRATION_TIME', 30 * 24 * 60 * 60);

// Emisor del token
define('JWT_ISSUER', 'circle-finance-api');

/**
 * Generar un JWT
 * 
 * @param array $payload Datos a incluir en el token
 * @return string Token JWT generado
 */
function generateJWT($payload) {
    $header = [
        'alg' => JWT_ALGORITHM,
        'typ' => 'JWT'
    ];
    
    $issuedAt = time();
    $expiration = $issuedAt + JWT_EXPIRATION_TIME;
    
    $payload['iat'] = $issuedAt;
    $payload['exp'] = $expiration;
    $payload['iss'] = JWT_ISSUER;
    
    $base64UrlHeader = base64UrlEncode(json_encode($header));
    $base64UrlPayload = base64UrlEncode(json_encode($payload));
    
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET_KEY, true);
    $base64UrlSignature = base64UrlEncode($signature);
    
    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

/**
 * Validar y decodificar un JWT
 * 
 * @param string $token Token JWT a validar
 * @return array|null Payload decodificado o null si es inválido
 */
function validateJWT($token) {

    
    if (!$token) {
        error_log("validateJWT - ERROR: Token vacío");
        return null;
    }
    
    $tokenParts = explode('.', $token);
    
    if (count($tokenParts) !== 3) {
        error_log("validateJWT - ERROR: Token no tiene 3 partes (tiene " . count($tokenParts) . ")");
        return null;
    }
    
    list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $tokenParts;
    
    // Verificar firma
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET_KEY, true);
    $base64UrlSignatureCheck = base64UrlEncode($signature);
    
    if ($base64UrlSignature !== $base64UrlSignatureCheck) {
        error_log("validateJWT - ERROR: Firma inválida");
        return null;
    }
    
    // Decodificar payload
    $payload = json_decode(base64UrlDecode($base64UrlPayload), true);
    
    // Verificar expiración
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        error_log("validateJWT - ERROR: Token expirado");
        return null;
    }
    

    
    return $payload;
}

/**
 * Codificar en Base64 URL-safe
 */
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Decodificar Base64 URL-safe
 */
function base64UrlDecode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Obtener token del header Authorization
 */
function getBearerToken() {
    $headers = getAuthorizationHeader();
    
    if (!empty($headers)) {
        if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
            return $matches[1];
        }
    }
    
    return null;
}

/**
 * Obtener header de autorización
 * MEJORADO: Funciona correctamente con PUT, PATCH, DELETE
 */
function getAuthorizationHeader() {
    $headers = null;
    
    // Método 1: $_SERVER['Authorization'] (menos común)
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    }
    // Método 2: $_SERVER['HTTP_AUTHORIZATION'] (más común en Apache con mod_rewrite)
    else if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    }
    // Método 3: Usar getallheaders() si está disponible (mejor para todos los métodos HTTP)
    else if (function_exists('getallheaders')) {
        $allHeaders = getallheaders();
        // Buscar header Authorization (case-insensitive)
        foreach ($allHeaders as $name => $value) {
            if (strtolower($name) === 'authorization') {
                $headers = trim($value);
                break;
            }
        }
    }
    // Método 4: apache_request_headers() como fallback
    else if (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }
    


    
    return $headers;
}