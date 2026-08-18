<?php
require_once __DIR__ . '/../includes/conexion.php';
require_once __DIR__ . '/../includes/config.php';

// =========================================================================
// ENROLAMIENTO DE TARJETAS RFID
//
// Una tarjeta se puede dar de alta de dos maneras:
//   a) Desde la PÁGINA: el admin pulsa "Registrar tarjeta" en un usuario;
//      se crea un enrolamiento pendiente y la caja lo detecta al consultar.
//   b) Desde la CAJA: el docente pasa su tarjeta, teclea la matrícula del
//      alumno y luego se acerca la tarjeta nueva.
// =========================================================================

/**
 * Marca como expirados los enrolamientos cuya ventana de tiempo ya pasó.
 */
function limpiarEnrolamientos($pdo) {
    $pdo->exec("
        UPDATE enrolamientos_rfid
        SET estado = 'expirado', mensaje = 'Se agotó el tiempo para acercar la tarjeta'
        WHERE estado = 'pendiente' AND expira_en IS NOT NULL AND expira_en < NOW()
    ");
}

/**
 * Crea un enrolamiento pendiente para una matrícula.
 *
 * @return array ['success' => bool, 'message' => string, 'enrol_id' => int|null]
 */
function crearEnrolamiento($pdo, $matricula, $solicitadoPor, $origen = 'web', $deviceId = null) {
    limpiarEnrolamientos($pdo);

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE matricula = :m LIMIT 1");
    $stmt->execute(['m' => $matricula]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        return ['success' => false, 'message' => "La matrícula $matricula no existe", 'enrol_id' => null];
    }
    if ($usuario['estado'] !== 'activo') {
        return ['success' => false, 'message' => 'Ese usuario está dado de baja', 'enrol_id' => null];
    }

    // Cancelar cualquier solicitud previa del mismo usuario
    $stmtCancel = $pdo->prepare("
        UPDATE enrolamientos_rfid SET estado = 'cancelado', mensaje = 'Reemplazado por una solicitud nueva'
        WHERE matricula = :m AND estado = 'pendiente'
    ");
    $stmtCancel->execute(['m' => $matricula]);

    $stmtIns = $pdo->prepare("
        INSERT INTO enrolamientos_rfid (matricula, solicitado_por, origen, device_id, estado, expira_en)
        VALUES (:matricula, :solicitado_por, :origen, :device_id, 'pendiente',
                DATE_ADD(NOW(), INTERVAL " . (int)ENROL_TIMEOUT_SEGUNDOS . " SECOND))
    ");
    $stmtIns->execute([
        'matricula' => $matricula,
        'solicitado_por' => $solicitadoPor,
        'origen' => $origen,
        'device_id' => ($deviceId !== '' ? $deviceId : null)
    ]);

    return [
        'success' => true,
        'message' => 'Acerca la tarjeta al lector de la caja',
        'enrol_id' => (int)$pdo->lastInsertId(),
        'userName' => $usuario['nombre']
    ];
}

/**
 * Devuelve el enrolamiento pendiente que le toca atender a una caja.
 * Un enrolamiento sin device_id lo puede atender cualquier caja.
 */
function buscarEnrolamientoPendiente($pdo, $deviceId = null) {
    limpiarEnrolamientos($pdo);

    $stmt = $pdo->prepare("
        SELECT e.*, u.nombre AS usuario_nombre
        FROM enrolamientos_rfid e
        JOIN usuarios u ON u.matricula = e.matricula
        WHERE e.estado = 'pendiente'
          AND (e.device_id IS NULL OR e.device_id = :device_id)
        ORDER BY e.creado_en DESC
        LIMIT 1
    ");
    $stmt->execute(['device_id' => $deviceId]);
    return $stmt->fetch() ?: null;
}

/**
 * Asigna el UID leído al usuario del enrolamiento y cierra la solicitud.
 */
function completarEnrolamiento($pdo, $enrol, $uid) {
    $uid = trim(strtoupper($uid));

    if ($uid === '') {
        return ['success' => false, 'message' => 'No se leyó ningún UID'];
    }

    // ¿La tarjeta ya pertenece a alguien más?
    $stmtDup = $pdo->prepare("SELECT matricula, nombre FROM usuarios WHERE tarjeta_rfid = :uid LIMIT 1");
    $stmtDup->execute(['uid' => $uid]);
    $duenio = $stmtDup->fetch();

    if ($duenio && $duenio['matricula'] !== $enrol['matricula']) {
        $mensaje = 'Esa tarjeta ya es de ' . $duenio['nombre'];
        $stmtErr = $pdo->prepare("UPDATE enrolamientos_rfid SET mensaje = :msg WHERE id = :id");
        $stmtErr->execute(['msg' => $mensaje, 'id' => $enrol['id']]);
        return ['success' => false, 'message' => $mensaje];
    }

    $pdo->beginTransaction();
    try {
        $stmtUser = $pdo->prepare("UPDATE usuarios SET tarjeta_rfid = :uid WHERE matricula = :m");
        $stmtUser->execute(['uid' => $uid, 'm' => $enrol['matricula']]);

        $stmtEnrol = $pdo->prepare("
            UPDATE enrolamientos_rfid
            SET estado = 'completado', uid = :uid, mensaje = 'Tarjeta asignada correctamente'
            WHERE id = :id
        ");
        $stmtEnrol->execute(['uid' => $uid, 'id' => $enrol['id']]);

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('[rfid_helper] ' . $e->getMessage());
        return ['success' => false, 'message' => 'No se pudo guardar la tarjeta'];
    }

    return [
        'success' => true,
        'status' => 'enrolado',
        'uid' => $uid,
        'matricula' => $enrol['matricula'],
        'userName' => $enrol['usuario_nombre'] ?? '',
        'message' => 'Tarjeta registrada correctamente'
    ];
}

/**
 * Consulta el resultado de una solicitud (la usa el panel web para refrescar).
 */
function estadoEnrolamiento($pdo, $enrolId) {
    limpiarEnrolamientos($pdo);

    $stmt = $pdo->prepare("
        SELECT e.*, u.nombre AS usuario_nombre
        FROM enrolamientos_rfid e
        JOIN usuarios u ON u.matricula = e.matricula
        WHERE e.id = :id LIMIT 1
    ");
    $stmt->execute(['id' => $enrolId]);
    return $stmt->fetch() ?: null;
}
