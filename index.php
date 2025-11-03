<?php

require_once __DIR__ . '/root/config.php';
// Verificar autenticación
if (!isset($_COOKIE['auth']) || Utils::leerCookie('auth') === false) {
    header("Location: {$_URL_}/login.php");
    exit;
}

$userId = Utils::leerCookie('auth');

// Routing centralizado basado en $_GET['module']
$module = $_GET['module'] ?? 'dashboard';
$action = $_GET['action'] ?? null;
$id = (int)($_GET['id'] ?? 0);
$page = null;

// Roles y acceso
$cargo = (int)Utils::getUserCargo();
$isAdmin = in_array($cargo, [1,2], true);
if (!$isAdmin) {
    $generalAllowed = ['comprobante'];
    if (!in_array($module, $generalAllowed, true)) {
        header("Location: {$_URL_}/comprobante");
        exit;
    }
}
switch ($module) {
    case 'dashboard':
        $page = __DIR__ . '/modulos/dashboard.php';
        break;
    case 'empleados':
        $page = __DIR__ . '/modulos/empleados.php';
        break;
    case 'liquidacion':
        $page = __DIR__ . '/modulos/liquidacion.php';
        break;
    case 'aprobacion':
        $page = __DIR__ . '/modulos/aprobacion.php';
        break;
    case 'comprobante':
        $page = __DIR__ . '/modulos/comprobante.php';
        break;
    case 'reportes':
        $page = __DIR__ . '/modulos/reportes.php';
        break;
    case 'importarEmpleados':
        $page = __DIR__ . '/modulos/importarEmpleados.php';
        break;
    case 'importarNovedades':
        $page = __DIR__ . '/modulos/importarNovedades.php';
        break;
    case 'novedades':
        $page = __DIR__ . '/modulos/novedades.php';
        break;
    case 'manual':
        $page = __DIR__ . '/modulos/manual.php';
        break;
    case 'configuracion':
        $page = __DIR__ . '/modulos/configuracion.php';
        break;
    default:
        $module = 'dashboard';
        $page = __DIR__ . '/modulos/dashboard.php';
        break;
}

// Si es dashboard o módulo válido, incluir bloques y página

// Detectar exportación CSV en reportes para omitir layout
$isExport = ($module === 'reportes' && isset($_GET['export']) && $_GET['export'] === 'csv');
$isImportTemplate = (($module === 'importarEmpleados' || $module === 'importarNovedades') && $action === 'plantilla');
if ($isExport || $isImportTemplate) {
    if ($page && file_exists($page)) {
        include $page;
    }
    exit;  // Salir después de incluir el módulo para salida sin layout (CSV/plantilla)
}

// Pasar $action y $id al módulo
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $_NOMBRE_; ?> - <?php echo ucfirst($module); ?></title>
    <link rel="icon" type="image/x-icon" href="<?php echo $_URL_; ?>/dist/images/favicon.ico">
    <link rel="shortcut icon" href="<?php echo $_URL_; ?>/dist/images/favicon.ico">
    <link rel="stylesheet" href="<?php echo $_URL_; ?>/dist/css/bootstrap.css">
    <link rel="stylesheet" href="<?php echo $_URL_; ?>/dist/css/bootstrap-icons/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo $_URL_; ?>/dist/css/estilos.css">
    <style>
        html, body { height: 100%; }
        body { min-height: 100vh; display: flex; flex-direction: column; }
    </style>
</head>
<body>
    <!-- header moved to content column -->
    <div class="container-fluid flex-grow-1">
        <div class="row h-100">
            <?php include __DIR__ . '/bloques/sidebar.php'; ?>
            <div class="col-md-9 ms-sm-auto col-lg-10 px-md-0 flex-grow-1 d-flex flex-column">
                <?php include __DIR__ . '/bloques/header.php'; ?>
                <main class="px-md-4 py-4 flex-grow-1 d-flex flex-column">
                    <?php if ($page && file_exists($page)) {
                        include $page;
                    } else {
                        echo "Módulo no encontrado.";
                    } ?>
                </main>
            </div>
        </div>
    </div>
    
    <?php include __DIR__ . '/bloques/footer.php'; ?>
    <script src="<?php echo $_URL_; ?>/dist/js/bootstrap.min.js"></script>
</body>
</html>