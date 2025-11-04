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

// Obtener tasas parametrizadas desde la configuración
$tasas_config = [];

try {
    $stmt = $pdo->query("SELECT tasasalud, tasapension, factor_extras, incapacidad_primeros_dias, incapacidad_siguiente_dias, incapacidad_limite_dias FROM configuracion WHERE id = 1");
    $config_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($config_data) {
        $tasas_config = [
            'tasasalud' => (float)$config_data['tasasalud'],
            'tasapension' => (float)$config_data['tasapension'],
            'factor_extras' => (float)$config_data['factor_extras'],
            'incapacidad_primeros_dias' => (float)$config_data['incapacidad_primeros_dias'],
            'incapacidad_siguiente_dias' => (float)$config_data['incapacidad_siguiente_dias'],
            'incapacidad_limite_dias' => (int)$config_data['incapacidad_limite_dias']
        ];
    } else {
        // Si no hay configuración, mostrar error
        die("Error: No se encontró la configuración del sistema. Por favor configure las tasas en el módulo de configuración.");
    }
} catch (Exception $e) {
    // Si hay error, mostrar mensaje
    die("Error al obtener la configuración: " . $e->getMessage());
}

// Función para calcular horas extras de un empleado en un período
function calcularHorasExtras($pdo, $id_empleado, $ano, $mes) {
    $stmt = $pdo->prepare("
        SELECT fecha_inicio, hora_inicio, fecha_fin, hora_fin
        FROM novedades
        WHERE id_empleado = ? AND tipo = 'hora_extra'
        AND YEAR(fecha_inicio) = ? AND MONTH(fecha_inicio) = ?
    ");
    $stmt->execute([$id_empleado, $ano, $mes]);
    $horas_extras = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_horas = 0;
    foreach ($horas_extras as $extra) {
        $inicio = new DateTime($extra['fecha_inicio'] . ' ' . $extra['hora_inicio']);
        $fin = new DateTime($extra['fecha_fin'] . ' ' . $extra['hora_fin']);
        $diferencia = $inicio->diff($fin);
        $total_horas += $diferencia->h + ($diferencia->i / 60);
    }
    
    return $total_horas;
}

// Función para calcular incapacidad de un empleado en un período
function calcularIncapacidad($pdo, $id_empleado, $ano, $mes, $salario_base, $config) {
    $stmt = $pdo->prepare("
        SELECT fecha_inicio, fecha_fin
        FROM novedades
        WHERE id_empleado = ? AND tipo = 'incapacidad'
        AND YEAR(fecha_inicio) = ? AND MONTH(fecha_inicio) = ?
    ");
    $stmt->execute([$id_empleado, $ano, $mes]);
    $incapacidades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_dias = 0;
    $total_valor = 0;
    
    foreach ($incapacidades as $incapacidad) {
        $inicio = new DateTime($incapacidad['fecha_inicio']);
        $fin = new DateTime($incapacidad['fecha_fin']);
        $dias = $inicio->diff($fin)->days + 1; // Incluir ambos días
        
        // Limitar al máximo configurado
        $dias = min($dias, $config['incapacidad_limite_dias']);
        $total_dias += $dias;
        
        // Calcular valor usando salario base directamente para evitar redondeo
        // Salario mensual / 30 * días * porcentaje = Salario mensual * días * porcentaje / 30
        if ($dias <= 2) {
            // Primeros 2 días: paga el empleador
            $valor = ($salario_base * $dias * $config['incapacidad_primeros_dias']) / 3000;
        } else {
            // Primeros 2 días: paga el empleador
            $valor_primeros = ($salario_base * 2 * $config['incapacidad_primeros_dias']) / 3000;
            // Días restantes: paga la EPS
            $dias_restantes = $dias - 2;
            $valor_restantes = ($salario_base * $dias_restantes * $config['incapacidad_siguiente_dias']) / 3000;
            $valor = $valor_primeros + $valor_restantes;
        }
        
        $total_valor += $valor;
    }
    
    return [
        'dias' => $total_dias,
        'valor' => $total_valor
    ];
}

$message = '';
$results = null;
$guardado = false;

// AJAX: meses ocupados para un empleado y año
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ocupados') {
    header('Content-Type: application/json');
    $ajax_emp = (int)($_GET['id_empleado'] ?? 0);
    $ajax_ano = (int)($_GET['ano'] ?? 0);
    if ($ajax_emp > 0 && $ajax_ano > 0) {
        // Obtener todos los meses ocupados, tanto mensuales como de primas
        $stmt = $pdo->prepare("SELECT mes, tipo_liquidacion FROM liquidacion WHERE id_empleado = ? AND ano = ? ORDER BY mes ASC");
        $stmt->execute([$ajax_emp, $ajax_ano]);
        $ocupados_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $ocupados = [];
        foreach ($ocupados_data as $row) {
            if ($row['tipo_liquidacion'] === 'prima') {
                $ocupados[] = $row['mes'] . '-prima';
            } else {
                $ocupados[] = (int)$row['mes'];
            }
        }
        
        echo json_encode($ocupados);
    } else {
        echo json_encode([]);
    }
    exit;
}

// Obtener empleados con salario mayor a 0
$empleados = $pdo->query("
    SELECT e.id, e.nombre, e.apellido, e.salario, e.documento
    FROM empleado e
    WHERE e.salario > 0
    ORDER BY e.nombre
")->fetchAll(PDO::FETCH_ASSOC);

// AJAX: Obtener empleados disponibles para liquidación en un mes/año específico
if (isset($_GET['ajax']) && $_GET['ajax'] === 'liquidacionMasivaEmpleados') {
    header('Content-Type: application/json');
    $ajax_ano = (int)($_GET['ano'] ?? 0);
    $ajax_mes = (int)($_GET['mes'] ?? 0);
    
    if ($ajax_ano > 0 && $ajax_mes >= 1 && $ajax_mes <= 12) {
        // Obtener empleados con salario > 0 que no tengan liquidación en ese mes/año
        $stmt = $pdo->prepare("
            SELECT e.id, e.nombre, e.apellido, e.documento, e.salario
            FROM empleado e
            WHERE e.salario > 0
            AND e.id NOT IN (
                SELECT l.id_empleado
                FROM liquidacion l
                WHERE l.mes = ? AND l.ano = ?
            )
            ORDER BY e.nombre
        ");
        $stmt->execute([$ajax_mes, $ajax_ano]);
        $empleados_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($empleados_disponibles);
    } else {
        echo json_encode([]);
    }
    exit;
}

// Manejar POST para calcular o guardar liquidacion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   $modo = $_POST['modo'] ?? 'calcular';
   $id_empleado = (int)($_POST['id_empleado'] ?? 0);
   $mes_valor = $_POST['mes'] ?? '';
   $ano = (int)($_POST['ano'] ?? 0);
   $extras = (float)($_POST['extras'] ?? 0);
   
   // Procesar el valor del mes para determinar si es prima
   if (strpos($mes_valor, '-prima') !== false) {
       $mes = (int)str_replace('-prima', '', $mes_valor);
       $tipo_liquidacion = 'prima';
   } else {
       $mes = (int)$mes_valor;
       $tipo_liquidacion = 'mensual';
   }

   if ($id_empleado > 0 && $mes >= 1 && $mes <= 12 && $ano >= 2024 && $extras >= 0) {
        // Obtener salario y nombre empleado
        $stmt = $pdo->prepare("SELECT nombre, apellido, salario FROM empleado WHERE id = ?");
        $stmt->execute([$id_empleado]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        $salario_base = (float)($emp['salario'] ?? 0);

        if ($salario_base > 0) {
            // Calcular incapacidad del período primero para determinar días trabajados reales
            if ($tipo_liquidacion === 'mensual') {
                $incapacidad_data = calcularIncapacidad($pdo, $id_empleado, $ano, $mes, $salario_base, $tasas_config);
                $incapacidad_dias = $incapacidad_data['dias'];
                
                // Calcular días trabajados reales (30 días - días de incapacidad)
                $dias_trabajados = 30 - $incapacidad_dias;
                
                // Validar que los días estén entre 1 y 30
                if ($dias_trabajados < 1) {
                    $dias_trabajados = 1;
                } elseif ($dias_trabajados > 30) {
                    $dias_trabajados = 30;
                }
            } else {
                // Para prima, los días trabajados no aplican
                $dias_trabajados = 30;
                $incapacidad_dias = 0;
            }
        } else {
            $dias_trabajados = 30;
            $incapacidad_dias = 0;
        }
   } else {
        $dias_trabajados = 30;
        $incapacidad_dias = 0;
   }
   
   if ($tipo_liquidacion === 'prima') {
               // Liquidación de prima semestral
               if ($mes == 6) {
                   // Prima de junio (acumulado enero-junio)
                   $mes_inicio = 1;
                   $mes_fin = 6;
                   $periodo = 'Enero-Junio';
               } elseif ($mes == 12) {
                   // Prima de diciembre (acumulado julio-diciembre)
                   $mes_inicio = 7;
                   $mes_fin = 12;
                   $periodo = 'Julio-Diciembre';
               } else {
                   $message = '<div class="alert alert-danger">La liquidación de prima solo se puede realizar en junio (primer semestre) o diciembre (segundo semestre).</div>';
                   $results = null;
               }
               
               if (isset($periodo)) {
                   // Inicializar variables para prima
                   $devengos = 0;
                   $salud = 0;
                   $pension = 0;
                   $deducciones_total = 0;
                   $total_neto = 0;
                   $salud_patronal = 0;
                   $pension_patronal = 0;
                   $aportes_total = 0;
                   $prima = 0;
                   $cesantias = 0;
                   $vacaciones = 0;
                   $prima_semestral = 0;
                   
                   // Calcular prima semestral acumulada basada en liquidaciones mensuales reales
                   $prima_acumulada = 0;
                   
                   // Buscar liquidaciones mensuales aprobadas en el período
                   $stmt = $pdo->prepare("
                       SELECT l.devengos
                       FROM liquidacion l
                       INNER JOIN aprobacion a ON l.id = a.id_liquidacion
                       WHERE l.id_empleado = ? AND l.ano = ? AND l.mes BETWEEN ? AND ?
                       AND l.tipo_liquidacion = 'mensual' AND a.estado = 'aprobada'
                   ");
                   $stmt->execute([$id_empleado, $ano, $mes_inicio, $mes_fin]);
                   $liquidaciones_mensuales = $stmt->fetchAll(PDO::FETCH_COLUMN);
                   
                   // Sumar los devengos de las liquidaciones mensuales
                   foreach ($liquidaciones_mensuales as $devengos_mensual) {
                       $prima_acumulada += (float)$devengos_mensual;
                   }
                   
                   // Calcular prima (8.33% del total devengado en el semestre)
                   if ($prima_acumulada > 0) {
                       $prima_semestral = $prima_acumulada * 0.0833;
                       $devengos = $prima_semestral + $extras;
                       
                       // Para prima, no se aplican deducciones de salud y pensión
                       $salud = 0;
                       $pension = 0;
                       $deducciones_total = 0;
                       $total_neto = $devengos;
                       
                       // Aportes patronales (no aplican para prima)
                       $salud_patronal = 0;
                       $pension_patronal = 0;
                       $aportes_total = 0;
                       
                       // Prestaciones
                       $prima = $prima_semestral;
                       $cesantias = 0;
                       $vacaciones = 0;
                   } else {
                       // No hay liquidaciones mensuales acumuladas
                       $message = '<div class="alert alert-warning">No existen liquidaciones mensuales aprobadas para el período ' . $periodo . '. Debe realizar y aprobar las liquidaciones mensuales primero antes de calcular la prima semestral.</div>';
                       $results = null;
                   }
                   
               }
           } else {
               // Liquidación mensual normal con ajuste por días trabajados
               // Calcular salario ajustado usando fracciones para evitar redondeo
               $salario_ajustado = ($salario_base * $dias_trabajados) / 30;
               
               // Calcular horas extras del período
               $horas_extras_cantidad = calcularHorasExtras($pdo, $id_empleado, $ano, $mes);
               $valor_hora_extra = ($salario_base * $tasas_config['factor_extras']) / 240; // 240 horas mensuales promedio
               $horas_extras_valor = $horas_extras_cantidad * $valor_hora_extra;
               
               // Ya calculamos la incapacidad arriba, ahora obtenemos el valor
               $incapacidad_valor = $incapacidad_data['valor'];
               
               // Calcular devengos totales
               $devengos = $salario_ajustado + $extras + $horas_extras_valor + $incapacidad_valor;

               // Deducciones empleado usando tasas parametrizadas
               $salud = $devengos * ($tasas_config['tasasalud'] / 100);
               $pension = $devengos * ($tasas_config['tasapension'] / 100);
               $deducciones_total = $salud + $pension;
               $total_neto = $devengos - $deducciones_total;

               // Aportes patronales (solo Salud y Pensión) ajustados por días
               // Usando tasas patronales estándar (8.5% y 12%)
               $salud_patronal = ($salario_base * 85 * $dias_trabajados) / 10000; // 8.5% = 85/10000
               $pension_patronal = ($salario_base * 12 * $dias_trabajados) / 100; // 12% = 12/100
               $aportes_total = $salud_patronal + $pension_patronal;
               
               // Aportes adicionales según el mes (ajustados por días)
               $aportes_adicionales = 0;
               if ($mes == 6 || $mes == 12) {
                   // Junio y diciembre: aporte de prima
                   $prima_aporte = ($salario_base * $dias_trabajados) / 360; // 1/12 = 30/360
                   $aportes_adicionales += $prima_aporte;
               }
               
               if ($mes == 1) {
                   // Enero: aporte de cesantías
                   $cesantias_aporte = ($salario_base * $dias_trabajados) / 360; // 1/12 = 30/360
                   $aportes_adicionales += $cesantias_aporte;
               }
               
               $aportes_total += $aportes_adicionales;

               // Prestaciones (mensuales aproximadas) ajustadas por días
               $prima = ($salario_base * $dias_trabajados) / 360; // 1/12 = 30/360
               $cesantias = ($salario_base * $dias_trabajados) / 360; // 1/12 = 30/360
               $vacaciones = ($salario_base * 15 * $dias_trabajados) / 10800; // 15/360 = 15/360
           }

           // Resultados para vista previa
           $emp_name = $emp ? trim(($emp['nombre'] ?? '') . ' ' . ($emp['apellido'] ?? '')) : ('Empleado #' . $id_empleado);
           $emp_doc = $emp ? ($emp['documento'] ?? '') : '';
           $mes_nombre_map = [
               1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
               5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
               9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
           ];
           $mes_nombre = $mes_nombre_map[(int)$mes] ?? (string)$mes;
           $results = [
               'empleado' => $emp_name,
               'documento' => $emp_doc,
               'mes_ano' => ($tipo_liquidacion === 'prima' ? "Prima $periodo " : $mes_nombre) . '/' . $ano,
               'tipo_liquidacion' => $tipo_liquidacion,
               'salario_base' => $salario_base,
               'dias_trabajados' => $dias_trabajados,
               'extras' => $extras,
               'horas_extras_cantidad' => $horas_extras_cantidad ?? 0,
               'horas_extras_valor' => $horas_extras_valor ?? 0,
               'incapacidad_dias' => $incapacidad_dias ?? 0,
               'incapacidad_valor' => $incapacidad_valor ?? 0,
               'devengos' => $devengos,
               'deducciones' => ['salud' => $salud, 'pension' => $pension],
               'deducciones_total' => $deducciones_total,
               'total_neto' => $total_neto,
               'aportes' => [
                   'salud_patronal' => $salud_patronal,
                   'pension_patronal' => $pension_patronal
               ],
               'aportes_total' => $aportes_total,
               'prestaciones' => ['prima' => $prima, 'cesantias' => $cesantias, 'vacaciones' => $vacaciones]
           ];

           // Bloquear recálculo si ya existe y está en modo "calcular"
           $dupCheck = $pdo->prepare("SELECT COUNT(*) FROM liquidacion WHERE id_empleado = ? AND mes = ? AND ano = ? AND tipo_liquidacion = ?");
           $dupCheck->execute([$id_empleado, $mes, $ano, $tipo_liquidacion]);
           if ((int)$dupCheck->fetchColumn() > 0 && $modo === 'calcular') {
               $meses_es = [
   1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
   5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
   9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
];
$mn = $meses_es[(int)$mes] ?? (string)$mes;
$tipo_desc = $tipo_liquidacion === 'prima' ? 'prima semestral en ' : '';
$message = '<div class="alert alert-warning">Ya existe una liquidación de ' . $tipo_desc . $mn . '/' . $ano . ' para este empleado. Seleccione otro período.</div>';
               $results = null;
           }

           if ($modo === 'guardar') {
               // Evitar duplicado
               $dup = $pdo->prepare("SELECT COUNT(*) FROM liquidacion WHERE id_empleado = ? AND mes = ? AND ano = ? AND tipo_liquidacion = ?");
               $dup->execute([$id_empleado, $mes, $ano, $tipo_liquidacion]);
               if ((int)$dup->fetchColumn() > 0) {
                   $meses_es = [
                       1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
                       5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
                       9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
                   ];
                   $mn = $meses_es[(int)$mes] ?? (string)$mes;
                   $tipo_desc = $tipo_liquidacion === 'prima' ? 'prima semestral en ' : '';
                   $message = '<div class="alert alert-danger">Ya existe una liquidación de ' . $tipo_desc . $mn . '/' . $ano . ' para este empleado.</div>';
               } else {
                   // Insert liquidacion
                   $stmt = $pdo->prepare("INSERT INTO liquidacion (id_empleado, mes, ano, dias_trabajados, salario_base, devengos, horas_extras, horas_extras_cantidad, incapacidad_valor, incapacidad_dias, deducciones_total, aportes_total, total_neto, tipo_liquidacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                   $stmt->execute([$id_empleado, $mes, $ano, $dias_trabajados, $salario_base, $devengos, $horas_extras_valor ?? 0, $horas_extras_cantidad ?? 0, $incapacidad_valor ?? 0, $incapacidad_dias ?? 0, $deducciones_total, $aportes_total, $total_neto, $tipo_liquidacion]);
                   $id_liquidacion = $pdo->lastInsertId();

                   // Insert deducciones (con base) - solo si no es prima
                   if ($tipo_liquidacion !== 'prima') {
                       $stmt = $pdo->prepare("INSERT INTO deducciones (id_liquidacion, tipo, monto, base, porcentaje) VALUES (?, ?, ?, ?, ?)");
                       $stmt->execute([$id_liquidacion, 'salud', $salud, $devengos, $tasas_config['tasasalud']]);
                       $stmt->execute([$id_liquidacion, 'pension', $pension, $devengos, $tasas_config['tasapension']]);
                   }

                   // Insert aportes (solo Salud y Pensión) con base - solo si no es prima
                   if ($tipo_liquidacion !== 'prima') {
                       $stmt = $pdo->prepare("INSERT INTO aportes (id_liquidacion, tipo, monto, base, porcentaje) VALUES (?, ?, ?, ?, ?)");
                       $stmt->execute([$id_liquidacion, 'salud', $salud_patronal, $salario_base, 8.5]);
                       $stmt->execute([$id_liquidacion, 'pension', $pension_patronal, $salario_base, 12]);
                       
                       // Aportes adicionales según el mes
                       if ($mes == 6 || $mes == 12) {
                           // Junio y diciembre: aporte de prima
                           $prima_aporte = $salario_base * (1/12);
                           $stmt->execute([$id_liquidacion, 'prima', $prima_aporte, $salario_base, 8.33]);
                       }
                       
                       if ($mes == 1) {
                           // Enero: aporte de cesantías
                           $cesantias_aporte = $salario_base * (1/12);
                           $stmt->execute([$id_liquidacion, 'cesantia', $cesantias_aporte, $salario_base, 8.33]);
                       }
                   }

                   // Insert prestaciones (con base y fecha correcta)
                   $fecha_prestacion = "$ano-$mes-01"; // Primer día del mes de liquidación
                   $stmt = $pdo->prepare("INSERT INTO prestaciones (id_empleado, tipo, monto, base, fecha) VALUES (?, ?, ?, ?, ?)");
                   $stmt->execute([$id_empleado, 'prima', $prima, $salario_base, $fecha_prestacion]);
                   if ($tipo_liquidacion !== 'prima') {
                       $stmt->execute([$id_empleado, 'cesantias', $cesantias, $salario_base, $fecha_prestacion]);
                       $stmt->execute([$id_empleado, 'vacaciones', $vacaciones, $salario_base, $fecha_prestacion]);
                   }

                   // Insert aprobacion inicial
                   $stmt = $pdo->prepare("INSERT INTO aprobacion (id_liquidacion, estado) VALUES (?, 'pendiente')");
                   $stmt->execute([$id_liquidacion]);

                   $guardado = true;
                   $tipo_desc = $tipo_liquidacion === 'prima' ? 'Prima semestral guardada' : 'Liquidación mensual guardada';
                   $message = '<div class="alert alert-success">' . $tipo_desc . ' exitosamente. ID: ' . $id_liquidacion . '</div>';
               }
           }
       } else {
           // Solo mostrar este mensaje si se envió el formulario POST
           if ($_SERVER['REQUEST_METHOD'] === 'POST') {
               $message = '<div class="alert alert-danger">Empleado no encontrado o salario invalido.</div>';
           }
       }
 
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Liquidacion de Nomina</h1>
</div>
<?php if ($message) echo $message; ?>
            
<!-- Formulario Liquidacion -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Generar Liquidación</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="formLiquidacion">
                    <input type="hidden" name="modo" value="calcular">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <select class="form-control" id="id_empleado" name="id_empleado" required>
                                    <option value="" disabled <?php echo (!isset($id_empleado) || $id_empleado == 0) ? 'selected' : ''; ?>>Seleccionar empleado</option>
                                    <?php foreach ($empleados as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>" <?php echo (isset($id_empleado) && $id_empleado == $emp['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($emp['nombre'] . ' ' . $emp['apellido'] . ' - $' . number_format($emp['salario'], 0, ',', '.')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <label for="id_empleado" class="form-label">Empleado</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <select class="form-control" id="ano" name="ano" required <?php echo (!isset($id_empleado) || $id_empleado == 0) ? 'disabled' : ''; ?>>
                                    <option value="" disabled <?php echo (!isset($ano) || $ano == 0) ? 'selected' : ''; ?>>Seleccionar año</option>
                                    <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo (isset($ano) && $ano == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                                <label for="ano" class="form-label">Año</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <select class="form-control" id="mes" name="mes" required <?php echo (!isset($id_empleado) || $id_empleado == 0 || !isset($ano) || $ano == 0) ? 'disabled' : ''; ?>>
                                    <option value="" disabled <?php echo (!isset($mes_valor) || empty($mes_valor)) ? 'selected' : ''; ?>>Seleccionar mes</option>
                                    <?php
                                    $meses_es = [
                                        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
                                        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
                                        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
                                    ];
                                    for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo (isset($mes_valor) && $mes_valor == $m) ? 'selected' : ''; ?>><?php echo $meses_es[$m]; ?></option>
                                    <?php endfor; ?>
                                    <option value="6-prima" <?php echo (isset($mes_valor) && $mes_valor == '6-prima') ? 'selected' : ''; ?>>Junio - Prima</option>
                                    <option value="12-prima" <?php echo (isset($mes_valor) && $mes_valor == '12-prima') ? 'selected' : ''; ?>>Diciembre - Prima</option>
                                </select>
                                <label for="mes" class="form-label">Mes</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <input type="number" class="form-control" id="dias_trabajados" name="dias_trabajados" placeholder="" value="<?php echo isset($dias_trabajados) ? $dias_trabajados : 30; ?>" min="1" max="30" readonly>
                                <label for="dias_trabajados" class="form-label">Días trabajados (calculado automáticamente)</label>
                                <small class="form-text text-muted">Se calcula automáticamente restando los días de incapacidad</small>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Calcular</button>
                    <?php if ($results && !$guardado): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="modo" value="guardar">
                        <input type="hidden" name="id_empleado" value="<?php echo (int)$id_empleado; ?>">
                        <input type="hidden" name="mes" value="<?php echo htmlspecialchars($mes_valor); ?>">
                        <input type="hidden" name="ano" value="<?php echo (int)$ano; ?>">
                        <input type="hidden" name="dias_trabajados" value="<?php echo (int)$dias_trabajados; ?>">
                        <input type="hidden" name="extras" value="<?php echo htmlspecialchars((string)$extras); ?>">
                        <button type="submit" class="btn btn-success ms-2">
                            <i class="bi bi-save me-1"></i> Guardar liquidación
                        </button>
                    </form>
                    <?php endif; ?>
                </form>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const urlAjax = '<?php echo $_URL_; ?>/index.php?module=liquidacion&ajax=ocupados';
                    const selEmp = document.getElementById('id_empleado');
                    const selAno = document.getElementById('ano');
                    const selMes = document.getElementById('mes');

                    function resetSelect(selectEl) {
                        if (!selectEl) return;
                        selectEl.selectedIndex = 0;
                    }
                    function setDisabled(selectEl, disabled) {
                        if (!selectEl) return;
                        selectEl.disabled = disabled;
                    }
                    function actualizarMesesOcupados() {
                        const emp = parseInt(selEmp.value || '0', 10);
                        const ano = parseInt(selAno.value || '0', 10);
                        if (!emp || !ano) return;
                        fetch(urlAjax + '&id_empleado=' + emp + '&ano=' + ano)
                            .then(r => r.json())
                            .then(data => {
                                // data = [meses ocupados, pueden ser números o "6-prima", "12-prima"]
                                Array.from(selMes.options).forEach(opt => {
                                    if (!opt.value) return; // placeholder
                                    opt.disabled = data.includes(opt.value);
                                });
                                setDisabled(selMes, false);
                            })
                            .catch(() => {
                                // Si error, dejamos al menos habilitado el select
                                setDisabled(selMes, false);
                            });
                    }

                    if (selEmp) {
                        selEmp.addEventListener('change', function() {
                            // habilitar año, resetear año y mes
                            setDisabled(selAno, false);
                            // Solo resetear si no hay un valor preseleccionado (después de POST)
                            <?php if (isset($ano) && $ano > 0): ?>
                            if (!selAno.querySelector('option[value="<?php echo $ano; ?>"]')) {
                                resetSelect(selAno);
                            }
                            <?php else: ?>
                            resetSelect(selAno);
                            <?php endif; ?>
                            setDisabled(selMes, true);
                            // limpiar estados disabled de los meses por si venían de otra selección
                            Array.from(selMes.options).forEach(opt => {
                                if (opt.value) {
                                    opt.disabled = false;
                                    opt.style.display = 'block';
                                }
                            });
                            // Solo resetear si no hay un valor preseleccionado (después de POST)
                            <?php if (isset($mes_valor) && !empty($mes_valor)): ?>
                            if (!selMes.querySelector('option[value="<?php echo $mes_valor; ?>"]')) {
                                resetSelect(selMes);
                            }
                            <?php else: ?>
                            resetSelect(selMes);
                            <?php endif; ?>
                        });
                    }
                    
                    if (selAno) {
                        selAno.addEventListener('change', function() {
                            // al cambiar año, resetear mes y cargar ocupados
                            resetSelect(selMes);
                            setDisabled(selMes, true);
                            // limpiar estados disabled antes de volver a calcular ocupados
                            Array.from(selMes.options).forEach(opt => {
                                if (opt.value) {
                                    opt.disabled = false;
                                    opt.style.display = 'block';
                                }
                            });
                            actualizarMesesOcupados();
                        });
                    }
                    
                    // Si hay valores preseleccionados (después de POST), habilitar los campos correspondientes
                    <?php if (isset($id_empleado) && $id_empleado > 0): ?>
                        // Habilitar año si hay empleado seleccionado
                        setDisabled(selAno, false);
                        
                        <?php if (isset($ano) && $ano > 0): ?>
                            // Habilitar mes si hay año seleccionado
                            setDisabled(selMes, false);
                            actualizarMesesOcupados();
                        <?php endif; ?>
                    <?php endif; ?>
                });
                </script>
            </div>
        </div>
    </div>
</div>

<?php if ($results): ?>
    <!-- Resultados -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Resultados Liquidación - <?php echo htmlspecialchars($results['empleado'] . ' ' . $results['mes_ano']); ?></h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="card text-white" style="background-color: #745F58;">
                                <div class="card-body py-3">
                                    <h6 class="mb-1">Devengos Total</h6>
                                    <h4 class="mb-0">$ <?php echo number_format($results['devengos'], 0, ',', '.'); ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card text-white" style="background-color: #5C4C46;">
                                <div class="card-body py-3">
                                    <h6 class="mb-1">Deducciones</h6>
                                    <h4 class="mb-0">$ <?php echo number_format($results['deducciones_total'], 0, ',', '.'); ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card text-white" style="background-color: #6B4423;">
                                <div class="card-body py-3">
                                    <h6 class="mb-1">Aportes Total</h6>
                                    <h4 class="mb-0">$ <?php echo number_format($results['aportes_total'], 0, ',', '.'); ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card text-white" style="background-color: #5D4037;">
                                <div class="card-body py-3">
                                    <h6 class="mb-1">Neto a Pagar</h6>
                                    <h4 class="mb-0">$ <?php echo number_format($results['total_neto'], 0, ',', '.'); ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Detalle Deducciones -->
                    <div class="row mt-4">
                        <?php if ($results['tipo_liquidacion'] === 'prima'): ?>
                            <div class="col-md-12">
                                <div class="alert alert-info">
                                    <h6><i class="bi bi-info-circle me-2"></i>Liquidación de Prima Semestral</h6>
                                    <p class="mb-0">Esta liquidación corresponde a la prima de servicios semestral acumulada.
                                    Las primas no están sujetas a deducciones de salud y pensión.</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="col-md-4">
                                <h6>Devengos Detallados</h6>
                                <table class="table table-sm">
                                    <tr><td>Salario Base:</td><td>$ <?php echo number_format($results['salario_base'], 0, ',', '.'); ?></td></tr>
                                    <?php if ($results['extras'] > 0): ?>
                                    <tr><td>Extras:</td><td>$ <?php echo number_format($results['extras'], 0, ',', '.'); ?></td></tr>
                                    <?php endif; ?>
                                    <?php if ($results['horas_extras_cantidad'] > 0): ?>
                                    <tr><td>Horas Extras (<?php echo number_format($results['horas_extras_cantidad'], 1, ',', '.'); ?> h):</td><td>$ <?php echo number_format($results['horas_extras_valor'], 0, ',', '.'); ?></td></tr>
                                    <?php endif; ?>
                                    <?php if ($results['incapacidad_dias'] > 0): ?>
                                    <tr><td>Incapacidad (<?php echo $results['incapacidad_dias']; ?> días):</td><td>$ <?php echo number_format($results['incapacidad_valor'], 0, ',', '.'); ?></td></tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                            <div class="col-md-4">
                                <h6>Deducciones</h6>
                                <table class="table table-sm">
                                    <tr><td>Salud (<?php echo $tasas_config['tasasalud']; ?>%):</td><td>$ <?php echo number_format($results['deducciones']['salud'], 0, ',', '.'); ?></td></tr>
                                    <tr><td>Pensión (<?php echo $tasas_config['tasapension']; ?>%):</td><td>$ <?php echo number_format($results['deducciones']['pension'], 0, ',', '.'); ?></td></tr>
                                </table>
                            </div>
                            <div class="col-md-4">
                                <h6>Aportes Patronales</h6>
                                <table class="table table-sm">
                                    <tr><td>Salud (8.5%):</td><td>$ <?php echo number_format($results['aportes']['salud_patronal'], 0, ',', '.'); ?></td></tr>
                                    <tr><td>Pensión (12%):</td><td>$ <?php echo number_format($results['aportes']['pension_patronal'], 0, ',', '.'); ?></td></tr>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-12">
                            <h6><?php echo $results['tipo_liquidacion'] === 'prima' ? 'Prima Semestral' : 'Prestaciones Aproximadas Mensuales'; ?></h6>
                            <table class="table table-sm">
                                <tr><td>Prima (<?php echo $results['tipo_liquidacion'] === 'prima' ? '8.33% del acumulado semestral' : '8.33% mensual'; ?>):</td><td>$ <?php echo number_format($results['prestaciones']['prima'], 0, ',', '.'); ?></td></tr>
                                <?php if ($results['tipo_liquidacion'] !== 'prima'): ?>
                                    <tr><td>Cesantias (8.33%):</td><td>$ <?php echo number_format($results['prestaciones']['cesantias'], 0, ',', '.'); ?></td></tr>
                                    <tr><td>Vacaciones (4.17%):</td><td>$ <?php echo number_format($results['prestaciones']['vacaciones'], 0, ',', '.'); ?></td></tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<script>
// Validación extra: impedir enviar si el mes seleccionado está deshabilitado (ya existe liquidación)
document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('formLiquidacion');
  const selMes = document.getElementById('mes');
  if (form && selMes) {
    form.addEventListener('submit', function(e) {
      const opt = selMes.options[selMes.selectedIndex];
      if (opt && opt.disabled) {
        e.preventDefault();
        alert('Ya existe una liquidación para este empleado en el mes/año seleccionado. Elija otro mes.');
      }
    });
  }
});
</script>

<!-- Nueva sección para liquidación masiva -->
<div class="row mt-5">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Liquidación Masiva de Empleados</h5>
            </div>
            <div class="card-body">
                <form id="formLiquidacionMasiva">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <select class="form-control" id="ano_masivo" name="ano" required>
                                    <option value="" disabled selected>Seleccionar año</option>
                                    <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
                                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                                <label for="ano_masivo" class="form-label">Año</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <select class="form-control" id="mes_masivo" name="mes" required>
                                    <option value="" disabled selected>Seleccionar mes</option>
                                    <?php
                                    $meses_es = [
                                        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
                                        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
                                        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
                                    ];
                                    for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>"><?php echo $meses_es[$m]; ?></option>
                                    <?php endfor; ?>
                                    <option value="6-prima">Junio - Prima</option>
                                    <option value="12-prima">Diciembre - Prima</option>
                                </select>
                                <label for="mes_masivo" class="form-label">Mes</label>
                            </div>
                        </div>
                        <div class="col-md-2" id="div_dias_masivo">
                            <div class="mb-3">
                                <input type="number" class="form-control" id="dias_masivo" name="dias_trabajados" value="30" min="1" max="30" readonly>
                                <label for="dias_masivo" class="form-label">Días trabajados</label>
                                <small class="form-text text-muted">Se calcula automáticamente restando incapacidad</small>
                            </div>
                        </div>
                        <div class="col-md-2" id="div_extras_masivo" style="display: none;">
                            <div class="mb-3">
                                <input type="number" step="0.01" class="form-control" id="extras_masivo" name="extras" value="0" min="0">
                                <label for="extras_masivo" class="form-label">Extras</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="btnCargarEmpleados" class="form-label d-block invisible">&nbsp;</label>
                                <button type="button" id="btnCargarEmpleados" class="btn btn-primary w-100">Cargar Empleados</button>
                            </div>
                        </div>
                    </div>
                </form>
                
                <!-- Tabla de empleados disponibles para liquidación -->
                <div id="divEmpleadosDisponibles" style="display: none;" class="mt-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6>Empleados disponibles para liquidación</h6>
                        <div>
                            <button type="button" id="btnSeleccionarTodos" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-check-square me-1"></i> Seleccionar todos
                            </button>
                            <button type="button" id="btnDeseleccionarTodos" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-x-square me-1"></i> Deseleccionar todos
                            </button>
                            <button type="button" id="btnGenerarLiquidaciones" class="btn btn-success">Generar liquidaciones</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="tablaEmpleados">
                            <thead>
                                <tr>
                                    <th width="50px">
                                        <input type="checkbox" id="selectAll">
                                    </th>
                                    <th>Nombre</th>
                                    <th>Cédula</th>
                                    <th>Salario</th>
                                    <th id="th_dias_trabajados" width="100px">Días trabajados</th>
                                    <th width="120px">Devengos</th>
                                    <th width="120px">Deducciones</th>
                                    <th width="120px">Neto a Pagar</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyEmpleados">
                                <!-- Se cargarán dinámicamente -->
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Resultados de liquidación masiva -->
                <div id="divResultadosMasivos" style="display: none;" class="mt-4">
                    <h6>Resultados de liquidación</h6>
                    <div id="contenedorResultados">
                        <!-- Se cargarán dinámicamente -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const urlAjax = '<?php echo $_URL_; ?>/api/liquidacionMasivaEmpleados.php';
    const anoMasivo = document.getElementById('ano_masivo');
    const mesMasivo = document.getElementById('mes_masivo');
    const btnCargarEmpleados = document.getElementById('btnCargarEmpleados');
    const divEmpleadosDisponibles = document.getElementById('divEmpleadosDisponibles');
    const tbodyEmpleados = document.getElementById('tbodyEmpleados');
    const selectAll = document.getElementById('selectAll');
    const btnSeleccionarTodos = document.getElementById('btnSeleccionarTodos');
    const btnDeseleccionarTodos = document.getElementById('btnDeseleccionarTodos');
    const btnGenerarLiquidaciones = document.getElementById('btnGenerarLiquidaciones');
    const divResultadosMasivos = document.getElementById('divResultadosMasivos');
    const contenedorResultados = document.getElementById('contenedorResultados');
    const divDiasMasivo = document.getElementById('div_dias_masivo');
    const divExtrasMasivo = document.getElementById('div_extras_masivo');
    const thDiasTrabajados = document.getElementById('th_dias_trabajados');
    
    // Función para mostrar/ocultar campos según el tipo de liquidación
    function actualizarCamposPorTipoLiquidacion() {
        const mesValor = mesMasivo.value;
        const esPrima = mesValor && mesValor.includes('-prima');
        
        if (esPrima) {
            // Ocultar días trabajados y extras para prima
            divDiasMasivo.style.display = 'none';
            divExtrasMasivo.style.display = 'none';
            thDiasTrabajados.style.display = 'none';
        } else {
            // Mostrar días trabajados para liquidación normal
            divDiasMasivo.style.display = 'block';
            divExtrasMasivo.style.display = 'none'; // Extras siempre oculto
            thDiasTrabajados.style.display = 'table-cell';
        }
    }
    
    // Event listener para cambios en el mes
    mesMasivo.addEventListener('change', function() {
        actualizarCamposPorTipoLiquidacion();
        // Limpiar empleados disponibles cuando cambia el mes
        divEmpleadosDisponibles.style.display = 'none';
        divResultadosMasivos.style.display = 'none';
    });
    
    // Inicializar estado de los campos
    actualizarCamposPorTipoLiquidacion();
    
    // Cargar empleados disponibles
    btnCargarEmpleados.addEventListener('click', function() {
        const ano = parseInt(anoMasivo.value || '0', 10);
        const mesValor = mesMasivo.value;
        
        if (!ano || !mesValor) {
            alert('Por favor seleccione año y mes');
            return;
        }
        
        // Determinar si es prima y extraer el mes
        let mes;
        let tipoLiquidacion = 'mensual';
        
        if (mesValor.includes('-prima')) {
            mes = parseInt(mesValor.replace('-prima', ''));
            tipoLiquidacion = 'prima';
        } else {
            mes = parseInt(mesValor);
        }
        
        let url = urlAjax + '?ano=' + ano + '&mes=' + mes;
        if (tipoLiquidacion === 'prima') {
            url += '&tipo=prima';
        }
        
        fetch(url)
            .then(r => {
                if (!r.ok) {
                    throw new Error('Error en la respuesta del servidor: ' + r.status);
                }
                return r.json();
            })
            .then(data => {
                tbodyEmpleados.innerHTML = '';
                
                if (data.length === 0) {
                    tbodyEmpleados.innerHTML = '<tr><td colspan="8" class="text-center">No hay empleados disponibles para liquidación en este período</td></tr>';
                    divEmpleadosDisponibles.style.display = 'block';
                    return;
                }
                
                // Procesar los empleados
                if (mesValor.includes('-prima')) {
                    // Para prima, necesitamos calcular los valores para cada empleado
                    procesarEmpleadosConPrima(data, mesValor, ano)
                        .then(() => {
                            divEmpleadosDisponibles.style.display = 'block';
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Error al calcular las primas: ' + error.message);
                        });
                } else {
                    // Para liquidación normal, procesar como antes
                    data.forEach(emp => {
                        const dias = document.getElementById('dias_masivo').value;
                        const extras = parseFloat(document.getElementById('extras_masivo').value) || 0;
                        const salarioBase = parseFloat(emp.salario);
                        const factorDias = parseInt(dias) / 30;
                        const salarioAjustado = salarioBase * factorDias;
                        const devengos = salarioAjustado + extras;
                        const salud = devengos * (<?php echo $tasas_config['tasasalud']; ?> / 100);
                        const pension = devengos * (<?php echo $tasas_config['tasapension']; ?> / 100);
                        const deducciones = salud + pension;
                        const neto = devengos - deducciones;
                        
                        const diasHtml = `<td><input type="number" class="form-control form-control-sm dias-individuales" value="${dias}" min="1" max="30" data-salario-base="${emp.salario}"></td>`;
                        
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td><input type="checkbox" class="emp-checkbox" data-id="${emp.id}" data-nombre="${emp.nombre} ${emp.apellido}" data-documento="${emp.documento}" data-salario="${emp.salario}"></td>
                            <td>${emp.nombre} ${emp.apellido}</td>
                            <td>${emp.documento}</td>
                            <td>$ ${number_format(emp.salario, 0, ',', '.')}</td>
                            ${diasHtml}
                            <td class="devengos-cell">$ ${number_format(devengos, 0, ',', '.')}</td>
                            <td class="deducciones-cell">$ ${number_format(deducciones, 0, ',', '.')}</td>
                            <td class="neto-cell">$ ${number_format(neto, 0, ',', '.')}</td>
                        `;
                        tbodyEmpleados.appendChild(row);
                    });
                    
                    divEmpleadosDisponibles.style.display = 'block';
                    
                    // Agregar event listeners para los cambios en días individuales
                    document.querySelectorAll('.dias-individuales').forEach(input => {
                        input.addEventListener('change', function() {
                            const row = this.closest('tr');
                            const salarioBase = parseFloat(this.dataset.salarioBase);
                            const dias = parseInt(this.value) || 30;
                            const extras = parseFloat(document.getElementById('extras_masivo').value) || 0;
                            
                            if (dias < 1 || dias > 30) {
                                this.value = 30;
                                return;
                            }
                            
                            const factorDias = dias / 30;
                            const salarioAjustado = salarioBase * factorDias;
                            const devengos = salarioAjustado + extras;
                            const salud = devengos * (<?php echo $tasas_config['tasasalud']; ?> / 100);
                            const pension = devengos * (<?php echo $tasas_config['tasapension']; ?> / 100);
                            const deducciones = salud + pension;
                            const neto = devengos - deducciones;
                            
                            row.querySelector('.devengos-cell').textContent = '$ ' + number_format(devengos, 0, ',', '.');
                            row.querySelector('.deducciones-cell').textContent = '$ ' + number_format(deducciones, 0, ',', '.');
                            row.querySelector('.neto-cell').textContent = '$ ' + number_format(neto, 0, ',', '.');
                        });
                    });
                }
                
                divEmpleadosDisponibles.style.display = 'block';
                
                // Agregar event listeners para los cambios en días individuales
                document.querySelectorAll('.dias-individuales').forEach(input => {
                    input.addEventListener('change', function() {
                        const row = this.closest('tr');
                        const salarioBase = parseFloat(this.dataset.salarioBase);
                        const dias = parseInt(this.value) || 30;
                        const extras = parseFloat(document.getElementById('extras_masivo').value) || 0;
                        
                        if (dias < 1 || dias > 30) {
                            this.value = 30;
                            return;
                        }
                        
                        const factorDias = dias / 30;
                        const salarioAjustado = salarioBase * factorDias;
                        const devengos = salarioAjustado + extras;
                        const salud = devengos * (<?php echo $tasas_config['tasasalud']; ?> / 100);
                        const pension = devengos * (<?php echo $tasas_config['tasapension']; ?> / 100);
                        const deducciones = salud + pension;
                        const neto = devengos - deducciones;
                        
                        row.querySelector('.devengos-cell').textContent = '$ ' + number_format(devengos, 0, ',', '.');
                        row.querySelector('.deducciones-cell').textContent = '$ ' + number_format(deducciones, 0, ',', '.');
                        row.querySelector('.neto-cell').textContent = '$ ' + number_format(neto, 0, ',', '.');
                    });
                });
            })
            .catch(error => {
                console.error('Error:', error);
                console.error('URL solicitada:', url);
                alert('Error al cargar los empleados: ' + error.message);
            });
    });
    
    // Seleccionar/deseleccionar todos
    selectAll.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.emp-checkbox');
        checkboxes.forEach(cb => cb.checked = this.checked);
    });
    
    btnSeleccionarTodos.addEventListener('click', function() {
        const checkboxes = document.querySelectorAll('.emp-checkbox');
        checkboxes.forEach(cb => cb.checked = true);
        selectAll.checked = true;
    });
    
    btnDeseleccionarTodos.addEventListener('click', function() {
        const checkboxes = document.querySelectorAll('.emp-checkbox');
        checkboxes.forEach(cb => cb.checked = false);
        selectAll.checked = false;
    });
    
    // Cambiar en extras globales
    document.getElementById('extras_masivo').addEventListener('change', function() {
        document.querySelectorAll('.dias-individuales').forEach(input => {
            input.dispatchEvent(new Event('change'));
        });
    });
    
    // Generar liquidaciones masivas
    btnGenerarLiquidaciones.addEventListener('click', function() {
        const checkboxes = document.querySelectorAll('.emp-checkbox:checked');
        
        if (checkboxes.length === 0) {
            alert('Por favor seleccione al menos un empleado');
            return;
        }
        
        const ano = parseInt(anoMasivo.value);
        const mesValor = mesMasivo.value;
        const extras = parseFloat(document.getElementById('extras_masivo').value) || 0;
        
        // Determinar si es prima y extraer el mes
        let mes;
        let tipoLiquidacion = 'mensual';
        
        if (mesValor.includes('-prima')) {
            mes = parseInt(mesValor.replace('-prima', ''));
            tipoLiquidacion = 'prima';
        } else {
            mes = parseInt(mesValor);
        }
        
        const empleadosSeleccionados = [];
        checkboxes.forEach(cb => {
            const row = cb.closest('tr');
            let dias = 30; // Valor por defecto para prima
            
            if (tipoLiquidacion === 'mensual') {
                dias = parseInt(row.querySelector('.dias-individuales').value) || 30;
            }
            
            empleadosSeleccionados.push({
                id: cb.dataset.id,
                nombre: cb.dataset.nombre,
                documento: cb.dataset.documento,
                salario: parseFloat(cb.dataset.salario),
                dias: dias
            });
        });
        
        // Generar tablas de liquidación para cada empleado
        let html = '';
        empleadosSeleccionados.forEach(emp => {
            let devengos, deduccionesTotal, neto, aportesTotal, prima, cesantias, vacaciones;
            let detallesHtml = '';
            
            if (tipoLiquidacion === 'prima') {
                // Para prima, los valores se calcularán en el servidor
                // Mostramos mensaje informativo
                devengos = 0;
                deduccionesTotal = 0;
                neto = 0;
                aportesTotal = 0;
                prima = 0;
                cesantias = 0;
                vacaciones = 0;
                
                detallesHtml = `
                    <div class="col-md-12">
                        <div class="alert alert-info">
                            <h6><i class="bi bi-info-circle me-2"></i>Liquidación de Prima Semestral</h6>
                            <p class="mb-0">Esta liquidación corresponde a la prima de servicios semestral acumulada.
                            Las primas no están sujetas a deducciones de salud y pensión.</p>
                        </div>
                    </div>
                `;
            } else {
                const factorDias = emp.dias / 30;
                const salarioAjustado = emp.salario * factorDias;
                devengos = salarioAjustado + extras;
                const salud = devengos * (<?php echo $tasas_config['tasasalud']; ?> / 100);
                const pension = devengos * (<?php echo $tasas_config['tasapension']; ?> / 100);
                deduccionesTotal = salud + pension;
                neto = devengos - deduccionesTotal;
                const saludPatronal = emp.salario * 0.085 * factorDias;
                const pensionPatronal = emp.salario * 0.12 * factorDias;
                aportesTotal = saludPatronal + pensionPatronal;
                prima = emp.salario * (1/12) * factorDias;
                cesantias = emp.salario * (1/12) * factorDias;
                vacaciones = emp.salario * (15/360) * factorDias;
                
                detallesHtml = `
                    <div class="col-md-6">
                        <h6>Deducciones</h6>
                        <table class="table table-sm">
                            <tr><td>Salud (<?php echo $tasas_config['tasasalud']; ?>%):</td><td>$ ${number_format(salud, 0, ',', '.')}</td></tr>
                            <tr><td>Pensión (<?php echo $tasas_config['tasapension']; ?>%):</td><td>$ ${number_format(pension, 0, ',', '.')}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Aportes Patronales</h6>
                        <table class="table table-sm">
                            <tr><td>Salud (8.5%):</td><td>$ ${number_format(saludPatronal, 0, ',', '.')}</td></tr>
                            <tr><td>Pensión (12%):</td><td>$ ${number_format(pensionPatronal, 0, ',', '.')}</td></tr>
                        </table>
                    </div>
                `;
            }
            
            html += `
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">${emp.nombre} - ${emp.documento}</h6>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" checked data-emp-id="${emp.id}" data-dias="${emp.dias}">
                            <label class="form-check-label">Aprobar</label>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <div class="card text-white" style="background-color: #745F58;">
                                    <div class="card-body py-2">
                                        <h6 class="mb-1 small">Devengos Total</h6>
                                        <h5 class="mb-0">$ ${number_format(devengos, 0, ',', '.')}</h5>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-white" style="background-color: #5C4C46;">
                                    <div class="card-body py-2">
                                        <h6 class="mb-1 small">Deducciones</h6>
                                        <h5 class="mb-0">$ ${number_format(deduccionesTotal, 0, ',', '.')}</h5>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-white" style="background-color: #6B4423;">
                                    <div class="card-body py-2">
                                        <h6 class="mb-1 small">Aportes Total</h6>
                                        <h5 class="mb-0">$ ${number_format(aportesTotal, 0, ',', '.')}</h5>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-white" style="background-color: #5D4037;">
                                    <div class="card-body py-2">
                                        <h6 class="mb-1 small">Neto a Pagar</h6>
                                        <h5 class="mb-0">$ ${number_format(neto, 0, ',', '.')}</h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            ${detallesHtml}
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-12">
                                <h6>${tipoLiquidacion === 'prima' ? 'Prima Semestral' : 'Prestaciones Aproximadas Mensuales'}</h6>
                                <table class="table table-sm">
                                    <tr><td>Prima (${tipoLiquidacion === 'prima' ? '8.33% del acumulado semestral' : '8.33% mensual'}):</td><td>$ ${number_format(prima, 0, ',', '.')}</td></tr>
                                    ${tipoLiquidacion === 'mensual' ? `
                                    <tr><td>Cesantias (8.33%):</td><td>$ ${number_format(cesantias, 0, ',', '.')}</td></tr>
                                    <tr><td>Vacaciones (4.17%):</td><td>$ ${number_format(vacaciones, 0, ',', '.')}</td></tr>
                                    ` : ''}
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        html += `
            <div class="text-center mt-4">
                <button type="button" id="btnGuardarLiquidacionesMasivas" class="btn btn-success btn-lg">
                    <i class="bi bi-save me-2"></i>Guardar Liquidaciones Seleccionadas
                </button>
            </div>
        `;
        
        contenedorResultados.innerHTML = html;
        divResultadosMasivos.style.display = 'block';
        
        // Scroll a resultados
        divResultadosMasivos.scrollIntoView({ behavior: 'smooth' });
        
        // Event listener para guardar liquidaciones
        document.getElementById('btnGuardarLiquidacionesMasivas').addEventListener('click', function() {
            guardarLiquidacionesMasivas(ano, mesValor, tipoLiquidacion, extras);
        });
    });
    
    function guardarLiquidacionesMasivas(ano, mesValor, tipoLiquidacion, extras) {
        const checkboxes = document.querySelectorAll('#contenedorResultados input[type="checkbox"]:checked');
        
        if (checkboxes.length === 0) {
            alert('Por favor seleccione al menos una liquidación para guardar');
            return;
        }
        
        const datos = [];
        checkboxes.forEach(cb => {
            const empId = cb.dataset.empId;
            const dias = cb.dataset.dias;
            datos.push({ id: empId, dias: dias });
        });
        
        // Enviar datos al servidor
        fetch('<?php echo $_URL_; ?>/api/liquidacionMasivaGuardar.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                ano: ano,
                mes: mesValor,
                tipo_liquidacion: tipoLiquidacion,
                extras: extras,
                empleados: datos
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Liquidaciones guardadas exitosamente: ' + data.guardados + ' de ' + data.total);
                // Recargar la página para actualizar el estado
                window.location.reload();
            } else {
                alert('Error al guardar las liquidaciones: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al guardar las liquidaciones');
        });
    }
    
    // Función para procesar empleados con prima
    async function procesarEmpleadosConPrima(empleados, mesValor, ano) {
        const mes = parseInt(mesValor.replace('-prima', ''));
        
        for (const emp of empleados) {
            try {
                // Llamar al API para calcular la prima de este empleado
                const response = await fetch(`<?php echo $_URL_; ?>/api/liquidacionMasivaPrima.php?ano=${ano}&mes=${mes}&id_empleado=${emp.id}`);
                const data = await response.json();
                
                if (data.success) {
                    // Mostrar los valores calculados
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td><input type="checkbox" class="emp-checkbox" data-id="${emp.id}" data-nombre="${emp.nombre} ${emp.apellido}" data-documento="${emp.documento}" data-salario="${emp.salario}"></td>
                        <td>${emp.nombre} ${emp.apellido}</td>
                        <td>${emp.documento}</td>
                        <td>$ ${number_format(emp.salario, 0, ',', '.')}</td>
                        <td class="devengos-cell">$ ${number_format(data.devengos, 0, ',', '.')}</td>
                        <td class="deducciones-cell">$ ${number_format(data.deducciones_total, 0, ',', '.')}</td>
                        <td class="neto-cell">$ ${number_format(data.total_neto, 0, ',', '.')}</td>
                    `;
                    tbodyEmpleados.appendChild(row);
                } else {
                    // Mostrar mensaje de error para este empleado
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td><input type="checkbox" class="emp-checkbox" data-id="${emp.id}" data-nombre="${emp.nombre} ${emp.apellido}" data-documento="${emp.documento}" data-salario="${emp.salario}" disabled></td>
                        <td>${emp.nombre} ${emp.apellido}</td>
                        <td>${emp.documento}</td>
                        <td>$ ${number_format(emp.salario, 0, ',', '.')}</td>
                        <td colspan="3" class="text-center text-danger">${data.message}</td>
                    `;
                    tbodyEmpleados.appendChild(row);
                }
            } catch (error) {
                console.error('Error al calcular prima para empleado', emp.id, error);
                // Mostrar mensaje de error para este empleado
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td><input type="checkbox" class="emp-checkbox" data-id="${emp.id}" data-nombre="${emp.nombre} ${emp.apellido}" data-documento="${emp.documento}" data-salario="${emp.salario}" disabled></td>
                    <td>${emp.nombre} ${emp.apellido}</td>
                    <td>${emp.documento}</td>
                    <td>$ ${number_format(emp.salario, 0, ',', '.')}</td>
                    <td colspan="3" class="text-center text-danger">Error al calcular prima</td>
                `;
                tbodyEmpleados.appendChild(row);
            }
        }
    }
});

// Función helper para formatear números (similar a PHP)
function number_format(number, decimals, dec_point, thousands_sep) {
    number = (number + '').replace(/[^0-9+\-Ee.]/g, '');
    var n = !isFinite(+number) ? 0 : +number,
        prec = !isFinite(+decimals) ? 0 : Math.abs(decimals),
        sep = (typeof thousands_sep === 'undefined') ? ',' : thousands_sep,
        dec = (typeof dec_point === 'undefined') ? '.' : dec_point,
        s = '',
        toFixedFix = function (n, prec) {
            var k = Math.pow(10, prec);
            return '' + Math.round(n * k) / k;
        };
    s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.');
    if (sep) {
        var re = /(-?\d+)(\d{3})/;
        while (re.test(s[0])) {
            s[0] = s[0].replace(re, '$1' + sep + '$2');
        }
    }
    if ((s[1] || '').length < prec) {
        s[1] = s[1] || '';
        s[1] += new Array(prec - s[1].length + 1).join('0');
    }
    return s.join(dec);
}
</script>