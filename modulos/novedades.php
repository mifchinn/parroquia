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
$novedades = [];
$filtros = [
    'id_empleado' => (int)($_GET['id_empleado'] ?? 0),
    'tipo' => $_GET['tipo'] ?? '',
    'fecha_desde' => $_GET['fecha_desde'] ?? '',
    'fecha_hasta' => $_GET['fecha_hasta'] ?? ''
];

// Construir consulta con filtros
$where = [];
$params = [];

if ($filtros['id_empleado'] > 0) {
    $where[] = "n.id_empleado = ?";
    $params[] = $filtros['id_empleado'];
}

if (!empty($filtros['tipo'])) {
    $where[] = "n.tipo = ?";
    $params[] = $filtros['tipo'];
}

if (!empty($filtros['fecha_desde'])) {
    $where[] = "n.fecha_inicio >= ?";
    $params[] = $filtros['fecha_desde'];
}

if (!empty($filtros['fecha_hasta'])) {
    $where[] = "n.fecha_fin <= ?";
    $params[] = $filtros['fecha_hasta'];
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $query = "
        SELECT n.id, n.id_empleado, n.tipo, n.fecha_inicio, n.hora_inicio, 
               n.fecha_fin, n.hora_fin, n.descripcion, n.fecha_registro,
               e.nombre, e.apellido, e.documento
        FROM novedades n
        JOIN empleado e ON n.id_empleado = e.id
        $whereClause
        ORDER BY n.fecha_inicio DESC, n.hora_inicio DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $novedades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = '<div class="alert alert-danger">Error al consultar novedades: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// Obtener empleados para el filtro
$empleados = $pdo->query("SELECT id, nombre, apellido, documento FROM empleado ORDER BY nombre, apellido")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Gestión de Novedades</h1>
</div>
<?php if ($message) echo $message; ?>

<!-- Filtros -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title">Filtros</h5>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <div class="mb-3">
                    <select class="form-control" id="id_empleado" name="id_empleado">
                        <option value="">Todos los empleados</option>
                        <?php foreach ($empleados as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>" <?php echo ($filtros['id_empleado'] == $emp['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($emp['nombre'] . ' ' . $emp['apellido'] . ' - ' . $emp['documento']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="id_empleado" class="form-label">Empleado</label>
                </div>
            </div>
            <div class="col-md-2">
                <div class="mb-3">
                    <select class="form-control" id="tipo" name="tipo">
                        <option value="">Todos los tipos</option>
                        <option value="incapacidad" <?php echo ($filtros['tipo'] === 'incapacidad') ? 'selected' : ''; ?>>Incapacidad</option>
                        <option value="hora_extra" <?php echo ($filtros['tipo'] === 'hora_extra') ? 'selected' : ''; ?>>Hora Extra</option>
                    </select>
                    <label for="tipo" class="form-label">Tipo</label>
                </div>
            </div>
            <div class="col-md-2">
                <div class="mb-3">
                    <input type="date" class="form-control" id="fecha_desde" name="fecha_desde" value="<?php echo htmlspecialchars($filtros['fecha_desde']); ?>">
                    <label for="fecha_desde" class="form-label">Fecha Desde</label>
                </div>
            </div>
            <div class="col-md-2">
                <div class="mb-3">
                    <input type="date" class="form-control" id="fecha_hasta" name="fecha_hasta" value="<?php echo htmlspecialchars($filtros['fecha_hasta']); ?>">
                    <label for="fecha_hasta" class="form-label">Fecha Hasta</label>
                </div>
            </div>
            <div class="col-md-1">
                <div class="mb-3">
                    <label class="form-label d-block invisible">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Lista de Novedades -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Novedades Registradas</h5>
        <div>
            <a href="<?php echo $_URL_; ?>/importarNovedades" class="btn btn-success btn-sm">
                <i class="bi bi-upload me-1"></i>Importar Novedades
            </a>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($novedades)): ?>
            <div class="alert alert-info">No se encontraron novedades con los filtros seleccionados.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Empleado</th>
                            <th>Tipo</th>
                            <th>Fecha Inicio</th>
                            <th>Hora Inicio</th>
                            <th>Fecha Fin</th>
                            <th>Hora Fin</th>
                            <th>Descripción</th>
                            <th>Fecha Registro</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($novedades as $novedad): ?>
                            <tr>
                                <td><?php echo $novedad['id']; ?></td>
                                <td><?php echo htmlspecialchars($novedad['nombre'] . ' ' . $novedad['apellido']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo ($novedad['tipo'] === 'incapacidad') ? 'danger' : 'warning'; ?>">
                                        <?php echo ($novedad['tipo'] === 'incapacidad') ? 'Incapacidad' : 'Hora Extra'; ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($novedad['fecha_inicio'])); ?></td>
                                <td><?php echo $novedad['hora_inicio']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($novedad['fecha_fin'])); ?></td>
                                <td><?php echo $novedad['hora_fin']; ?></td>
                                <td><?php echo htmlspecialchars($novedad['descripcion'] ?? ''); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($novedad['fecha_registro'])); ?></td>
                                <td>
                                    <!-- Botones eliminados -->
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function editarNovedad(id) {
    // Implementar edición de novedad
    alert('Función de edición no implementada. ID: ' + id);
}

function eliminarNovedad(id) {
    if (confirm('¿Está seguro de eliminar esta novedad?')) {
        window.location.href = '<?php echo $_URL_; ?>/api/eliminarNovedad.php?id=' + id;
    }
}
</script>