<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../root/config.php';
require_once __DIR__ . '/../root/utils.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$documento = trim($input['documento'] ?? '');
$password = $input['password'] ?? '';

if (empty($documento) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Credenciales requeridas']);
    exit;
}

$user = Utils::validarLogin($documento, $password);
if ($user) {
    // Crear cookie encriptada con ID usuario
    Utils::crearCookie('auth', $user['id'], time() + (30 * 24 * 3600));  // 30 días
    echo json_encode(['success' => true, 'message' => 'Login exitoso', 'user' => ['id' => $user['id'], 'nombre' => $user['nombre'] . ' ' . $user['apellido']]]);
} else {
    echo json_encode(['success' => false, 'message' => 'Credenciales inválidas']);
}
?>