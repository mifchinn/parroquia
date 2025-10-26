<?php
require_once __DIR__ . '/../root/config.php';
require_once __DIR__ . '/../root/utils.php';

$pdo = getDBConnection();

// Verificar autenticación
if (!isset($_COOKIE['auth']) || Utils::leerCookie('auth') === false) {
    header("Location: {$_URL_}/login.php");
    exit;
}
// Verificar rol (solo cargos 1 o 2)
if (!in_array((int)Utils::getUserCargo(), [1,2], true)) {
    header("Location: {$_URL_}/index.php");
    exit;
}

$message = '';

// Manejar POST para aprobar/rechazar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_liquidacion = (int)($_POST['id_liquidacion'] ?? 0);
    $accion = $_POST['accion'] ?? '';
    $observaciones = trim($_POST['observaciones'] ?? '');

    if ($id_liquidacion > 0 && in_array($accion, ['aprobar', 'rechazar'])) {
        $estado = ($accion === 'aprobar') ? 'aprobada' : 'rechazada';
        $stmt = $pdo->prepare("UPDATE aprobacion SET estado = ?, fecha_aprobacion = CURRENT_DATE, observaciones = ? WHERE id_liquidacion = ? AND estado = 'pendiente'");
        $rows = $stmt->execute([$estado, $observaciones, $id_liquidacion]);
        
        if ($rows > 0) {
            $message = '<div class="alert alert-success">Liquidación ' . $estado . ' exitosamente.</div>';
        } else {
            $message = '<div class="alert alert-danger">No se pudo actualizar. Posiblemente ya fue procesada.</div>';
        }
    }
}

// Listar liquidaciones pendientes
$stmt = $pdo->query("
    SELECT l.id, l.mes, l.ano, l.total_neto, e.nombre, e.apellido
    FROM liquidacion l
    JOIN empleado e ON l.id_empleado = e.id
    JOIN aprobacion a ON l.id = a.id_liquidacion
    WHERE a.estado = 'pendiente'
    ORDER BY l.ano DESC, l.mes DESC
");
$pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2">Aprobar Liquidaciones</h1>
        </div>
        <?php if ($message) echo $message; ?>
        
        <?php if (empty($pendientes)): ?>
            <div class="alert alert-info">No hay liquidaciones pendientes de aprobación.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Empleado</th>
                            <th>Mes/Año</th>
                            <th>Total Neto</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $meses_es = [
                            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
                            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
                            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
                        ];
                        foreach ($pendientes as $liq): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($liq['nombre'] . ' ' . $liq['apellido']); ?></td>
                                <td><?php $mn = $meses_es[(int)$liq['mes']] ?? $liq['mes']; echo htmlspecialchars($mn . '/' . $liq['ano']); ?></td>
                                <td>$ <?php echo number_format($liq['total_neto'], 0, ',', '.'); ?></td>
                                <td>
                                    <form method="POST" class="d-flex align-items-center">
                                        <input type="hidden" name="id_liquidacion" value="<?php echo $liq['id']; ?>">
                                        <div class="btn-group btn-group-sm" role="group" aria-label="Acciones">
                                            <button type="submit" name="accion" value="aprobar" class="btn btn-outline-success" onclick="return confirm('¿Aprobar esta liquidación?');"><i class="bi bi-check-square me-1"></i> Aprobar</button>
                                            <button type="submit" name="accion" value="rechazar" class="btn btn-outline-danger" onclick="return confirm('¿Rechazar esta liquidación?');"><i class="bi bi-x-square me-1"></i> Rechazar</button>
                                        </div>
                                        <div class="input-group input-group-sm ms-auto" style="max-width: 320px;">
                                            <input type="text" class="form-control" name="observaciones" placeholder="Observaciones">
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>