<?php
/**
 * CRM Abogados - Clase de Conexión a Base de Datos
 * Singleton PDO con prepared statements
 */

if (!defined('CRM_ROOT')) {
    die('Acceso prohibido');
}

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                DB_HOST, DB_PORT, DB_NAME
            );
            
            $opciones = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $opciones);
        } catch (PDOException $e) {
            if (DEBUG) {
                die('Error de conexión: ' . $e->getMessage());
            }
            die('Error al conectar con la base de datos. Contacte al administrador.');
        }
    }
    
    /**
     * Obtener instancia única de la conexión
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Obtener el objeto PDO
     */
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * Ejecutar consulta preparada con parámetros
     * @param string $sql Consulta SQL con placeholders
     * @param array $params Parámetros para la consulta
     * @return PDOStatement
     */
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            $error = $this->pdo->errorInfo();
            throw new PDOException("Prepare failed: " . ($error[2] ?? 'Unknown error') . " - SQL: " . $sql);
        }
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Obtener un solo registro
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * Obtener todos los registros
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Obtener un solo valor (primera columna del primer registro)
     */
    public function fetchColumn($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
    }
    
    /**
     * Insertar un registro y devolver el ID
     */
    public function insert($tabla, $datos) {
        $columnas = implode(', ', array_keys($datos));
        $placeholders = implode(', ', array_fill(0, count($datos), '?'));
        
        $sql = "INSERT INTO {$tabla} ({$columnas}) VALUES ({$placeholders})";
        $this->query($sql, array_values($datos));
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Actualizar registros
     */
    public function update($tabla, $datos, $where, $whereParams = []) {
        $sets = [];
        $valores = [];
        foreach ($datos as $col => $val) {
            $sets[] = "{$col} = ?";
            $valores[] = $val;
        }
        
        $sql = "UPDATE {$tabla} SET " . implode(', ', $sets) . " WHERE {$where}";
        $valores = array_merge($valores, $whereParams);
        
        return $this->query($sql, $valores)->rowCount();
    }
    
    /**
     * Eliminar registros
     */
    public function delete($tabla, $where, $params = []) {
        $sql = "DELETE FROM {$tabla} WHERE {$where}";
        return $this->query($sql, $params)->rowCount();
    }
    
    /**
     * Contar registros
     */
    public function count($tabla, $where = '1=1', $params = []) {
        $sql = "SELECT COUNT(*) FROM {$tabla} WHERE {$where}";
        return (int) $this->fetchColumn($sql, $params);
    }
    
    /**
     * Iniciar transacción
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Confirmar transacción
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * Revertir transacción
     */
    public function rollBack() {
        return $this->pdo->rollBack();
    }
    
    // Prevenir clonación y deserialización
    private function __clone() {}
    public function __wakeup() {
        throw new Exception("No se puede deserializar un singleton");
    }
}
