<?php
declare(strict_types=1);

session_start();

const DB_HOST = 'localhost';
const DB_NAME = 'event_booking';
const DB_USER = 'admin';
const DB_PASS = '8FRf4T';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function require_login(): void {
    if (empty($_SESSION['logged_in'])) {
        header('Location: login.php');
        exit;
    }
}

function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
