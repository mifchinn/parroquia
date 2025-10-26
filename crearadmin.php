<?php
require_once __DIR__ . '/root/config.php';

$pdo = getDBConnection();

/**
 * Crea un usuario si no existe por documento
 * @param PDO $pdo
 * @param string $documento
 * @param string $password_plain
 * @param int $id_cargo
 * @param string $nombre
 * @param string $apellido
 * @param string $cuenta
 * @param float|int $salario
 */
function ensure_user($pdo, $documento, $password_plain, $id_cargo, $nombre, $apellido, $cuenta = '000000', $salario = 0) {
    $check = $pdo->prepare("SELECT id FROM empleado WHERE documento = ?");
    $check->execute([$documento]);
    if ($check->fetch(PDO::FETCH_ASSOC)) {
        echo "Usuario ya existe. Documento: {$documento}\n";
        return;
    }

    $hash = password_hash($password_plain, PASSWORD_DEFAULT);
    $ins = $pdo->prepare("
        INSERT INTO empleado (nombre, apellido, password, id_tipodoc, documento, id_cargo, id_banco, id_ciudad, id_tipcue, cuenta, salario)
        VALUES (?, ?, ?, 1, ?, ?, 1, 1, 1, ?, ?)
    ");
    $ins->execute([$nombre, $apellido, $hash, $documento, $id_cargo, $cuenta, $salario]);
    echo "Usuario creado. Documento: {$documento}, Contraseña: {$password_plain}, Cargo: {$id_cargo}\n";
}

// Admin (cargo 1): documento 123456 / contraseña admin123
ensure_user($pdo, '123456', 'admin123', 1, 'Admin', 'Sistema', '000000', 5000000);

// Empleado general (cargo 3): documento 12345 / contraseña empleado123
ensure_user($pdo, '12345', 'empleado123', 3, 'Empleado', 'General', '000001', 2000000);

echo "Proceso finalizado. Recuerde eliminar este archivo [create_admin.php](create_admin.php) del servidor.\n";
?>