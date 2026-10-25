<?php
/**
 * Seed initial admin user
 * Run this after creating the database schema
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$db = Database::getInstance();

// Create default admin user (username: admin, password: admin123)
// IMPORTANT: Change this password after first login!
$passwordHash = password_hash('admin123', PASSWORD_DEFAULT);

$sql = "INSERT INTO users (username, email, password_hash, role, active) 
        VALUES (:username, :email, :password, :role, 1)
        ON DUPLICATE KEY UPDATE password_hash = :password2";

$db->query($sql, [
    ':username' => 'admin',
    ':email' => 'admin@example.com',
    ':password' => $passwordHash,
    ':password2' => $passwordHash,
    ':role' => 'admin'
]);

echo "Default admin user created:\n";
echo "Username: admin\n";
echo "Password: admin123\n";
echo "IMPORTANT: Please change this password after first login!\n";

