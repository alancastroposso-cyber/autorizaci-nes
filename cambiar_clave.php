<?php
require __DIR__ . '/config.php';

$u = sesionValida($pdo);
if (!$u) {
    header('Location: login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actual = $_POST['actual'] ?? '';
    $nueva = $_POST['nueva'] ?? '';
    $st = $pdo->prepare('SELECT clave_hash FROM usuarios WHERE id = ?');
    $st->execute([$u['id']]);
    if (!hash_equals(tokenCsrf(), $_POST['csrf'] ?? '')) {
        $error = 'La página expiró. Intente de nuevo.';
    } elseif (!password_verify($actual, $st->fetchColumn())) {
        $error = 'La contraseña actual no es correcta.';
    } elseif ($nueva !== ($_POST['repetir'] ?? '')) {
        $error = 'Las contraseñas nuevas no coinciden.';
    } elseif ($nueva === $actual) {
        $error = 'La nueva contraseña debe ser diferente a la actual.';
    } elseif ($msg = validarClave($nueva)) {
        $error = $msg;
    } else {
        $pdo->prepare('UPDATE usuarios SET clave_hash = ?, debe_cambiar = 0 WHERE id = ?')
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), $u['id']]);
        $_SESSION['usuario']['debe_cambiar'] = false;
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cambiar contraseña · Estado de Autorizaciones</title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body class="login">
  <form method="post" class="login-caja">
    <h1>Cambiar contraseña</h1>
    <p>
      Hola, <?= e($u['nombre']) ?>.
      <?= $u['debe_cambiar'] ? 'Antes de continuar debe crear una contraseña personal.' : '' ?>
    </p>
    <?php if ($error): ?><div class="alerta"><?= e($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
    <label>Contraseña actual
      <input name="actual" type="password" required autocomplete="current-password" autofocus>
    </label>
    <label>Nueva contraseña <small>(mínimo 8 caracteres, con letras y números)</small>
      <input name="nueva" type="password" required minlength="8" autocomplete="new-password">
    </label>
    <label>Repita la nueva contraseña
      <input name="repetir" type="password" required minlength="8" autocomplete="new-password">
    </label>
    <button class="btn primario">Guardar contraseña</button>
    <?php if (!$u['debe_cambiar']): ?><a class="pie" href="index.php">Volver</a><?php endif; ?>
    <a class="pie" href="logout.php">Cerrar sesión</a>
  </form>
</body>
</html>
