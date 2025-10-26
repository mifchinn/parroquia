<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../root/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$documento = trim($input['documento'] ?? '');
$action = $input['action'] ?? 'create';
$current_id = (int)($input['current_id'] ?? 0);

if (empty($documento)) {
    echo json_encode(['valid' => false, 'message' => 'Documento requerido']);
    exit;
}

$pdo = getDBConnection();

if ($action === 'create') {
    $stmt = $pdo->prepare("SELECT id FROM empleado WHERE documento = ?");
    $stmt->execute([$documento]);
    if ($stmt->fetch()) {
        echo json_encode(['valid' => false, 'message' => 'Documento ya existe']);
    } else {
        echo json_encode(['valid' => true, 'message' => 'Documento disponible']);
    }
} else if ($action === 'edit') {
    $stmt = $pdo->prepare("SELECT id FROM empleado WHERE documento = ? AND id != ?");
    $stmt->execute([$documento, $current_id]);
    if ($stmt->fetch()) {
        echo json_encode(['valid' => false, 'message' => 'Documento ya existe en otro empleado']);
    } else {
        echo json_encode(['valid' => true, 'message' => 'Documento disponible']);
    }
} else {
    echo json_encode(['valid' => false, 'message' => 'Acción inválida']);
}
?>