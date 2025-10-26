<?php
require_once __DIR__ . '/../root/config.php';
require_once __DIR__ . '/../root/utils.php';

// Verificar autenticación
if (!isset($_COOKIE['auth']) || Utils::leerCookie('auth') === false) {
    header("Location: {$_URL_}/login.php");
    exit;
}

$pdo = getDBConnection();

// Obtener datos de empleados con información de nómina actual (campos maestro: salario/bancarios)
$query = "
    SELECT 
        e.id,
        e.nombre,
        e.apellido,
        e.documento,
        c.nombre  AS cargo,
        b.nombre  AS banco,
        ci.nombre AS ciudad,
        tc.nombre AS tipo_cuenta,
        e.cuenta,
        e.salario,
        e.fechacreacion
    FROM empleado e
    JOIN cargo c      ON e.id_cargo = c.id
    JOIN banco b      ON e.id_banco = b.id
    JOIN ciudad ci    ON e.id_ciudad = ci.id
    JOIN tipocuenta tc ON e.id_tipcue = tc.id
    ORDER BY e.id ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Preparar headers para descarga CSV
$filename = 'respaldo_empleados_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Salida CSV
$output = fopen('php://output', 'w');

// Encabezados
fputcsv($output, [
    'ID',
    'Nombre Completo',
    'Documento',
    'Cargo',
    'Banco',
    'Ciudad',
    'Tipo Cuenta',
    'Cuenta',
    'Salario',
    'Fecha Creacion'
]);

// Filas
foreach ($rows as $r) {
    $nombreCompleto = trim(($r['nombre'] ?? '') . ' ' . ($r['apellido'] ?? ''));
    fputcsv($output, [
        $r['id'],
        $nombreCompleto,
        $r['documento'],
        $r['cargo'],
        $r['banco'],
        $r['ciudad'],
        $r['tipo_cuenta'],
        $r['cuenta'],
        $r['salario'],
        $r['fechacreacion']
    ]);
}

fclose($output);
exit;