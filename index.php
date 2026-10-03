<?php
require __DIR__ . '/config.php';

$u = sesionValida($pdo);
if (!$u) {
    header('Location: login.php');
    exit;
}
if ($u['debe_cambiar']) {
    header('Location: cambiar_clave.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Estado de Autorizaciones</title>
<link rel="stylesheet" href="assets/styles.css?v=5">
</head>
<body>

<header class="barra">
  <h1>Estado de Autorizaciones</h1>
  <div class="usuario">
    <span><?= e($u['nombre']) ?> · <?= e($u['cargo'] ?: 'Sin cargo') ?></span>
    <a class="btn enlace" href="tablero.php" target="_blank">Tablero</a>
    <?php if ($u['es_admin']): ?><button id="btn-usuarios" class="btn enlace">Usuarios</button><?php endif; ?>
    <a class="btn enlace" href="cambiar_clave.php">Cambiar contraseña</a>
    <a class="btn enlace" href="logout.php">Salir</a>
  </div>
</header>

<main>
  <section id="alertas" class="alertas oculto">
    <div class="alertas-cabeza">
      <b id="alertas-titulo"></b>
      <button id="btn-sonido" class="btn enlace" type="button"></button>
      <button id="btn-plegar" class="btn enlace" type="button">Ocultar</button>
    </div>
    <ul id="alertas-lista"></ul>
  </section>

  <section id="tarjetas" class="tarjetas"></section>

  <section class="herramientas">
    <input id="buscar" type="search" placeholder="Buscar por ingreso, documento, nombre, autorización o factura…">
    <select id="filtro-eps"><option value="">Todas las EPS</option></select>
    <button id="btn-limpiar" class="btn">Limpiar filtros</button>
    <span class="espacio"></span>
    <a id="btn-exportar" class="btn" href="api.php?accion=exportar">Exportar a Excel</a>
    <?php if (in_array('admision', $u['secciones'], true)): ?>
      <button id="btn-nuevo" class="btn primario">+ Nuevo ingreso</button>
    <?php endif; ?>
  </section>

  <div class="tabla-caja">
    <table id="tabla">
      <thead>
        <tr>
          <th>No</th><th>Fecha</th><th>Ingreso</th><th>Responsable</th><th>Paciente</th><th>EPS</th>
          <th>Autorización</th><th>Vence</th><th>Estado</th><th>Factura</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
    <p id="vacio" class="vacio oculto">No hay ingresos con estos filtros.</p>
  </div>
  <p class="nota">Se actualiza solo cada minuto. Última actualización: <span id="ultima"></span></p>
</main>

<!-- Ficha del ingreso -->
<dialog id="ficha">
  <form id="form-ficha" method="dialog">
    <div class="ficha-cabeza">
      <h2 id="ficha-titulo">Nuevo ingreso</h2>
      <span id="ficha-estado"></span>
      <button type="button" class="btn enlace cerrar" data-cerrar>✕</button>
    </div>

    <div class="ficha-cuerpo">
      <fieldset data-seccion="admision">
        <legend>Admisionadora <small data-resp="resp_admision"></small></legend>
        <div class="rejilla">
          <label>Fecha <input type="date" name="fecha"></label>
          <label>Número de ingreso * <input name="numero_ingreso"></label>
          <label>Documento <input name="documento"></label>
          <label class="ancho2">Nombre completo <input name="nombre"></label>
          <label>EPS <input name="eps" list="lista-eps"></label>
          <label>Autorización <input name="autorizacion"></label>
          <label>Hora solicitud <input type="time" name="hora_solicitud"></label>
          <label class="ancho3">Observaciones <textarea name="obs_admision" rows="2"></textarea></label>
        </div>
      </fieldset>

      <fieldset data-seccion="urgencias">
        <legend>Autorizadora de urgencias <small data-resp="urg_responsable"></small></legend>
        <div class="rejilla">
          <label>Autorización de estancia de urgencia <input name="urg_autorizacion"></label>
          <label>Fecha y hora de la autorización <input type="datetime-local" name="urg_hora"></label>
          <div class="calculo" id="urg-vence"></div>
          <label class="ancho3">Observación <textarea name="urg_observacion" rows="2"></textarea></label>
        </div>
      </fieldset>

      <fieldset data-seccion="autorizacion">
        <legend>Autorizadora <small data-resp="egreso_responsable"></small></legend>
        <div class="rejilla">
          <label>Tipo
            <select name="est_tipo">
              <option value="">—</option>
              <option>Observación</option>
              <option>Hospitalización</option>
            </select>
          </label>
          <label>Autorización de observación / hospitalización <input name="est_autorizacion"></label>
          <span class="relleno"></span>
          <label>Fecha de inicio <input type="date" name="est_inicio"></label>
          <label>Fecha de expiración <input type="date" name="est_expira"></label>
          <div class="calculo" id="est-ayuda"></div>
        </div>
        <h4>Procedimientos</h4>
        <div class="rejilla cinco">
          <label>1 <input name="proc1"></label>
          <label>2 <input name="proc2"></label>
          <label>3 <input name="proc3"></label>
          <label>4 <input name="proc4"></label>
          <label>5 <input name="proc5"></label>
        </div>
        <h4>Prórrogas</h4>
        <div class="rejilla prorrogas">
          <label>Prórroga 1 <input name="pro1"></label><label>Expira <input type="date" name="pro1_expira"></label>
          <label>Prórroga 2 <input name="pro2"></label><label>Expira <input type="date" name="pro2_expira"></label>
          <label>Prórroga 3 <input name="pro3"></label><label>Expira <input type="date" name="pro3_expira"></label>
          <label>Prórroga 4 <input name="pro4"></label><label>Expira <input type="date" name="pro4_expira"></label>
        </div>
        <div class="rejilla">
          <label>Autorización de egreso <input name="egreso_autorizacion"></label>
        </div>
      </fieldset>

      <fieldset data-seccion="facturacion">
        <legend>Facturadora <small data-resp="fact_responsable"></small></legend>
        <div class="rejilla">
          <label>Fecha de egreso <input type="date" name="fecha_egreso"></label>
          <label>Número de factura <input name="factura"></label>
          <label class="ancho3">Observación <textarea name="fact_observacion" rows="2"></textarea></label>
        </div>
      </fieldset>

      <details id="caja-historial" class="oculto">
        <summary>Historial de cambios</summary>
        <table class="historial"><tbody></tbody></table>
      </details>
    </div>

    <div class="ficha-pie">
      <span id="ficha-error" class="error"></span>
      <button type="button" class="btn" data-cerrar>Cerrar</button>
      <button type="submit" id="btn-guardar" class="btn primario">Guardar</button>
    </div>
  </form>
</dialog>
<datalist id="lista-eps"></datalist>

<?php if ($u['es_admin']): ?>
<!-- Administración de usuarios -->
<dialog id="dlg-usuarios">
  <div class="ficha-cabeza">
    <h2>Usuarios</h2>
    <button type="button" class="btn primario" id="btn-nuevo-usuario">+ Nuevo usuario</button>
    <button type="button" class="btn enlace cerrar" data-cerrar-usuarios>✕</button>
  </div>
  <div class="ficha-cuerpo">
    <table class="tabla-usuarios">
      <thead><tr><th>Nombre</th><th>Usuario</th><th>Cargo</th><th>Puede editar</th><th>Último ingreso</th><th></th></tr></thead>
      <tbody></tbody>
    </table>
  </div>
</dialog>

<dialog id="dlg-usuario">
  <form id="form-usuario" method="dialog">
    <div class="ficha-cabeza">
      <h2 id="usuario-titulo">Nuevo usuario</h2>
      <button type="button" class="btn enlace cerrar" data-cerrar-usuario>✕</button>
    </div>
    <div class="ficha-cuerpo">
      <div class="rejilla dos">
        <label class="ancho3">Nombre completo <input name="nombre" required></label>
        <label>Usuario (para iniciar sesión) <input name="usuario" autocapitalize="none" placeholder="ej: maria.lopez"></label>
        <label>Contraseña inicial <input name="clave" placeholder="mín. 8, letras y números"></label>
        <label class="ancho3">Cargo
          <select name="cargo">
            <option>Admisionadora</option>
            <option>Autorizadora de urgencias</option>
            <option>Autorizadora</option>
            <option>Facturadora</option>
            <option>Coordinación</option>
            <option>Consulta</option>
          </select>
        </label>
      </div>
      <h4>Secciones que puede editar</h4>
      <div class="checks">
        <label><input type="checkbox" name="secciones" value="admision"> Admisionadora</label>
        <label><input type="checkbox" name="secciones" value="urgencias"> Autorizadora de urgencias</label>
        <label><input type="checkbox" name="secciones" value="autorizacion"> Autorizadora</label>
        <label><input type="checkbox" name="secciones" value="facturacion"> Facturadora</label>
      </div>
      <p class="nota">Todos los usuarios pueden <b>ver</b> todo. Solo pueden <b>modificar</b> las secciones marcadas.
        Al primer ingreso el sistema les pide cambiar la contraseña.</p>
    </div>
    <div class="ficha-pie">
      <span id="usuario-error" class="error"></span>
      <button type="button" class="btn" data-cerrar-usuario>Cancelar</button>
      <button type="submit" class="btn primario">Guardar</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<div id="aviso" class="aviso"></div>
<script src="assets/app.js?v=5"></script>
</body>
</html>
