<?php
// API para guardar liquidaciones masivas
if (!function_exists('getDBConnection')) { require_once __DIR__ . '/../root/config.php'; }
require_once __DIR__ . '/../root/utils.php';

// Solo responder a AJAX, sin ninguna salida HTML
header('Content-Type: application/json');

// Verificar autenticación
if (!isset($_COOKIE['auth']) || Utils::leerCookie('auth') === false) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Verificar permisos
if (!in_array((int)Utils::getUserCargo(), [1,2], true)) {
    echo json_encode(['success' => false, 'message' => 'Permisos insuficientes']);
    exit;
}

try {
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
            echo json_encode(['success' => false, 'message' => 'No se encontró la configuración del sistema. Por favor configure las tasas en el módulo de configuración.']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al obtener la configuración: ' . $e->getMessage()]);
        exit;
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
function calcularIncapacidad($pdo, $id_empleado, $ano, $mes, $salario_diario, $config) {
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
        
        // Calcular valor según normas colombianas
        if ($dias <= 2) {
            // Primeros 2 días: paga el empleador
            $valor = $salario_diario * $dias * ($config['incapacidad_primeros_dias'] / 100);
        } else {
            // Primeros 2 días: paga el empleador
            $valor_primeros = $salario_diario * 2 * ($config['incapacidad_primeros_dias'] / 100);
            // Días restantes: paga la EPS
            $dias_restantes = $dias - 2;
            $valor_restantes = $salario_diario * $dias_restantes * ($config['incapacidad_siguiente_dias'] / 100);
            $valor = $valor_primeros + $valor_restantes;
        }
        
        $total_valor += $valor;
    }
    
    return [
        'dias' => $total_dias,
        'valor' => $total_valor
    ];
}
    
    // Obtener datos del POST
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    if (!$data || !isset($data['ano']) || !isset($data['mes']) || !isset($data['empleados'])) {
        echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
        exit;
    }
    
    $ano = (int)$data['ano'];
    $mes_valor = $data['mes'];
    $tipo_liquidacion = $data['tipo_liquidacion'] ?? 'mensual';
    $empleados = $data['empleados'];
    
    // Procesar el valor del mes para determinar si es prima
    if (strpos($mes_valor, '-prima') !== false) {
        $mes = (int)str_replace('-prima', '', $mes_valor);
        $tipo_liquidacion = 'prima';
    } else {
        $mes = (int)$mes_valor;
        $tipo_liquidacion = 'mensual';
    }
    
    if ($ano <= 0 || $mes < 1 || $mes > 12 || empty($empleados)) {
        echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
        exit;
    }
    
    // Validar que la prima solo se pueda hacer en junio o diciembre
    if ($tipo_liquidacion === 'prima' && !in_array($mes, [6, 12])) {
        echo json_encode(['success' => false, 'message' => 'La liquidación de prima solo se puede realizar en junio o diciembre']);
        exit;
    }
    
    $guardados = 0;
    $total = count($empleados);
    $errores = [];
    
    // Iniciar transacción
    $pdo->beginTransaction();
    
    foreach ($empleados as $emp) {
        $id_empleado = (int)$emp['id'];
        $dias_trabajados = (int)($emp['dias'] ?? 30);
        
        // Validar que los días estén entre 1 y 30
        if ($dias_trabajados < 1 || $dias_trabajados > 30) {
            $dias_trabajados = 30;
        }
        
        try {
            // Verificar que no exista ya una liquidación para este empleado en este período
            $dupCheck = $pdo->prepare("SELECT COUNT(*) FROM liquidacion WHERE id_empleado = ? AND mes = ? AND ano = ? AND tipo_liquidacion = ?");
            $dupCheck->execute([$id_empleado, $mes, $ano, $tipo_liquidacion]);
            
            if ((int)$dupCheck->fetchColumn() > 0) {
                $errores[] = "Ya existe una liquidación de $tipo_liquidacion para el empleado ID $id_empleado en $mes/$ano";
                continue;
            }
            
            // Obtener datos del empleado
            $stmt = $pdo->prepare("SELECT nombre, apellido, salario FROM empleado WHERE id = ?");
            $stmt->execute([$id_empleado]);
            $emp_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$emp_data || (float)$emp_data['salario'] <= 0) {
                $errores[] = "Empleado ID $id_empleado no encontrado o sin salario válido";
                continue;
            }
            
            $salario_base = (float)$emp_data['salario'];
            
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
                    $errores[] = "La liquidación de prima solo se puede realizar en junio (primer semestre) o diciembre (segundo semestre)";
                    continue;
                }
                
                // Calcular prima semestral acumulada basada en los APORTES de prima
                $prima_acumulada = 0;
                
                // Buscar PRESTACIONES de prima del empleado en el período
                $stmt = $pdo->prepare("
                    SELECT p.monto
                    FROM prestaciones p
                    WHERE p.id_empleado = ? AND p.tipo = 'prima'
                    AND YEAR(p.fecha) = ? AND MONTH(p.fecha) BETWEEN ? AND ?
                ");
                $stmt->execute([$id_empleado, $ano, $mes_inicio, $mes_fin]);
                $prestaciones_prima = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Sumar las PRESTACIONES de prima del período
                foreach ($prestaciones_prima as $prestacion) {
                    $prima_acumulada += (float)$prestacion;
                }
                
                // La prima a pagar es la sumatoria de los APORTES de prima del semestre
                if ($prima_acumulada > 0) {
                    $prima_semestral = $prima_acumulada;
                    $devengos = $prima_semestral;
                    
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
                    // No hay prestaciones de prima acumuladas
                    $errores[] = "No existen prestaciones de prima registradas para el empleado ID $id_empleado en el período $periodo";
                    continue;
                }
            } else {
                // Liquidación mensual normal con ajuste por días trabajados
                $factor_dias = $dias_trabajados / 30;
                $salario_ajustado = $salario_base * $factor_dias;
                
                // Calcular horas extras del período
                $horas_extras_cantidad = calcularHorasExtras($pdo, $id_empleado, $ano, $mes);
                $valor_hora_extra = ($salario_base / 240) * $tasas_config['factor_extras']; // 240 horas mensuales promedio
                $horas_extras_valor = $horas_extras_cantidad * $valor_hora_extra;
                
                // Calcular incapacidad del período
                $salario_diario = $salario_base / 30;
                $incapacidad_data = calcularIncapacidad($pdo, $id_empleado, $ano, $mes, $salario_diario, $tasas_config);
                $incapacidad_dias = $incapacidad_data['dias'];
                $incapacidad_valor = $incapacidad_data['valor'];
                
                // Calcular devengos totales
                $devengos = $salario_ajustado + $horas_extras_valor + $incapacidad_valor;
                
                // Deducciones empleado usando tasas parametrizadas
                $salud = $devengos * ($tasas_config['tasasalud'] / 100);
                $pension = $devengos * ($tasas_config['tasapension'] / 100);
                $deducciones_total = $salud + $pension;
                $total_neto = $devengos - $deducciones_total;
                
                // Aportes patronales (solo Salud y Pensión) ajustados por días
                $salud_patronal = $salario_base * 0.085 * $factor_dias;
                $pension_patronal = $salario_base * 0.12 * $factor_dias;
                $aportes_total = $salud_patronal + $pension_patronal;
                
                // Aportes adicionales según el mes (ajustados por días)
                $aportes_adicionales = 0;
                if ($mes == 6 || $mes == 12) {
                    // Junio y diciembre: aporte de prima
                    $prima_aporte = $salario_base * (1/12) * $factor_dias;
                    $aportes_adicionales += $prima_aporte;
                }
                
                if ($mes == 1) {
                    // Enero: aporte de cesantías
                    $cesantias_aporte = $salario_base * (1/12) * $factor_dias;
                    $aportes_adicionales += $cesantias_aporte;
                }
                
                $aportes_total += $aportes_adicionales;
                
                // Prestaciones (mensuales aproximadas) ajustadas por días
                $prima = $salario_base * (1/12) * $factor_dias;
                $cesantias = $salario_base * (1/12) * $factor_dias;
                $vacaciones = $salario_base * (15/360) * $factor_dias;
            }
            
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
            
            $guardados++;
            
        } catch (Exception $e) {
            $errores[] = "Error al procesar empleado ID $id_empleado: " . $e->getMessage();
        }
    }
    
    // Confirmar transacción
    $pdo->commit();
    
    $response = [
        'success' => true,
        'guardados' => $guardados,
        'total' => $total,
        'message' => "Se guardaron $guardados de $total liquidaciones"
    ];
    
    if (!empty($errores)) {
        $response['errores'] = $errores;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Error en el servidor: ' . $e->getMessage()
    ]);
}
?>