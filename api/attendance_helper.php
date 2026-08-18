<?php
require_once __DIR__ . '/../includes/conexion.php';
require_once __DIR__ . '/../includes/config.php';

// =========================================================================
// FECHAS Y HORARIOS
// =========================================================================

/**
 * Retorna el día de la semana actual en español y minúsculas.
 */
function obtenerDiaSemanaEspanol() {
    $dias = [
        1 => 'lunes',
        2 => 'martes',
        3 => 'miercoles',
        4 => 'jueves',
        5 => 'viernes',
        6 => 'sabado',
        7 => 'domingo'
    ];
    $numDia = (int)date('N'); // 1 (lunes) a 7 (domingo)
    return $dias[$numDia];
}

/**
 * Convierte una hora "HH:MM:SS" a minutos transcurridos desde medianoche.
 * Evita usar strtotime(), que mezclaba la fecha de hoy con la hora de la clase.
 */
function minutosDelDia($hora) {
    $partes = explode(':', (string)$hora);
    $h = isset($partes[0]) ? (int)$partes[0] : 0;
    $m = isset($partes[1]) ? (int)$partes[1] : 0;
    return ($h * 60) + $m;
}

// =========================================================================
// CLASES
// =========================================================================

/**
 * Busca si existe una clase activa para el usuario en el día y hora actuales.
 *
 * Para los alumnos se usa primero la tabla `inscripciones` (asignación
 * explícita hecha por el administrador). Si el alumno no tiene inscripciones
 * registradas, se cae al método anterior por carrera/grupo/semestre.
 *
 * @param PDO   $pdo     Conexión a la base de datos.
 * @param array $usuario Datos del usuario.
 * @return array|null Datos de la clase activa o null si no hay clase.
 */
function obtenerClaseActiva($pdo, $usuario) {
    $dia = obtenerDiaSemanaEspanol();
    $horaActual = horaAhora();

    if ($usuario['rol'] === 'alumno') {
        // 1. Clases inscritas explícitamente
        $stmt = $pdo->prepare("
            SELECT c.*
            FROM clases c
            JOIN inscripciones i ON i.clase_id = c.id
            WHERE i.alumno_id = :matricula
              AND c.dia_semana = :dia
              AND :hora BETWEEN c.hora_inicio AND c.hora_fin
            ORDER BY c.hora_inicio ASC
            LIMIT 1
        ");
        $stmt->execute([
            'matricula' => $usuario['matricula'],
            'dia' => $dia,
            'hora' => $horaActual
        ]);
        $clase = $stmt->fetch();
        if ($clase) {
            return $clase;
        }

        // 2. Respaldo: coincidencia por carrera / grupo / semestre
        $stmt = $pdo->prepare("
            SELECT * FROM clases
            WHERE dia_semana = :dia
              AND carrera = :carrera
              AND grupo = :grupo
              AND semestre = :semestre
              AND :hora BETWEEN hora_inicio AND hora_fin
            LIMIT 1
        ");
        $stmt->execute([
            'dia' => $dia,
            'carrera' => $usuario['carrera'],
            'grupo' => $usuario['grupo'],
            'semestre' => $usuario['semestre'],
            'hora' => $horaActual
        ]);
        return $stmt->fetch() ?: null;
    }

    if ($usuario['rol'] === 'docente') {
        $stmt = $pdo->prepare("
            SELECT * FROM clases
            WHERE docente_id = :docente_id
              AND dia_semana = :dia
              AND :hora BETWEEN hora_inicio AND hora_fin
            LIMIT 1
        ");
        $stmt->execute([
            'docente_id' => $usuario['matricula'],
            'dia' => $dia,
            'hora' => $horaActual
        ]);
        return $stmt->fetch() ?: null;
    }

    return null;
}

/**
 * Determina el estado de la asistencia (presente, retardo, falta) según la
 * tolerancia configurada.
 *
 * @param array|null  $clase Datos de la clase activa.
 * @param string|null $hora  Hora del registro (por omisión, la hora actual).
 * @return string 'presente' | 'retardo' | 'falta'
 */
function determinarEstadoAsistencia($clase, $hora = null) {
    if (!$clase) {
        // Sin clase asociada en el horario: se registra como pase general
        return 'presente';
    }

    $hora = $hora ?: horaAhora();
    $diferenciaMinutos = minutosDelDia($hora) - minutosDelDia($clase['hora_inicio']);

    if ($diferenciaMinutos <= TOLERANCIA_RETARDO) {
        return 'presente';
    } elseif ($diferenciaMinutos <= TOLERANCIA_FALTA) {
        return 'retardo';
    }
    return 'falta';
}

// =========================================================================
// DISPOSITIVOS (cajas ESP32)
// =========================================================================

/**
 * Registra o actualiza la caja que envió la petición.
 *
 * @return array|null Fila del dispositivo, o null si no se envió device_id.
 */
function registrarDispositivo($pdo, $deviceId, $ip = null) {
    $deviceId = trim((string)$deviceId);
    if ($deviceId === '') {
        return null;
    }

    $ip = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? null);

    $stmt = $pdo->prepare("SELECT * FROM dispositivos WHERE device_id = :id LIMIT 1");
    $stmt->execute(['id' => $deviceId]);
    $dispositivo = $stmt->fetch();

    if (!$dispositivo) {
        // Alta automática: la caja aparece en el panel para que el admin la nombre
        $stmtNuevo = $pdo->prepare("
            INSERT INTO dispositivos (device_id, nombre, ultima_conexion, ultima_ip, total_lecturas)
            VALUES (:id, :nombre, NOW(), :ip, 1)
        ");
        $stmtNuevo->execute([
            'id' => $deviceId,
            'nombre' => 'Caja ' . $deviceId,
            'ip' => $ip
        ]);

        $stmt->execute(['id' => $deviceId]);
        return $stmt->fetch();
    }

    $stmtUpd = $pdo->prepare("
        UPDATE dispositivos
        SET ultima_conexion = NOW(), ultima_ip = :ip, total_lecturas = total_lecturas + 1
        WHERE device_id = :id
    ");
    $stmtUpd->execute(['id' => $deviceId, 'ip' => $ip]);

    return $dispositivo;
}

// =========================================================================
// ASISTENCIAS
// =========================================================================

/**
 * ¿El usuario ya tiene asistencia registrada hoy para esa clase?
 * Evita registros duplicados si alguien pasa la tarjeta dos veces.
 */
function yaRegistroAsistencia($pdo, $matricula, $claseId, $fecha = null) {
    $fecha = $fecha ?: fechaHoy();

    if ($claseId === null) {
        // Sin clase: se considera duplicado si ya pasó lista en la última hora
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM asistencias
            WHERE usuario_id = :matricula
              AND clase_id IS NULL
              AND fecha = :fecha
              AND hora >= :desde
        ");
        $stmt->execute([
            'matricula' => $matricula,
            'fecha' => $fecha,
            'desde' => date('H:i:s', strtotime('-1 hour'))
        ]);
        return $stmt->fetchColumn() > 0;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM asistencias
        WHERE usuario_id = :matricula AND clase_id = :clase_id AND fecha = :fecha
    ");
    $stmt->execute([
        'matricula' => $matricula,
        'clase_id' => $claseId,
        'fecha' => $fecha
    ]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Inserta una asistencia usando SIEMPRE la fecha y hora de PHP
 * (zona horaria de la escuela), no la del servidor MySQL.
 */
function registrarAsistencia($pdo, $matricula, $claseId, $tipo, $estado, $docenteAutorizoId = null, $deviceId = null) {
    // La columna dispositivo_id tiene llave foránea contra `dispositivos`.
    // Si llega un identificador que no está dado de alta, el INSERT fallaría
    // y se perdería la asistencia. Vale más guardar el registro sin caja que
    // no guardarlo: se da de alta la caja al vuelo y, si aun así no existe,
    // se guarda en NULL.
    if (!empty($deviceId)) {
        $stmtDev = $pdo->prepare("SELECT COUNT(*) FROM dispositivos WHERE device_id = :id");
        $stmtDev->execute(['id' => $deviceId]);

        if ($stmtDev->fetchColumn() == 0) {
            registrarDispositivo($pdo, $deviceId);

            $stmtDev->execute(['id' => $deviceId]);
            if ($stmtDev->fetchColumn() == 0) {
                error_log("[registrarAsistencia] Caja desconocida '$deviceId'; se guarda sin dispositivo.");
                $deviceId = null;
            }
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO asistencias
            (usuario_id, clase_id, fecha, hora, tipo, estado, docente_autorizo_id, dispositivo_id)
        VALUES
            (:usuario_id, :clase_id, :fecha, :hora, :tipo, :estado, :docente_autorizo_id, :dispositivo_id)
    ");
    $stmt->execute([
        'usuario_id' => $matricula,
        'clase_id' => $claseId,
        'fecha' => fechaHoy(),
        'hora' => horaAhora(),
        'tipo' => $tipo,
        'estado' => $estado,
        'docente_autorizo_id' => $docenteAutorizoId,
        'dispositivo_id' => ($deviceId !== '' ? $deviceId : null)
    ]);
    return (int)$pdo->lastInsertId();
}

// =========================================================================
// RESPUESTAS JSON (mensajes cortos: la LCD de la caja es de 16 caracteres)
// =========================================================================

/**
 * Prepara un texto para la pantalla LCD: quita acentos y símbolos que la
 * pantalla no puede dibujar, y lo recorta a 16 caracteres.
 *
 * Es importante recortar DESPUÉS de pasar a ASCII: cortar a la mitad una letra
 * acentuada dejaba bytes UTF-8 inválidos y json_encode() devolvía false, así
 * que la caja se quedaba sin respuesta.
 */
function textoLcd($texto) {
    $acentos = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        '¿'=>'', '¡'=>'', '“'=>'"', '”'=>'"', '–'=>'-', '—'=>'-', '…'=>'...',
    ];
    $texto = strtr((string)$texto, $acentos);
    $texto = preg_replace('/[^\x20-\x7E]/', '', $texto); // solo ASCII imprimible

    return trim(substr(trim($texto), 0, 16));
}

/**
 * Responde en JSON y termina la ejecución.
 *
 * @param string $lcd Mensaje corto para la pantalla de la caja.
 */
function responder($data, $lcd = null) {
    if ($lcd !== null) {
        $data['lcd'] = textoLcd($lcd);
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        // Último recurso: nunca dejar a la caja sin respuesta
        error_log('[responder] json_encode falló: ' . json_last_error_msg());
        $json = json_encode([
            'success' => false,
            'message' => 'Error al preparar la respuesta',
            'lcd' => 'Error respuesta'
        ]);
    }

    echo $json;
    exit;
}

/**
 * Respuesta de error estándar.
 */
function responderError($mensaje, $lcd = null, $extra = []) {
    responder(array_merge(['success' => false, 'message' => $mensaje], $extra), $lcd ?: $mensaje);
}

function validarAulaDispositivo($dispositivo, $claseActiva) {
    if (!$dispositivo || !$claseActiva) {
        return;
    }
    $aulaDispositivo = isset($dispositivo['aula']) ? trim($dispositivo['aula']) : '';
    $aulaClase = isset($claseActiva['aula']) ? trim($claseActiva['aula']) : '';

    if ($aulaDispositivo !== '' && $aulaClase !== '' && strcasecmp($aulaDispositivo, $aulaClase) !== 0) {
        responderError("Esta clase es en el aula " . $aulaClase . ", no en esta caja", "Aula " . $aulaClase);
    }
}

function generarFaltasParaClaseYFecha($pdo, $claseId, $fecha) {
    $stmt = $pdo->prepare("SELECT * FROM clases WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $claseId]);
    $clase = $stmt->fetch();
    if (!$clase) {
        return 0;
    }

    $esHoy = ($fecha === fechaHoy());
    if ($fecha > fechaHoy()) {
        return 0;
    }
    if ($esHoy && horaAhora() <= $clase['hora_fin']) {
        return 0;
    }

    $dias = [1 => 'lunes', 2 => 'martes', 3 => 'miercoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sabado', 7 => 'domingo'];
    $numDia = (int)date('N', strtotime($fecha));
    $diaSemanaFecha = $dias[$numDia] ?? '';

    if (strcasecmp($diaSemanaFecha, $clase['dia_semana']) !== 0) {
        return 0;
    }

    $stmtInsert = $pdo->prepare("
        INSERT INTO asistencias (usuario_id, clase_id, fecha, hora, tipo, estado)
        SELECT i.alumno_id, :clase_id, :fecha, :hora_fin, 'MATRICULA_VERIFICADA', 'falta'
        FROM inscripciones i
        JOIN usuarios u ON u.matricula = i.alumno_id
        WHERE i.clase_id = :clase_id2
          AND u.estado = 'activo'
          AND NOT EXISTS (
              SELECT 1 FROM asistencias a
              WHERE a.usuario_id = i.alumno_id
                AND a.clase_id = :clase_id3
                AND a.fecha = :fecha2
          )
    ");
    $stmtInsert->execute([
        'clase_id'  => $claseId,
        'fecha'     => $fecha,
        'hora_fin'  => $clase['hora_fin'],
        'clase_id2' => $claseId,
        'clase_id3' => $claseId,
        'fecha2'    => $fecha,
    ]);

    if (!empty($clase['docente_id'])) {
        $stmtInsertDoc = $pdo->prepare("
            INSERT INTO asistencias (usuario_id, clase_id, fecha, hora, tipo, estado)
            SELECT u.matricula, :clase_id, :fecha, :hora_fin, 'MATRICULA_VERIFICADA', 'falta'
            FROM usuarios u
            WHERE u.matricula = :docente_id
              AND u.estado = 'activo'
              AND NOT EXISTS (
                  SELECT 1 FROM asistencias a
                  WHERE a.usuario_id = :docente_id2
                    AND a.clase_id = :clase_id2
                    AND a.fecha = :fecha2
              )
        ");
        $stmtInsertDoc->execute([
            'clase_id'    => $claseId,
            'fecha'       => $fecha,
            'hora_fin'    => $clase['hora_fin'],
            'docente_id'  => $clase['docente_id'],
            'docente_id2' => $clase['docente_id'],
            'clase_id2'   => $claseId,
            'fecha2'      => $fecha,
        ]);
    }

    return $stmtInsert->rowCount();
}

function generarFaltasPendientes($pdo) {
    $diaHoy = obtenerDiaSemanaEspanol();
    $horaActual = horaAhora();

    $stmt = $pdo->prepare("
        SELECT id FROM clases
        WHERE dia_semana = :dia AND hora_fin < :hora
    ");
    $stmt->execute(['dia' => $diaHoy, 'hora' => $horaActual]);
    $clasesHoy = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($clasesHoy as $claseId) {
        generarFaltasParaClaseYFecha($pdo, $claseId, fechaHoy());
    }
}


