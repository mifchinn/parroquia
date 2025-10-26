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
    header('Content-Disposition: attachment; filename="plantilla_empleados.csv"');
    echo "nombre,apellido,password,id_tipodoc,documento,id_cargo,id_banco,id_ciudad,id_tipcue,cuenta,salario\n";
    echo "Juan,Campos,temporal123,1,123456789,1,1,1,1,000123456,1200000\n";
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
                    $message = '<div class="alert alert-danger">El archivo está vacío.</div>';
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
                    $required = ['nombre','apellido','id_tipodoc','documento','id_cargo','id_banco','id_ciudad','id_tipcue','cuenta','salario'];
                    $missing = array_diff($required, $map);
                    if (!empty($missing)) {
                        $faltantes = implode(', ', $missing);
                        $message = '<div class="alert alert-danger">Faltan columnas requeridas en el CSV: ' . htmlspecialchars($faltantes) . '</div>';
                    } else {
                        // Procesar filas
                        // Recolocar puntero justo después de la cabecera leída
                        // (ya estamos después de fgets, así que seguimos)
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
                                continue;
                            }
                            // Normalizar tipos
                            $id_tipodoc = (int)$data['id_tipodoc'];
                            $id_cargo = (int)$data['id_cargo'];
                            $id_banco = (int)$data['id_banco'];
                            $id_ciudad = (int)$data['id_ciudad'];
                            $id_tipcue = (int)$data['id_tipcue'];
                            $salario = (float)str_replace([',', ' '], ['', ''], $data['salario']);
                            $documento = $data['documento'];
                            $nombre = $data['nombre'];
                            $apellido = $data['apellido'];
                            $cuenta = $data['cuenta'];
                            $password = $data['password'] ?? 'temporal123';
                            // Verificar duplicado por documento
                            $stmt = $pdo->prepare("SELECT id FROM empleado WHERE documento = ?");
                            $stmt->execute([$documento]);
                            $exists = $stmt->fetchColumn();
                            if ($exists) {
                                $stats['saltados']++;
                                continue;
                            }
                            // Insertar
                            try {
                                $hash = password_hash($password, PASSWORD_DEFAULT);
                                $ins = $pdo->prepare("INSERT INTO empleado (nombre, apellido, password, id_tipodoc, documento, id_cargo, id_banco, id_ciudad, id_tipcue, cuenta, salario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $ins->execute([$nombre, $apellido, $hash, $id_tipodoc, $documento, $id_cargo, $id_banco, $id_ciudad, $id_tipcue, $cuenta, $salario]);
                                $stats['insertados']++;
                            } catch (Exception $e) {
                                $stats['errores']++;
                            }
                        }
                        fclose($fh);
                        $message = '<div class="alert alert-info">Importación finalizada. Insertados: ' . $stats['insertados'] . ' | Duplicados: ' . $stats['saltados'] . ' | Errores: ' . $stats['errores'] . '.</div>';
                    }
                }
            }
        }
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Importar Empleados</h1>
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
                            Columnas requeridas: nombre, apellido, id_tipodoc, documento, id_cargo, id_banco, id_ciudad, id_tipcue, cuenta, salario. Columna opcional: password.
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Importar</button>
                    <a href="?module=importarEmpleados&action=plantilla" class="btn btn-outline-secondary">Descargar plantilla CSV</a>
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
            <li>El archivo debe ser CSV (separado por coma, punto y coma o tab). El sistema intenta detectar el delimitador automáticamente.</li>
            <li>Si la columna password no se incluye, se asignará "temporal123" para el usuario importado.</li>
            <li>Los registros con documento existente se omiten como duplicados.</li>
            <li>Verifique que los IDs referenciados (tipo documento, cargo, banco, ciudad, tipo cuenta) existan en sus tablas correspondientes.</li>
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