<?php
// Configuración global del sistema
global $_ROOT_, $_DB_, $_PASS_, $_USER_, $_NOMBRE_, $_URL_;

$_ROOT_ = dirname(__DIR__);
$_DB_ = 'parroquia';
$_USER_ = 'root';
$_PASS_ = '';
$_NOMBRE_ = ''; // Se obtendrá desde la base de datos
$_URL_ = 'http://127.0.0.1/parroquia';

require_once __DIR__ . '/utils.php';


// Función helper para conexión PDO (usar en utils o donde sea necesario)
function getDBConnection() {
    global $_DB_, $_USER_, $_PASS_;
    try {
        $pdo = new PDO("mysql:host=localhost;dbname={$_DB_};charset=utf8mb4", $_USER_, $_PASS_);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die("Error de conexión: " . $e->getMessage());
    }
}

// Función para obtener el nombre de la organización desde la base de datos (optimizada)
function getNombreOrganizacion() {
    global $_NOMBRE_;
    
    // Si ya está cargado, retornarlo sin hacer consulta
    if (!empty($_NOMBRE_)) {
        return $_NOMBRE_;
    }
    
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT nombre FROM configuracion WHERE id = 1 LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && !empty($result['nombre'])) {
            $_NOMBRE_ = $result['nombre'];
        } else {
            $_NOMBRE_ = 'Parroquia San Francisco de Asís'; // Valor por defecto si no hay datos
        }
    } catch (Exception $e) {
        $_NOMBRE_ = 'Parroquia San Francisco de Asís'; // Valor por defecto si hay error
    }
    return $_NOMBRE_;
}

// Actualizar el nombre global al cargar el archivo (solo una vez)
getNombreOrganizacion();
?>