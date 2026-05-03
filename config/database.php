<?php
// config/database.php — PDO connection (singleton)

// ── Timezone: set BOTH PHP and MySQL to Bangladesh time ─────
date_default_timezone_set('Asia/Dhaka');

// ════════════════════════════════════════════════════════════
//  MANUAL OVERRIDE — uncomment and edit this line if the
//  auto-detected URL is wrong (broken CSS / links):
// ════════════════════════════════════════════════════════════
// define('BASE_URL', 'http://localhost/smart_exam');

// ── Auto-detect BASE_URL (used only if override above is commented out) ──
if (!defined('BASE_URL')) {
    $proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Normalize slashes for Windows (XAMPP)
    $docRoot  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $filePath = rtrim(str_replace('\\', '/', dirname(dirname(__FILE__))), '/');
    $subPath  = str_replace($docRoot, '', $filePath);
    $subPath  = '/' . trim($subPath, '/');
    if ($subPath === '/') $subPath = '';
    define('BASE_URL', $proto . '://' . $host . $subPath);
}

// ── Database credentials — edit these ───────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'smart_exam_db');
define('DB_USER',    'root');
define('DB_PASS',    '');           // leave empty if no MySQL password set
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME',    'SmartExam');
define('APP_VERSION', '3.0.0');
define('APP_ENV',     'development'); // change to 'production' when live

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => true,  // must be true for CALL stored procedures
                ]);
                // Sync MySQL session timezone with PHP (Bangladesh = UTC+6)
                self::$instance->exec("SET time_zone = '+06:00'");
            } catch (PDOException $e) {
                error_log('DB Connection failed: ' . $e->getMessage());
                die('<div style="font-family:sans-serif;padding:40px;background:#FEF2F2;color:#991B1B;border:2px solid #FCA5A5;border-radius:12px;max-width:500px;margin:60px auto;">
                    <h2>⚠️ Database Connection Failed</h2>
                    <p>Could not connect to <strong>' . DB_NAME . '</strong> on <strong>' . DB_HOST . '</strong>.</p>
                    <p>Please check your credentials in <code>config/database.php</code> and ensure MySQL is running.</p>
                    <hr style="border:none;border-top:1px solid #FCA5A5;margin:16px 0;">
                    <p style="font-size:13px;"><strong>Quick fix steps:</strong><br>
                    1. Open XAMPP Control Panel → Start <strong>MySQL</strong><br>
                    2. Go to <a href="http://localhost/phpmyadmin" style="color:#991B1B;">phpMyAdmin</a> → create database <code>smart_exam_db</code><br>
                    3. Import <code>sql/schema.sql</code> then <code>sql/cheating_engine.sql</code><br>
                    4. Edit <code>config/database.php</code> with your MySQL username &amp; password</p>
                </div>');
            }
        }
        return self::$instance;
    }
}
