<?php
/**
 * Database Connection Class
 * Singleton pattern for PDO connection
 */

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?Database $instance = null;
    private ?PDO $connection = null;
    private array $config;

    private function __construct()
    {
        $this->config = require __DIR__ . '/../config/database.php';
        $this->connect();
    }

    private function connect(): void
    {
        try {
            $dsn = sprintf(
                "mysql:host=%s;dbname=%s;charset=%s",
                $this->config['host'],
                $this->config['database'],
                $this->config['charset']
            );

            $this->connection = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $this->config['options']
            );
        } catch (PDOException $e) {
            // Full details (host/db/user, PDO error) go to the server log only.
            error_log("Database connection failed: " . $e->getMessage());
            error_log("Connection details - Host: " . $this->config['host'] . ", Database: " . $this->config['database'] . ", User: " . $this->config['username']);

            // The thrown message is shown to whoever sees the page. Keep it
            // diagnostic but free of credentials so it's safe in production.
            throw new \RuntimeException(
                "Database connection failed. Please check:\n"
                . "1. MySQL/MariaDB is running\n"
                . "2. The configured database exists\n"
                . "3. The configured user has access\n"
                . "4. .env contains correct DB_HOST, DB_NAME, DB_USER, DB_PASS\n"
                . "Full details are in the server error log."
            );
        }
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database query error: " . $e->getMessage());

            // Check for duplicate entry error
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                if (strpos($e->getMessage(), 'email') !== false) {
                    throw new \RuntimeException("Diese E-Mail-Adresse wird bereits verwendet.");
                } elseif (strpos($e->getMessage(), 'username') !== false) {
                    throw new \RuntimeException("Dieser Benutzername existiert bereits.");
                }
            }

            // In development, show more details
            if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
                throw new \RuntimeException("Database query failed: " . $e->getMessage(), 0, $e);
            }
            throw new \RuntimeException("Database query failed.", 0, $e);
        }
    }

    public function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }

    // Prevent cloning
    private function __clone() {}

    // Prevent unserialization
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}

