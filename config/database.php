<?php
/**
 * Configuración de Base de Datos
 * Circle Finance - Backend PHP
 */

define('DB_HOST', '92.205.2.161');
define('DB_NAME', 'genialisis-wallet-prod');
define('DB_USERNAME', 'admin-genialisis-wallet-prod');
define('DB_PASSWORD', 'Pd&dIeY]us1L');
define('DB_CHARSET', 'utf8mb4');
define('DB_TYPE', 'mysql');

/**
 * Obtener DSN para PDO
 */
function getDSN() {
    return DB_TYPE . ':host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
}

/**
 * Obtener opciones PDO
 */
function getPDOOptions() {
    return [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
    ];
}
