<?php
/**
 * Script de debug para verificar password
 * Coloca este archivo en la raíz del backend y accede: http://localhost:9999/debug_password.php
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/Database.php';

header('Content-Type: application/json');

$email = 'diego@lumen.com';
$password_to_test = '123456';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Obtener usuario
    $query = "SELECT id, nombre, email, password_hash FROM usuarios WHERE email = :email";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode([
            'error' => 'Usuario no encontrado',
            'email_buscado' => $email
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    // Probar password
    $password_matches = password_verify($password_to_test, $user['password_hash']);
    
    // Generar nuevo hash
    $new_hash = password_hash($password_to_test, PASSWORD_DEFAULT);
    
    echo json_encode([
        'usuario_encontrado' => true,
        'id' => $user['id'],
        'nombre' => $user['nombre'],
        'email' => $user['email'],
        'hash_actual_primeros_20_chars' => substr($user['password_hash'], 0, 20),
        'hash_actual_completo' => $user['password_hash'],
        'password_probado' => $password_to_test,
        'password_coincide' => $password_matches,
        'nuevo_hash_generado' => $new_hash,
        'sql_para_actualizar' => "UPDATE usuarios SET password_hash = '$new_hash' WHERE email = '$email';"
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
