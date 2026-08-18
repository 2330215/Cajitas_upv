<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/../includes/conexion.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/attendance_helper.php';
require_once __DIR__ . '/rfid_helper.php';
require_once __DIR__ . '/telegram_helper.php';

// Leer datos JSON enviados
$inputData = json_decode(file_get_contents("php://input"), true);

if (!$inputData) {
    responderError("No se recibieron datos válidos", "Peticion vacia");
}

$id       = isset($inputData['id']) ? trim(strtoupper($inputData['id'])) : '';
$type     = isset($inputData['type']) ? trim(strtoupper($inputData['type'])) : '';
$deviceId = isset($inputData['device_id']) ? trim($inputData['device_id']) : '';

if ($id === '' || $type === '') {
    responderError("Faltan el ID y el tipo de lectura", "Datos incompletos");
}

try {
    // ---------------------------------------------------------------------
    // IDENTIFICAR LA CAJA (ID único por ESP32)
    // ---------------------------------------------------------------------
    $dispositivo = registrarDispositivo($pdo, $deviceId);

    if ($dispositivo && $dispositivo['estado'] === 'inactivo') {
        responderError("Esta caja está deshabilitada por el administrador", "Caja bloqueada");
    }

    // ---------------------------------------------------------------------
    // FLUJO C: AUTORIZACIÓN DEL DOCENTE (bypass sin Telegram)
    // ---------------------------------------------------------------------
    if ($type === 'RFID_BYPASS') {
        $studentMatricula = isset($inputData['student_matricula'])
            ? trim(strtoupper($inputData['student_matricula'])) : '';

        if ($studentMatricula === '') {
            responderError("No se indicó a qué alumno autorizar", "Falta alumno");
        }

        // 1. La tarjeta debe pertenecer a un docente o administrativo activo
        $stmtDocente = $pdo->prepare("SELECT * FROM usuarios WHERE tarjeta_rfid = :rfid AND estado = 'activo' LIMIT 1");
        $stmtDocente->execute(['rfid' => $id]);
        $docente = $stmtDocente->fetch();

        if (!$docente) {
            responderError("Esa tarjeta no está registrada en el sistema", "Tarjeta desconoc");
        }
        if (!in_array($docente['rol'], ['docente', 'administrativo'], true)) {
            responderError("Solo un docente o administrativo puede autorizar", "No es docente");
        }

        // 2. El alumno debe existir y estar activo
        $stmtAlumno = $pdo->prepare("SELECT * FROM usuarios WHERE matricula = :matricula AND estado = 'activo' LIMIT 1");
        $stmtAlumno->execute(['matricula' => $studentMatricula]);
        $alumno = $stmtAlumno->fetch();

        if (!$alumno) {
            responderError("La matrícula $studentMatricula no existe o está inactiva", "Alumno no valido");
        }

        // 3. Resolver clase activa y estado (presente / retardo / falta)
        $claseActiva = obtenerClaseActiva($pdo, $alumno);
        validarAulaDispositivo($dispositivo, $claseActiva);
        $claseId = $claseActiva ? $claseActiva['id'] : null;

        if (yaRegistroAsistencia($pdo, $studentMatricula, $claseId)) {
            responder([
                "success" => true,
                "status" => "duplicado",
                "userName" => $alumno['nombre'],
                "message" => "Este alumno ya tenía su asistencia registrada"
            ], "Ya registrado");
        }

        $estadoAsistencia = determinarEstadoAsistencia($claseActiva);

        // 4. Guardar la asistencia
        registrarAsistencia(
            $pdo,
            $studentMatricula,
            $claseId,
            'AUTORIZADO_DOCENTE',
            $estadoAsistencia,
            $docente['matricula'],
            $deviceId
        );

        responder([
            "success" => true,
            "status" => "verified",
            "userName" => $alumno['nombre'],
            "estado" => $estadoAsistencia,
            "clase" => $claseActiva ? $claseActiva['nombre_materia'] : null,
            "message" => "Asistencia autorizada por " . $docente['nombre']
        ], strtoupper($estadoAsistencia));
    }

    // ---------------------------------------------------------------------
    // FLUJO A / B: RFID O MATRÍCULA (inicio de verificación)
    // ---------------------------------------------------------------------
    $usuario = null;

    if ($type === 'RFID') {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE tarjeta_rfid = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $usuario = $stmt->fetch();

        // Si la tarjeta no existe, quizá hay un enrolamiento pendiente
        // solicitado desde la página web: se la asignamos en ese momento.
        if (!$usuario) {
            $enrol = buscarEnrolamientoPendiente($pdo, $deviceId);
            if ($enrol) {
                $resultado = completarEnrolamiento($pdo, $enrol, $id);
                responder($resultado, $resultado['success'] ? 'Tarjeta lista' : 'Error tarjeta');
            }
            responderError("Tarjeta no registrada. Pide que la den de alta.", "Tarjeta no dada");
        }

        if ($usuario['estado'] !== 'activo') {
            responderError("El usuario de esa tarjeta está dado de baja", "Usuario inactivo");
        }
    } elseif ($type === 'TECLADO') {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE matricula = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            responderError("La matrícula $id no existe en el sistema", "Matricula no hay");
        }
        if ($usuario['estado'] !== 'activo') {
            responderError("Ese usuario está dado de baja", "Usuario inactivo");
        }
    } else {
        responderError("Tipo de lectura no reconocido: $type", "Lectura invalida");
    }

    // ---------------------------------------------------------------------
    // ¿Ya registró asistencia para la clase de este momento?
    // ---------------------------------------------------------------------
    $claseActiva = obtenerClaseActiva($pdo, $usuario);
    validarAulaDispositivo($dispositivo, $claseActiva);
    if (yaRegistroAsistencia($pdo, $usuario['matricula'], $claseActiva ? $claseActiva['id'] : null)) {
        responder([
            "success" => true,
            "status" => "duplicado",
            "userName" => $usuario['nombre'],
            "message" => "Ya tienes tu asistencia registrada en esta clase"
        ], "Ya registrado");
    }

    // ---------------------------------------------------------------------
    // Sin Telegram -> requiere firma del docente (Flujo C)
    // ---------------------------------------------------------------------
    if (empty($usuario['id_telegram'])) {
        responder([
            "success" => true,
            "status" => "require_bypass",
            "message" => "Este usuario no tiene Telegram. Necesita la tarjeta del docente.",
            "userName" => $usuario['nombre'],
            "matricula" => $usuario['matricula']
        ], "Pase tarj. prof");
    }

    // ---------------------------------------------------------------------
    // Generar y enviar código de 4 dígitos
    // ---------------------------------------------------------------------
    $codigo = sprintf("%04d", random_int(0, 9999));

    // Invalidar códigos anteriores que sigan pendientes
    $stmtLimpia = $pdo->prepare("
        UPDATE codigos_verificacion SET estado = 'expirado'
        WHERE usuario_id = :usuario_id AND estado = 'pendiente'
    ");
    $stmtLimpia->execute(['usuario_id' => $usuario['matricula']]);

    $stmtCode = $pdo->prepare("
        INSERT INTO codigos_verificacion (usuario_id, codigo, tipo_flujo, expira_en, estado)
        VALUES (:usuario_id, :codigo, :tipo_flujo, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 'pendiente')
    ");
    $stmtCode->execute([
        'usuario_id' => $usuario['matricula'],
        'codigo' => $codigo,
        'tipo_flujo' => ($type === 'RFID' ? 'RFID' : 'MATRICULA')
    ]);

    $mensaje = "🔑 *Código de Verificación*\n\n"
        . "Hola " . $usuario['nombre'] . ",\n"
        . "Tu código para registrar la asistencia es:\n\n"
        . "👉 *`" . $codigo . "`*\n\n"
        . "Tienes *5 minutos* para escribirlo en el teclado de la caja.";

    $telegramEnviado = enviarMensajeTelegram($usuario['id_telegram'], $mensaje);

    if (!$telegramEnviado) {
        responderError(
            "No se pudo enviar el código por Telegram. Revisa el chat ID del usuario.",
            "Telegram fallo",
            ["status" => "telegram_error", "matricula" => $usuario['matricula']]
        );
    }

    responder([
        "success" => true,
        "status" => "code_sent",
        "message" => "Código enviado por Telegram",
        "userName" => $usuario['nombre'],
        "matricula" => $usuario['matricula']
    ], "Revisa Telegram");

} catch (PDOException $e) {
    error_log("[attendance.php] " . $e->getMessage());
    responderError("Error interno de la base de datos", "Error servidor");
}
