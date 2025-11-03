<?php
if (!function_exists('getDBConnection')) { require_once __DIR__ . '/../root/config.php'; }
require_once __DIR__ . '/../root/utils.php';
if (!isset($_COOKIE['auth']) || Utils::leerCookie('auth') === false) {
    header("Location: {$_URL_}/login.php");
    exit;
}
if (!in_array((int)Utils::getUserCargo(), [1,2], true)) {
    header("Location: {$_URL_}/index.php");
    exit;
}
$pdo = getDBConnection();

// Fecha en español
date_default_timezone_set('America/Bogota');
$dias = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
$meses = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
$now = new DateTime('now', new DateTimeZone('America/Bogota'));
$fechaTexto = $dias[(int)$now->format('w')] . ', ' . $now->format('j') . ' de ' . $meses[(int)$now->format('n')] . ' de ' . $now->format('Y') . ' ' . $now->format('H:i');

// Métricas
try {
    $empleadosNoAdmin = (int)$pdo->query("SELECT COUNT(*) FROM empleado WHERE id_cargo <> 1")->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM aprobacion a JOIN liquidacion l ON a.id_liquidacion = l.id WHERE a.estado = 'pendiente' AND l.fecha_liquidacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stmt->execute();
    $novedadesPend = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM liquidacion l WHERE l.fecha_liquidacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stmt->execute();
    $nominasProc = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(l.total_neto),0) FROM liquidacion l WHERE l.fecha_liquidacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stmt->execute();
    $totalPagado30 = (float)$stmt->fetchColumn();
    
    // Contar novedades registradas en los últimos 30 días
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM novedades WHERE fecha_registro >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stmt->execute();
    $novedadesPend = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $empleadosNoAdmin = 0;
    $novedadesPend = 0;
    $nominasProc = 0;
    $totalPagado30 = 0.0;
}
// Nóminas recientes
$nominasRecientes = [];
try {
    $stmt = $pdo->query("SELECT l.id, l.id_empleado, l.mes, l.ano, l.devengos, l.total_neto, a.estado FROM liquidacion l LEFT JOIN aprobacion a ON a.id_liquidacion = l.id ORDER BY l.fecha_liquidacion DESC, l.id DESC LIMIT 8");
    $nominasRecientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $nominasRecientes = [];
}
?>

<div class="d-flex justify-content-between align-items-center pt-2 pb-2 mb-3 border-bottom">
    <h1 class="h4 mb-0">Resumen del Sistema</h1>
    <div class="text-muted small"><?php echo htmlspecialchars($fechaTexto); ?></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">Empleados</div>
                    <div class="h4 mb-0"><?php echo number_format($empleadosNoAdmin, 0, ',', '.'); ?></div>
                </div>
                <i class="bi bi-people fs-1 text-primary"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">Novedades registradas (30 días)</div>
                    <div class="h4 mb-0"><?php echo number_format($novedadesPend, 0, ',', '.'); ?></div>
                </div>
                <i class="bi bi-bell fs-1 text-warning"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">Nóminas procesadas (30 días)</div>
                    <div class="h4 mb-0"><?php echo number_format($nominasProc, 0, ',', '.'); ?></div>
                </div>
                <i class="bi bi-journal-check fs-1 text-info"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">Total pagado (30 días)</div>
                    <div class="h4 mb-0">$ <?php echo number_format($totalPagado30, 0, ',', '.'); ?></div>
                </div>
                <i class="bi bi-cash-stack fs-1 text-success"></i>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-12 col-md-6 col-lg-3">
                <a href="<?php echo $_URL_; ?>/liquidacion" class="btn btn-primary w-100 d-flex align-items-center justify-content-center">
                    <i class="bi bi-plus-circle me-2"></i>
                    Nueva liquidación
                </a>
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <a href="<?php echo $_URL_; ?>/importarEmpleados" class="btn btn-secondary w-100 d-flex align-items-center justify-content-center">
                    <i class="bi bi-person-plus me-2"></i>
                    Importar empleados
                </a>
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <a href="<?php echo $_URL_; ?>/reportes" class="btn btn-info text-white w-100 d-flex align-items-center justify-content-center">
                    <i class="bi bi-eye me-2"></i>
                    Mostrar novedades
                </a>
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <a href="<?php echo $_URL_; ?>/api/exportarRespaldoEmpleados.php" class="btn btn-success w-100 d-flex align-items-center justify-content-center">
                    <i class="bi bi-download me-2"></i>
                    Crear respaldo
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Nóminas Recientes -->
<div class="d-flex justify-content-between align-items-center mt-4 mb-2">
    <h2 class="h5 mb-0">Nóminas Recientes</h2>
    <a href="<?php echo $_URL_; ?>/reportes?tipo=liquidaciones" class="btn btn-link btn-sm">Ver todo</a>
</div>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Período</th>
                    <th>Estado</th>
                    <th class="text-end">Total Bruto</th>
                    <th class="text-end">Total Neto</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($nominasRecientes)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No hay nóminas recientes.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($nominasRecientes as $r):
                        $periodo = $meses[(int)$r['mes']] . ' ' . $r['ano'];
                        $estado = $r['estado'] ?? 'pendiente';
                        $badgeClass = $estado === 'aprobada' ? 'success' : ($estado === 'rechazada' ? 'danger' : 'warning');
                        $estadoLabel = ucfirst($estado);
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($periodo); ?></td>
                            <td><span class="badge bg-<?php echo $badgeClass; ?>"><?php echo $estadoLabel; ?></span></td>
                            <td class="text-end">$ <?php echo number_format($r['devengos'], 0, ',', '.'); ?></td>
                            <td class="text-end">$ <?php echo number_format($r['total_neto'], 0, ',', '.'); ?></td>
                            <td>
                                <div class="d-flex gap-2">
                                    <?php if ($estado === 'aprobada'): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo $_URL_; ?>/comprobante?module=comprobante&emp=<?php echo (int)$r['id_empleado']; ?>&year=<?php echo (int)$r['ano']; ?>&month=<?php echo (int)$r['mes']; ?>" title="Comprobante">
                                            <i class="bi bi-receipt"></i>
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-secondary" title="Comprobante" disabled>
                                            <i class="bi bi-receipt"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>