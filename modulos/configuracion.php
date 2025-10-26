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
$success = false;

// Manejar POST para guardar configuración de la organización
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_organizacion'])) {
    $nombre = trim($_POST['nombre'] ?? '');
    $nit = trim($_POST['nit'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $otro = trim($_POST['otro'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    if (!empty($nombre) && !empty($nit) && !empty($direccion) && !empty($telefono) && !empty($email)) {
        try {
            // Actualizar o insertar configuración
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM configuracion WHERE id = 1");
            $stmt->execute();
            $existe = (int)$stmt->fetchColumn() > 0;
            
            if ($existe) {
                $stmt = $pdo->prepare("UPDATE configuracion SET nombre = ?, nit = ?, direccion = ?, telefono = ?, otro = ?, email = ?, fecha_actualizacion = CURRENT_DATE WHERE id = 1");
                $stmt->execute([$nombre, $nit, $direccion, $telefono, $otro, $email]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO configuracion (id, nombre, nit, direccion, telefono, otro, email, fecha_creacion, fecha_actualizacion) VALUES (1, ?, ?, ?, ?, ?, ?, CURRENT_DATE, CURRENT_DATE)");
                $stmt->execute([$nombre, $nit, $direccion, $telefono, $otro, $email]);
            }
            
            $success = true;
            $message = '<div class="alert alert-success">Información de la organización guardada exitosamente.</div>';
        } catch (Exception $e) {
            $message = '<div class="alert alert-danger">Error al guardar la información: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } else {
        $message = '<div class="alert alert-danger">Por favor complete todos los campos requeridos.</div>';
    }
}

// Manejar POST para guardar tasas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_tasas'])) {
    $tasasalud = (int)($_POST['tasasalud'] ?? 4);
    $tasapension = (int)($_POST['tasapension'] ?? 4);
    $factor_extras = (float)($_POST['factor_extras'] ?? 1.25);
    
    try {
        // Actualizar o insertar tasas
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM configuracion WHERE id = 1");
        $stmt->execute();
        $existe = (int)$stmt->fetchColumn() > 0;
        
        if ($existe) {
            $stmt = $pdo->prepare("UPDATE configuracion SET tasasalud = ?, tasapension = ?, factor_extras = ?, fecha_actualizacion = CURRENT_DATE WHERE id = 1");
            $stmt->execute([$tasasalud, $tasapension, $factor_extras]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO configuracion (id, tasasalud, tasapension, factor_extras, fecha_creacion, fecha_actualizacion) VALUES (1, ?, ?, ?, CURRENT_DATE, CURRENT_DATE)");
            $stmt->execute([$tasasalud, $tasapension, $factor_extras]);
        }
        
        $success = true;
        $message = '<div class="alert alert-success">Tasas actualizadas exitosamente.</div>';
    } catch (Exception $e) {
        $message = '<div class="alert alert-danger">Error al actualizar las tasas: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Obtener configuración actual
$config = [
    'nombre' => 'Parroquia San Francisco de Asís',
    'nit' => '123456789-0',
    'direccion' => 'Calle Principal #123, Quibdó, Chocó',
    'telefono' => '(4) 123-4567',
    'otro' => '',
    'email' => 'parroquia.sanfrancisco@ejemplo.com',
    'tasasalud' => 4,
    'tasapension' => 4,
    'factor_extras' => 1.25
];

try {
    $stmt = $pdo->query("SELECT * FROM configuracion WHERE id = 1");
    $configData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($configData) {
        $config = $configData;
    }
} catch (Exception $e) {
    // Si la tabla no existe, usar valores por defecto
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Configuración del Sistema</h1>
</div>
<?php if ($message) echo $message; ?>

<div class="row">
    <div class="col-md-8">
        <!-- Información de la Organización -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">Información de la Organización</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="guardar_organizacion" value="1">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <input type="text" class="form-control" id="nombre" name="nombre" value="<?php echo htmlspecialchars($config['nombre'] ?? ''); ?>" required>
                                <label for="nombre" class="form-label">Nombre de la Organización</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <input type="text" class="form-control" id="nit" name="nit" value="<?php echo htmlspecialchars($config['nit'] ?? ''); ?>" required>
                                <label for="nit" class="form-label">NIT</label>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <input type="text" class="form-control" id="direccion" name="direccion" value="<?php echo htmlspecialchars($config['direccion'] ?? ''); ?>" required>
                                <label for="direccion" class="form-label">Dirección</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <input type="text" class="form-control" id="telefono" name="telefono" value="<?php echo htmlspecialchars($config['telefono'] ?? ''); ?>" required>
                                <label for="telefono" class="form-label">Teléfono</label>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($config['email'] ?? ''); ?>" required>
                                <label for="email" class="form-label">Email</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-2"></i>Guardar Información
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Tasas de Aportes -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Tasas de Aportes</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="guardar_tasas" value="1">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <input type="number" class="form-control" id="tasasalud" name="tasasalud" value="<?php echo htmlspecialchars($config['tasasalud'] ?? ''); ?>" min="0" max="100" required>
                                <label for="tasasalud" class="form-label">Tasa de Aporte a Salud (%)</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <input type="number" class="form-control" id="tasapension" name="tasapension" value="<?php echo htmlspecialchars($config['tasapension'] ?? ''); ?>" min="0" max="100" required>
                                <label for="tasapension" class="form-label">Tasa de Aporte a Pensión (%)</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <input type="number" class="form-control" id="factor_extras" name="factor_extras" value="<?php echo htmlspecialchars($config['factor_extras'] ?? ''); ?>" min="0" step="0.01" required>
                                <label for="factor_extras" class="form-label">Factor Horas Extra</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-2"></i>Guardar Cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Información</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">En esta sección puedes configurar los parámetros generales del sistema:</p>
                <ul class="small">
                    <li><strong>Información de la Organización:</strong> Datos básicos de la entidad que se usarán en los reportes y documentos.</li>
                    <li><strong>Tasas de Aportes:</strong> Porcentajes que se aplicarán en los cálculos de liquidación.</li>
                    <li><strong>Factor Horas Extra:</strong> Multiplicador para el cálculo de horas extras.</li>
                </ul>
                <div class="alert alert-info small">
                    <i class="bi bi-info-circle me-1"></i>
                    Los cambios en la configuración afectarán los cálculos futuros de liquidación.
                </div>
            </div>
        </div>
    </div>
    
    <!-- Respaldo de Datos -->
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Respaldo de Datos</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div>Último respaldo:</div>
                                <div class="form-control-plaintext">
                                    <?php
                                    if (!empty($config['fecha_respaldo'])) {
                                        echo date('d/m/Y H:i:s', strtotime($config['fecha_respaldo']));
                                    } else {
                                        echo 'Nunca';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label d-block invisible">&nbsp;</label>
                                <div class="btn-group w-100" role="group">
                                    <button type="button" id="btnCrearRespaldo" class="btn btn-primary">
                                        <i class="bi bi-download me-2"></i>Crear respaldo
                                    </button>
                                    <button type="button" id="btnRestaurarRespaldo" class="btn btn-warning">
                                        <i class="bi bi-upload me-2"></i>Restaurar respaldo
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Formulario oculto para restaurar -->
                    <form id="formRestaurar" style="display: none;" enctype="multipart/form-data">
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <input type="file" class="form-control" id="respaldo_file" name="respaldo_file" accept=".json" required>
                                    <label for="respaldo_file" class="form-label">Seleccionar archivo de respaldo (.json)</label>
                                    <div class="form-text">Seleccione un archivo de respaldo previamente creado.</div>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end">
                            <button type="button" id="btnCancelarRestaurar" class="btn btn-secondary me-2">Cancelar</button>
                            <button type="submit" class="btn btn-warning">
                                <i class="bi bi-upload me-2"></i>Restaurar datos
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const btnCrearRespaldo = document.getElementById('btnCrearRespaldo');
        const btnRestaurarRespaldo = document.getElementById('btnRestaurarRespaldo');
        const formRestaurar = document.getElementById('formRestaurar');
        const btnCancelarRestaurar = document.getElementById('btnCancelarRestaurar');
        
        // Crear respaldo
        btnCrearRespaldo.addEventListener('click', function() {
            if (confirm('¿Está seguro de crear un respaldo de todos los datos del sistema?')) {
                btnCrearRespaldo.disabled = true;
                btnCrearRespaldo.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creando respaldo...';
                
                fetch('<?php echo $_URL_; ?>/api/respaldo.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=crear_respaldo&download=true'
                })
                .then(response => {
                    // Si es una descarga, manejarla diferente
                    const contentType = response.headers.get('Content-Type');
                    if (contentType && contentType.includes('application/octet-stream')) {
                        // Es una descarga de archivo
                        return response.blob().then(blob => {
                            const url = window.URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = 'respaldo_' + new Date().toISOString().slice(0,19).replace(/:/g, '-') + '.json';
                            document.body.appendChild(a);
                            a.click();
                            window.URL.revokeObjectURL(url);
                            document.body.removeChild(a);
                            
                            alert('Respaldo creado y descargado exitosamente');
                            location.reload();
                        });
                    } else {
                        // Es una respuesta JSON
                        return response.json().then(data => {
                            if (data.success) {
                                alert('Respaldo creado exitosamente: ' + data.filename);
                                location.reload();
                            } else {
                                alert('Error al crear el respaldo: ' + data.message);
                            }
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al crear el respaldo');
                })
                .finally(() => {
                    btnCrearRespaldo.disabled = false;
                    btnCrearRespaldo.innerHTML = '<i class="bi bi-download me-2"></i>Crear respaldo';
                });
            }
        });
        
        // Mostrar formulario de restauración
        btnRestaurarRespaldo.addEventListener('click', function() {
            formRestaurar.style.display = 'block';
            btnRestaurarRespaldo.style.display = 'none';
        });
        
        // Cancelar restauración
        btnCancelarRestaurar.addEventListener('click', function() {
            formRestaurar.style.display = 'none';
            btnRestaurarRespaldo.style.display = 'inline-block';
            document.getElementById('respaldo_file').value = '';
        });
        
        // Restaurar respaldo
        formRestaurar.addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (!confirm('¿Está seguro de restaurar los datos desde este archivo? Los datos existentes no se sobreescribirán.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'restaurar_respaldo');
            formData.append('respaldo_file', document.getElementById('respaldo_file').files[0]);
            
            btnCancelarRestaurar.disabled = true;
            formRestaurar.querySelector('button[type="submit"]').disabled = true;
            formRestaurar.querySelector('button[type="submit"]').innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Restaurando...';
            
            fetch('<?php echo $_URL_; ?>/api/respaldo.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Restauración completada: ' + data.message);
                    location.reload();
                } else {
                    alert('Error al restaurar: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al restaurar el respaldo');
            })
            .finally(() => {
                btnCancelarRestaurar.disabled = false;
                formRestaurar.querySelector('button[type="submit"]').disabled = false;
                formRestaurar.querySelector('button[type="submit"]').innerHTML = '<i class="bi bi-upload me-2"></i>Restaurar datos';
            });
        });
    });
    </script>
</div>