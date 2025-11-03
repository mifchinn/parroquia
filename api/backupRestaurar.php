<?php
// API para crear y restaurar respaldos de datos
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
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $accion = $_POST['accion'] ?? '';
        
        if ($accion === 'crear_backup') {
            // Crear respaldo de todas las tablas importantes
            $tablas = ['empleado', 'liquidacion', 'novedades', 'aprobacion', 'deducciones', 'aportes', 'prestaciones', 'configuracion'];
            $backup = [];
            
            foreach ($tablas as $tabla) {
                try {
                    $stmt = $pdo->query("SELECT * FROM $tabla");
                    $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $backup[$tabla] = $datos;
                } catch (Exception $e) {
                    $backup[$tabla] = ['error' => $e->getMessage()];
                }
            }
            
            // Guardar respaldo en archivo
            $nombre_archivo = 'backup_' . date('Y-m-d_H-i-s') . '.json';
            $ruta_archivo = __DIR__ . '/../backups/' . $nombre_archivo;
            
            // Crear directorio si no existe
            $directorio = dirname($ruta_archivo);
            if (!is_dir($directorio)) {
                mkdir($directorio, 0755, true);
            }
            
            // Guardar backup
            file_put_contents($ruta_archivo, json_encode($backup, JSON_PRETTY_PRINT));
            
            // Actualizar fecha de respaldo en configuración (si la columna existe)
            try {
                $stmt = $pdo->prepare("UPDATE configuracion SET fecha_respaldo = ? WHERE id = 1");
                $stmt->execute([date('Y-m-d H:i:s')]);
            } catch (Exception $e) {
                // Si la columna no existe, ignorar el error
                if (strpos($e->getMessage(), "Unknown column 'fecha_respaldo'") !== false) {
                    // Es otro error, lanzarlo
                    throw $e;
                }
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Respaldo creado exitosamente',
                'archivo' => $nombre_archivo
            ]);
            
        } elseif ($accion === 'restaurar_backup') {
            // Restaurar desde archivo de respaldo
            if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                $archivo_temporal = $_FILES['backup_file']['tmp_name'];
                $nombre_archivo = $_FILES['backup_file']['name'];
                
                // Validar que sea un archivo JSON
                $extension = strtolower(pathinfo($nombre_archivo, PATHINFO_EXTENSION));
                if ($extension !== 'json') {
                    echo json_encode(['success' => false, 'message' => 'El archivo debe ser de formato JSON']);
                    exit;
                }
                
                // Leer y validar el contenido del archivo
                $contenido = file_get_contents($archivo_temporal);
                $backup = json_decode($contenido, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo json_encode(['success' => false, 'message' => 'El archivo JSON no es válido']);
                    exit;
                }
                
                // Iniciar transacción
                $pdo->beginTransaction();
                
                try {
                    // Restaurar cada tabla
                    foreach ($backup as $tabla => $datos) {
                        if (!is_array($datos) || isset($datos['error'])) {
                            continue; // Omitir tablas con error
                        }
                        
                        // Limpiar tabla
                        $pdo->exec("DELETE FROM $tabla");
                        
                        // Insertar datos
                        $columnas = array_keys($datos[0]);
                        $placeholders = implode(',', array_fill(0, count($columnas), '?'));
                        
                        $stmt = $pdo->prepare("INSERT INTO $tabla (" . implode(',', $columnas) . ") VALUES ($placeholders)");
                        
                        foreach ($datos as $fila) {
                            $valores = array_map(function($columna) use ($fila) {
                                return $fila[$columna] ?? null;
                            }, $columnas);
                            
                            $stmt->execute($valores);
                        }
                    }
                    
                    // Confirmar transacción
                    $pdo->commit();
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Datos restaurados exitosamente'
                    ]);
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Error al restaurar: ' . $e->getMessage()
                    ]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al subir el archivo']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
        }
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error en el servidor: ' . $e->getMessage()]);
}
?>