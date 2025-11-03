<?php
// API para eliminar novedades
if (!function_exists('getDBConnection')) { require_once __DIR__ . '/../root/config.php'; }
require_once __DIR__ . '/../root/utils.php';

// Solo responder a AJAX, sin ninguna salida HTML
header('Content-Type: application/json');

// Verificar autenticación
if (!isset($_COOKIE['auth']) || Utils::leerCookie('auth') === false) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Verificar permisos
if (!in_array((int)Utils::getUserCargo(), [1,2], true)) {
    echo json_encode(['success' => false, 'message' => 'Permisos insuficientes']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Obtener ID de la novedad
    $id_novedad = (int)($_GET['id'] ?? 0);
    
    if ($id_novedad <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID de novedad inválido']);
        exit;
    }
    
    // Eliminar novedad
    $stmt = $pdo->prepare("DELETE FROM novedades WHERE id = ?");
    $stmt->execute([$id_novedad]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Novedad eliminada correctamente']);
    } else {
        echo json_encode(['success' => false, 'message' => 'No se encontró la novedad o ya fue eliminada']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error en el servidor: ' . $e->getMessage()]);
}
?>