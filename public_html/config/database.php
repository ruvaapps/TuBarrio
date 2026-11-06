<?php
// config/database.php

class Database {
    private static $host = 'localhost';
    private static $db_name = 'admlova_tubarrio'; // Cambiar por el nombre de tu BD
    private static $username = 'admlova_tubarrio';            // Cambiar por tu usuario
    private static $password = 'wHKgfLAEBuECj8Ftqe5z';                // Cambiar por tu password
    private static $conn = null;

    public static function getConnection() {
        if (self::$conn === null) {
            try {
                self::$conn = new PDO(
                    "mysql:host=" . self::$host . ";dbname=" . self::$db_name . ";charset=utf8mb4",
                    self::$username,
                    self::$password,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            } catch(PDOException $e) {
                http_response_code(500);
                echo json_encode(["error" => "Error de conexión a la base de datos."]);
                exit;
            }
        }
        return self::$conn;
    }
}