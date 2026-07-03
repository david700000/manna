<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1', 'root', '');
    $pdo->exec('CREATE DATABASE IF NOT EXISTS mannabridal');
    echo "SUCCESS: Database mannabridal created or already exists.\n";
} catch (PDOException $e) {
    echo "ERROR: Could not connect to MySQL. " . $e->getMessage() . "\n";
}
