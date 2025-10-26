<?php
// API para manejar respaldos y restauración
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
    $action = $_POST['action'] ?? '';
    
    if ($action === 'crear_respaldo') {
        // Obtener toda la información necesaria
        $respaldo = [];
        
        // 1. Configuración de la empresa
        $stmt = $pdo->query("SELECT * FROM configuracion WHERE id = 1");
        $respaldo['configuracion'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 2. Tipos de documento
        $stmt = $pdo->query("SELECT * FROM tipodocumento");
        $respaldo['tipodocumento'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 3. Cargos
        $stmt = $pdo->query("SELECT * FROM cargo");
        $respaldo['cargo'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 4. Bancos
        $stmt = $pdo->query("SELECT * FROM banco");
        $respaldo['banco'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 5. Ciudades
        $stmt = $pdo->query("SELECT * FROM ciudad");
        $respaldo['ciudad'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 6. Tipos de cuenta
        $stmt = $pdo->query("SELECT * FROM tipocuenta");
        $respaldo['tipocuenta'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 7. Empleados
        $stmt = $pdo->query("SELECT * FROM empleado");
        $respaldo['empleado'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 8. Liquidaciones
        try {
            // Verificar primero si la tabla existe
            $stmt = $pdo->query("SHOW TABLES LIKE 'liquidacion'");
            $tabla_existe = $stmt->rowCount() > 0;
            
            if ($tabla_existe) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM liquidacion");
                $count = $stmt->fetchColumn();
                error_log("Tabla liquidacion existe con $count registros");
                
                if ($count > 0) {
                    $stmt = $pdo->query("SELECT * FROM liquidacion");
                    $respaldo['liquidacion'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $respaldo['liquidacion'] = [];
                    error_log("Tabla liquidacion existe pero está vacía");
                }
            } else {
                $respaldo['liquidacion'] = [];
                error_log("Tabla liquidacion no existe en la base de datos");
            }
        } catch (Exception $e) {
            // Si hay error al obtener liquidaciones, registrar y continuar
            $respaldo['liquidacion'] = [];
            error_log("Error al obtener liquidaciones: " . $e->getMessage());
        }
        
        // 9. Deducciones
        try {
            $stmt = $pdo->query("SELECT * FROM deducciones");
            $respaldo['deducciones'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $respaldo['deducciones'] = [];
            error_log("Error al obtener deducciones: " . $e->getMessage());
        }
        
        // 10. Aportes
        try {
            $stmt = $pdo->query("SELECT * FROM aportes");
            $respaldo['aportes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $respaldo['aportes'] = [];
            error_log("Error al obtener aportes: " . $e->getMessage());
        }
        
        // 11. Prestaciones
        try {
            $stmt = $pdo->query("SELECT * FROM prestaciones");
            $respaldo['prestaciones'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $respaldo['prestaciones'] = [];
            error_log("Error al obtener prestaciones: " . $e->getMessage());
        }
        
        // 12. Aprobaciones
        try {
            $stmt = $pdo->query("SELECT * FROM aprobacion");
            $respaldo['aprobacion'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $respaldo['aprobacion'] = [];
            error_log("Error al obtener aprobaciones: " . $e->getMessage());
        }
        
        // 13. Comprobantes
        try {
            $stmt = $pdo->query("SELECT * FROM comprobante");
            $respaldo['comprobante'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $respaldo['comprobante'] = [];
            error_log("Error al obtener comprobantes: " . $e->getMessage());
        }
        
        // Crear directorio de respaldos si no existe
        $backup_dir = __DIR__ . '/../respaldos';
        if (!file_exists($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        // Verificar permisos de escritura
        if (!is_writable($backup_dir)) {
            echo json_encode([
                'success' => false,
                'message' => 'El directorio de respaldos no tiene permisos de escritura',
                'path' => $backup_dir
            ]);
            exit;
        }
        
        // Generar nombre de archivo
        $filename = 'respaldo_' . date('Y-m-d_H-i-s') . '.json';
        $filepath = $backup_dir . '/' . $filename;
        
        // Guardar respaldo en formato JSON
        $json_content = json_encode($respaldo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        // Intentar guardar con lock para evitar problemas de concurrencia
        $result = false;
        $fp = fopen($filepath, 'w');
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $json_content);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            $result = true;
        } else {
            fclose($fp);
        }
        
        // Verificar si se guardó correctamente
        if ($result === false) {
            $error_msg = error_get_last()['message'] ?? 'Error desconocido';
            error_log("Error al guardar respaldo: " . $error_msg);
            error_log("Directorio: " . $backup_dir);
            error_log("Permisos: " . (is_writable($backup_dir) ? 'Writable' : 'Not writable'));
            error_log("JSON size: " . strlen($json_content) . " bytes");
            
            echo json_encode([
                'success' => false,
                'message' => 'Error al guardar el archivo de respaldo',
                'error' => $error_msg,
                'backup_dir' => $backup_dir,
                'writable' => is_writable($backup_dir),
                'json_size' => strlen($json_content)
            ]);
            exit;
        }
        
        // Verificación final: asegurar que el archivo existe y tiene contenido
        clearstatcache();
        if (!file_exists($filepath) || filesize($filepath) < 100) {
            error_log("Error crítico: El archivo de respaldo no existe o está vacío: " . $filepath);
            echo json_encode([
                'success' => false,
                'message' => 'Error crítico: El archivo de respaldo no se creó correctamente',
                'path' => $filepath,
                'exists' => file_exists($filepath),
                'size' => filesize($filepath)
            ]);
            exit;
        }
        
        // Verificar el tamaño del archivo guardado
        $file_size = filesize($filepath);
        if ($file_size === 0) {
            echo json_encode([
                'success' => false,
                'message' => 'El archivo de respaldo se guardó vacío',
                'path' => $filepath
            ]);
            exit;
        }
        
        // Actualizar fecha de último respaldo en configuración
        $stmt = $pdo->prepare("UPDATE configuracion SET fecha_respaldo = CURRENT_TIMESTAMP WHERE id = 1");
        $stmt->execute();
        
        // Verificar si se solicita descarga automática
        $download = $_POST['download'] ?? 'false';
        
        // Contar registros para depuración
        $totales = [];
        foreach ($respaldo as $tabla => $datos) {
            if (is_array($datos)) {
                $totales[$tabla] = count($datos);
            } else {
                $totales[$tabla] = 0;
            }
        }
        
        if ($download === 'true') {
            // Descargar el archivo
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            readfile($filepath);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Respaldo creado exitosamente',
            'filename' => $filename,
            'download_url' => $_URL_ . '/respaldos/' . $filename,
            'totales' => $totales,
            'file_size' => $file_size,
            'liquidaciones_count' => isset($respaldo['liquidacion']) ? count($respaldo['liquidacion']) : 0
        ]);
        
    } elseif ($action === 'restaurar_respaldo') {
        if (!isset($_FILES['respaldo_file'])) {
            echo json_encode(['success' => false, 'message' => 'No se seleccionó ningún archivo']);
            exit;
        }
        
        $file = $_FILES['respaldo_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Error al subir el archivo']);
            exit;
        }
        
        // Leer y decodificar el JSON
        $json_content = file_get_contents($file['tmp_name']);
        $respaldo = json_decode($json_content, true);
        
        if (!$respaldo) {
            echo json_encode(['success' => false, 'message' => 'El archivo no es un JSON válido']);
            exit;
        }
        
        $pdo->beginTransaction();
        $restaurados = 0;
        $errores = [];
        
        try {
            // Restaurar configuración (solo si no existe)
            if (isset($respaldo['configuracion'])) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM configuracion WHERE id = 1");
                $stmt->execute();
                if ((int)$stmt->fetchColumn() === 0) {
                    $config = $respaldo['configuracion'];
                    $stmt = $pdo->prepare("INSERT INTO configuracion (id, nombre, nit, direccion, telefono, otro, email, tasasalud, tasapension, factor_extras, fecha_creacion) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $config['nombre'] ?? '',
                        $config['nit'] ?? '',
                        $config['direccion'] ?? '',
                        $config['telefono'] ?? '',
                        $config['otro'] ?? null,
                        $config['email'] ?? '',
                        $config['tasasalud'] ?? 4,
                        $config['tasapension'] ?? 4,
                        $config['factor_extras'] ?? 1.25
                    ]);
                    $restaurados++;
                }
            }
            
            // Restaurar tipos de documento (evitando duplicados por nombre)
            if (isset($respaldo['tipodocumento'])) {
                foreach ($respaldo['tipodocumento'] as $item) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tipodocumento WHERE nombre = ?");
                    $stmt->execute([$item['nombre']]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $stmt = $pdo->prepare("INSERT INTO tipodocumento (id, nombre) VALUES (?, ?)");
                        $stmt->execute([$item['id'], $item['nombre']]);
                        $restaurados++;
                    }
                }
            }
            
            // Restaurar cargos (evitando duplicados por nombre)
            if (isset($respaldo['cargo'])) {
                foreach ($respaldo['cargo'] as $item) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cargo WHERE nombre = ?");
                    $stmt->execute([$item['nombre']]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $stmt = $pdo->prepare("INSERT INTO cargo (id, nombre) VALUES (?, ?)");
                        $stmt->execute([$item['id'], $item['nombre']]);
                        $restaurados++;
                    }
                }
            }
            
            // Restaurar bancos (evitando duplicados por nombre)
            if (isset($respaldo['banco'])) {
                foreach ($respaldo['banco'] as $item) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM banco WHERE nombre = ?");
                    $stmt->execute([$item['nombre']]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $stmt = $pdo->prepare("INSERT INTO banco (id, nombre) VALUES (?, ?)");
                        $stmt->execute([$item['id'], $item['nombre']]);
                        $restaurados++;
                    }
                }
            }
            
            // Restaurar ciudades (evitando duplicados por nombre)
            if (isset($respaldo['ciudad'])) {
                foreach ($respaldo['ciudad'] as $item) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ciudad WHERE nombre = ?");
                    $stmt->execute([$item['nombre']]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $stmt = $pdo->prepare("INSERT INTO ciudad (id, nombre) VALUES (?, ?)");
                        $stmt->execute([$item['id'], $item['nombre']]);
                        $restaurados++;
                    }
                }
            }
            
            // Restaurar tipos de cuenta (evitando duplicados por nombre)
            if (isset($respaldo['tipocuenta'])) {
                foreach ($respaldo['tipocuenta'] as $item) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tipocuenta WHERE nombre = ?");
                    $stmt->execute([$item['nombre']]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $stmt = $pdo->prepare("INSERT INTO tipocuenta (id, nombre) VALUES (?, ?)");
                        $stmt->execute([$item['id'], $item['nombre']]);
                        $restaurados++;
                    }
                }
            }
            
            // Restaurar empleados (evitando duplicados por documento)
            if (isset($respaldo['empleado'])) {
                foreach ($respaldo['empleado'] as $item) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM empleado WHERE documento = ?");
                    $stmt->execute([$item['documento']]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $stmt = $pdo->prepare("INSERT INTO empleado (id, nombre, apellido, password, id_tipodoc, documento, id_cargo, id_banco, id_ciudad, id_tipcue, cuenta, salario, fechacreacion, fechamodificacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $item['id'],
                            $item['nombre'],
                            $item['apellido'],
                            $item['password'],
                            $item['id_tipodoc'],
                            $item['documento'],
                            $item['id_cargo'],
                            $item['id_banco'],
                            $item['id_ciudad'],
                            $item['id_tipcue'],
                            $item['salario'],
                            $item['fechacreacion'],
                            $item['fechamodificacion']
                        ]);
                        $restaurados++;
                    }
                }
            }
            
            // Restaurar liquidaciones
            if (isset($respaldo['liquidacion'])) {
                foreach ($respaldo['liquidacion'] as $item) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM liquidacion WHERE id = ?");
                    $stmt->execute([$item['id']]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $stmt = $pdo->prepare("INSERT INTO liquidacion (id, id_empleado, mes, ano, dias_trabajados, salario_base, devengos, deducciones_total, aportes_total, total_neto, tipo_liquidacion, fecha_liquidacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $item['id'],
                            $item['id_empleado'],
                            $item['mes'],
                            $item['ano'],
                            $item['dias_trabajados'],
                            $item['salario_base'],
                            $item['devengos'],
                            $item['deducciones_total'],
                            $item['aportes_total'],
                            $item['total_neto'],
                            $item['tipo_liquidacion'],
                            $item['fecha_liquidacion']
                        ]);
                        $restaurados++;
                    }
                }
            }
            
            // Restaurar deducciones
            if (isset($respaldo['deducciones'])) {
                foreach ($respaldo['deducciones'] as $item) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM deducciones WHERE id = ?");
                    $stmt->execute([$item['id']]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $stmt = $pdo->prepare("INSERT INTO deducciones (id, id_liquidacion, tipo, monto, base, porcentaje) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $item['id'],
                            $item['id_liquidacion'],
                            $item['tipo'],
                            $item['monto'],
                            $item['base'],
                            $item['porcentaje']
                        ]);
                        $restaurados++;
                    }
                }
            }
            
            // Restaurar aportes
            if (isset($respaldo['aportes'])) {
                foreach ($respaldo['aportes'] as $item) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM aportes WHERE id = ?");
                    $stmt->execute([$item['id']]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $stmt = $pdo->prepare("INSERT INTO aportes (id, id_liquidacion, tipo, monto, base, porcentaje, cobrado, fecha_cobro) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $item['id'],
                            $item['id_liquidacion'],
                            $item['tipo'],
                            $item['monto'],
                            $item['base'],
                            $item['porcentaje'],
                            $item['cobrado'],
                            $item['fecha_cobro']
                        ]);
                        $restaurados++;
                    }
                }
            }
            
            // Restaurar prestaciones
            if (isset($respaldo['prestaciones'])) {
                foreach ($respaldo['prestaciones'] as $item) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM prestaciones WHERE id = ?");
                    $stmt->execute([$item['id']]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $stmt = $pdo->prepare("INSERT INTO prestaciones (id, id_empleado, tipo, monto, base, fecha) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $item['id'],
                            $item['id_empleado'],
                            $item['tipo'],
                            $item['monto'],
                            $item['base'],
                            $item['fecha']
                        ]);
                        $restaurados++;
                    }
                }
            }
            
            // Restaurar aprobaciones
            if (isset($respaldo['aprobacion'])) {
                foreach ($respaldo['aprobacion'] as $item) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM aprobacion WHERE id = ?");
                    $stmt->execute([$item['id']]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $stmt = $pdo->prepare("INSERT INTO aprobacion (id, id_liquidacion, estado, fecha_aprobacion, observaciones) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $item['id'],
                            $item['id_liquidacion'],
                            $item['estado'],
                            $item['fecha_aprobacion'],
                            $item['observaciones']
                        ]);
                        $restaurados++;
                    }
                }
            }
            
            // Restaurar comprobantes
            if (isset($respaldo['comprobante'])) {
                foreach ($respaldo['comprobante'] as $item) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comprobante WHERE id = ?");
                    $stmt->execute([$item['id']]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $stmt = $pdo->prepare("INSERT INTO comprobante (id, id_liquidacion, ruta_archivo, fecha_generacion) VALUES (?, ?, ?, ?)");
                        $stmt->execute([
                            $item['id'],
                            $item['id_liquidacion'],
                            $item['ruta_archivo'] ?? null,
                            $item['fecha_generacion'] ?? null
                        ]);
                        $restaurados++;
                    }
                }
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => "Restauración completada. Se restauraron $restaurados registros.",
                'restaurados' => $restaurados
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error en el servidor: ' . $e->getMessage()
    ]);
}
?>