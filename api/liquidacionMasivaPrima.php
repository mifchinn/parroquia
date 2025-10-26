<?php
// API para calcular previsualización de prima semestral
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
    
    // Obtener datos del GET
    $ano = (int)($_GET['ano'] ?? 0);
    $mes = (int)($_GET['mes'] ?? 0);
    $id_empleado = (int)($_GET['id_empleado'] ?? 0);
    
    if ($ano <= 0 || $mes < 1 || $mes > 12 || $id_empleado <= 0) {
        echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
        exit;
    }
    
    // Validar que la prima solo se pueda hacer en junio o diciembre
    if (!in_array($mes, [6, 12])) {
        echo json_encode(['success' => false, 'message' => 'La liquidación de prima solo se puede realizar en junio o diciembre']);
        exit;
    }
    
    // Obtener datos del empleado
    $stmt = $pdo->prepare("SELECT nombre, apellido, salario FROM empleado WHERE id = ?");
    $stmt->execute([$id_empleado]);
    $emp_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$emp_data || (float)$emp_data['salario'] <= 0) {
        echo json_encode(['success' => false, 'message' => 'Empleado no encontrado o sin salario válido']);
        exit;
    }
    
    $salario_base = (float)$emp_data['salario'];
    
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
        
        $response = [
            'success' => true,
            'devengos' => $devengos,
            'deducciones_total' => $deducciones_total,
            'total_neto' => $total_neto,
            'aportes_total' => $aportes_total,
            'prima' => $prima,
            'cesantias' => $cesantias,
            'vacaciones' => $vacaciones,
            'periodo' => $periodo,
            'prestaciones_encontradas' => count($prestaciones_prima),
            'detalle_prestaciones' => $prestaciones_prima
        ];
        
        echo json_encode($response);
    } else {
        // No hay prestaciones de prima acumuladas
        echo json_encode([
            'success' => false,
            'message' => "No existen prestaciones de prima registradas para el empleado en el período $periodo"
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error en el servidor: ' . $e->getMessage()
    ]);
}
?>