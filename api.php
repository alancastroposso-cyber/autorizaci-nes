<?php
require __DIR__ . '/config.php';

function responder($datos, $codigo = 200) {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function fallar($mensaje, $codigo = 400) {
    responder(['error' => $mensaje], $codigo);
}

$u = sesionValida($pdo);
if (!$u) fallar('Su sesión terminó. Vuelva a ingresar.', 401);
if ($u['debe_cambiar']) fallar('Debe cambiar su contraseña antes de continuar.', 403);

$accion = $_GET['accion'] ?? '';
$esPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$datos = [];
if ($esPost) {
    if (!hash_equals(tokenCsrf(), $_SERVER['HTTP_X_CSRF'] ?? '')) fallar('La página expiró. Recárguela.', 403);
    $datos = json_decode(file_get_contents('php://input'), true) ?: [];
}

function soloAdmin($u) {
    if (!$u['es_admin']) fallar('Solo el administrador puede hacer esto.', 403);
}

function registrarHistorial($pdo, $ingresoId, $usuario, $campo, $antes, $despues) {
    $pdo->prepare('INSERT INTO historial (ingreso_id, fecha, usuario, campo, antes, despues) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$ingresoId, date('Y-m-d H:i:s'), $usuario, $campo, $antes, $despues]);
}

// Lista de ingresos con estado calculado y filtros. Devuelve [filas, resumen].
function listarIngresos($pdo, $p) {
    $filas = array_map('conEstado', $pdo->query('SELECT * FROM ingresos')->fetchAll());
    $q = mb_strtolower(trim($p['q'] ?? ''));
    if ($q !== '') {
        $filas = array_values(array_filter($filas, function ($f) use ($q) {
            foreach (['numero_ingreso', 'documento', 'nombre', 'eps', 'autorizacion', 'factura'] as $c) {
                if (mb_strpos(mb_strtolower($f[$c]), $q) !== false) return true;
            }
            return false;
        }));
    }
    if (!empty($p['eps'])) {
        $filas = array_values(array_filter($filas, fn($f) => $f['eps'] === $p['eps']));
    }
    $resumen = array_fill_keys(array_column(ESTADOS, 0), 0);
    foreach ($filas as $f) $resumen[$f['estado']]++;
    if (!empty($p['estado'])) {
        $filas = array_values(array_filter($filas, fn($f) => $f['estado'] === $p['estado']));
    }
    $orden = array_flip(array_column(ESTADOS, 0));
    usort($filas, fn($a, $b) =>
        [$orden[$a['estado']], $a['vence'] ?: '9999', -$a['id']] <=> [$orden[$b['estado']], $b['vence'] ?: '9999', -$b['id']]);
    return [$filas, $resumen];
}

// Secciones que el usuario puede editar en un ingreso: las de su permiso,
// más las de autorización si él es la responsable (quien lo registró).
// $r = null significa un ingreso nuevo (que él mismo está registrando).
function seccionesEditables($u, $r = null) {
    $propio = $r === null || (string)$r['creado_por_id'] === (string)$u['id'];
    return array_values(array_unique(array_merge($u['secciones'], $propio ? SECCIONES_RESPONSABLE : [])));
}

// Limpia los valores recibidos y verifica que el usuario pueda editar cada
// campo. Si se pasa $original, solo se devuelven los campos que cambiaron.
function camposPermitidos($u, $entrada, $original = null) {
    $permitidas = seccionesEditables($u, $original);
    $limpio = [];
    foreach ($entrada as $campo => $valor) {
        $seccion = seccionDeCampo($campo);
        if (!$seccion) continue;
        $valor = mb_substr(trim((string)$valor), 0, in_array($campo, CAMPOS_LARGOS, true) ? 1000 : 120);
        if ($original !== null && $valor === $original[$campo]) continue;
        if (!in_array($seccion, $permitidas, true)) {
            fallar('No tiene permiso para modificar la sección "' . SECCIONES[$seccion]['nombre'] . '".', 403);
        }
        $limpio[$campo] = $valor;
    }
    return $limpio;
}

switch ($accion) {

case 'config':
    responder([
        'usuario' => $u,
        'csrf' => tokenCsrf(),
        'estados' => ESTADOS,
        'secciones' => SECCIONES,
        'horas_urgencia' => HORAS_URGENCIA,
        'dias_hospitalizacion' => DIAS_HOSPITALIZACION,
        'alertas' => ALERTAS,
        'secciones_responsable' => SECCIONES_RESPONSABLE,
        'hoy' => date('Y-m-d'),
    ]);

case 'listar':
    [$filas, $resumen] = listarIngresos($pdo, $_GET);
    $eps = $pdo->query("SELECT DISTINCT eps FROM ingresos WHERE eps <> '' ORDER BY eps")->fetchAll(PDO::FETCH_COLUMN);
    // Las alertas no dependen de los filtros: siempre se muestran todas.
    [$todos] = listarIngresos($pdo, []);
    $alertas = array_values(array_filter($todos, fn($f) => isset(ALERTAS[$f['estado']])));
    usort($alertas, fn($a, $b) => [$a['solicitada'], $a['estado'] === 'por_vencer'] <=> [$b['solicitada'], $b['estado'] === 'por_vencer']);
    responder(['ingresos' => $filas, 'resumen' => $resumen, 'eps' => $eps, 'alertas' => $alertas, 'hoy' => date('Y-m-d')]);

case 'detalle':
    $st = $pdo->prepare('SELECT * FROM ingresos WHERE id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $r = $st->fetch();
    if (!$r) fallar('El ingreso no existe.', 404);
    $st = $pdo->prepare('SELECT fecha, usuario, campo, antes, despues FROM historial
                         WHERE ingreso_id = ? ORDER BY id DESC LIMIT 200');
    $st->execute([$r['id']]);
    $hist = array_map(function ($h) {
        $h['campo'] = ETIQUETAS[$h['campo']] ?? $h['campo'];
        return $h;
    }, $st->fetchAll());
    responder(['ingreso' => conEstado($r), 'historial' => $hist, 'editables' => seccionesEditables($u, $r)]);

case 'crear':
    if (!$esPost) fallar('Método no permitido.', 405);
    if (!in_array('admision', $u['secciones'], true)) fallar('No tiene permiso para registrar ingresos.', 403);
    $valores = camposPermitidos($u, $datos);
    if (($valores['numero_ingreso'] ?? '') === '') fallar('El número de ingreso es obligatorio.');
    $st = $pdo->prepare('SELECT id FROM ingresos WHERE numero_ingreso = ?');
    $st->execute([$valores['numero_ingreso']]);
    if ($otro = $st->fetchColumn()) fallar("El ingreso {$valores['numero_ingreso']} ya está registrado (No $otro).", 409);
    if (($valores['fecha'] ?? '') === '') $valores['fecha'] = date('Y-m-d');
    $valores['creado_por_id'] = (string)$u['id'];
    $valores['creado_por'] = $u['nombre'];
    foreach (SECCIONES as $s) {
        foreach ($s['campos'] as $c) {
            if (($valores[$c] ?? '') !== '') { $valores[$s['responsable']] = $u['nombre']; break; }
        }
    }
    $cols = array_keys($valores);
    $sql = 'INSERT INTO ingresos (`' . implode('`,`', $cols) . '`, creado, actualizado) VALUES ('
         . implode(',', array_fill(0, count($cols), '?')) . ', ?, ?)';
    $ahora = date('Y-m-d H:i:s');
    $pdo->prepare($sql)->execute([...array_values($valores), $ahora, $ahora]);
    $id = (int)$pdo->lastInsertId();
    registrarHistorial($pdo, $id, $u['nombre'], 'Registro', '', 'Ingreso creado');
    responder(['id' => $id], 201);

case 'actualizar':
    if (!$esPost) fallar('Método no permitido.', 405);
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM ingresos WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) fallar('El ingreso no existe.', 404);
    // Solo se guardan los campos que cambiaron, para no pisar lo que otra persona guardó.
    $cambios = camposPermitidos($u, $datos, $r);
    if (!$cambios) responder(['ok' => true, 'cambios' => 0]);
    if (array_key_exists('numero_ingreso', $cambios)) {
        if ($cambios['numero_ingreso'] === '') fallar('El número de ingreso es obligatorio.');
        $st = $pdo->prepare('SELECT id FROM ingresos WHERE numero_ingreso = ? AND id <> ?');
        $st->execute([$cambios['numero_ingreso'], $id]);
        if ($otro = $st->fetchColumn()) fallar("Ese número de ingreso ya existe (No $otro).", 409);
    }
    $pdo->beginTransaction();
    foreach ($cambios as $c => $v) registrarHistorial($pdo, $id, $u['nombre'], $c, $r[$c], $v);
    foreach (array_unique(array_map('seccionDeCampo', array_keys($cambios))) as $s) {
        $cambios[SECCIONES[$s]['responsable']] = $u['nombre'];
    }
    $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($cambios)));
    $pdo->prepare("UPDATE ingresos SET $sets, actualizado = ? WHERE id = ?")
        ->execute([...array_values($cambios), date('Y-m-d H:i:s'), $id]);
    $pdo->commit();
    responder(['ok' => true, 'cambios' => count($cambios)]);

case 'exportar':
    [$filas] = listarIngresos($pdo, $_GET);
    $nombres = array_column(ESTADOS, 1, 0);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="autorizaciones_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");  // para que Excel reconozca las tildes
    fputcsv($out, array_merge(['No', 'ESTADO', 'VENCE'], array_map(fn($c) => ETIQUETAS[$c], ORDEN_EXCEL)), ';');
    foreach ($filas as $f) {
        fputcsv($out, array_merge([$f['id'], $nombres[$f['estado']], $f['vence']],
                                  array_map(fn($c) => $f[$c], ORDEN_EXCEL)), ';');
    }
    exit;

case 'solicitud':
    // Marca (o desmarca) que la autorización pendiente ya se pidió a la EPS.
    if (!$esPost) fallar('Método no permitido.', 405);
    $id = (int)($datos['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM ingresos WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) fallar('El ingreso no existe.', 404);
    if (!array_intersect(['urgencias', 'autorizacion'], seccionesEditables($u, $r))) {
        fallar('Solo las autorizadoras o la responsable del ingreso pueden marcar solicitudes a la EPS.', 403);
    }
    $r = conEstado($r);
    if (!isset(ALERTAS[$r['estado']])) fallar('Este ingreso no tiene autorizaciones pendientes.');
    $ahora = date('Y-m-d H:i:s');
    if (empty($datos['quitar'])) {
        $pdo->prepare('UPDATE ingresos SET sol_estado = ?, sol_fecha = ?, sol_usuario = ? WHERE id = ?')
            ->execute([$r['estado'], $ahora, $u['nombre'], $id]);
        registrarHistorial($pdo, $id, $u['nombre'], 'sol_estado', '', 'Solicitada a la EPS: ' . ALERTAS[$r['estado']]);
    } else {
        $pdo->prepare("UPDATE ingresos SET sol_estado = '', sol_fecha = '', sol_usuario = '' WHERE id = ?")
            ->execute([$id]);
        registrarHistorial($pdo, $id, $u['nombre'], 'sol_estado', 'Solicitada', 'Se quitó la marca de solicitada');
    }
    responder(['ok' => true]);

/* ---------- Administración de usuarios ---------- */

case 'usuarios':
    soloAdmin($u);
    responder(['usuarios' => $pdo->query(
        'SELECT id, usuario, nombre, cargo, secciones, es_admin, activo, ultimo_ingreso
         FROM usuarios ORDER BY activo DESC, nombre')->fetchAll()]);

case 'guardar_usuario':
    if (!$esPost) fallar('Método no permitido.', 405);
    soloAdmin($u);
    $id = (int)($datos['id'] ?? 0);
    $nombre = trim($datos['nombre'] ?? '');
    $cargo = trim($datos['cargo'] ?? '');
    $secciones = implode(',', array_intersect(array_keys(SECCIONES), (array)($datos['secciones'] ?? [])));
    if ($nombre === '') fallar('El nombre es obligatorio.');
    if ($id) {
        $st = $pdo->prepare('SELECT usuario FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn() === 'admin') fallar('El usuario admin no se puede modificar aquí.');
        $pdo->prepare('UPDATE usuarios SET nombre = ?, cargo = ?, secciones = ? WHERE id = ?')
            ->execute([$nombre, $cargo, $secciones, $id]);
        responder(['ok' => true]);
    }
    $usuario = strtolower(trim($datos['usuario'] ?? ''));
    if (!preg_match('/^[a-z0-9._-]{3,30}$/', $usuario)) {
        fallar('El usuario debe tener entre 3 y 30 caracteres: letras sin tildes, números, punto o guion.');
    }
    $clave = $datos['clave'] ?? '';
    if ($msg = validarClave($clave)) fallar($msg);
    $st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE usuario = ?');
    $st->execute([$usuario]);
    if ($st->fetchColumn()) fallar("El usuario \"$usuario\" ya existe.", 409);
    $pdo->prepare('INSERT INTO usuarios (usuario, nombre, cargo, secciones, clave_hash, debe_cambiar, creado)
                   VALUES (?, ?, ?, ?, ?, 1, ?)')
        ->execute([$usuario, $nombre, $cargo, $secciones, password_hash($clave, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
    responder(['ok' => true], 201);

case 'restablecer_clave':
    if (!$esPost) fallar('Método no permitido.', 405);
    soloAdmin($u);
    if ($msg = validarClave($datos['clave'] ?? '')) fallar($msg);
    $pdo->prepare('UPDATE usuarios SET clave_hash = ?, debe_cambiar = 1, intentos = 0, bloqueado_hasta = NULL WHERE id = ?')
        ->execute([password_hash($datos['clave'], PASSWORD_DEFAULT), (int)($datos['id'] ?? 0)]);
    responder(['ok' => true]);

case 'activar_usuario':
    if (!$esPost) fallar('Método no permitido.', 405);
    soloAdmin($u);
    $id = (int)($datos['id'] ?? 0);
    if ($id === $u['id']) fallar('No puede desactivar su propio usuario.');
    $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ? AND usuario <> 'admin'")
        ->execute([empty($datos['activo']) ? 0 : 1, $id]);
    responder(['ok' => true]);

default:
    fallar('Acción no válida.', 404);
}
