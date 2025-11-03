<?php
if (!function_exists('getDBConnection')) { require_once __DIR__ . '/../root/config.php'; }
require_once __DIR__ . '/../root/utils.php';
if (!isset($_COOKIE['auth']) || Utils::leerCookie('auth') === false) {
    header("Location: {$_URL_}/login.php");
    exit;
}
$pdo = getDBConnection();

// Info de usuario y rol
$__user = Utils::getCurrentUser();
$currentUserId = (int)($__user['id'] ?? 0);
$cargo = (int)($__user['id_cargo'] ?? 0);
$isAdmin = in_array($cargo, [1,2], true);

$message = '';
$comprobante = null;
$selected_id = 0;

// Filtros dependientes para generar comprobante (empleado -> año -> mes)
$empId = (int)($_GET['emp'] ?? 0);
$year  = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);
// Forzar empleado actual si no es admin
if (!$isAdmin) {
    $empId = $currentUserId;
}

// Empleados con liquidaciones aprobadas (restringir listado para no admins)
if ($isAdmin) {
    $stmt = $pdo->query("
       SELECT DISTINCT e.id, e.nombre, e.apellido
       FROM empleado e
       JOIN liquidacion l ON l.id_empleado = e.id
       JOIN aprobacion a ON a.id_liquidacion = l.id
       WHERE a.estado = 'aprobada'
       ORDER BY e.nombre
    ");
    $empleadosAprob = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stEmp = $pdo->prepare("
       SELECT DISTINCT e.id, e.nombre, e.apellido
       FROM empleado e
       JOIN liquidacion l ON l.id_empleado = e.id
       JOIN aprobacion a ON a.id_liquidacion = l.id
       WHERE a.estado = 'aprobada' AND e.id = ?
       ORDER BY e.nombre
    ");
    $stEmp->execute([$currentUserId]);
    $empleadosAprob = $stEmp->fetchAll(PDO::FETCH_ASSOC);
}

// Años disponibles para el empleado
$years = [];
if ($empId > 0) {
   $st = $pdo->prepare("
       SELECT DISTINCT l.ano
       FROM liquidacion l
       JOIN aprobacion a ON l.id = a.id_liquidacion
       WHERE a.estado = 'aprobada' AND l.id_empleado = ?
       ORDER BY l.ano ASC
   ");
   $st->execute([$empId]);
   $years = $st->fetchAll(PDO::FETCH_COLUMN);
}

// Meses disponibles para el empleado y año
$months = [];
if ($empId > 0 && $year > 0) {
   $st = $pdo->prepare("
       SELECT DISTINCT l.mes, l.tipo_liquidacion
       FROM liquidacion l
       JOIN aprobacion a ON l.id = a.id_liquidacion
       WHERE a.estado = 'aprobada' AND l.id_empleado = ? AND l.ano = ?
       ORDER BY l.mes ASC
   ");
   $st->execute([$empId, $year]);
   $months_data = $st->fetchAll(PDO::FETCH_ASSOC);
   
   // Procesar meses para incluir primas
   foreach ($months_data as $m) {
       if ($m['tipo_liquidacion'] === 'prima') {
           $months[] = $m['mes'] . '-prima';
       } else {
           $months[] = (int)$m['mes'];
       }
   }
}

// Liquidación seleccionada (si aplica)
$selected_liquidacion_id = 0;
$selected_liquidacion_total = 0.0;
$es_prima_seleccion = isset($_GET['prima']) && $_GET['prima'] === '1';

if ($empId > 0 && $year > 0 && $month > 0) {
   if ($es_prima_seleccion) {
       // Buscar liquidación de prima
       $st = $pdo->prepare("
           SELECT l.id, l.total_neto
           FROM liquidacion l
           JOIN aprobacion a ON l.id = a.id_liquidacion
           WHERE a.estado = 'aprobada' AND l.id_empleado = ? AND l.ano = ? AND l.mes = ? AND l.tipo_liquidacion = 'prima'
           ORDER BY l.id DESC
           LIMIT 1
       ");
       $st->execute([$empId, $year, $month]);
   } else {
       // Buscar liquidación mensual
       $st = $pdo->prepare("
           SELECT l.id, l.total_neto
           FROM liquidacion l
           JOIN aprobacion a ON l.id = a.id_liquidacion
           WHERE a.estado = 'aprobada' AND l.id_empleado = ? AND l.ano = ? AND l.mes = ? AND l.tipo_liquidacion = 'mensual'
           ORDER BY l.id DESC
           LIMIT 1
       ");
       $st->execute([$empId, $year, $month]);
   }
   
   if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
       $selected_liquidacion_id = (int)$row['id'];
       $selected_liquidacion_total = (float)$row['total_neto'];
   }
}

// Nombres de meses para visualización
$meses_es = [
   1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
   5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
   9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

// Manejar POST para generar comprobante
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_liquidacion = (int)($_POST['id_liquidacion'] ?? 0);
    
    if ($id_liquidacion > 0) {
        // Obtener detalles completos
        if ($isAdmin) {
            $stmt = $pdo->prepare("
                SELECT l.*, e.nombre, e.apellido, a.observaciones
                FROM liquidacion l
                JOIN empleado e ON l.id_empleado = e.id
                JOIN aprobacion a ON l.id = a.id_liquidacion
                WHERE l.id = ? AND a.estado = 'aprobada'
            ");
            $stmt->execute([$id_liquidacion]);
        } else {
            $stmt = $pdo->prepare("
                SELECT l.*, e.nombre, e.apellido, a.observaciones
                FROM liquidacion l
                JOIN empleado e ON l.id_empleado = e.id
                JOIN aprobacion a ON l.id = a.id_liquidacion
                WHERE l.id = ? AND a.estado = 'aprobada' AND l.id_empleado = ?
            ");
            $stmt->execute([$id_liquidacion, $currentUserId]);
        }
        $liq = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($liq) {
            // Obtener deducciones y aportes
            $ded_stmt = $pdo->prepare("SELECT tipo, monto FROM deducciones WHERE id_liquidacion = ?");
            $ded_stmt->execute([$id_liquidacion]);
            $deducciones = $ded_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $apr_stmt = $pdo->prepare("SELECT tipo, monto FROM aportes WHERE id_liquidacion = ?");
            $apr_stmt->execute([$id_liquidacion]);
            $aportes = $apr_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Filtrar y renombrar aportes visibles (solo Salud y Pensión)
            $saludPat = 0.0;
            $pensionPat = 0.0;
            foreach ($aportes as $apr) {
                $tipoApr = strtolower((string)($apr['tipo'] ?? ''));
                if (in_array($tipoApr, ['salud_patronal','salud'], true)) {
                    $saludPat += (float)$apr['monto'];
                } elseif (in_array($tipoApr, ['pension_patronal','pension'], true)) {
                    $pensionPat += (float)$apr['monto'];
                }
            }
            $aportes_visibles_total = $saludPat + $pensionPat;

            // Generar HTML comprobante
            $es_prima = ($liq['tipo_liquidacion'] ?? 'mensual') === 'prima';
            
            $html = '<div class="container">
                <div class="row">
                    <div class="col-md-12 text-center">
                        <img src="' . $_URL_ . '/dist/images/logo.png" alt="Logo" style="width:250px;height:250px;object-fit:contain;" />
                        <h2>Comprobante de ' . ($es_prima ? 'Prima Semestral' : 'Liquidación') . '</h2>
                        <p><strong>Parroquia de Asis</strong></p>
                        <p>Empleado: ' . htmlspecialchars($liq['nombre'] . ' ' . $liq['apellido']) . '</p>';
            
            if ($es_prima) {
                $periodo = ($liq['mes'] == 6) ? 'Enero-Junio' : 'Julio-Diciembre';
                $html .= '<p>Periodo: Prima ' . $periodo . '/' . $liq['ano'] . '</p>';
            } else {
                $meses_es = [
                    1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
                    5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
                    9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
                ];
                $mn = $meses_es[(int)$liq['mes']] ?? $liq['mes'];
                $html .= '<p>Periodo: ' . $mn . '/' . $liq['ano'] . '</p>';
            }
            
            $html .= '<p>Fecha: ' . date('d/m/Y') . '</p>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-md-6">
                        <h5 class="text-primary">Devengos</h5>
                        <table class="table">';
            
            if ($es_prima) {
                $html .= '<tr><td>Prima Semestral:</td><td class="text-end">$ ' . number_format($liq['devengos'], 0, ',', '.') . '</td></tr>';
                // Para primas, no mostrar extras negativos ya que no hay cálculo base
                $html .= '<tr><td colspan="2" class="text-muted small">Las primas no tienen concepto de extras</td></tr>';
            } else {
                $html .= '<tr><td>Salario Base:</td><td class="text-end">$ ' . number_format($liq['salario_base'], 0, ',', '.') . '</td></tr>';
                
                // Mostrar incapacidad si existe
                if (isset($liq['incapacidad_dias']) && $liq['incapacidad_dias'] > 0) {
                    $html .= '<tr><td>Incapacidad (' . $liq['incapacidad_dias'] . ' días):</td><td class="text-end">$ ' . number_format($liq['incapacidad_valor'], 0, ',', '.') . '</td></tr>';
                }
                
                // Calcular valores de extras
                $horas_extras_valor = isset($liq['horas_extras_valor']) ? (float)$liq['horas_extras_valor'] : 0;
                $incapacidad_valor = isset($liq['incapacidad_valor']) ? (float)$liq['incapacidad_valor'] : 0;
                $extras_manuales = (float)$liq['devengos'] - (float)$liq['salario_base'] - $horas_extras_valor - $incapacidad_valor;
                
                // Calcular total de horas extras (incluyendo extras manuales si hay horas extras registradas)
                $total_horas_extras = $horas_extras_valor;
                
                // Si hay horas extras registradas, agregar los extras manuales al total
                if (isset($liq['horas_extras_cantidad']) && $liq['horas_extras_cantidad'] > 0 && $extras_manuales > 0) {
                    $total_horas_extras += $extras_manuales;
                }
                
                // Mostrar horas extras (incluyendo extras manuales si aplica)
                if (isset($liq['horas_extras_cantidad']) && $liq['horas_extras_cantidad'] > 0) {
                    $html .= '<tr><td>Horas Extras (' . number_format($liq['horas_extras_cantidad'], 1, ',', '.') . ' h):</td><td class="text-end">$ ' . number_format($total_horas_extras, 0, ',', '.') . '</td></tr>';
                }
                
                // Mostrar solo extras manuales si no hay horas extras registradas
                if (!isset($liq['horas_extras_cantidad']) || $liq['horas_extras_cantidad'] == 0) {
                    if ($extras_manuales > 0) {
                        $html .= '<tr><td>Extras Manuales:</td><td class="text-end">$ ' . number_format($extras_manuales, 0, ',', '.') . '</td></tr>';
                    } elseif ($extras_manuales < 0) {
                        $html .= '<tr><td>Descuentos Manuales:</td><td class="text-end">$ ' . number_format(abs($extras_manuales), 0, ',', '.') . '</td></tr>';
                    }
                }
            }
            
            $html .= '<tr><td><strong>Total Devengos:</strong></td><td class="text-end"><strong>$ ' . number_format($liq['devengos'], 0, ',', '.') . '</strong></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h5 class="text-danger">Deducciones</h5>
                        <table class="table">';
            foreach ($deducciones as $ded) {
                $tipo = strtolower($ded['tipo'] ?? '');
                if ($tipo === 'salud') {
                    $label = 'Salud';
                } elseif ($tipo === 'pension') {
                    $label = 'Pensión';
                } else {
                    $label = ucwords(str_replace('_', ' ', (string)$ded['tipo']));
                }
                $html .= '<tr><td>' . htmlspecialchars($label) . ':</td><td class="text-end">$ ' . number_format($ded['monto'], 0, ',', '.') . '</td></tr>';
            }
            $html .= '<tr><td><strong>Total Deducciones:</strong></td><td class="text-end"><strong>$ ' . number_format($liq['deducciones_total'], 0, ',', '.') . '</strong></td></tr>
                        </table>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-md-6">
                        <h5 class="text-info">Aportes</h5>
                        <table class="table">';
            
            if (!$es_prima) {
                $html .= '<tr><td>Salud:</td><td class="text-end">$ ' . number_format($saludPat, 0, ',', '.') . '</td></tr>';
                $html .= '<tr><td>Pensión:</td><td class="text-end">$ ' . number_format($pensionPat, 0, ',', '.') . '</td></tr>';
                $html .= '<tr><td><strong>Total Aportes:</strong></td><td class="text-end"><strong>$ ' . number_format($aportes_visibles_total, 0, ',', '.') . '</strong></td></tr>';
            } else {
                $html .= '<tr><td colspan="2" class="text-center text-muted">Las primas no generan aportes patronales</td></tr>';
            }
            
            $html .= '</table>
                    </div>
                    <div class="col-md-6">
                        <h5>Total Neto</h5>
                        <p class="h4 text-success">$ ' . number_format($liq['total_neto'], 0, ',', '.') . '</p>
                        <p>Aprobado el: ' . date('d/m/Y', strtotime($liq['fecha_liquidacion'])) . '</p>
                        <p>Observaciones: ' . htmlspecialchars($liq['observaciones']) . '</p>
                    </div>
                </div>
            </div>';
            
            // Insert to BD
            $stmt = $pdo->prepare("INSERT INTO comprobante (id_liquidacion, contenido) VALUES (?, ?)");
            $stmt->execute([$id_liquidacion, $html]);
            
            $comprobante = $html;
            $selected_id = $id_liquidacion;
            $message = '<div class="alert alert-success">Comprobante generado. <button onclick="printComprobante()">Imprimir</button></div>';
        } else {
            $message = '<div class="alert alert-danger">Liquidacion no encontrada o no aprobada.</div>';
        }
    }
}

// El resto del contenido (form, results) se mantiene igual, pero sin include bloques ya que index los maneja
?>

<!-- Generar Comprobante (filtros dependientes) -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Generar Comprobante</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="mb-3">
                    <input type="hidden" name="module" value="comprobante">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="mb-2">
                                <select class="form-control" id="emp" name="emp">
                                    <option value="" disabled <?php echo $empId === 0 ? 'selected' : ''; ?>>Seleccionar empleado</option>
                                    <?php foreach ($empleadosAprob as $e): ?>
                                        <option value="<?php echo $e['id']; ?>" <?php echo $empId === (int)$e['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($e['nombre'] . ' ' . $e['apellido']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <label for="emp" class="form-label">Empleado</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-2">
                                <select class="form-control" id="year" name="year" <?php echo empty($years) ? 'disabled' : ''; ?>>
                                    <option value="" disabled <?php echo $year === 0 ? 'selected' : ''; ?>>Seleccionar año</option>
                                    <?php foreach ($years as $y): ?>
                                        <option value="<?php echo (int)$y; ?>" <?php echo $year === (int)$y ? 'selected' : ''; ?>>
                                            <?php echo (int)$y; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <label for="year" class="form-label">Año</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-2">
                                <select class="form-control" id="month" name="month" <?php echo empty($months) ? 'disabled' : ''; ?>>
                                    <option value="" disabled <?php echo $month === 0 ? 'selected' : ''; ?>>Seleccionar mes</option>
                                    <?php foreach ($months as $m): ?>
                                        <?php
                                        if (strpos($m, '-prima') !== false) {
                                            $mes_num = (int)str_replace('-prima', '', $m);
                                            $mes_text = ($mes_num == 6) ? 'Junio - Prima' : 'Diciembre - Prima';
                                            $selected = ($month === $mes_num && isset($_GET['prima']) && $_GET['prima'] === '1') ? 'selected' : '';
                                        } else {
                                            $mes_num = (int)$m;
                                            $mes_text = $meses_es[$mes_num] ?? $m;
                                            $selected = ($month === $mes_num && (!isset($_GET['prima']) || $_GET['prima'] !== '1')) ? 'selected' : '';
                                        }
                                        ?>
                                        <option value="<?php echo $m; ?>" <?php echo $selected; ?>>
                                            <?php echo $mes_text; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <label for="month" class="form-label">Mes</label>
                            </div>
                        </div>
                    </div>
                </form>
                <script>
document.addEventListener('DOMContentLoaded', function() {
  const form = document.querySelector('form[method="GET"]');
  const emp = document.getElementById('emp');
  const year = document.getElementById('year');
  const month = document.getElementById('month');

  if (emp) {
    emp.addEventListener('change', function() {
      if (year) year.selectedIndex = 0;
      if (month) month.selectedIndex = 0;
      form.submit();
    });
  }
  if (year) {
    year.addEventListener('change', function() {
      if (month) month.selectedIndex = 0;
      form.submit();
    });
  }
  if (month) {
    month.addEventListener('change', function() {
      const selectedOption = month.options[month.selectedIndex];
      const url = new URL(window.location);
      
      // Determinar si es prima según el valor seleccionado
      if (selectedOption && selectedOption.value && selectedOption.value.includes('-prima')) {
        url.searchParams.set('prima', '1');
        const mesNum = selectedOption.value.replace('-prima', '');
        url.searchParams.set('month', mesNum);
      } else {
        url.searchParams.delete('prima');
        url.searchParams.set('month', selectedOption.value);
      }
      
      window.location.href = url.toString();
    });
  }
});
                </script>

                <?php if ($selected_liquidacion_id > 0): ?>
                    <div class="alert alert-info d-flex justify-content-between align-items-center">
                        <div>
                            <div><strong>Empleado:</strong>
                                <?php
                                $selName = '';
                                foreach ($empleadosAprob as $e) {
                                    if ((int)$e['id'] === $empId) { $selName = trim(($e['nombre'] ?? '').' '.($e['apellido'] ?? '')); break; }
                                }
                                echo htmlspecialchars($selName);
                                ?>
                            </div>
                            <div><strong>Período:</strong> <?php
                                if ($es_prima_seleccion) {
                                    echo 'Prima ' . (($month == 6) ? 'Enero-Junio' : 'Julio-Diciembre') . ' ' . $year;
                                } else {
                                    echo htmlspecialchars(($meses_es[$month] ?? $month) . ' ' . $year);
                                }
                            ?></div>
                        </div>
                        <div class="text-end">
                            <div><strong>Total Neto:</strong> $ <?php echo number_format($selected_liquidacion_total, 0, ',', '.'); ?></div>
                        </div>
                    </div>
                    <form method="POST" class="mt-2">
                        <input type="hidden" name="id_liquidacion" value="<?php echo $selected_liquidacion_id; ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-receipt me-1"></i> Generar Comprobante
                        </button>
                    </form>
                <?php else: ?>
                    <div class="text-muted small">Seleccione empleado, año y mes para habilitar la generación del comprobante.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($comprobante): ?>
    <!-- Comprobante Printable -->
    <div id="printable" class="card">
        <div class="card-header no-print">
            <h5 class="card-title">Comprobante Generado</h5>
            <button onclick="printComprobante()" class="btn btn-secondary no-print">Imprimir</button>
        </div>
        <div class="card-body">
            <?php echo $comprobante; ?>
        </div>
    </div>
    <script>
        function printComprobante() {
            const printable = document.getElementById('printable').innerHTML;
            const original = document.body.innerHTML;
            document.body.innerHTML = '<html><head><title>Comprobante</title>' +
                '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">' +
                '<style>.no-print{display:none !important;}@media print{.no-print{display:none !important;} .row.mt-4{display:grid !important; grid-template-columns:1fr 1fr; column-gap:16px; align-items:start;} .row.mt-4 > [class^="col-"], .row.mt-4 > [class*=" col-"]{width:auto !important; float:none !important;} .container{max-width:100% !important; padding:0 8mm !important;} .card,.card-body{border:none !important; box-shadow:none !important;} h2,h5{margin:0 0 8px 0 !important;} table{width:100% !important;} table td:last-child, table th:last-child{text-align:right !important;} }</style>' +
                '</head><body>' + printable + '</body></html>';
            window.print();
            document.body.innerHTML = original;
            location.reload();
        }
    </script>
<?php endif; ?>