<?php
// API para obtener empleados disponibles para liquidación
if (!function_exists('getDBConnection')) { require_once __DIR__ . '/../root/config.php'; }
require_once __DIR__ . '/../root/utils.php';

// Solo responder a AJAX, sin ninguna salida HTML
header('Content-Type: application/json');

// Verificar autenticación
if (!isset($_COOKIE['auth']) || Utils::leerCookie('auth') === false) {
    echo json_encode([]);
    exit;
}

// Verificar permisos
if (!in_array((int)Utils::getUserCargo(), [1,2], true)) {
    echo json_encode([]);
    exit;
}

try {
    $pdo = getDBConnection();
    
    $ano = (int)($_GET['ano'] ?? 0);
    $mes = (int)($_GET['mes'] ?? 0);
    $tipo_liquidacion = $_GET['tipo'] ?? 'mensual';
    
    if ($ano > 0 && $mes >= 1 && $mes <= 12) {
        // Obtener empleados con salario > 0 que no tengan liquidación en ese mes/año y tipo
        $stmt = $pdo->prepare("
            SELECT e.id, e.nombre, e.apellido, e.documento, e.salario
            FROM empleado e
            WHERE e.salario > 0
            AND e.id NOT IN (
                SELECT l.id_empleado
                FROM liquidacion l
                WHERE l.mes = ? AND l.ano = ? AND l.tipo_liquidacion = ?
            )
            ORDER BY e.nombre
        ");
        $stmt->execute([$mes, $ano, $tipo_liquidacion]);
        $empleados_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($empleados_disponibles);
    } else {
        echo json_encode([]);
    }
} catch (Exception $e) {
    echo json_encode([]);
}
?>