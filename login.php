<?php
require_once __DIR__ . '/root/config.php';
require_once __DIR__ . '/root/utils.php';

// Si ya autenticado, redirect a index
if (isset($_COOKIE['auth']) && Utils::leerCookie('auth') !== false) {
    header("Location: {$_URL_}index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - <?php echo $_NOMBRE_; ?></title>
    <link rel="stylesheet" href="<?php echo $_URL_; ?>/dist/css/bootstrap.css">
    <link rel="stylesheet" href="<?php echo $_URL_; ?>/dist/css/bootstrap-icons/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo $_URL_; ?>/dist/css/estilos.css">
    <style>
    body {
        background: linear-gradient(135deg,
        var(--colorPrimarioOscuro) 0%,
        var(--colorPrimario) 50%,
        var(--colorPrimarioClaro) 100%);
        display: flex;
        justify-content: center;
        align-items: center;
    }
    </style>
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center align-items-center vh-100">
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-body p-4">
                        <h3 class="card-title text-center mb-4"><?php echo $_NOMBRE_; ?></h3>
                        <form id="loginForm">
                            <div class="mb-3">
                                <input type="text" class="form-control" id="documento" name="documento" placeholder="" required>
                                <label for="documento" class="form-label">Documento</label>
                            </div>
                            <div class="mb-3">
                                <input type="password" class="form-control" id="password" name="password" placeholder="" required>
                                <label for="password" class="form-label">Contraseña</label>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Iniciar Sesión</button>
                        </form>
                        <div id="errorMsg" class="alert alert-danger mt-3" style="display: none;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="<?php echo $_URL_; ?>/dist/js/bootstrap.min.js"></script>
    <script>
        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = {
                documento: document.getElementById('documento').value,
                password: document.getElementById('password').value
            };
            const errorDiv = document.getElementById('errorMsg');
            errorDiv.style.display = 'none';

            try {
                const response = await fetch('<?php echo $_URL_; ?>/api/login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();

                if (data.success) {
                    window.location.href = '<?php echo $_URL_; ?>/index.php';
                } else {
                    errorDiv.textContent = data.message || 'Error en el login';
                    errorDiv.style.display = 'block';
                }
            } catch (error) {
                errorDiv.textContent = 'Error de conexión';
                errorDiv.style.display = 'block';
            }
        });
    </script>
</body>
</html>