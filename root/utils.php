<?php
require_once __DIR__ . '/config.php';

class Utils {
    private static $clave = 'Parroquia';  // Clave para encriptación de cookies

    /**
     * Valida login verificando credenciales en BD
     * @param string $documento
     * @param string $password
     * @return bool|array Usuario si válido, false si no
     */
    public static function validarLogin($documento, $password) {
        $pdo = getDBConnection();
        
        // Verificar si existe empleado con documento
        $stmt = $pdo->prepare("SELECT id, nombre, apellido, password, id_cargo FROM empleado WHERE documento = ?");
        $stmt->execute([$documento]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            return $user;
        }
        
        // Si no existe, crear admin default
        $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);  // Cambiar en producción
        $stmt = $pdo->prepare("INSERT IGNORE INTO empleado (nombre, apellido, password, id_tipodoc, documento, id_cargo, id_banco, id_ciudad, id_tipcue, cuenta, salario) VALUES ('Admin', 'Sistema', ?, 1, '123456', 1, 1, 1, 1, '000000', 0)");
        $stmt->execute([$adminPassword]);
        
        // Re-verificar
        $stmt = $pdo->prepare("SELECT id, nombre, apellido, password, id_cargo FROM empleado WHERE documento = '123456'");
        $stmt->execute();
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin && password_verify('admin123', $admin['password'])) {
            return $admin;
        }
        
        return false;
    }

    /**
     * Crea cookie encriptada
     * @param string $name
     * @param string $value
     * @param int $expire
     */
    public static function crearCookie($name, $value, $expire = 0) {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', self::$clave, 0, $iv);
        $token = base64_encode($encrypted . '::' . base64_encode($iv));
        setcookie($name, $token, $expire, '/', '', false, true);  // Secure, HttpOnly
    }

    /**
     * Lee y desencripta cookie
     * @param string $name
     * @return string|false Valor desencriptado o false
     */
    public static function leerCookie($name) {
        if (!isset($_COOKIE[$name])) {
            return false;
        }
        
        $token = $_COOKIE[$name];
        list($encrypted_data, $iv) = explode('::', base64_decode($token), 2);
        $iv = base64_decode($iv);
        $decrypted = openssl_decrypt($encrypted_data, 'aes-256-cbc', self::$clave, 0, $iv);
        
        return $decrypted;
    }

    /**
     * Valida si la cookie auth es válida (ej. contiene ID usuario)
     * @param string $name
     * @return bool
     */
    public static function validarCookie($name) {
        $value = self::leerCookie($name);
        return $value !== false && !empty($value);
    }

    /**
     * Retorna el usuario autenticado (id, nombre, apellido, id_cargo)
     * Usa caché estático por request para evitar múltiples consultas
     * @return array|null
     */
    public static function getCurrentUser() {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $userId = self::leerCookie('auth');
        if ($userId === false) {
            return null;
        }
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT id, nombre, apellido, id_cargo FROM empleado WHERE id = ?");
            $stmt->execute([$userId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            $cache = $u ?: null;
            return $cache;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Retorna el id_cargo del usuario autenticado (0 si no hay usuario)
     * @return int
     */
    public static function getUserCargo() {
        $u = self::getCurrentUser();
        return (int)($u['id_cargo'] ?? 0);
    }
}
?>