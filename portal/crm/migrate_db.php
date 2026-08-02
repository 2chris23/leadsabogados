<?php
define('CRM_ROOT', __DIR__);
require CRM_ROOT . '/includes/config.php';
require CRM_ROOT . '/includes/Database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    // Add honorarios column if not exists
    $columns = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM solicitudes");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['Field'];
    }

    if (!in_array('honorarios', $columns)) {
        $pdo->exec("ALTER TABLE solicitudes ADD COLUMN honorarios VARCHAR(255) DEFAULT NULL AFTER estado");
        echo "Added honorarios column. <br>";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM solicitudes WHERE Field = 'estado'");
    $estadoCol = $stmt->fetch(PDO::FETCH_ASSOC);
    if (strpos($estadoCol['Type'], 'enum') !== false) {
        $pdo->exec("ALTER TABLE solicitudes MODIFY estado ENUM('pendiente', 'asignada', 'aceptada', 'denegada', 'archivada', 'cancelada', 'no aceptada', 'rechazada por todos') DEFAULT 'pendiente'");
        echo "Updated estado ENUM. <br>";
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS solicitud_asignaciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            solicitud_id INT NOT NULL,
            abogado_id INT NOT NULL,
            estado VARCHAR(50) DEFAULT 'pendiente',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (solicitud_id) REFERENCES solicitudes(id) ON DELETE CASCADE,
            FOREIGN KEY (abogado_id) REFERENCES usuarios_internos(id) ON DELETE CASCADE,
            UNIQUE KEY unique_asignacion (solicitud_id, abogado_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table solicitud_asignaciones created. <br>";
    echo "Migration completed successfully.";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
