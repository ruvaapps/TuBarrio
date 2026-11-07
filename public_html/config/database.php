<?php
// config/database.php

class Database {
    private static $host;
    private static $db_name;
    private static $username;
    private static $password;
    private static $port;
    private static $configLoaded = false;
    private static $conn = null;

    private static function loadConfig() {
        if (self::$configLoaded) {
            return;
        }

        self::$host = getenv('DB_HOST') ?: 'localhost';
        self::$port = getenv('DB_PORT') ?: '3306';
        self::$db_name = getenv('DB_NAME') ?: '';
        self::$username = getenv('DB_USER') ?: '';
        self::$password = getenv('DB_PASS') ?: '';
        self::$configLoaded = true;
    }

    public static function getConnection() {
        self::loadConfig();

        if (self::$conn === null) {
            if (self::$db_name === '' || self::$username === '' || self::$password === '') {
                error_log('Database config missing: set DB_NAME, DB_USER and DB_PASS environment variables.');
                http_response_code(500);
                echo json_encode(["error" => "Error interno del servidor."]);
                exit;
            }

            try {
                self::$conn = new PDO(
                    "mysql:host=" . self::$host . ";port=" . self::$port . ";dbname=" . self::$db_name . ";charset=utf8mb4",
                    self::$username,
                    self::$password,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            } catch(PDOException $e) {
                error_log('Database connection error: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(["error" => "Error interno del servidor."]);
                exit;
            }
        }
        return self::$conn;
    }
}