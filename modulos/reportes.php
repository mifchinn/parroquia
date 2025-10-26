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

$message = '';
$reportes = [];
$parametros = [];

// Manejar GET para filtros
$tipo = $_GET['tipo'] ?? 'liquidaciones';
$mes = (int)($_GET['mes'] ?? 0);
$ano = (int)($_GET['ano'] ?? 0);

$parametros = ['tipo' => $tipo, 'mes' => $mes, 'ano' => $ano];

// Query basada en tipo
switch ($tipo) {
    case 'empleados':
        $query = "SELECT e.id, e.nombre, e.apellido, e.documento, e.salario, e.fechacreacion FROM empleado e ORDER BY e.fechacreacion DESC";
        $reportes = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'liquidaciones':
        $where = '';
        $params = [];
        if ($mes > 0 && $ano > 0) {
            $where = "WHERE l.mes = ? AND l.ano = ?";
            $params = [$mes, $ano];
        }
        $stmt = $pdo->prepare("
            SELECT l.id, l.mes, l.ano, l.total_neto, e.nombre, e.apellido
            FROM liquidacion l
            JOIN empleado e ON l.id_empleado = e.id
            $where
            ORDER BY l.ano DESC, l.mes DESC
        ");
        $stmt->execute($params);
        $reportes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'aprobadas':
        $where = '';
        $params = [];
        if ($mes > 0 && $ano > 0) {
            $where = "AND l.mes = ? AND l.ano = ?";
            $params = [$mes, $ano];
        }
        $stmt = $pdo->prepare("
            SELECT l.id, l.mes, l.ano, l.total_neto, e.nombre, e.apellido
            FROM liquidacion l
            JOIN empleado e ON l.id_empleado = e.id
            JOIN aprobacion a ON l.id = a.id_liquidacion
            WHERE a.estado = 'aprobada' $where
            ORDER BY l.ano DESC, l.mes DESC
        ");
        $stmt->execute($params);
        $reportes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    default:
        $reportes = [];
}

// No registrar reportes en la tabla 'reporte' (removido por requerimiento)

// Manejar export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if ($tipo === 'empleados') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="reporte_empleados_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Nombre', 'Documento', 'Salario', 'Fecha Creacion']);
        foreach ($reportes as $row) {
            fputcsv($output, [
                $row['id'],
                $row['nombre'] . ' ' . $row['apellido'],
                $row['documento'],
                $row['salario'],
                date('d/m/Y', strtotime($row['fechacreacion']))
            ]);
        }
        fclose($output);
    } else {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="reporte_' . $tipo . '_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Empleado', 'Mes/Año', 'Total Neto']);
        $meses_es = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
        ];
        foreach ($reportes as $row) {
            $mn = $meses_es[(int)$row['mes']] ?? $row['mes'];
            fputcsv($output, [
                $row['id'],
                $row['nombre'] . ' ' . $row['apellido'],
                $mn . '/' . $row['ano'],
                $row['total_neto']
            ]);
        }
        fclose($output);
    }
    exit;
}
?>

<!-- Filtros -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET">
            <div class="row">
                <div class="col-md-3">
                    <div class="mb-3">
                        <select class="form-control" id="tipo" name="tipo" onchange="this.form.submit()">
                            <option value="" disabled selected>Seleccionar tipo de reporte</option>
                            <option value="empleados" <?php echo $tipo === 'empleados' ? 'selected' : ''; ?>>Empleados</option>
                            <option value="liquidaciones" <?php echo $tipo === 'liquidaciones' ? 'selected' : ''; ?>>Liquidaciones</option>
                            <option value="aprobadas" <?php echo $tipo === 'aprobadas' ? 'selected' : ''; ?>>Aprobadas</option>
                        </select>
                        <label for="tipo" class="form-label">Tipo de Reporte</label>
                    </div>
                </div>
                <?php if ($tipo !== 'empleados'): ?>
                <div class="col-md-2">
                    <div class="mb-3">
                        <select class="form-control" id="mes" name="mes">
                            <option value="" selected>Todos</option>
                            <?php
                            $meses_es = [
                                1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
                                5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
                                9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
                            ];
                            for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $mes == $m ? 'selected' : ''; ?>><?php echo $meses_es[$m]; ?></option>
                            <?php endfor; ?>
                        </select>
                        <label for="mes" class="form-label">Mes</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="mb-3">
                        <select class="form-control" id="ano" name="ano">
                            <option value="" selected>Todos</option>
                            <?php for ($y = 2024; $y <= date('Y'); $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $ano == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                        <label for="ano" class="form-label">Año</label>
                    </div>
                </div>
                <div class="col-md-5">
                    <button type="submit" class="btn btn-primary mt-4">Filtrar</button>
                    <a href="?tipo=<?php echo $tipo; ?>&mes=<?php echo $mes; ?>&ano=<?php echo $ano; ?>&export=csv" class="btn btn-success mt-4">Exportar CSV</a>
                </div>
                <?php else: ?>
                <div class="col-md-7">
                    <button type="submit" class="btn btn-primary mt-4">Filtrar</button>
                    <a href="?tipo=empleados&export=csv" class="btn btn-success mt-4">Exportar CSV</a>
                </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (empty($reportes)): ?>
    <div class="alert alert-info">No hay datos para este filtro.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <?php if ($tipo === 'empleados'): ?>
                    <tr><th>ID</th><th>Nombre</th><th>Documento</th><th>Salario</th><th>Fecha Creacion</th></tr>
                    <?php foreach ($reportes as $row): ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['nombre'] . ' ' . $row['apellido']); ?></td>
                            <td><?php echo htmlspecialchars($row['documento']); ?></td>
                            <td>$ <?php echo number_format($row['salario'], 0, ',', '.'); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($row['fechacreacion'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><th>ID</th><th>Empleado</th><th>Mes/Año</th><th>Total Neto</th></tr>
                    <?php foreach ($reportes as $row): ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['nombre'] . ' ' . $row['apellido']); ?></td>
                            <td><?php $mn = $meses_es[(int)$row['mes']] ?? $row['mes']; echo htmlspecialchars($mn . '/' . $row['ano']); ?></td>
                            <td>$ <?php echo number_format($row['total_neto'], 0, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </thead>
            <tbody>
            </tbody>
        </table>
    </div>
<?php endif; ?>