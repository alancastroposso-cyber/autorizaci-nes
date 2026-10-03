"use strict";

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];

const estado = {
  config: null,
  usuario: null,
  filtroEstado: "",
  actual: null,      // ingreso abierto en la ficha (null = nuevo)
};

function escapar(t) {
  return String(t ?? "").replace(/[&<>"']/g, c =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}

// Llama a api.php. Con "datos" hace POST en JSON.
async function api(accion, params = {}, datos) {
  const qs = new URLSearchParams({ accion, ...params });
  const resp = await fetch("api.php?" + qs, datos === undefined ? {} : {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF": estado.config?.csrf || "" },
    body: JSON.stringify(datos),
  });
  if (resp.status === 401) {          // sesión vencida
    location.href = "login.php";
    throw new Error("Sesión terminada");
  }
  const r = await resp.json().catch(() => ({}));
  if (!resp.ok) throw new Error(r.error || "Error del servidor");
  return r;
}

function aviso(texto) {
  const a = $("#aviso");
  a.textContent = texto;
  a.classList.add("ver");
  clearTimeout(aviso.t);
  aviso.t = setTimeout(() => a.classList.remove("ver"), 2500);
}

const fmtFecha = f => f ? f.slice(0, 10).split("-").reverse().join("/") : "";
const fmtVence = v => v.includes("T") ? `${fmtFecha(v)} ${v.slice(11, 16)}` : fmtFecha(v);
const sumarDias = (f, n) => {
  const d = new Date(f + "T12:00");
  d.setDate(d.getDate() + n);
  return d.toISOString().slice(0, 10);
};

// Texto "vence en ..." para la tabla. Urgencias en horas, estancia en dias.
function textoVence(v) {
  if (!v) return "";
  let txt, mal;
  if (v.includes("T")) {
    const min = Math.round((new Date(v) - new Date()) / 60000);
    const h = Math.floor(Math.abs(min) / 60), m = Math.abs(min) % 60;
    const dur = h ? `${h} h ${m} min` : `${m} min`;
    txt = min <= 0 ? `urgencia vencida hace ${dur}` : `urgencia: quedan ${dur}`;
    mal = min <= 0;
  } else {
    const d = diasHasta(v);
    txt = d < 0 ? `vencida hace ${-d} día${d === -1 ? "" : "s"}` :
      d === 0 ? "vence hoy" : `en ${d} día${d === 1 ? "" : "s"}`;
    mal = d <= 0;
  }
  return `${fmtVence(v)}<div class="dias ${mal ? "mal" : ""}">${txt}</div>`;
}

function diasHasta(f) {
  if (!f) return null;
  const hoy = new Date(estado.config.hoy + "T00:00");
  return Math.round((new Date(f + "T00:00") - hoy) / 86400000);
}

function infoEstado(clave) {
  const e = estado.config.estados.find(x => x[0] === clave);
  return { nombre: e[1], color: e[2] };
}

function etiquetaEstado(clave) {
  const e = infoEstado(clave);
  return `<span class="estado ${e.color}">${escapar(e.nombre)}</span>`;
}

/* ---------- Listado ---------- */
function parametros() {
  const p = {};
  const q = $("#buscar").value.trim();
  if (q) p.q = q;
  if ($("#filtro-eps").value) p.eps = $("#filtro-eps").value;
  if (estado.filtroEstado) p.estado = estado.filtroEstado;
  return p;
}

async function cargar() {
  const p = parametros();
  $("#btn-exportar").href = "api.php?" + new URLSearchParams({ accion: "exportar", ...p });
  let datos;
  try {
    datos = await api("listar", p);
  } catch (err) {
    aviso("No se pudo conectar con el servidor");
    return;
  }
  estado.config.hoy = datos.hoy;
  pintarAlertas(datos.alertas);
  pintarTarjetas(datos.resumen);
  pintarEps(datos.eps);
  pintarTabla(datos.ingresos);
  $("#ultima").textContent = new Date().toLocaleTimeString("es-CO");
}

function pintarTarjetas(resumen) {
  const total = Object.values(resumen).reduce((a, b) => a + b, 0);
  const html = [`<button class="tarjeta gris ${estado.filtroEstado ? "" : "activa"}" data-estado="">
      <b>${total}</b><span>Todos</span></button>`];
  for (const [clave, nombre, color] of estado.config.estados) {
    html.push(`<button class="tarjeta ${color} ${estado.filtroEstado === clave ? "activa" : ""}" data-estado="${clave}">
      <b>${resumen[clave]}</b><span>${escapar(nombre)}</span></button>`);
  }
  $("#tarjetas").innerHTML = html.join("");
}

$("#tarjetas").addEventListener("click", e => {
  const t = e.target.closest(".tarjeta");
  if (!t) return;
  estado.filtroEstado = t.dataset.estado;
  cargar();
});

function pintarEps(lista) {
  const sel = $("#filtro-eps");
  const actual = sel.value;
  sel.innerHTML = `<option value="">Todas las EPS</option>` +
    lista.map(e => `<option>${escapar(e)}</option>`).join("");
  sel.value = actual;
  $("#lista-eps").innerHTML = lista.map(e => `<option value="${escapar(e)}">`).join("");
}

function pintarTabla(filas) {
  $("#vacio").classList.toggle("oculto", filas.length > 0);
  $("#tabla tbody").innerHTML = filas.map(f => {
    const { color } = infoEstado(f.estado);
    const vence = f.fecha_egreso ? (f.vence ? fmtVence(f.vence) : "") : textoVence(f.vence);
    return `<tr data-id="${f.id}" class="fila-${color}">
      <td>${f.id}</td>
      <td>${fmtFecha(f.fecha)}</td>
      <td><b>${escapar(f.numero_ingreso)}</b></td>
      <td>${escapar(f.responsable)}${esMio(f) ? ' <span class="tag-mio">Tuyo</span>' : ""}</td>
      <td>${escapar(f.nombre)}<div class="doc">${escapar(f.documento)}</div></td>
      <td>${escapar(f.eps)}</td>
      <td>${escapar(f.autorizacion)}</td>
      <td>${vence}</td>
      <td>${etiquetaEstado(f.estado)}${f.solicitada ? `<span class="tag-tramite">Solicitada a EPS ${hace(f.sol_fecha)}</span>` : ""}</td>
      <td>${escapar(f.factura)}</td>
    </tr>`;
  }).join("");
}

$("#tabla tbody").addEventListener("click", e => {
  const tr = e.target.closest("tr[data-id]");
  if (tr) abrirFicha(tr.dataset.id);
});

let tBuscar;
$("#buscar").addEventListener("input", () => { clearTimeout(tBuscar); tBuscar = setTimeout(cargar, 250); });
$("#filtro-eps").addEventListener("change", cargar);
$("#btn-limpiar").addEventListener("click", () => {
  $("#buscar").value = "";
  $("#filtro-eps").value = "";
  estado.filtroEstado = "";
  cargar();
});

/* ---------- Alertas: autorizaciones que hay que pedir a la EPS ---------- */
// ¿El ingreso lo registró el usuario actual? (es su responsable)
const esMio = f => String(f.creado_por_id) === String(estado.usuario.id);

const puedeSolicitar = a => esMio(a) ||
  estado.usuario.secciones.some(s => s === "urgencias" || s === "autorizacion");

let pendientesAntes = null;
let sonidoActivo = leerPreferencia("sonido", "1") === "1";
let alertasPlegadas = leerPreferencia("plegar", "0") === "1";

function leerPreferencia(k, def) {
  try { return localStorage.getItem("pref_" + k) ?? def; } catch { return def; }
}
function guardarPreferencia(k, v) {
  try { localStorage.setItem("pref_" + k, v); } catch {}
}

function hace(fecha) {
  const min = Math.round((Date.now() - new Date(fecha.replace(" ", "T"))) / 60000);
  if (min < 60) return `hace ${Math.max(min, 0)} min`;
  if (min < 1440) return `hace ${Math.floor(min / 60)} h`;
  return `hace ${Math.floor(min / 1440)} d`;
}

function pintarAlertas(alertas) {
  const caja = $("#alertas");
  // Primero los ingresos de los que el usuario es responsable.
  alertas = [...alertas.filter(esMio), ...alertas.filter(a => !esMio(a))];
  const porSolicitar = alertas.filter(a => !a.solicitada && a.estado !== "por_vencer");
  caja.classList.toggle("oculto", alertas.length === 0);
  caja.classList.toggle("tranquila", porSolicitar.length === 0);
  caja.classList.toggle("plegada", alertasPlegadas);

  const enTramite = alertas.filter(a => a.solicitada).length;
  $("#alertas-titulo").textContent = porSolicitar.length
    ? `⚠ ${porSolicitar.length} ingreso${porSolicitar.length === 1 ? "" : "s"} sin autorización: solicitar a la EPS`
    : `Sin pendientes por solicitar` + (enTramite ? ` · ${enTramite} en trámite con la EPS` : "");
  $("#btn-sonido").textContent = sonidoActivo ? "🔔 Sonido activado" : "🔕 Sonido apagado";
  $("#btn-plegar").textContent = alertasPlegadas ? "Mostrar lista" : "Ocultar lista";

  $("#alertas-lista").innerHTML = alertas.map(a => {
    const tipo = a.solicitada ? "tramite" : a.estado === "por_vencer" ? "prevenir" : "urgente";
    const detalle = a.solicitada
      ? `Solicitada por ${escapar(a.sol_usuario)} ${hace(a.sol_fecha)}`
      : escapar(estado.config.alertas[a.estado]);
    const boton = !puedeSolicitar(a) ? "" : a.solicitada
      ? `<button class="btn enlace" data-quitar="${a.id}">Quitar marca</button>`
      : `<button class="btn" data-solicitar="${a.id}">Marcar solicitada</button>`;
    return `<li class="${tipo}" data-id="${a.id}">
      <span class="punto"></span>
      <span class="quien"><b>${escapar(a.numero_ingreso)}</b> · ${escapar(a.nombre)} <small>${escapar(a.eps)}</small>
        <small class="resp">Responsable: ${escapar(a.responsable || "—")}</small>
        ${esMio(a) ? '<span class="tag-mio">Tuyo</span>' : ""}</span>
      <span class="que">${detalle}</span>
      ${boton}
    </li>`;
  }).join("");

  // Aviso en la pestaña del navegador y sonido cuando aparece un pendiente nuevo.
  document.title = (porSolicitar.length ? `(${porSolicitar.length}) ⚠ ` : "") + "Estado de Autorizaciones";
  const atiende = puedeSolicitar({ creado_por_id: null });
  if (pendientesAntes !== null && porSolicitar.length > pendientesAntes && atiende && sonidoActivo) {
    sonar();
    aviso("Hay un ingreso nuevo sin autorización");
  }
  pendientesAntes = porSolicitar.length;
}

function sonar() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    [0, 0.25].forEach(t => {
      const o = ctx.createOscillator(), g = ctx.createGain();
      o.frequency.value = 880;
      g.gain.setValueAtTime(0.2, ctx.currentTime + t);
      g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + t + 0.2);
      o.connect(g).connect(ctx.destination);
      o.start(ctx.currentTime + t);
      o.stop(ctx.currentTime + t + 0.2);
    });
  } catch {}
}

$("#btn-sonido").addEventListener("click", () => {
  sonidoActivo = !sonidoActivo;
  guardarPreferencia("sonido", sonidoActivo ? "1" : "0");
  if (sonidoActivo) sonar();
  $("#btn-sonido").textContent = sonidoActivo ? "🔔 Sonido activado" : "🔕 Sonido apagado";
});

$("#btn-plegar").addEventListener("click", () => {
  alertasPlegadas = !alertasPlegadas;
  guardarPreferencia("plegar", alertasPlegadas ? "1" : "0");
  $("#alertas").classList.toggle("plegada", alertasPlegadas);
  $("#btn-plegar").textContent = alertasPlegadas ? "Mostrar lista" : "Ocultar lista";
});

$("#alertas-lista").addEventListener("click", async e => {
  const b = e.target.closest("button");
  const li = e.target.closest("li[data-id]");
  if (!li) return;
  if (!b) return abrirFicha(li.dataset.id);
  try {
    await api("solicitud", {}, { id: li.dataset.id, quitar: !!b.dataset.quitar });
    aviso(b.dataset.quitar ? "Marca quitada" : "Marcada como solicitada a la EPS");
    cargar();
  } catch (err) { aviso(err.message); }
});

/* ---------- Ficha ---------- */
const ficha = $("#ficha");
const formFicha = $("#form-ficha");
const campos = () => $$("input[name], textarea[name], select[name]", formFicha);

// Resalta y habilita solo las secciones que el usuario puede editar.
// "puede" viene del servidor para un ingreso existente; en uno nuevo, quien
// lo registra es la responsable y puede llenar también las autorizaciones.
function aplicarPermisos(puede) {
  puede = puede || [...new Set([...estado.usuario.secciones, ...estado.config.secciones_responsable])];
  $$("fieldset[data-seccion]", formFicha).forEach(fs => {
    const ok = puede.includes(fs.dataset.seccion);
    fs.disabled = !ok;
    fs.classList.toggle("mio", ok && !estado.usuario.es_admin);
  });
  $("#btn-guardar").classList.toggle("oculto", puede.length === 0);
}

$("#btn-nuevo")?.addEventListener("click", () => {
  estado.actual = null;
  formFicha.reset();
  formFicha.fecha.value = estado.config.hoy;
  $("#ficha-titulo").textContent = "Nuevo ingreso";
  $("#ficha-estado").innerHTML = "";
  $$("[data-resp]", formFicha).forEach(s => (s.textContent = ""));
  $("#caja-historial").classList.add("oculto");
  $("#ficha-error").textContent = "";
  ayudaUrgencia();
  ayudaEstancia();
  aplicarPermisos();
  ficha.showModal();
  formFicha.numero_ingreso.focus();
});

async function abrirFicha(id) {
  let datos;
  try { datos = await api("detalle", { id }); } catch (err) { return aviso(err.message); }
  const r = datos.ingreso;
  estado.actual = r;
  campos().forEach(c => (c.value = r[c.name] ?? ""));
  $$("[data-resp]", formFicha).forEach(s => {
    const v = r[s.dataset.resp];
    s.textContent = v ? `— Responsable: ${v}` : "";
  });
  $("#ficha-titulo").textContent = `Ingreso ${r.numero_ingreso} · ${r.nombre || ""}`;
  $("#ficha-estado").innerHTML = etiquetaEstado(r.estado);
  $("#caja-historial").classList.toggle("oculto", datos.historial.length === 0);
  $(".historial tbody").innerHTML = datos.historial.map(h => `<tr>
      <td>${escapar(h.fecha.replace("T", " "))}</td><td>${escapar(h.usuario)}</td>
      <td>${escapar(h.campo)}</td>
      <td>${escapar(h.antes)} ${h.antes ? "→" : ""} <b>${escapar(h.despues)}</b></td></tr>`).join("");
  $("#ficha-error").textContent = "";
  ayudaUrgencia();
  ayudaEstancia();
  aplicarPermisos(datos.editables);
  ficha.showModal();
  const mio = $("fieldset.mio", formFicha);
  if (mio) mio.scrollIntoView({ block: "start" });
}

/* Calculos automaticos de vencimiento */
function ayudaUrgencia() {
  const v = formFicha.urg_hora.value;
  const caja = $("#urg-vence");
  if (!v) { caja.innerHTML = ""; return; }
  const fin = new Date(new Date(v).getTime() + estado.config.horas_urgencia * 3600000);
  const vencida = fin <= new Date() && !formFicha.est_autorizacion.value.trim();
  const txt = fin.toLocaleString("es-CO", { dateStyle: "short", timeStyle: "short" });
  caja.innerHTML = vencida
    ? `<span class="mal">Venció el ${txt} — solicitar observación u hospitalización</span>`
    : `Vigente hasta <b>${txt}</b> (${estado.config.horas_urgencia} horas)`;
}

function ayudaEstancia() {
  const hosp = formFicha.est_tipo.value === "Hospitalización";
  $("#est-ayuda").textContent = hosp
    ? `Hospitalización: ${estado.config.dias_hospitalizacion} días desde la fecha de inicio`
    : "";
}

formFicha.urg_hora.addEventListener("input", ayudaUrgencia);
formFicha.est_autorizacion.addEventListener("input", ayudaUrgencia);

function calcularExpiraEstancia() {
  ayudaEstancia();
  const ini = formFicha.est_inicio.value;
  if (formFicha.est_tipo.value === "Hospitalización" && ini) {
    formFicha.est_expira.value = sumarDias(ini, estado.config.dias_hospitalizacion);
  }
}
formFicha.est_tipo.addEventListener("change", calcularExpiraEstancia);
formFicha.est_inicio.addEventListener("change", calcularExpiraEstancia);

// Al escribir una prorroga de hospitalizacion, su vencimiento se propone
// sumando los dias a partir del vencimiento anterior (se puede corregir).
["pro1", "pro2", "pro3", "pro4"].forEach((p, i) => {
  formFicha[p].addEventListener("change", () => {
    const exp = formFicha[p + "_expira"];
    const anterior = i === 0 ? formFicha.est_expira.value : formFicha[`pro${i}_expira`].value;
    if (formFicha[p].value.trim() && !exp.value && anterior &&
        formFicha.est_tipo.value === "Hospitalización") {
      exp.value = sumarDias(anterior, estado.config.dias_hospitalizacion);
    }
  });
});

$$("[data-cerrar]", formFicha).forEach(b => b.addEventListener("click", () => ficha.close()));

formFicha.addEventListener("submit", async e => {
  e.preventDefault();
  if (!estado.actual && !formFicha.numero_ingreso.value.trim()) {
    $("#ficha-error").textContent = "El número de ingreso es obligatorio.";
    return;
  }
  const valores = {};
  campos().forEach(c => (valores[c.name] = c.value.trim()));
  try {
    if (estado.actual) {
      // Solo se envian los campos que cambiaron, para no pisar lo que otra persona guardo.
      const cambios = {};
      for (const [k, v] of Object.entries(valores)) if (v !== (estado.actual[k] ?? "")) cambios[k] = v;
      if (Object.keys(cambios).length === 0) { ficha.close(); return; }
      await api("actualizar", { id: estado.actual.id }, cambios);
    } else {
      // Al crear, solo se envían las secciones que el usuario puede llenar.
      const nuevos = {};
      campos().forEach(c => { if (!c.closest("fieldset").disabled) nuevos[c.name] = c.value.trim(); });
      await api("crear", {}, nuevos);
    }
    ficha.close();
    aviso("Guardado");
    cargar();
  } catch (err) {
    $("#ficha-error").textContent = err.message;
  }
});

/* ---------- Inicio ---------- */
(async function iniciar() {
  estado.config = await api("config");
  estado.usuario = estado.config.usuario;
  await cargar();
  setInterval(() => { if (!ficha.open) cargar(); }, 60000);
})();

/* ---------- Usuarios (solo administrador) ---------- */
const dlgUsuarios = $("#dlg-usuarios");
const dlgUsuario = $("#dlg-usuario");
const formUsuario = $("#form-usuario");
let usuarioEditando = null;

// Secciones que se marcan por defecto según el cargo (se pueden ajustar).
const SECCIONES_POR_CARGO = {
  "Admisionadora": ["admision"],
  "Autorizadora de urgencias": ["urgencias"],
  "Autorizadora": ["urgencias", "autorizacion"],
  "Facturadora": ["facturacion"],
  "Coordinación": ["admision", "urgencias", "autorizacion", "facturacion"],
  "Consulta": [],
};

function marcarSecciones(lista) {
  $$("input[name=secciones]", formUsuario).forEach(ch => (ch.checked = lista.includes(ch.value)));
}

async function cargarUsuarios() {
  const { usuarios } = await api("usuarios");
  const nombres = Object.fromEntries(Object.entries(estado.config.secciones).map(([k, v]) => [k, v.nombre]));
  $(".tabla-usuarios tbody").innerHTML = usuarios.map(x => {
    const secc = x.es_admin == 1 ? "Todo (administrador)"
      : (x.secciones ? x.secciones.split(",").map(s => nombres[s]).join(", ") : "Solo consulta");
    const acciones = x.usuario === "admin" ? "" : `
      <button class="btn enlace" data-editar="${x.id}">Editar</button>
      <button class="btn enlace" data-clave="${x.id}">Nueva contraseña</button>
      <button class="btn enlace" data-activo="${x.id}" data-valor="${x.activo == 1 ? 0 : 1}">
        ${x.activo == 1 ? "Desactivar" : "Activar"}</button>`;
    return `<tr class="${x.activo == 1 ? "" : "inactivo"}">
      <td>${escapar(x.nombre)}${x.activo == 1 ? "" : " <small>(inactivo)</small>"}</td>
      <td>${escapar(x.usuario)}</td><td>${escapar(x.cargo)}</td><td>${escapar(secc)}</td>
      <td>${x.ultimo_ingreso ? escapar(x.ultimo_ingreso.slice(0, 16)) : "Nunca"}</td>
      <td class="acciones">${acciones}</td></tr>`;
  }).join("");
  cargarUsuarios.lista = usuarios;
}

function abrirUsuario(x) {
  usuarioEditando = x;
  formUsuario.reset();
  $("#usuario-error").textContent = "";
  $("#usuario-titulo").textContent = x ? `Editar: ${x.nombre}` : "Nuevo usuario";
  formUsuario.usuario.disabled = formUsuario.clave.disabled = !!x;
  formUsuario.usuario.closest("label").classList.toggle("oculto", !!x);
  formUsuario.clave.closest("label").classList.toggle("oculto", !!x);
  if (x) {
    formUsuario.nombre.value = x.nombre;
    formUsuario.cargo.value = x.cargo;
    marcarSecciones(x.secciones ? x.secciones.split(",") : []);
  } else {
    marcarSecciones(SECCIONES_POR_CARGO[formUsuario.cargo.value]);
  }
  dlgUsuario.showModal();
  formUsuario.nombre.focus();
}

if (dlgUsuarios) {
  $("#btn-usuarios").addEventListener("click", async () => {
    try { await cargarUsuarios(); dlgUsuarios.showModal(); } catch (err) { aviso(err.message); }
  });
  $("[data-cerrar-usuarios]").addEventListener("click", () => dlgUsuarios.close());
  $$("[data-cerrar-usuario]").forEach(b => b.addEventListener("click", () => dlgUsuario.close()));
  $("#btn-nuevo-usuario").addEventListener("click", () => abrirUsuario(null));
  formUsuario.cargo.addEventListener("change", () => marcarSecciones(SECCIONES_POR_CARGO[formUsuario.cargo.value] || []));

  $(".tabla-usuarios tbody").addEventListener("click", async e => {
    const b = e.target.closest("button");
    if (!b) return;
    const x = cargarUsuarios.lista.find(u => String(u.id) === (b.dataset.editar || b.dataset.clave || b.dataset.activo));
    try {
      if (b.dataset.editar) return abrirUsuario(x);
      if (b.dataset.clave) {
        const clave = prompt(`Nueva contraseña temporal para ${x.nombre} (mín. 8, letras y números).\nSe le pedirá cambiarla al ingresar.`);
        if (!clave) return;
        await api("restablecer_clave", {}, { id: x.id, clave });
        aviso("Contraseña restablecida");
      }
      if (b.dataset.activo) {
        const activar = b.dataset.valor === "1";
        if (!activar && !confirm(`¿Desactivar a ${x.nombre}? Ya no podrá ingresar (su historial se conserva).`)) return;
        await api("activar_usuario", {}, { id: x.id, activo: activar });
        aviso(activar ? "Usuario activado" : "Usuario desactivado");
      }
      cargarUsuarios();
    } catch (err) { aviso(err.message); }
  });

  formUsuario.addEventListener("submit", async e => {
    e.preventDefault();
    const datos = {
      nombre: formUsuario.nombre.value.trim(),
      cargo: formUsuario.cargo.value,
      secciones: $$("input[name=secciones]:checked", formUsuario).map(c => c.value),
    };
    if (usuarioEditando) datos.id = usuarioEditando.id;
    else { datos.usuario = formUsuario.usuario.value.trim(); datos.clave = formUsuario.clave.value; }
    try {
      await api("guardar_usuario", {}, datos);
      dlgUsuario.close();
      aviso("Usuario guardado");
      cargarUsuarios();
    } catch (err) {
      $("#usuario-error").textContent = err.message;
    }
  });
}
