<?php
// config/database.php — PDO connection (singleton)

define('DB_HOST', 'localhost');
define('DB_NAME', 'smart_exam_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME',    'SmartExam');
define('APP_VERSION', '1.0.0');
define('BASE_URL',    'http://localhost/smart_exam');

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s',
                           DB_HOST, DB_NAME, DB_CHARSET);
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                // In production, log this — never expose to browser
                error_log('DB Connection failed: ' . $e->getMessage());
                die(json_encode(['error' => 'Database connection failed.']));
            }
        }
        return self::$instance;
    }
}
