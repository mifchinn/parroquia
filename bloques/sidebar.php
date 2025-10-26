<?php
// Sidebar sin auth check, enlaces clean con /modulos/
$cargoSidebar = (int)Utils::getUserCargo();
$isAdminSidebar = in_array($cargoSidebar, [1,2], true);
?>
<style>
/* Logo en sidebar */
.sidebar .logo-box {
    width: 150px;
    height: 150px;
    background-color: #fff;
    background-image: url('<?php echo $_URL_; ?>/dist/images/logo.png');
    background-repeat: no-repeat;
    background-position: center;
    background-size: contain; /* ajusta sin recortar */
    border-radius: .5rem; /* opcional: esquinas suaves */
    box-shadow: 0 0 0 1px rgba(0,0,0,.05) inset; /* sutil delineado */
}

/* Rotación de flechita en el menú Importar */
.sidebar .nav-link .chevron {
    transition: transform 0.2s ease-in-out;
}
.sidebar .nav-link[aria-expanded="true"] .chevron {
    transform: rotate(90deg);
}
</style>
<nav class="bg-primary col-md-2 d-none d-md-block sidebar h-100">
    <div class="position-sticky pt-3">
        <a class="navbar-brand d-block text-center text-white text-decoration-none mb-3" href="<?php echo $_URL_; ?>/index.php">
            <div class="d-flex justify-content-center mb-2">
                <div class="logo-box"></div>
            </div>
            <div class="fw-semibold">Sistema de nómina</div>
            <div class="small opacity-75"><?php echo $_NOMBRE_; ?></div>
        </a>
        <ul class="nav flex-column">
        <?php if ($isAdminSidebar): ?>
            <li class="nav-item">
                <a class="nav-link text-white px-3 py-2" href="<?php echo $_URL_; ?>/index.php">
                    <i class="bi bi-house-door me-2"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white px-3 py-2" href="<?php echo $_URL_; ?>/empleados">
                    <i class="bi bi-people me-2"></i> Empleados
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white px-3 py-2" href="<?php echo $_URL_; ?>/liquidacion">
                    <i class="bi bi-calculator me-2"></i> Liquidacion
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white px-3 py-2" href="<?php echo $_URL_; ?>/aprobacion">
                    <i class="bi bi-check-circle me-2"></i> Aprobacion
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white px-3 py-2" href="<?php echo $_URL_; ?>/comprobante">
                    <i class="bi bi-file-earmark-text me-2"></i> Comprobantes
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white px-3 py-2" href="<?php echo $_URL_; ?>/reportes?tipo=liquidaciones">
                    <i class="bi bi-bell me-2"></i> Novedades
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white px-3 py-2" href="<?php echo $_URL_; ?>/reportes">
                    <i class="bi bi-bar-chart me-2"></i> Reportes
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white px-3 py-2 d-flex align-items-center gap-2 justify-content-between w-100" data-bs-toggle="collapse" href="#submenuImportar" role="button" aria-expanded="false" aria-controls="submenuImportar">
                    <span>Importar</span>
                    <i class="bi bi-chevron-right chevron"></i>
                </a>
                <div class="collapse" id="submenuImportar">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item">
                            <a class="nav-link text-white px-3 py-1 d-flex align-items-center gap-2" href="<?php echo $_URL_; ?>/importarEmpleados">
                                <i class="bi bi-person-plus"></i>
                                <span class="text-truncate">Importar Empleados</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white px-3 py-2" href="<?php echo $_URL_; ?>/configuracion">
                    <i class="bi bi-gear me-2"></i> Configuración
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white px-3 py-2" href="<?php echo $_URL_; ?>/manual">
                    <i class="bi bi-journal-bookmark me-2"></i> Manual de usuario
                </a>
            </li>
        <?php else: ?>
            <li class="nav-item">
                <a class="nav-link text-white px-3 py-2" href="<?php echo $_URL_; ?>/comprobante">
                    <i class="bi bi-file-earmark-text me-2"></i> Comprobantes
                </a>
            </li>
        <?php endif; ?>
        </ul>
    </div>
</nav>
<style>
/* Hover para ítems del sidebar (Dashboard, Empleados, Importar, etc.) */
.sidebar .nav-link {
  border-radius: .375rem; /* 6px aprox */
  transition: background-color .15s ease-in-out, color .15s ease-in-out;
}

/* Hover/focus general */
.sidebar .nav-link:hover,
.sidebar .nav-link:focus {
  background-color: rgba(255, 255, 255, 0.12);
  color: #fff;
  text-decoration: none;
}

/* Íconos dentro de los enlaces en hover/focus */
.sidebar .nav-link:hover .bi,
.sidebar .nav-link:focus .bi {
  color: #fff;
}

/* Submenú: Importar Empleados */
.sidebar .collapse .nav-link {
  border-radius: .375rem;
  transition: background-color .15s ease-in-out, color .15s ease-in-out;
}
.sidebar .collapse .nav-link:hover,
.sidebar .collapse .nav-link:focus {
  background-color: rgba(255, 255, 255, 0.10);
  color: #fff;
}

/* Mantener transición y rotación de flechita si ya existe */
.sidebar .nav-link .chevron {
  transition: transform 0.2s ease-in-out;
}
.sidebar .nav-link[aria-expanded="true"] .chevron {
  transform: rotate(90deg);
}
</style>