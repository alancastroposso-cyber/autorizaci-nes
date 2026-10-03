<?php
/*
  Reglas del negocio: secciones de la matriz, vigencias y cálculo del estado.
*/

// Autorización de urgencias: vigencia y aviso previo (en horas).
const HORAS_URGENCIA = 8;
const HORAS_ALERTA_URGENCIA = 2;
// Vigencia de la autorización de hospitalización (en días).
const DIAS_HOSPITALIZACION = 3;
// Días antes del vencimiento de estancia/prórroga en que pasa a "Por vencer".
const DIAS_ALERTA = 1;

// Secciones de la matriz (mismo orden que el Excel). El "responsable" de cada
// sección se llena solo con el usuario que la modifica.
const SECCIONES = [
    'admision' => [
        'nombre' => 'Admisionadora',
        'responsable' => 'resp_admision',
        'campos' => ['fecha', 'numero_ingreso', 'documento', 'nombre', 'eps',
                     'autorizacion', 'hora_solicitud', 'obs_admision'],
    ],
    'urgencias' => [
        'nombre' => 'Autorizadora de urgencias',
        'responsable' => 'urg_responsable',
        'campos' => ['urg_autorizacion', 'urg_hora', 'urg_observacion'],
    ],
    'autorizacion' => [
        'nombre' => 'Autorizadora',
        'responsable' => 'egreso_responsable',
        'campos' => ['est_tipo', 'est_autorizacion', 'est_inicio', 'est_expira',
                     'proc1', 'proc2', 'proc3', 'proc4', 'proc5',
                     'pro1', 'pro1_expira', 'pro2', 'pro2_expira',
                     'pro3', 'pro3_expira', 'pro4', 'pro4_expira',
                     'egreso_autorizacion'],
    ],
    'facturacion' => [
        'nombre' => 'Facturadora',
        'responsable' => 'fact_responsable',
        'campos' => ['fecha_egreso', 'factura', 'fact_observacion'],
    ],
];

const CAMPOS_LARGOS = ['obs_admision', 'urg_observacion', 'fact_observacion'];

// Orden de columnas igual a la matriz de Excel (para exportar).
const ORDEN_EXCEL = [
    'creado_por', 'fecha', 'numero_ingreso', 'documento', 'nombre', 'eps', 'autorizacion', 'hora_solicitud',
    'obs_admision', 'resp_admision',
    'urg_autorizacion', 'urg_hora', 'urg_observacion', 'urg_responsable',
    'est_tipo', 'est_autorizacion', 'est_inicio', 'est_expira',
    'proc1', 'proc2', 'proc3', 'proc4', 'proc5',
    'pro1', 'pro1_expira', 'pro2', 'pro2_expira', 'pro3', 'pro3_expira', 'pro4', 'pro4_expira',
    'egreso_autorizacion', 'egreso_responsable',
    'fecha_egreso', 'factura', 'fact_observacion', 'fact_responsable',
    'sol_fecha', 'sol_usuario',
];

const ETIQUETAS = [
    'fecha' => 'FECHA', 'numero_ingreso' => 'NUMERO DE INGRESO', 'documento' => 'DOCUMENTO',
    'nombre' => 'NOMBRE COMPLETO', 'eps' => 'EPS', 'autorizacion' => 'AUTORIZACION',
    'hora_solicitud' => 'HORA SOLICITUD', 'obs_admision' => 'OBSERVACIONES',
    'resp_admision' => 'RESPONSABLE ADMISION',
    'urg_autorizacion' => 'AUTORIZACION DE ESTANCIA DE URGENCIA',
    'urg_hora' => 'FECHA Y HORA AUTORIZACION URGENCIA',
    'urg_observacion' => 'OBSERVACION URGENCIAS', 'urg_responsable' => 'RESPONSABLE URGENCIAS',
    'est_tipo' => 'TIPO DE ESTANCIA', 'est_autorizacion' => 'AUTORIZACION DE ESTANCIA',
    'est_inicio' => 'FECHA INICIO ESTANCIA', 'est_expira' => 'FECHA DE EXPIRACION ESTANCIA',
    'proc1' => 'AUTORIZACION DE PROCEDIMIENTO 1', 'proc2' => 'AUTORIZACION DE PROCEDIMIENTO 2',
    'proc3' => 'AUTORIZACION DE PROCEDIMIENTO 3', 'proc4' => 'AUTORIZACION DE PROCEDIMIENTO 4',
    'proc5' => 'AUTORIZACION DE PROCEDIMIENTO 5',
    'pro1' => 'AUTORIZACION DE PRORROGA 1', 'pro1_expira' => 'FECHA DE EXPIRACION PRORROGA 1',
    'pro2' => 'AUTORIZACION DE PRORROGA 2', 'pro2_expira' => 'FECHA DE EXPIRACION PRORROGA 2',
    'pro3' => 'AUTORIZACION DE PRORROGA 3', 'pro3_expira' => 'FECHA DE EXPIRACION PRORROGA 3',
    'pro4' => 'AUTORIZACION DE PRORROGA 4', 'pro4_expira' => 'FECHA DE EXPIRACION PRORROGA 4',
    'egreso_autorizacion' => 'AUTORIZACION DE EGRESO', 'egreso_responsable' => 'RESPONSABLE AUTORIZACION',
    'fecha_egreso' => 'FECHA DE EGRESO', 'factura' => 'NUMERO DE FACTURA',
    'fact_observacion' => 'OBSERVACION FACTURACION', 'fact_responsable' => 'RESPONSABLE FACTURACION',
    'sol_fecha' => 'ULTIMA SOLICITUD A EPS', 'sol_usuario' => 'SOLICITADA POR',
    'sol_estado' => 'SOLICITUD A EPS', 'creado_por' => 'RESPONSABLE DEL INGRESO',
];

// Estados en orden de prioridad (el más urgente primero): [clave, nombre, color]
const ESTADOS = [
    ['sin_autorizacion', 'Sin autorización inicial', 'rojo'],
    ['urg_vencida', 'Urgencia >8 h: pedir observación u hospitalización', 'rojo'],
    ['vencida', 'Autorización vencida', 'rojo'],
    ['falta_egreso', 'Egresó sin autorización de egreso', 'rojo'],
    ['por_vencer', 'Por vencer', 'amarillo'],
    ['listo_facturar', 'Listo para facturar', 'azul'],
    ['al_dia', 'Autorizado / al día', 'verde'],
    ['facturado', 'Facturado', 'gris'],
];

function camposEditables() {
    return array_merge(...array_map(fn($s) => $s['campos'], array_values(SECCIONES)));
}

// Campos que llena el sistema (no aparecen en el formulario).
// sol_*: registro de "solicitud enviada a la EPS" para la alerta vigente.
// creado_por*: la responsable del ingreso (quien lo registró).
const CAMPOS_SISTEMA = ['sol_estado', 'sol_fecha', 'sol_usuario', 'creado_por_id', 'creado_por'];

// Secciones que la responsable puede completar en los ingresos que ella
// registró, aunque no las tenga en sus permisos generales.
const SECCIONES_RESPONSABLE = ['admision', 'urgencias', 'autorizacion'];

// Estados que significan "falta una autorización: hay que pedirla a la EPS",
// con el texto que ve la autorizadora.
const ALERTAS = [
    'sin_autorizacion' => 'Falta la autorización inicial',
    'urg_vencida' => 'Pedir autorización de observación u hospitalización',
    'vencida' => 'Pedir prórroga: la autorización venció',
    'falta_egreso' => 'Pedir autorización de egreso',
    'por_vencer' => 'Por vencer: ir pidiendo la prórroga / hospitalización',
];

function todosLosCampos() {
    return array_merge(camposEditables(), array_column(SECCIONES, 'responsable'), CAMPOS_SISTEMA);
}

function seccionDeCampo($campo) {
    foreach (SECCIONES as $clave => $s) {
        if (in_array($campo, $s['campos'], true)) return $clave;
    }
    return null;
}

function aFecha($txt) {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim((string)$txt), 0, 10));
    return $d ?: null;
}

// Fecha y hora en que vence la autorización de urgencias.
function venceUrgencia($r) {
    $txt = trim($r['urg_hora']);
    if (trim($r['urg_autorizacion']) === '' || $txt === '') return null;
    if (strpos($txt, 'T') === false) {
        $txt = substr($r['fecha'], 0, 10) . 'T' . substr($txt, 0, 5);  // solo hora: fecha del ingreso
    }
    $inicio = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr($txt, 0, 16));
    return $inicio ? $inicio->modify('+' . HORAS_URGENCIA . ' hours') : null;
}

// Última fecha de expiración registrada (estancia o prórrogas).
function vencimientoVigente($r) {
    $max = null;
    foreach (['est_expira', 'pro1_expira', 'pro2_expira', 'pro3_expira', 'pro4_expira'] as $c) {
        $f = aFecha($r[$c]);
        if ($f && (!$max || $f > $max)) $max = $f;
    }
    return $max;
}

// Devuelve [estado, vence]. vence es 'AAAA-MM-DDTHH:MM' para urgencias
// o 'AAAA-MM-DD' para estancia/prórrogas.
function calcularEstado($r, $ahora = null) {
    $ahora = $ahora ?: new DateTimeImmutable();
    $hoy = $ahora->setTime(0, 0);
    $egresado = trim($r['fecha_egreso']) !== '';
    $urg = trim($r['est_autorizacion']) === '' ? venceUrgencia($r) : null;
    $fin = vencimientoVigente($r);
    $vence = $urg ? $urg->format('Y-m-d\TH:i') : ($fin ? $fin->format('Y-m-d') : '');

    if (trim($r['factura']) !== '') return ['facturado', $vence];
    if (trim($r['autorizacion']) === '') return ['sin_autorizacion', $vence];
    if ($egresado && trim($r['egreso_autorizacion']) === '') return ['falta_egreso', $vence];
    if (!$egresado) {
        if ($urg) {
            if ($urg <= $ahora) return ['urg_vencida', $vence];
            if ($urg <= $ahora->modify('+' . HORAS_ALERTA_URGENCIA . ' hours')) return ['por_vencer', $vence];
        } elseif ($fin) {
            if ($fin < $hoy) return ['vencida', $vence];
            if ($fin <= $hoy->modify('+' . DIAS_ALERTA . ' days')) return ['por_vencer', $vence];
        }
    }
    if (trim($r['egreso_autorizacion']) !== '') return ['listo_facturar', $vence];
    return ['al_dia', $vence];
}

function conEstado($r) {
    [$r['estado'], $r['vence']] = calcularEstado($r);
    $r['id'] = (int)$r['id'];
    // La marca de "solicitada" solo vale para la alerta que existía cuando se marcó:
    // si el ingreso pasa a otra alerta (ej. de urgencias a hospitalización), vuelve a pedirse.
    $r['solicitada'] = $r['sol_estado'] !== '' && $r['sol_estado'] === $r['estado'];
    // Ingresos anteriores a este campo: se toma quien llenó la admisión.
    $r['responsable'] = $r['creado_por'] !== '' ? $r['creado_por'] : $r['resp_admision'];
    return $r;
}
