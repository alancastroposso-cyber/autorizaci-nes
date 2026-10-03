<?php
/*
  ESTADO DE AUTORIZACIONES - configuración.
  Con XAMPP recién instalado estos valores funcionan tal cual
  (usuario root sin contraseña). La base de datos y las tablas se crean solas
  la primera vez que alguien abre la página de inicio de sesión.
*/
$DB_HOST = '127.0.0.1';
$DB_PORT = 3306;
$DB_NAME = 'estado_autorizaciones';
$DB_USER = 'root';
$DB_PASS = '';

// Minutos sin actividad tras los cuales se cierra la sesión.
$MINUTOS_SESION = 180;

// Tablero (tablero.php): true = lo puede ver cualquier funcionario sin iniciar
// sesión (solo lectura, nombres abreviados). false = exige usuario.
$TABLERO_PUBLICO = true;

date_default_timezone_set('America/Bogota');

// Permite que las pruebas locales apunten a otra base sin tocar este archivo.
if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';

// --- Sesión: cookie solo por HTTP y solo en navegación del mismo sitio ---
$esHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('autorizaciones');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $esHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$DB_NAME}`");
} catch (PDOException $e) {
    error_log('Error de conexión a la base de datos: ' . $e->getMessage());
    http_response_code(500);
    die('No se pudo conectar a MySQL. Verifique que MySQL esté encendido en el panel de XAMPP y revise config.php.');
}

require __DIR__ . '/reglas.php';

function e($t) {
    return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
}

function usuarioActual() {
    return $_SESSION['usuario'] ?? null;
}

// Devuelve el usuario con sesión activa, o null si no hay sesión o ya expiró.
function sesionValida($pdo) {
    global $MINUTOS_SESION;
    $u = usuarioActual();
    if (!$u) return null;
    if (time() - ($_SESSION['actividad'] ?? 0) > $MINUTOS_SESION * 60) {
        session_unset();
        return null;
    }
    // Se relee de la base para que desactivar un usuario o cambiar sus
    // permisos tenga efecto inmediato.
    $st = $pdo->prepare('SELECT * FROM usuarios WHERE id = ? AND activo = 1');
    $st->execute([$u['id']]);
    $fila = $st->fetch();
    if (!$fila) {
        session_unset();
        return null;
    }
    $_SESSION['actividad'] = time();
    $_SESSION['usuario'] = datosSesion($fila);
    return $_SESSION['usuario'];
}

function datosSesion($fila) {
    $secciones = $fila['es_admin'] ? array_keys(SECCIONES)
        : array_values(array_filter(explode(',', $fila['secciones'])));
    return [
        'id' => (int)$fila['id'],
        'usuario' => $fila['usuario'],
        'nombre' => $fila['nombre'],
        'cargo' => $fila['cargo'],
        'es_admin' => (bool)$fila['es_admin'],
        'secciones' => $secciones,
        'debe_cambiar' => (bool)$fila['debe_cambiar'],
    ];
}

function tokenCsrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function validarClave($clave) {
    if (strlen($clave) < 8) return 'La contraseña debe tener al menos 8 caracteres.';
    if (!preg_match('/[A-Za-z]/', $clave) || !preg_match('/\d/', $clave)) {
        return 'La contraseña debe tener letras y números.';
    }
    return null;
}

// Crea las tablas y el usuario admin si no existen. Se llama desde login.php.
function instalar($pdo) {
    $cols = [];
    foreach (todosLosCampos() as $c) {
        $largo = in_array($c, CAMPOS_LARGOS, true) ? 1000 : 120;
        $cols[] = "`$c` VARCHAR($largo) NOT NULL DEFAULT ''";
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS ingresos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        " . implode(",\n        ", $cols) . ",
        creado DATETIME NOT NULL,
        actualizado DATETIME NOT NULL,
        UNIQUE KEY uq_numero_ingreso (numero_ingreso)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Columnas agregadas en versiones posteriores
    $existentes = $pdo->query('SHOW COLUMNS FROM ingresos')->fetchAll(PDO::FETCH_COLUMN);
    foreach (todosLosCampos() as $c) {
        if (!in_array($c, $existentes, true)) {
            $largo = in_array($c, CAMPOS_LARGOS, true) ? 1000 : 120;
            $pdo->exec("ALTER TABLE ingresos ADD COLUMN `$c` VARCHAR($largo) NOT NULL DEFAULT ''");
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS historial (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ingreso_id INT NOT NULL,
        fecha DATETIME NOT NULL,
        usuario VARCHAR(120) NOT NULL,
        campo VARCHAR(60) NOT NULL,
        antes VARCHAR(1000) NOT NULL DEFAULT '',
        despues VARCHAR(1000) NOT NULL DEFAULT '',
        KEY ix_ingreso (ingreso_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario VARCHAR(50) NOT NULL UNIQUE,
        nombre VARCHAR(120) NOT NULL,
        cargo VARCHAR(60) NOT NULL DEFAULT '',
        secciones VARCHAR(120) NOT NULL DEFAULT '',
        es_admin TINYINT(1) NOT NULL DEFAULT 0,
        clave_hash VARCHAR(255) NOT NULL,
        debe_cambiar TINYINT(1) NOT NULL DEFAULT 1,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        intentos INT NOT NULL DEFAULT 0,
        bloqueado_hasta DATETIME NULL,
        ultimo_ingreso DATETIME NULL,
        creado DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $hay = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE usuario = 'admin'")->fetchColumn();
    if (!$hay) {
        // Clave inicial "admin": el sistema obliga a cambiarla en el primer ingreso.
        $st = $pdo->prepare("INSERT INTO usuarios (usuario, nombre, cargo, es_admin, clave_hash, debe_cambiar, creado)
                             VALUES ('admin', 'Administrador', 'Administrador', 1, ?, 1, ?)");
        $st->execute([password_hash('admin', PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
    }
}
