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
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$isEdit = ($action === 'edit' && $id > 0);
$employee = null;

// Manejar POST primero
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? 'create';
    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $password = $_POST['password'] ?? '';
    $id_tipodoc = (int)($_POST['id_tipodoc'] ?? 0);
    $documento = trim($_POST['documento'] ?? '');
    $id_cargo = (int)($_POST['id_cargo'] ?? 0);
    $id_banco = (int)($_POST['id_banco'] ?? 0);
    $id_ciudad = (int)($_POST['id_ciudad'] ?? 0);
    $id_tipcue = (int)($_POST['id_tipcue'] ?? 0);
    $cuenta = trim($_POST['cuenta'] ?? '');
    $salario = (float)($_POST['salario'] ?? 0);

    $valid = !empty($nombre) && !empty($apellido) && $id_tipodoc > 0 && !empty($documento) && $id_cargo > 0 && $id_banco > 0 && $id_ciudad > 0 && $id_tipcue > 0 && !empty($cuenta) && $salario > 0;

    if ($post_action === 'update' && $id > 0 && $valid) {
        if (!empty($password)) {
            $password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE empleado SET nombre = ?, apellido = ?, password = ?, id_tipodoc = ?, documento = ?, id_cargo = ?, id_banco = ?, id_ciudad = ?, id_tipcue = ?, cuenta = ?, salario = ?, fechamodificacion = CURRENT_DATE WHERE id = ?");
            $stmt->execute([$nombre, $apellido, $password, $id_tipodoc, $documento, $id_cargo, $id_banco, $id_ciudad, $id_tipcue, $cuenta, $salario, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE empleado SET nombre = ?, apellido = ?, id_tipodoc = ?, documento = ?, id_cargo = ?, id_banco = ?, id_ciudad = ?, id_tipcue = ?, cuenta = ?, salario = ?, fechamodificacion = CURRENT_DATE WHERE id = ?");
            $stmt->execute([$nombre, $apellido, $id_tipodoc, $documento, $id_cargo, $id_banco, $id_ciudad, $id_tipcue, $cuenta, $salario, $id]);
        }
        $message = '<div class="alert alert-success">Empleado actualizado exitosamente.</div>';
        $action = 'list';
        $isEdit = false;
    } else if ($post_action === 'create' && $valid) {
        $password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO empleado (nombre, apellido, password, id_tipodoc, documento, id_cargo, id_banco, id_ciudad, id_tipcue, cuenta, salario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nombre, $apellido, $password, $id_tipodoc, $documento, $id_cargo, $id_banco, $id_ciudad, $id_tipcue, $cuenta, $salario]);
        $message = '<div class="alert alert-success">Empleado creado exitosamente.</div>';
        $action = 'list';
    } else {
        $message = '<div class="alert alert-danger">Todos los campos son requeridos.</div>';
    }
}

// Manejar delete
if ($action === 'delete' && $id > 0) {
    $stmt = $pdo->prepare("DELETE FROM empleado WHERE id = ?");
    $stmt->execute([$id]);
    $message = '<div class="alert alert-success">Empleado eliminado exitosamente.</div>';
    $action = 'list';
}

// Si es edit, cargar datos del empleado
if ($isEdit && $id > 0) {
    $stmt = $pdo->prepare("
        SELECT e.*, td.nombre as tipodoc, c.nombre as cargo, b.nombre as banco, ci.nombre as ciudad, tc.nombre as tipocuenta
        FROM empleado e
        LEFT JOIN tipodocumento td ON e.id_tipodoc = td.id
        LEFT JOIN cargo c ON e.id_cargo = c.id
        LEFT JOIN banco b ON e.id_banco = b.id
        LEFT JOIN ciudad ci ON e.id_ciudad = ci.id
        LEFT JOIN tipocuenta tc ON e.id_tipcue = tc.id
        WHERE e.id = ?
    ");
    $stmt->execute([$id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) {
        $message = '<div class="alert alert-danger">Empleado no encontrado.</div>';
        $isEdit = false;
    }
}

// Obtener opciones para selects
$tipodocs = $pdo->query("SELECT id, nombre FROM tipodocumento ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$cargos = $pdo->query("SELECT id, nombre FROM cargo ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$bancos = $pdo->query("SELECT id, nombre FROM banco ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$ciudades = $pdo->query("SELECT id, nombre FROM ciudad ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$tipocuentas = $pdo->query("SELECT id, nombre FROM tipocuenta ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Listar empleados con JOIN
$stmt = $pdo->query("
    SELECT e.id, e.nombre, e.apellido, e.documento, e.salario, e.fechacreacion, e.cuenta,
           td.nombre as tipodoc, c.nombre as cargo, b.nombre as banco, ci.nombre as ciudad, tc.nombre as tipocuenta
    FROM empleado e
    LEFT JOIN tipodocumento td ON e.id_tipodoc = td.id
    LEFT JOIN cargo c ON e.id_cargo = c.id
    LEFT JOIN banco b ON e.id_banco = b.id
    LEFT JOIN ciudad ci ON e.id_ciudad = ci.id
    LEFT JOIN tipocuenta tc ON e.id_tipcue = tc.id
    ORDER BY e.fechacreacion DESC
");
$empleados = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Gestión de Empleados</h1>
            </div>
            <?php if (isset($message)) echo $message; ?>
            
            <!-- Formulario Crear/Editar Empleado -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title"><?php echo $isEdit ? 'Editar Empleado ID: ' . $id : 'Crear Nuevo Empleado'; ?></h5>
                        </div>
                        <div class="card-body p-3">
                            <form method="POST">
                                <?php if ($isEdit): ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                                <?php else: ?>
                                    <input type="hidden" name="action" value="create">
                                <?php endif; ?>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <input type="text" class="form-control form-control-sm" id="nombre" name="nombre" placeholder="" value="<?php echo $isEdit ? htmlspecialchars($employee['nombre']) : ''; ?>" required>
                                            <label for="nombre" class="form-label">Nombre</label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <input type="text" class="form-control form-control-sm" id="apellido" name="apellido" placeholder="" value="<?php echo $isEdit ? htmlspecialchars($employee['apellido']) : ''; ?>" required>
                                            <label for="apellido" class="form-label">Apellido</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <input type="password" class="form-control form-control-sm" id="password" name="password" placeholder="" <?php echo $isEdit ? '' : 'required'; ?>>
                                            <label for="password" class="form-label"><?php echo $isEdit ? 'Nueva Contraseña (opcional)' : 'Contraseña'; ?></label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <select class="form-control" id="id_tipodoc" name="id_tipodoc" required>
                                                <option value="" disabled <?php echo !$isEdit || $employee['id_tipodoc'] == '' ? 'selected' : ''; ?>>Seleccionar tipo documento</option>
                                                <?php foreach ($tipodocs as $td): ?>
                                                    <option value="<?php echo $td['id']; ?>" <?php echo $isEdit && $employee['id_tipodoc'] == $td['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($td['nombre']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <label for="id_tipodoc" class="form-label">Tipo Documento</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <input type="text" class="form-control form-control-sm" id="documento" name="documento" placeholder="" value="<?php echo $isEdit ? htmlspecialchars($employee['documento']) : ''; ?>" required>
                                            <label for="documento" class="form-label">Documento</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <select class="form-control" id="id_cargo" name="id_cargo" required>
                                                <option value="" disabled <?php echo !$isEdit || $employee['id_cargo'] == '' ? 'selected' : ''; ?>>Seleccionar cargo</option>
                                                <?php foreach ($cargos as $car): ?>
                                                    <option value="<?php echo $car['id']; ?>" <?php echo $isEdit && $employee['id_cargo'] == $car['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($car['nombre']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <label for="id_cargo" class="form-label">Cargo</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <select class="form-control" id="id_banco" name="id_banco" required>
                                                <option value="" disabled <?php echo !$isEdit || $employee['id_banco'] == '' ? 'selected' : ''; ?>>Seleccionar banco</option>
                                                <?php foreach ($bancos as $ban): ?>
                                                    <option value="<?php echo $ban['id']; ?>" <?php echo $isEdit && $employee['id_banco'] == $ban['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($ban['nombre']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <label for="id_banco" class="form-label">Banco</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <select class="form-control" id="id_ciudad" name="id_ciudad" required>
                                                <option value="" disabled <?php echo !$isEdit || $employee['id_ciudad'] == '' ? 'selected' : ''; ?>>Seleccionar ciudad</option>
                                                <?php foreach ($ciudades as $ciu): ?>
                                                    <option value="<?php echo $ciu['id']; ?>" <?php echo $isEdit && $employee['id_ciudad'] == $ciu['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($ciu['nombre']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <label for="id_ciudad" class="form-label">Ciudad</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <select class="form-control" id="id_tipcue" name="id_tipcue" required>
                                                <option value="" disabled <?php echo !$isEdit || $employee['id_tipcue'] == '' ? 'selected' : ''; ?>>Seleccionar tipo cuenta</option>
                                                <?php foreach ($tipocuentas as $tc): ?>
                                                    <option value="<?php echo $tc['id']; ?>" <?php echo $isEdit && $employee['id_tipcue'] == $tc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tc['nombre']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <label for="id_tipcue" class="form-label">Tipo Cuenta</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <input type="text" class="form-control form-control-sm" id="cuenta" name="cuenta" placeholder="" value="<?php echo $isEdit ? htmlspecialchars($employee['cuenta']) : ''; ?>" required>
                                            <label for="cuenta" class="form-label">Número de Cuenta</label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <input type="number" step="0.01" class="form-control form-control-sm" id="salario" name="salario" placeholder="" value="<?php echo $isEdit ? $employee['salario'] : ''; ?>" required>
                                            <label for="salario" class="form-label">Salario Base</label>
                                        </div>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm"><?php echo $isEdit ? 'Actualizar Empleado' : 'Crear Empleado'; ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lista de Empleados -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Lista de Empleados</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nombre Completo</th>
                                    <th>Tipo Doc / Documento</th>
                                    <th>Cargo</th>
                                    <th>Banco / Cuenta</th>
                                    <th>Ciudad</th>
                                    <th>Salario</th>
                                    <th>Fecha Creación</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($empleados)): ?>
                                    <tr><td colspan="9" class="text-center">No hay empleados registrados.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($empleados as $emp): ?>
                                        <tr>
                                            <td><?php echo $emp['id']; ?></td>
                                            <td><?php echo htmlspecialchars($emp['nombre'] . ' ' . $emp['apellido']); ?></td>
                                            <td><?php echo htmlspecialchars($emp['tipodoc'] . ' / ' . $emp['documento']); ?></td>
                                            <td><?php echo htmlspecialchars($emp['cargo']); ?></td>
                                            <td><?php echo htmlspecialchars($emp['banco'] . ' / ' . $emp['tipocuenta'] . ' - ' . $emp['cuenta']); ?></td>
                                            <td><?php echo htmlspecialchars($emp['ciudad']); ?></td>
                                            <td>$ <?php echo number_format($emp['salario'], 0, ',', '.'); ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($emp['fechacreacion'])); ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group" aria-label="Acciones">
                                                    <a href="?module=empleados&action=edit&id=<?php echo $emp['id']; ?>" class="btn btn-outline-warning" title="Editar">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="?module=empleados&action=delete&id=<?php echo $emp['id']; ?>" class="btn btn-outline-danger" onclick="return confirm('¿Eliminar este empleado?')" title="Eliminar">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const documentoInput = document.getElementById('documento');
    const form = document.querySelector('form');
    let documentoValid = <?php echo $isEdit ? 'true' : 'false'; ?>;

    if (documentoInput) {
        documentoInput.addEventListener('blur', async function() {
            const documento = this.value.trim();
            if (documento === '') {
                documentoValid = false;
                return;
            }

            try {
                const action = '<?php echo $isEdit ? 'edit' : 'create'; ?>';
                const currentId = <?php echo $isEdit ? $id : '0'; ?>;
                const response = await fetch('<?php echo $_URL_; ?>/api/validarEmpleado.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ documento: documento, action: action, current_id: currentId })
                });
                const data = await response.json();
                const errorDiv = document.createElement('div');
                errorDiv.id = 'docError';
                errorDiv.className = 'alert alert-danger mt-2 p-2';
                errorDiv.style.display = 'none';

                if (data.valid) {
                    errorDiv.textContent = data.message;
                    errorDiv.className = 'alert alert-success mt-2 p-2';
                    documentoValid = true;
                } else {
                    errorDiv.textContent = data.message;
                    documentoValid = false;
                }

                const existingError = document.getElementById('docError');
                if (existingError) existingError.remove();
                documentoInput.parentNode.appendChild(errorDiv);
                errorDiv.style.display = 'block';
            } catch (error) {
                console.error('Error validación');
                documentoValid = false;
            }
        });
    }

    // Validar en submit
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!documentoValid) {
                e.preventDefault();
                alert('Valide el documento primero');
            }
        });
    }
});
</script>