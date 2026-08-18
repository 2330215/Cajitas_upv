<?php
// =========================================================================
// API DE ENROLAMIENTO DE TARJETAS RFID
// =========================================================================
// Acciones desde la PÁGINA WEB (requieren sesión de administrativo):
//   accion=start    { matricula, device_id? }  -> abre la solicitud
//   accion=status   { enrol_id }               -> consulta el resultado
//   accion=cancel   { enrol_id }               -> cancela la solicitud
//
// Acciones desde la CAJA ESP32 (sin sesión):
//   accion=pending     { device_id }                       -> ¿hay algo pendiente?
//   accion=caja_start  { device_id, tarjeta_docente, matricula }
//   accion=assign      { device_id, uid }                  -> guarda el UID leído
// =========================================================================

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/../includes/conexion.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/attendance_helper.php';
require_once __DIR__ . '/rfid_helper.php';

// Aceptar datos por JSON, POST clásico o querystring
$body = json_decode(file_get_contents("php://input"), true) ?: [];
$datos = array_merge($_GET, $_POST, $body);

$accion   = isset($datos['accion']) ? trim($datos['accion']) : '';
$deviceId = isset($datos['device_id']) ? trim($datos['device_id']) : '';

/**
 * Exige sesión iniciada de administrativo para las acciones del panel web.
 */
function exigirAdmin() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'administrativo') {
        http_response_code(403);
        responderError('No tienes permiso para registrar tarjetas');
    }
    return $_SESSION['usuario'];
}

try {
    switch ($accion) {

        // -----------------------------------------------------------------
        // PÁGINA WEB
        // -----------------------------------------------------------------
        case 'start': {
            $admin = exigirAdmin();
            $matricula = isset($datos['matricula']) ? trim(strtoupper($datos['matricula'])) : '';

            if ($matricula === '') {
                responderError('Selecciona a qué usuario asignarle la tarjeta');
            }

            $resultado = crearEnrolamiento($pdo, $matricula, $admin['matricula'], 'web', $deviceId);
            $resultado['segundos'] = ENROL_TIMEOUT_SEGUNDOS;
            responder($resultado);
        }

        case 'status': {
            exigirAdmin();
            $enrolId = isset($datos['enrol_id']) ? (int)$datos['enrol_id'] : 0;
            $enrol = estadoEnrolamiento($pdo, $enrolId);

            if (!$enrol) {
                responderError('No se encontró la solicitud');
            }

            responder([
                'success' => true,
                'estado' => $enrol['estado'],
                'uid' => $enrol['uid'],
                'matricula' => $enrol['matricula'],
                'userName' => $enrol['usuario_nombre'],
                'message' => $enrol['mensaje'] ?: 'Esperando que acerques la tarjeta...'
            ]);
        }

        case 'cancel': {
            exigirAdmin();
            $enrolId = isset($datos['enrol_id']) ? (int)$datos['enrol_id'] : 0;

            $stmt = $pdo->prepare("
                UPDATE enrolamientos_rfid
                SET estado = 'cancelado', mensaje = 'Cancelado desde el panel'
                WHERE id = :id AND estado = 'pendiente'
            ");
            $stmt->execute(['id' => $enrolId]);

            responder(['success' => true, 'message' => 'Solicitud cancelada']);
        }

        // -----------------------------------------------------------------
        // CAJA ESP32
        // -----------------------------------------------------------------
        case 'pending': {
            $dispositivo = registrarDispositivo($pdo, $deviceId);
            if ($dispositivo && $dispositivo['estado'] === 'inactivo') {
                responder(['success' => true, 'pendiente' => false]);
            }

            $enrol = buscarEnrolamientoPendiente($pdo, $deviceId);

            if (!$enrol) {
                responder(['success' => true, 'pendiente' => false]);
            }

            responder([
                'success' => true,
                'pendiente' => true,
                'enrol_id' => (int)$enrol['id'],
                'matricula' => $enrol['matricula'],
                'userName' => $enrol['usuario_nombre']
            ], substr($enrol['usuario_nombre'], 0, 16));
        }

        case 'caja_start': {
            registrarDispositivo($pdo, $deviceId);

            $tarjetaDocente = isset($datos['tarjeta_docente']) ? trim(strtoupper($datos['tarjeta_docente'])) : '';
            $matricula      = isset($datos['matricula']) ? trim(strtoupper($datos['matricula'])) : '';

            if ($tarjetaDocente === '' || $matricula === '') {
                responderError('Faltan la tarjeta del docente o la matrícula', 'Datos incompletos');
            }

            // Solo docentes o administrativos pueden dar de alta tarjetas
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE tarjeta_rfid = :t AND estado = 'activo' LIMIT 1");
            $stmt->execute(['t' => $tarjetaDocente]);
            $autoriza = $stmt->fetch();

            if (!$autoriza) {
                responderError('Esa tarjeta no está registrada', 'Tarjeta desconoc');
            }
            if (!in_array($autoriza['rol'], ['docente', 'administrativo'], true)) {
                responderError('Solo un docente puede dar de alta tarjetas', 'No es docente');
            }

            $resultado = crearEnrolamiento($pdo, $matricula, $autoriza['matricula'], 'caja', $deviceId);

            if (!$resultado['success']) {
                responderError($resultado['message'], 'Alumno no valido');
            }

            responder($resultado, 'Pase tarj nueva');
        }

        case 'assign': {
            registrarDispositivo($pdo, $deviceId);

            $uid = isset($datos['uid']) ? trim(strtoupper($datos['uid'])) : '';
            if ($uid === '') {
                responderError('No se recibió el UID de la tarjeta', 'Sin lectura');
            }

            $enrolId = isset($datos['enrol_id']) ? (int)$datos['enrol_id'] : 0;
            $enrol = $enrolId ? estadoEnrolamiento($pdo, $enrolId) : buscarEnrolamientoPendiente($pdo, $deviceId);

            if (!$enrol || $enrol['estado'] !== 'pendiente') {
                responderError('No hay ninguna alta de tarjeta pendiente', 'Nada pendiente');
            }

            $resultado = completarEnrolamiento($pdo, $enrol, $uid);

            if (!$resultado['success']) {
                responderError($resultado['message'], 'Tarjeta ocupada');
            }

            responder($resultado, 'Tarjeta lista');
        }

        default:
            responderError('Acción no reconocida: ' . $accion);
    }

} catch (PDOException $e) {
    error_log("[rfid_enroll.php] " . $e->getMessage());
    responderError('Error interno del servidor', 'Error servidor');
}
