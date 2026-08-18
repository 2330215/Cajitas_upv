<?php
// =========================================================================
// MENSAJES DE ERROR
// =========================================================================
// Antes cada página atrapaba el PDOException y mostraba siempre el mismo
// texto: "¿Ya ejecutaste database/migracion_v2.sql?". Eso tenía dos
// problemas:
//
//   1. Se lo enseñaba igual a un alumno o a un docente, que no tienen
//      forma de ejecutar nada ni saben qué es un .sql.
//   2. Salía aunque el fallo fuera otro (la base caída, un campo repetido,
//      un permiso), así que mandaba a revisar donde no era.
//
// Aquí se decide el mensaje a partir del código real de MySQL y del rol de
// quien está viendo la pantalla. El detalle técnico siempre va al log.
// =========================================================================

/**
 * Convierte un PDOException en un mensaje entendible para quien lo lee.
 *
 * @param PDOException $e      La excepción atrapada.
 * @param string       $accion Qué se estaba haciendo, en lenguaje normal.
 *                             Ej: 'cargar tus materias', 'guardar la caja'.
 * @param string|null  $rol    Rol del usuario en sesión. Si es
 *                             'administrativo' se incluye la pista técnica.
 * @param string       $origen Archivo o módulo, sólo para el log.
 */
function mensajeErrorBD(PDOException $e, $accion, $rol = null, $origen = '') {
    error_log('[' . ($origen ?: 'BD') . '] ' . $e->getMessage());

    $esAdmin = ($rol === 'administrativo');
    $sqlState = $e->getCode();

    // El driver no siempre rellena errorInfo[1]; se protege el acceso.
    $info = $e->errorInfo ?? [];
    $codigoMysql = isset($info[1]) ? (int)$info[1] : 0;

    switch (true) {

        // Tabla o columna que no existe: falta correr la migración.
        case $sqlState === '42S02' || $sqlState === '42S22'
          || $codigoMysql === 1146 || $codigoMysql === 1054:
            return $esAdmin
                ? "No se pudo $accion porque la base de datos está incompleta. "
                . 'Ejecuta database/migracion_v2.sql sobre la base del sistema.'
                : "No se pudo $accion porque el sistema está a medio actualizar. "
                . 'Avísale al administrador.';

        // Dato repetido en una columna única (matrícula, correo, tarjeta...).
        case $codigoMysql === 1062:
            return "No se pudo $accion: ese dato ya está registrado a nombre de alguien más.";

        // Llave foránea: se apunta a algo que ya no existe.
        case $codigoMysql === 1451 || $codigoMysql === 1452:
            return "No se pudo $accion porque hay información relacionada que lo impide. "
                 . 'Revisa que el alumno, la clase o la caja sigan existiendo.';

        // Base caída, credenciales, permisos.
        case $sqlState === 'HY000' || $codigoMysql === 1045 || $codigoMysql === 2002:
            return $esAdmin
                ? "No se pudo $accion: no hay conexión con la base de datos. "
                . 'Revisa que el servicio de MySQL esté encendido.'
                : "No se pudo $accion en este momento. Avísale al administrador.";

        default:
            return $esAdmin
                ? "No se pudo $accion. Revisa el log del servidor para ver el detalle."
                : "No se pudo $accion. Intenta de nuevo; si sigue igual, avísale al administrador.";
    }
}
