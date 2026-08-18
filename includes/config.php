<?php
// =========================================================================
// Configuración general del sistema
// =========================================================================

// Textos de error para el usuario final (mensajeErrorBD).
require_once __DIR__ . '/errores.php';

// -------------------------------------------------------------------------
// ZONA HORARIA
// -------------------------------------------------------------------------
// Se define en un solo lugar y se aplica tanto a PHP como a MySQL
// (ver includes/conexion.php). Antes, PHP usaba la hora de México pero
// MySQL usaba la del servidor (UTC en AWS), por lo que las asistencias se
// guardaban con varias horas de diferencia y el cálculo de retardo fallaba.
define('APP_TIMEZONE', 'America/Mexico_City');
date_default_timezone_set(APP_TIMEZONE);

/**
 * Fecha actual (YYYY-MM-DD) en la zona horaria del sistema.
 */
function fechaHoy() {
    return date('Y-m-d');
}

/**
 * Hora actual (HH:MM:SS) en la zona horaria del sistema.
 */
function horaAhora() {
    return date('H:i:s');
}

// -------------------------------------------------------------------------
// CONFIGURACIÓN DE TELEGRAM BOT
// -------------------------------------------------------------------------
define('TELEGRAM_BOT_TOKEN', '8009530562:AAEl4kNo-GTmbCE6OVvMpCA5GK3y7N7qRTA');

// -------------------------------------------------------------------------
// CONFIGURACIÓN DE HORARIOS (tolerancia en minutos)
// -------------------------------------------------------------------------
define('TOLERANCIA_RETARDO', 10); // Minutos tras el inicio para considerarse "Retardo"
define('TOLERANCIA_FALTA', 20);   // Minutos tras el inicio para considerarse "Falta"

// -------------------------------------------------------------------------
// ENROLAMIENTO DE TARJETAS RFID
// -------------------------------------------------------------------------
define('ENROL_TIMEOUT_SEGUNDOS', 60); // Ventana para acercar la tarjeta al lector
