<?php

/**
 * AuthController
 * Manejo de autenticación y login
 */

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../utils/Response.php';

class AuthController
{
    private $usuarioModel;

    public function __construct()
    {
        $this->usuarioModel = new Usuario();
    }

    /**
     * Login de usuario
     * POST /auth/login
     * Body: { "email": "...", "password": "..." }
     */
    public function login()
    {
        $debug = [];

        // Obtener datos del POST
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        $debug[] = "Step 1: Data received";

        if (!isset($data['email']) || !isset($data['password'])) {
            Response::error('Faltan campos - DEBUG: ' . implode(' | ', $debug), 400);
        }

        $email = trim($data['email']);
        $password = $data['password'];

        $debug[] = "Step 2: Email=$email, PwdLen=" . strlen($password);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Email inválido - DEBUG: ' . implode(' | ', $debug), 400);
        }

        $debug[] = "Step 3: Calling authenticate";

        // Autenticar usuario
        $user = $this->usuarioModel->authenticate($email, $password);

        if (!$user) {
            $debug[] = "Step 4: FAILED - authenticate returned NULL";
            Response::unauthorized('Credenciales inválidas - DEBUG: ' . implode(' | ', $debug));
        }

        $debug[] = "Step 4: SUCCESS - User ID=" . $user['id'];

        // Obtener círculos del usuario
        $circulos = $this->usuarioModel->getCirculos($user['id']);

        $debug[] = "Step 5: Circles=" . count($circulos);

        // Generar JWT
        $payload = [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'nombre' => $user['nombre']
        ];

        $token = generateJWT($payload);

        $debug[] = "Step 6: Token generated";

        // Respuesta exitosa
        Response::success([
            'user' => $user,
            'circulos' => $circulos,
            'token' => $token,
            'debug_info' => implode(' | ', $debug)
        ], 'Login exitoso');
    }
    /**
     * Obtener usuario actual (validar token)
     * GET /auth/me
     * Header: Authorization: Bearer {token}
     */
    public function me()
    {
        $token = getBearerToken();

        if (!$token) {
            Response::unauthorized('Token no proporcionado');
        }

        $payload = validateJWT($token);

        if (!$payload) {
            Response::unauthorized('Token inválido o expirado');
        }

        // Obtener datos actualizados del usuario
        $user = $this->usuarioModel->getById($payload['user_id']);

        if (!$user) {
            Response::unauthorized('Usuario no encontrado');
        }

        // Obtener círculos
        $circulos = $this->usuarioModel->getCirculos($user['id']);

        Response::success([
            'user' => $user,
            'circulos' => $circulos
        ], 'Usuario autenticado');
    }
}
