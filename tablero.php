<?php
/*
  Tablero de solo lectura para todos los funcionarios (ideal para una pantalla
  o TV). No permite modificar nada. Si $TABLERO_PUBLICO es true en config.php
  no pide usuario; por privacidad muestra los nombres abreviados.
*/
require __DIR__ . '/config.php';

if (!$TABLERO_PUBLICO && !sesionValida($pdo)) {
    header('Location: login.php');
    exit;
}
if (!isset($_GET['datos'])) instalar($pdo);  // por si se abre antes que login.php

// "ANA MARIA PEREZ GOMEZ" -> "ANA M. P. G."
function abreviarNombre($nombre) {
    $partes = preg_split('/\s+/', trim($nombre), -1, PREG_SPLIT_NO_EMPTY);
    if (!$partes) return '';
    $primero = array_shift($partes);
    return trim($primero . ' ' . implode(' ', array_map(fn($p) => mb_substr($p, 0, 1) . '.', $partes)));
}

if (isset($_GET['datos'])) {
    $filas = array_map('conEstado', $pdo->query('SELECT * FROM ingresos')->fetchAll());
    $resumen = array_fill_keys(array_column(ESTADOS, 0), 0);
    $porEps = [];
    $pendientes = [];
    foreach ($filas as $f) {
        $resumen[$f['estado']]++;
        if (!isset(ALERTAS[$f['estado']])) continue;
        $eps = $f['eps'] ?: 'Sin EPS';
        $porEps[$eps] = ($porEps[$eps] ?? 0) + 1;
        $pendientes[] = [
            'ingreso' => $f['numero_ingreso'],
            'responsable' => $f['responsable'],
            'paciente' => abreviarNombre($f['nombre']),
            'eps' => $f['eps'],
            'estado' => $f['estado'],
            'motivo' => ALERTAS[$f['estado']],
            'solicitada' => $f['solicitada'],
            'sol_fecha' => $f['sol_fecha'],
            'vence' => $f['vence'],
        ];
    }
    $orden = array_flip(array_column(ESTADOS, 0));
    usort($pendientes, fn($a, $b) =>
        [$a['solicitada'], $orden[$a['estado']], $a['vence'] ?: '9999'] <=> [$b['solicitada'], $orden[$b['estado']], $b['vence'] ?: '9999']);
    arsort($porEps);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'estados' => ESTADOS,
        'resumen' => $resumen,
        'total' => count($filas),
        'pendientes' => $pendientes,
        'por_eps' => $porEps,
        'hora' => date('Y-m-d\TH:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tablero · Estado de Autorizaciones</title>
<link rel="stylesheet" href="assets/styles.css?v=3">
<link rel="stylesheet" href="assets/tablero.css?v=2">
</head>
<body class="tablero">
<header class="barra">
  <h1>Estado de Autorizaciones · Tablero</h1>
  <div class="usuario">
    <span>Solo lectura · actualizado <b id="hora">—</b></span>
    <a class="btn enlace" href="index.php">Ingresar a la app</a>
  </div>
</header>
<main>
  <section id="tarjetas" class="tarjetas"></section>

  <div class="tablero-cuerpo">
    <section class="panel">
      <h2>Ingresos que necesitan autorización de la EPS <span id="n-pendientes"></span></h2>
      <div class="tabla-caja">
        <table id="pendientes">
          <thead><tr><th>Ingreso</th><th>Responsable</th><th>Paciente</th><th>EPS</th><th>Qué falta</th><th>Gestión</th></tr></thead>
          <tbody></tbody>
        </table>
        <p id="sin-pendientes" class="vacio oculto">✓ No hay autorizaciones pendientes.</p>
      </div>
    </section>
    <section class="panel">
      <h2>Pendientes por EPS</h2>
      <div id="por-eps"></div>
    </section>
  </div>
</main>
<script>
"use strict";
const esc = t => String(t ?? "").replace(/[&<>"']/g, c =>
  ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

function hace(fecha) {
  const min = Math.round((Date.now() - new Date(fecha.replace(" ", "T"))) / 60000);
  if (min < 60) return `hace ${Math.max(min, 0)} min`;
  if (min < 1440) return `hace ${Math.floor(min / 60)} h`;
  return `hace ${Math.floor(min / 1440)} d`;
}

async function cargar() {
  let d;
  try {
    d = await (await fetch("tablero.php?datos=1")).json();
  } catch {
    document.getElementById("hora").textContent = "sin conexión";
    return;
  }
  document.getElementById("hora").textContent = d.hora.slice(11, 16);
  document.getElementById("tarjetas").innerHTML =
    `<div class="tarjeta gris"><b>${d.total}</b><span>Ingresos</span></div>` +
    d.estados.map(([k, nombre, color]) =>
      `<div class="tarjeta ${color}"><b>${d.resumen[k]}</b><span>${esc(nombre)}</span></div>`).join("");

  const sinSolicitar = d.pendientes.filter(p => !p.solicitada && p.estado !== "por_vencer").length;
  document.getElementById("n-pendientes").textContent =
    d.pendientes.length ? `(${sinSolicitar} por solicitar · ${d.pendientes.length} en total)` : "";
  document.getElementById("sin-pendientes").classList.toggle("oculto", d.pendientes.length > 0);
  document.querySelector("#pendientes tbody").innerHTML = d.pendientes.map(p => {
    const tipo = p.solicitada ? "tramite" : p.estado === "por_vencer" ? "prevenir" : "urgente";
    const gestion = p.solicitada ? `<span class="estado azul">Solicitada ${hace(p.sol_fecha)}</span>`
                                 : `<span class="estado ${tipo === "prevenir" ? "amarillo" : "rojo"}">Por solicitar</span>`;
    return `<tr class="${tipo}"><td><b>${esc(p.ingreso)}</b></td><td>${esc(p.responsable)}</td><td>${esc(p.paciente)}</td>
      <td>${esc(p.eps)}</td><td>${esc(p.motivo)}</td><td>${gestion}</td></tr>`;
  }).join("");

  const max = Math.max(1, ...Object.values(d.por_eps));
  document.getElementById("por-eps").innerHTML = Object.keys(d.por_eps).length
    ? Object.entries(d.por_eps).map(([eps, n]) => `<div class="barra-eps">
        <span>${esc(eps)}</span><div><i style="width:${(n / max) * 100}%"></i></div><b>${n}</b></div>`).join("")
    : `<p class="vacio">—</p>`;
}

cargar();
setInterval(cargar, 30000);
</script>
</body>
</html>
