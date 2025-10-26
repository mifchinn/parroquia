<?php
// Página de Manual de Usuario (visible para todos los roles)
// Se asume que este módulo es incluido por [index.php](index.php) y ya están disponibles $_URL_ y estilos.
?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Manual de Usuario</h1>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <p>Consulta aquí la guía de uso del sistema de nómina.</p>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" href="<?php echo $_URL_; ?>/dist/manual.pdf" target="_blank" rel="noopener">
                <i class="bi bi-file-earmark-pdf me-1"></i>
                Ver/Descargar Manual (PDF)
            </a>
        </div>
        <hr>
        <div class="small text-muted">
            Nota: si el archivo no se abre, verifique que exista en <code>/dist/manual.pdf</code>.
        </div>
    </div>
</div>