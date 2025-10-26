<?php
require_once __DIR__ . '/../root/config.php';
require_once __DIR__ . '/../root/utils.php';

// Verificar autenticación
if (!isset($_COOKIE['auth']) || Utils::leerCookie('auth') === false) {
    header("Location: {$_URL_}/login.php");
    exit;
}
$userId = Utils::leerCookie('auth');

// Obtener nombre completo del usuario autenticado
$fullName = 'Usuario';
try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT nombre, apellido FROM empleado WHERE id = ?");
    $stmt->execute([$userId]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $fullName = trim(($row['nombre'] ?? '') . ' ' . ($row['apellido'] ?? ''));
        if ($fullName === '') {
            $fullName = 'Usuario';
        }
    }
} catch (Throwable $e) {
    // En caso de error, se mantiene nombre genérico sin romper la UI
}
?>

<header class="bg-white border-bottom shadow-sm">
    <div class="container-fluid">
        <div class="d-flex align-items-center justify-content-between py-2">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-person-circle"></i>
                <span class="fw-medium text-body"><?php echo htmlspecialchars($fullName); ?></span>
            </div>
            <a class="btn btn-outline-danger btn-sm" href="<?php echo $_URL_; ?>/logout.php">
                <i class="bi bi-box-arrow-right me-1"></i>
                Cerrar sesión
            </a>
        </div>
    </div>
</header>
