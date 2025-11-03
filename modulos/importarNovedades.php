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
$action = $_GET['action'] ?? null;

// Descargar plantilla CSV
if ($action === 'plantilla') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plantilla_novedades.csv"');
    echo "documento_empleado,tipo,fecha_inicio,hora_inicio,fecha_fin,hora_fin,descripcion\n";
    echo "123456789,incapacidad,2025-01-01,08:00,2025-01-05,18:00,Incapacidad medica\n";
    echo "987654321,hora_extra,2025-01-10,18:00,2025-01-10,22:00,Horas extras del sabado\n";
    exit;
}

// Helpers
function normalize_key($k) {
    $k = trim(mb_strtolower($k));
    $k = str_replace([' ', '-', '.'], '_', $k);
    return $k;
}

function detect_delimiter($line) {
    $candidates = ["," => substr_count($line, ","), ";" => substr_count($line, ";"), "\t" => substr_count($line, "\t")];
    arsort($candidates);
    $delim = array_key_first($candidates);
    return $delim ?: ",";
}

$stats = ['insertados' => 0, 'saltados' => 0, 'errores' => 0];
$detalles_errores = [];
$detalles_exitosos = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        $message = '<div class="alert alert-danger">Archivo no recibido correctamente.</div>';
    } else {
        $tmp = $_FILES['archivo']['tmp_name'];
        $orig = $_FILES['archivo']['name'];
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $message = '<div class="alert alert-danger">Formato no soportado. Cargue un archivo .csv</div>';
        } else {
            $fh = fopen($tmp, 'r');
            if (!$fh) {
                $message = '<div class="alert alert-danger">No se pudo abrir el archivo.</div>';
            } else {
                // Detectar delimitador usando primera línea cruda
                $first = fgets($fh);
                if ($first === false) {
                    $message = '<div class="alert alert-danger">El archivo esta vacío.</div>';
                } else {
                    // Remover BOM si existe
                    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
                    $delim = detect_delimiter($first);
                    // Parsear cabecera como CSV con el delimitador detectado
                    $headers = str_getcsv($first, $delim);
                    $map = [];
                    foreach ($headers as $idx => $h) {
                        $map[$idx] = normalize_key($h);
                    }
                    // Campos requeridos
                    $required = ['documento_empleado','tipo','fecha_inicio','hora_inicio','fecha_fin','hora_fin'];
                    $missing = array_diff($required, $map);
                    if (!empty($missing)) {
                        $faltantes = implode(', ', $missing);
                        $message = '<div class="alert alert-danger">Faltan columnas requeridas en el CSV: ' . htmlspecialchars($faltantes) . '</div>';
                    } else {
                        // Procesar filas
                        // Recolocar puntero justo despues de la cabecera leída
                        // (ya estamos despues de fgets, así que seguimos)
                        $linea = 1;
                        while (($row = fgetcsv($fh, 0, $delim)) !== false) {
                            $linea++;
                            if (count($row) === 1 && trim($row[0]) === '') {
                                continue; // saltar líneas vacías
                            }
                            $data = [];
                            foreach ($row as $idx => $val) {
                                $key = $map[$idx] ?? null;
                                if ($key === null) continue;
                                $data[$key] = trim((string)$val);
                            }
                            // Validar requeridos
                            $ok = true;
                            foreach ($required as $rk) {
                                if (!isset($data[$rk]) || $data[$rk] === '') {
                                    $ok = false; break;
                                }
                            }
                            if (!$ok) {
                                $stats['errores']++;
                                $detalles_errores[] = "Línea $linea: Faltan campos requeridos";
                                continue;
                            }
                            // Validar tipo
                            $tipo = strtolower($data['tipo']);
                            if (!in_array($tipo, ['incapacidad', 'hora_extra'])) {
                                $stats['errores']++;
                                $detalles_errores[] = "Línea $linea: Tipo '$tipo' no valido. Debe ser 'incapacidad' o 'hora_extra'";
                                continue;
                            }
                            // Normalizar datos
                            $documento_empleado = $data['documento_empleado'];
                            $fecha_inicio = $data['fecha_inicio'];
                            $hora_inicio = $data['hora_inicio'];
                            $fecha_fin = $data['fecha_fin'];
                            $hora_fin = $data['hora_fin'];
                            $descripcion = $data['descripcion'] ?? '';
                            
                            // Verificar que el empleado exista
                            $stmt = $pdo->prepare("SELECT id, nombre, apellido FROM empleado WHERE documento = ?");
                            $stmt->execute([$documento_empleado]);
                            $empleado = $stmt->fetch(PDO::FETCH_ASSOC);
                            if (!$empleado) {
                                $stats['errores']++;
                                $detalles_errores[] = "Línea $linea: No existe empleado con documento '$documento_empleado'";
                                continue;
                            }
                            $id_empleado = $empleado['id'];
                            $nombre_empleado = $empleado['nombre'] . ' ' . $empleado['apellido'];
                            
                            // Validar formato de fechas y horas
                            $fecha_inicio_valida = DateTime::createFromFormat('Y-m-d', $fecha_inicio);
                            $fecha_fin_valida = DateTime::createFromFormat('Y-m-d', $fecha_fin);
                            $hora_inicio_valida = DateTime::createFromFormat('H:i', $hora_inicio);
                            $hora_fin_valida = DateTime::createFromFormat('H:i', $hora_fin);
                            
                            if (!$fecha_inicio_valida) {
                                $stats['errores']++;
                                $detalles_errores[] = "Línea $linea: Formato de fecha_inicio '$fecha_inicio' invalido. Debe ser YYYY-MM-DD";
                                continue;
                            }
                            if (!$fecha_fin_valida) {
                                $stats['errores']++;
                                $detalles_errores[] = "Línea $linea: Formato de fecha_fin '$fecha_fin' invalido. Debe ser YYYY-MM-DD";
                                continue;
                            }
                            if (!$hora_inicio_valida) {
                                $stats['errores']++;
                                $detalles_errores[] = "Línea $linea: Formato de hora_inicio '$hora_inicio' invalido. Debe ser HH:MM";
                                continue;
                            }
                            if (!$hora_fin_valida) {
                                $stats['errores']++;
                                $detalles_errores[] = "Línea $linea: Formato de hora_fin '$hora_fin' invalido. Debe ser HH:MM";
                                continue;
                            }
                            
                            // Insertar
                            try {
                                $ins = $pdo->prepare("INSERT INTO novedades (id_empleado, tipo, fecha_inicio, hora_inicio, fecha_fin, hora_fin, descripcion) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                $ins->execute([$id_empleado, $tipo, $fecha_inicio, $hora_inicio, $fecha_fin, $hora_fin, $descripcion]);
                                $stats['insertados']++;
                                
                                // Agregar detalles del registro exitoso
                                $detalles_exitosos[] = [
                                    'empleado' => $nombre_empleado,
                                    'documento' => $documento_empleado,
                                    'tipo' => $tipo,
                                    'fecha_inicio' => $fecha_inicio,
                                    'hora_inicio' => $hora_inicio,
                                    'fecha_fin' => $fecha_fin,
                                    'hora_fin' => $hora_fin,
                                    'descripcion' => $descripcion
                                ];
                            } catch (Exception $e) {
                                $stats['errores']++;
                                $detalles_errores[] = "Línea $linea: Error al insertar novedad para '$nombre_empleado' - " . $e->getMessage();
                            }
                        }
                        fclose($fh);
                        $message = '<div class="alert alert-info">Importación finalizada. Insertados: ' . $stats['insertados'] . ' | Errores: ' . $stats['errores'] . '.</div>';
                        
                        // Mostrar detalles de registros exitosos
                        if (!empty($detalles_exitosos)) {
                            $message .= '<div class="alert alert-success mt-3" style="background-color: rgba(40, 167, 69, 0.1); border: 1px solid rgba(40, 167, 69, 0.3);"><h6>Novedades registradas exitosamente:</h6><div class="table-responsive"><table class="table table-sm" style="background-color: transparent; border: none;"><thead><tr><th>Empleado</th><th>Documento</th><th>Tipo</th><th>Fecha Inicio</th><th>Hora Inicio</th><th>Fecha Fin</th><th>Hora Fin</th><th>Descripción</th></tr></thead><tbody>';
                            foreach ($detalles_exitosos as $registro) {
                                $message .= '<tr style="background-color: transparent; border: none;">';
                                $message .= '<td style="background-color: transparent; border: none;">' . htmlspecialchars($registro['empleado']) . '</td>';
                                $message .= '<td style="background-color: transparent; border: none;">' . htmlspecialchars($registro['documento']) . '</td>';
                                $message .= '<td style="background-color: transparent; border: none;">' . htmlspecialchars($registro['tipo']) . '</td>';
                                $message .= '<td style="background-color: transparent; border: none;">' . htmlspecialchars($registro['fecha_inicio']) . '</td>';
                                $message .= '<td style="background-color: transparent; border: none;">' . htmlspecialchars($registro['hora_inicio']) . '</td>';
                                $message .= '<td style="background-color: transparent; border: none;">' . htmlspecialchars($registro['fecha_fin']) . '</td>';
                                $message .= '<td style="background-color: transparent; border: none;">' . htmlspecialchars($registro['hora_fin']) . '</td>';
                                $message .= '<td style="background-color: transparent; border: none;">' . htmlspecialchars($registro['descripcion']) . '</td>';
                                $message .= '</tr>';
                            }
                            $message .= '</tbody></table></div></div>';
                        }
                        
                        // Mostrar detalles de errores si hay alguno
                        if (!empty($detalles_errores)) {
                            $message .= '<div class="alert alert-warning mt-3" style="background-color: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.3);"><h6>Detalles de errores:</h6><ul class="mb-0" style="background-color: rgba(255, 255, 255, 0.5); padding: 10px; border-radius: 5px;">';
                            foreach ($detalles_errores as $error) {
                                $message .= '<li>' . htmlspecialchars($error) . '</li>';
                            }
                            $message .= '</ul></div>';
                        }
                    }
                }
            }
        }
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Importar Novedades</h1>
</div>
<?php if (!empty($message)) echo $message; ?>

<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Cargar archivo CSV</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <input type="file" class="form-control" id="archivo" name="archivo" accept=".csv" required>
                        <label for="archivo" class="form-label">Seleccione un archivo .csv</label>
                        <div class="form-text">
                            Columnas requeridas: documento_empleado, tipo, fecha_inicio, hora_inicio, fecha_fin, hora_fin. Columna opcional: descripcion.
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Importar</button>
                    <a href="?module=importarNovedades&action=plantilla" class="btn btn-outline-secondary">Descargar plantilla CSV</a>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">Instrucciones</h5>
    </div>
    <div class="card-body">
        <ul class="mb-0">
            <li>El archivo debe ser CSV (separado por coma, punto y coma o tab). El sistema intenta detectar el delimitador automaticamente.</li>
            <li>El campo tipo debe ser "incapacidad" o "hora_extra".</li>
            <li>Las fechas deben estar en formato YYYY-MM-DD y las horas en formato HH:MM.</li>
            <li>El documento_empleado debe corresponder a un empleado existente en el sistema.</li>
            <li>La descripción es opcional y puede dejarse vacía.</li>
        </ul>
    </div>
</div>

<script>
document.getElementById('archivo')?.addEventListener('change', function(e) {
  const f = e.target.files[0];
  if (!f) return;
  const ok = /\.csv$/i.test(f.name);
  if (!ok) {
    alert('Seleccione un archivo con extensión .csv');
    e.target.value = '';
  }
});
</script>