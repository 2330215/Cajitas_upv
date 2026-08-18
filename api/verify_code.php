<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/../includes/conexion.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/attendance_helper.php';

// Leer datos JSON enviados
$inputData = json_decode(file_get_contents("php://input"), true);

if (!$inputData) {
    responderError("No se recibieron datos válidos", "Peticion vacia");
}

$matricula = isset($inputData['matricula']) ? trim(strtoupper($inputData['matricula'])) : '';
$code      = isset($inputData['code']) ? trim($inputData['code']) : '';
$deviceId  = isset($inputData['device_id']) ? trim($inputData['device_id']) : '';

if ($matricula === '' || $code === '') {
    responderError("Falta la matrícula o el código", "Datos incompletos");
}

try {
    $dispositivo = registrarDispositivo($pdo, $deviceId);

    // 1. Último código pendiente y vigente de esta matrícula
    $stmt = $pdo->prepare("
        SELECT * FROM codigos_verificacion
        WHERE usuario_id = :usuario_id
          AND estado = 'pendiente'
          AND expira_en > NOW()
        ORDER BY creado_en DESC
        LIMIT 1
    ");
    $stmt->execute(['usuario_id' => $matricula]);
    $registroCodigo = $stmt->fetch();

    if (!$registroCodigo) {
        // Sin código vigente pueden pasar cuatro cosas distintas, y antes las
        // cuatro decían "El código venció", que mandaba al alumno a esperar
        // cuando en realidad ya se había registrado o lo habían bloqueado.
        // Se busca el último código para decirle qué fue lo que pasó.
        $stmtUltimo = $pdo->prepare("
            SELECT estado, expira_en, intentos
            FROM codigos_verificacion
            WHERE usuario_id = :usuario_id
            ORDER BY creado_en DESC
            LIMIT 1
        ");
        $stmtUltimo->execute(['usuario_id' => $matricula]);
        $ultimo = $stmtUltimo->fetch();

        if (!$ultimo) {
            responderError(
                "No has pedido ningún código. Pasa primero tu tarjeta o tu matrícula.",
                "Pasa tu tarjeta"
            );
        }

        if ($ultimo['estado'] === 'verificado') {
            responderError(
                "Ese código ya se usó. Si necesitas registrarte otra vez, vuelve a pasar tu tarjeta.",
                "Codigo ya usado"
            );
        }

        if ((int)$ultimo['intentos'] >= 3) {
            responderError(
                "El código se bloqueó por fallar 3 veces. Vuelve a pasar tu tarjeta.",
                "Bloqueado x3"
            );
        }

        responderError(
            "El código venció. Vuelve a pasar tu tarjeta para pedir uno nuevo.",
            "Codigo vencido"
        );
    }

    // 2. Código incorrecto
    if (!hash_equals($registroCodigo['codigo'], $code)) {
        $nuevosIntentos = (int)$registroCodigo['intentos'] + 1;

        if ($nuevosIntentos >= 3) {
            $stmtLock = $pdo->prepare("UPDATE codigos_verificacion SET estado = 'expirado', intentos = :i WHERE id = :id");
            $stmtLock->execute(['i' => $nuevosIntentos, 'id' => $registroCodigo['id']]);
            responderError("Fallaste 3 veces. Vuelve a empezar el registro.", "3 fallos: reinic");
        }

        $stmtTry = $pdo->prepare("UPDATE codigos_verificacion SET intentos = :i WHERE id = :id");
        $stmtTry->execute(['i' => $nuevosIntentos, 'id' => $registroCodigo['id']]);

        $restantes = 3 - $nuevosIntentos;
        $plural = $restantes === 1 ? 'intento' : 'intentos';
        responderError(
            "Código incorrecto. Te queda" . ($restantes === 1 ? '' : 'n')
            . " $restantes $plural.",
            "Mal. Faltan: $restantes"
        );
    }

    // 3. Código correcto
    $stmtUpdate = $pdo->prepare("UPDATE codigos_verificacion SET estado = 'verificado' WHERE id = :id");
    $stmtUpdate->execute(['id' => $registroCodigo['id']]);

    $stmtUser = $pdo->prepare("SELECT * FROM usuarios WHERE matricula = :matricula LIMIT 1");
    $stmtUser->execute(['matricula' => $matricula]);
    $usuario = $stmtUser->fetch();

    if (!$usuario) {
        responderError("No se encontró al usuario", "Usuario no exist");
    }

    // 4. Clase activa y estado del registro
    $claseActiva = obtenerClaseActiva($pdo, $usuario);
    validarAulaDispositivo($dispositivo, $claseActiva);
    $claseId = $claseActiva ? $claseActiva['id'] : null;

    if (yaRegistroAsistencia($pdo, $matricula, $claseId)) {
        responder([
            "success" => true,
            "status" => "duplicado",
            "userName" => $usuario['nombre'],
            "message" => "Ya tenías tu asistencia registrada en esta clase"
        ], "Ya registrado");
    }

    $estadoAsistencia = determinarEstadoAsistencia($claseActiva);
    $tipoAsistencia = ($registroCodigo['tipo_flujo'] === 'RFID') ? 'RFID_VERIFICADO' : 'MATRICULA_VERIFICADA';

    registrarAsistencia($pdo, $matricula, $claseId, $tipoAsistencia, $estadoAsistencia, null, $deviceId);

    $etiquetas = [
        'presente' => 'Asistencia OK',
        'retardo'  => 'Con retardo',
        'falta'    => 'Llegaste tarde'
    ];

    responder([
        "success" => true,
        "status" => "verified",
        "userName" => $usuario['nombre'],
        "estado" => $estadoAsistencia,
        "clase" => $claseActiva ? $claseActiva['nombre_materia'] : null,
        "message" => "Asistencia registrada como " . $estadoAsistencia
    ], $etiquetas[$estadoAsistencia] ?? 'Registrado');

} catch (PDOException $e) {
    error_log("[verify_code.php] " . $e->getMessage());
    responderError("Error interno al verificar el código", "Error servidor");
}
