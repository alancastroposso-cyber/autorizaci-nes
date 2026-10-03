<?php
require __DIR__ . '/config.php';
instalar($pdo);

const MAX_INTENTOS = 5;
const MINUTOS_BLOQUEO = 10;

if (sesionValida($pdo)) {
    header('Location: index.php');
    exit;
}

$error = '';
$usuarioEscrito = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuarioEscrito = strtolower(trim($_POST['usuario'] ?? ''));
    $clave = $_POST['clave'] ?? '';
    $st = $pdo->prepare('SELECT * FROM usuarios WHERE usuario = ?');
    $st->execute([$usuarioEscrito]);
    $u = $st->fetch();

    if ($u && $u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time()) {
        $error = 'Usuario bloqueado por varios intentos fallidos. Intente de nuevo en unos minutos.';
    } elseif ($u && $u['activo'] && password_verify($clave, $u['clave_hash'])) {
        $pdo->prepare('UPDATE usuarios SET intentos = 0, bloqueado_hasta = NULL, ultimo_ingreso = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $u['id']]);
        session_regenerate_id(true);
        $_SESSION['usuario'] = datosSesion($u);
        $_SESSION['actividad'] = time();
        header('Location: ' . ($u['debe_cambiar'] ? 'cambiar_clave.php' : 'index.php'));
        exit;
    } else {
        if ($u) {
            $intentos = $u['intentos'] + 1;
            $bloqueo = $intentos >= MAX_INTENTOS ? date('Y-m-d H:i:s', time() + MINUTOS_BLOQUEO * 60) : null;
            $pdo->prepare('UPDATE usuarios SET intentos = ?, bloqueado_hasta = ? WHERE id = ?')
                ->execute([$bloqueo ? 0 : $intentos, $bloqueo, $u['id']]);
        }
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ingresar · Estado de Autorizaciones</title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body class="login">
  <form method="post" class="login-caja" autocomplete="on">
    <h1>Estado de Autorizaciones</h1>
    <p>Ingrese con su usuario y contraseña.</p>
    <?php if ($error): ?><div class="alerta"><?= e($error) ?></div><?php endif; ?>
    <label>Usuario
      <input name="usuario" value="<?= e($usuarioEscrito) ?>" required autofocus autocomplete="username" autocapitalize="none">
    </label>
    <label>Contraseña
      <input name="clave" type="password" required autocomplete="current-password">
    </label>
    <button class="btn primario">Ingresar</button>
    <p class="pie">¿Olvidó su contraseña? Pídale al administrador que se la restablezca.</p>
  </form>
</body>
</html>
