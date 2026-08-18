<?php
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/api/attendance_helper.php';
require_once __DIR__ . '/header.php'; // maneja la sesión y la redirección a login

$rol = $user['rol'];
$matricula = $user['matricula'];
$hoy = obtenerDiaSemanaEspanol();

try {
    generarFaltasPendientes($pdo);
    // =====================================================================
    // VISTA DE ALUMNO
    // =====================================================================
    if ($rol === 'alumno') {
        // 1. Asistencia por materia (sesiones impartidas vs. asistidas)
        //    Todos los indicadores del alumno salen de ESTA consulta, para que
        //    el porcentaje, las puntualidades, los retardos y las faltas
        //    siempre cuadren entre sí. (Antes el porcentaje se calculaba sobre
        //    las clases inscritas y los contadores sobre todos los registros,
        //    así que podía verse "0% de asistencia" junto a "3 puntualidades".)
        $stmt = $pdo->prepare("
            SELECT
                c.id, c.nombre_materia, c.dia_semana, c.hora_inicio, c.hora_fin, c.aula,
                (SELECT COUNT(DISTINCT a.fecha) FROM asistencias a WHERE a.clase_id = c.id) AS sesiones,
                (SELECT COUNT(DISTINCT a.fecha) FROM asistencias a
                  WHERE a.clase_id = c.id AND a.usuario_id = :m1
                    AND a.estado IN ('presente','retardo','justificado')) AS asistidas,
                (SELECT COUNT(DISTINCT a.fecha) FROM asistencias a
                  WHERE a.clase_id = c.id AND a.usuario_id = :m2
                    AND a.estado = 'presente') AS presentes,
                (SELECT COUNT(DISTINCT a.fecha) FROM asistencias a
                  WHERE a.clase_id = c.id AND a.usuario_id = :m3
                    AND a.estado = 'retardo') AS retardos,
                (SELECT COUNT(DISTINCT a.fecha) FROM asistencias a
                  WHERE a.clase_id = c.id AND a.usuario_id = :m4
                    AND a.estado = 'justificado') AS justificados
            FROM inscripciones i
            JOIN clases c ON c.id = i.clase_id
            WHERE i.alumno_id = :m5
            ORDER BY c.hora_inicio
        ");
        $stmt->execute([
            'm1' => $matricula, 'm2' => $matricula, 'm3' => $matricula,
            'm4' => $matricula, 'm5' => $matricula,
        ]);
        $materias = $stmt->fetchAll();

        // Se calcula materia por materia y luego se suma, para que el
        // porcentaje por materia y el global usen exactamente la misma regla:
        // asistió = presente + retardo + justificado.
        $totalSesiones  = 0;
        $totalAsistidas = 0;
        $presentes = 0;
        $retardos  = 0;
        $justificados = 0;

        foreach ($materias as $i => $m) {
            $sesiones  = (int)$m['sesiones'];
            $asistidas = min((int)$m['asistidas'], $sesiones);

            $materias[$i]['faltas']     = max(0, $sesiones - $asistidas);
            $materias[$i]['porcentaje'] = $sesiones > 0 ? round(($asistidas / $sesiones) * 100) : null;

            $totalSesiones  += $sesiones;
            $totalAsistidas += $asistidas;
            $presentes      += (int)$m['presentes'];
            $retardos       += (int)$m['retardos'];
            $justificados   += (int)$m['justificados'];
        }

        $faltas = max(0, $totalSesiones - $totalAsistidas);

        // Sin sesiones impartidas todavía no hay porcentaje que mostrar:
        // se marca como null para pintar "—" en vez de un engañoso 0% o 100%.
        $porcentaje = $totalSesiones > 0 ? round(($totalAsistidas / $totalSesiones) * 100) : null;

        // Registros que llegaron sin clase asociada (fuera de horario o de
        // cuando el sistema aún no ligaba la asistencia a una materia). No
        // entran en el porcentaje, pero se avisa para que el número no
        // "desaparezca" sin explicación.
        $stmtSueltos = $pdo->prepare("
            SELECT COUNT(*) FROM asistencias
            WHERE usuario_id = :m AND clase_id IS NULL
        ");
        $stmtSueltos->execute(['m' => $matricula]);
        $registrosSinClase = (int)$stmtSueltos->fetchColumn();

        // 3. Clases de hoy y si ya pasó lista en cada una
        $stmtHoy = $pdo->prepare("
            SELECT c.*, doc.nombre AS docente_nombre, a.estado, a.hora
            FROM inscripciones i
            JOIN clases c ON c.id = i.clase_id
            JOIN usuarios doc ON doc.matricula = c.docente_id
            LEFT JOIN asistencias a
                   ON a.clase_id = c.id AND a.usuario_id = :m1 AND a.fecha = :fecha
            WHERE i.alumno_id = :m2 AND c.dia_semana = :dia
            ORDER BY c.hora_inicio
        ");
        $stmtHoy->execute(['m1' => $matricula, 'fecha' => fechaHoy(), 'm2' => $matricula, 'dia' => $hoy]);
        $clasesHoy = $stmtHoy->fetchAll();

        // 4. Últimos registros
        $stmtRecientes = $pdo->prepare("
            SELECT a.*, c.nombre_materia, c.aula
            FROM asistencias a
            LEFT JOIN clases c ON a.clase_id = c.id
            WHERE a.usuario_id = :m
            ORDER BY a.fecha DESC, a.hora DESC
            LIMIT 5
        ");
        $stmtRecientes->execute(['m' => $matricula]);
        $recientes = $stmtRecientes->fetchAll();
    }

    // =====================================================================
    // VISTA DE DOCENTE
    // =====================================================================
    elseif ($rol === 'docente') {
        $stmtClases = $pdo->prepare("SELECT COUNT(*) FROM clases WHERE docente_id = :m");
        $stmtClases->execute(['m' => $matricula]);
        $totalClases = (int)$stmtClases->fetchColumn();

        $stmtAlumnos = $pdo->prepare("
            SELECT COUNT(DISTINCT i.alumno_id)
            FROM inscripciones i
            JOIN clases c ON c.id = i.clase_id
            WHERE c.docente_id = :m
        ");
        $stmtAlumnos->execute(['m' => $matricula]);
        $totalAlumnos = (int)$stmtAlumnos->fetchColumn();

        $stmtAsistenciasHoy = $pdo->prepare("
            SELECT COUNT(*)
            FROM asistencias a
            JOIN clases c ON a.clase_id = c.id
            WHERE c.docente_id = :m AND a.fecha = :fecha
        ");
        $stmtAsistenciasHoy->execute(['m' => $matricula, 'fecha' => fechaHoy()]);
        $asistenciasHoy = (int)$stmtAsistenciasHoy->fetchColumn();

        // Clases de hoy con el conteo de quién ya pasó lista
        $stmtHoy = $pdo->prepare("
            SELECT c.*,
                   (SELECT COUNT(*) FROM inscripciones i WHERE i.clase_id = c.id) AS inscritos,
                   (SELECT COUNT(*) FROM asistencias a
                     WHERE a.clase_id = c.id AND a.fecha = :fecha
                       AND a.estado IN ('presente','retardo','justificado')) AS asistieron
            FROM clases c
            WHERE c.docente_id = :m AND c.dia_semana = :dia
            ORDER BY c.hora_inicio
        ");
        $stmtHoy->execute(['fecha' => fechaHoy(), 'm' => $matricula, 'dia' => $hoy]);
        $clasesHoy = $stmtHoy->fetchAll();

        $totalHoy = 0;
        foreach ($clasesHoy as $c) {
            $totalHoy += (int)$c['inscritos'];
        }

        $stmtRecientes = $pdo->prepare("
            SELECT a.*, u.nombre AS alumno_nombre, c.nombre_materia
            FROM asistencias a
            JOIN usuarios u ON a.usuario_id = u.matricula
            JOIN clases c ON a.clase_id = c.id
            WHERE c.docente_id = :m
            ORDER BY a.fecha DESC, a.hora DESC
            LIMIT 10
        ");
        $stmtRecientes->execute(['m' => $matricula]);
        $recientes = $stmtRecientes->fetchAll();

        $stmtDocStats = $pdo->prepare("
            SELECT
                COUNT(*) AS total_sesiones,
                SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) AS presentes,
                SUM(CASE WHEN a.estado = 'retardo' THEN 1 ELSE 0 END) AS retardos,
                SUM(CASE WHEN a.estado = 'falta' THEN 1 ELSE 0 END) AS faltas,
                SUM(CASE WHEN a.estado = 'justificado' THEN 1 ELSE 0 END) AS justificados
            FROM asistencias a
            JOIN clases c ON c.id = a.clase_id
            WHERE a.usuario_id = :m
        ");
        $stmtDocStats->execute(['m' => $matricula]);
        $docStats = $stmtDocStats->fetch();

        $docSesiones   = (int)($docStats['total_sesiones'] ?? 0);
        $docPresentes  = (int)($docStats['presentes'] ?? 0);
        $docRetardos   = (int)($docStats['retardos'] ?? 0);
        $docFaltas     = (int)($docStats['faltas'] ?? 0);
        $docJustific   = (int)($docStats['justificados'] ?? 0);
        $docAsistidas  = $docPresentes + $docRetardos + $docJustific;
        $docPorcentaje = $docSesiones > 0 ? round(($docAsistidas / $docSesiones) * 100) : null;
    }

    // =====================================================================
    // VISTA DE ADMINISTRATIVO
    // =====================================================================
    else {
        $totalAlumnos   = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'alumno' AND estado = 'activo'")->fetchColumn();
        $totalDocentes  = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'docente' AND estado = 'activo'")->fetchColumn();
        $totalHorarios  = (int)$pdo->query("SELECT COUNT(*) FROM clases")->fetchColumn();

        $stmtHoyAdmin = $pdo->prepare("SELECT COUNT(*) FROM asistencias WHERE fecha = :fecha");
        $stmtHoyAdmin->execute(['fecha' => fechaHoy()]);
        $asistenciasHoy = (int)$stmtHoyAdmin->fetchColumn();

        // Estado de las cajas ESP32
        $cajas = $pdo->query("
            SELECT device_id, nombre, aula, estado, ultima_conexion
            FROM dispositivos ORDER BY ultima_conexion DESC LIMIT 6
        ")->fetchAll();

        // Alumnos sin tarjeta o sin Telegram (avisos útiles)
        $sinTarjeta  = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'alumno' AND estado = 'activo' AND (tarjeta_rfid IS NULL OR tarjeta_rfid = '')")->fetchColumn();
        $sinTelegram = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'alumno' AND estado = 'activo' AND (id_telegram IS NULL OR id_telegram = '')")->fetchColumn();
        $sinInscripcion = (int)$pdo->query("
            SELECT COUNT(*) FROM usuarios u
            WHERE u.rol = 'alumno' AND u.estado = 'activo'
              AND NOT EXISTS (SELECT 1 FROM inscripciones i WHERE i.alumno_id = u.matricula)
        ")->fetchColumn();

        $stmtRecientes = $pdo->prepare("
            SELECT a.*, u.nombre AS usuario_nombre, u.rol AS usuario_rol, c.nombre_materia
            FROM asistencias a
            JOIN usuarios u ON a.usuario_id = u.matricula
            LEFT JOIN clases c ON a.clase_id = c.id
            ORDER BY a.fecha DESC, a.hora DESC
            LIMIT 10
        ");
        $stmtRecientes->execute();
        $recientes = $stmtRecientes->fetchAll();
    }

} catch (PDOException $e) {
    $dbError = mensajeErrorBD($e, 'cargar tu panel', $user['rol'] ?? null, 'index.php');
    $recientes = $recientes ?? [];
}
?>

<!-- Barra Superior -->
<div class="top-bar">
    <div class="page-title">
        <h2>Panel de Control</h2>
        <p>
            Bienvenido, <?php echo htmlspecialchars($user['nombre']); ?>.
            Hoy es <?php echo ucfirst($hoy); ?> <?php echo date('d/m/Y'); ?>, son las <?php echo date('h:i A'); ?>.
        </p>
    </div>
</div>

<?php if (isset($dbError)): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?php echo htmlspecialchars($dbError); ?></span>
    </div>
<?php endif; ?>

<!-- ===================================================================== -->
<!-- ESTADÍSTICAS SEGÚN EL ROL                                             -->
<!-- ===================================================================== -->
<div class="stats-grid">
    <?php if ($rol === 'alumno'): ?>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $porcentaje === null ? '—' : $porcentaje . '%'; ?></span>
                <span class="stat-label">
                    <?php if ($porcentaje === null): ?>
                        Sin clases registradas aún
                    <?php else: ?>
                        Tasa de Asistencia (<?php echo $totalAsistidas; ?>/<?php echo $totalSesiones; ?>)
                    <?php endif; ?>
                </span>
            </div>
            <div class="stat-icon stat-purple"><i class="fa-solid fa-percent"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $presentes; ?></span>
                <span class="stat-label">Puntualidades</span>
            </div>
            <div class="stat-icon stat-green"><i class="fa-solid fa-circle-check"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $retardos; ?></span>
                <span class="stat-label">Retardos</span>
            </div>
            <div class="stat-icon stat-orange"><i class="fa-solid fa-circle-exclamation"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $faltas; ?></span>
                <span class="stat-label">Faltas</span>
            </div>
            <div class="stat-icon stat-danger"><i class="fa-solid fa-circle-xmark"></i></div>
        </div>

    <?php elseif ($rol === 'docente'): ?>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $docPorcentaje === null ? '—' : $docPorcentaje . '%'; ?></span>
                <span class="stat-label">
                    <?php if ($docPorcentaje === null): ?>
                        Sin checadas registradas
                    <?php else: ?>
                        Mi Asistencia (<?php echo $docAsistidas; ?>/<?php echo $docSesiones; ?>)
                    <?php endif; ?>
                </span>
            </div>
            <div class="stat-icon stat-purple"><i class="fa-solid fa-percent"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $docPresentes; ?></span>
                <span class="stat-label">Puntualidades</span>
            </div>
            <div class="stat-icon stat-green"><i class="fa-solid fa-circle-check"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $docRetardos; ?></span>
                <span class="stat-label">Retardos</span>
            </div>
            <div class="stat-icon stat-orange"><i class="fa-solid fa-circle-exclamation"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $docFaltas; ?></span>
                <span class="stat-label">Faltas</span>
            </div>
            <div class="stat-icon stat-danger"><i class="fa-solid fa-circle-xmark"></i></div>
        </div>

    <?php else: // Administrativo ?>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $totalAlumnos; ?></span>
                <span class="stat-label">Total Alumnos</span>
            </div>
            <div class="stat-icon stat-blue"><i class="fa-solid fa-graduation-cap"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $totalDocentes; ?></span>
                <span class="stat-label">Total Docentes</span>
            </div>
            <div class="stat-icon stat-purple"><i class="fa-solid fa-chalkboard-user"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $totalHorarios; ?></span>
                <span class="stat-label">Clases Programadas</span>
            </div>
            <div class="stat-icon stat-orange"><i class="fa-solid fa-clock"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $asistenciasHoy; ?></span>
                <span class="stat-label">Asistencias Hoy</span>
            </div>
            <div class="stat-icon stat-green"><i class="fa-solid fa-calendar-day"></i></div>
        </div>
    <?php endif; ?>
</div>

<!-- ===================================================================== -->
<!-- ALUMNO: CLASES DE HOY                                                 -->
<!-- ===================================================================== -->
<?php if ($rol === 'alumno'): ?>
    <?php if (empty($materias)): ?>
        <div class="alert alert-warning" style="margin-bottom: 20px;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>
                Todavía no tienes materias asignadas, por eso no se puede calcular tu
                asistencia. Pídele al administrador que te inscriba a tus clases.
            </span>
        </div>
    <?php elseif ($registrosSinClase > 0): ?>
        <div class="alert alert-warning" style="margin-bottom: 20px;">
            <i class="fa-solid fa-circle-info"></i>
            <span>
                Tienes <strong><?php echo $registrosSinClase; ?></strong>
                registro<?php echo $registrosSinClase === 1 ? '' : 's'; ?> hecho<?php echo $registrosSinClase === 1 ? '' : 's'; ?>
                fuera del horario de tus materias. Quedan en tu historial, pero no
                cuentan para el porcentaje de asistencia.
            </span>
        </div>
    <?php endif; ?>

    <div class="panel-card" style="margin-bottom: 20px;">
        <div class="panel-header">
            <div class="panel-title">
                <i class="fa-solid fa-calendar-day icon-orange" style="margin-right: 8px;"></i>
                Tus clases de hoy (<?php echo ucfirst($hoy); ?>)
            </div>
            <a href="mis_clases.php" class="btn-filter" style="padding: 6px 12px; height: auto; font-size: 0.8rem;">
                Ver todas <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <?php if (empty($clasesHoy)): ?>
            <p style="color: var(--text-muted); text-align: center; padding: 24px;">
                No tienes clases programadas para hoy.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Materia</th>
                            <th>Horario</th>
                            <th>Aula</th>
                            <th>Docente</th>
                            <th>Tu asistencia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clasesHoy as $c): ?>
                            <?php
                                $enCurso = (horaAhora() >= $c['hora_inicio'] && horaAhora() <= $c['hora_fin']);
                            ?>
                            <tr <?php echo $enCurso ? 'style="background: rgba(34,197,94,0.07);"' : ''; ?>>
                                <td>
                                    <strong><?php echo htmlspecialchars($c['nombre_materia']); ?></strong>
                                    <?php if ($enCurso): ?>
                                        <span style="color: #22c55e; font-size: 0.7rem;">EN CURSO</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('h:i A', strtotime($c['hora_inicio'])); ?> - <?php echo date('h:i A', strtotime($c['hora_fin'])); ?></td>
                                <td><?php echo htmlspecialchars($c['aula'] ?: 'S/A'); ?></td>
                                <td style="font-size: 0.85rem;"><?php echo htmlspecialchars($c['docente_nombre']); ?></td>
                                <td>
                                    <?php if ($c['estado']): ?>
                                        <span class="badge badge-<?php echo $c['estado']; ?>"><?php echo $c['estado']; ?></span>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo date('h:i A', strtotime($c['hora'])); ?></div>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">pendiente</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ===================================================================== -->
<!-- DOCENTE: CLASES DE HOY CON ACCESO AL PASE DE LISTA                    -->
<!-- ===================================================================== -->
<?php if ($rol === 'docente'): ?>
    <div class="panel-card" style="margin-bottom: 20px;">
        <div class="panel-header">
            <div class="panel-title">
                <i class="fa-solid fa-clipboard-user icon-orange" style="margin-right: 8px;"></i>
                Tus clases de hoy (<?php echo ucfirst($hoy); ?>)
            </div>
            <a href="pase_lista.php" class="btn-filter" style="padding: 6px 12px; height: auto; font-size: 0.8rem;">
                Ver todas <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <?php if (empty($clasesHoy)): ?>
            <p style="color: var(--text-muted); text-align: center; padding: 24px;">
                Hoy no tienes clases programadas.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Materia</th>
                            <th>Horario</th>
                            <th>Aula</th>
                            <th>Asistencia</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clasesHoy as $c): ?>
                            <?php
                                $enCurso = (horaAhora() >= $c['hora_inicio'] && horaAhora() <= $c['hora_fin']);
                                $inscritos = (int)$c['inscritos'];
                                $asistieron = (int)$c['asistieron'];
                                $pct = $inscritos > 0 ? round(($asistieron / $inscritos) * 100) : 0;
                            ?>
                            <tr <?php echo $enCurso ? 'style="background: rgba(34,197,94,0.07);"' : ''; ?>>
                                <td>
                                    <strong><?php echo htmlspecialchars($c['nombre_materia']); ?></strong>
                                    <?php if ($enCurso): ?>
                                        <span style="color: #22c55e; font-size: 0.7rem;">EN CURSO</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('h:i A', strtotime($c['hora_inicio'])); ?> - <?php echo date('h:i A', strtotime($c['hora_fin'])); ?></td>
                                <td><?php echo htmlspecialchars($c['aula'] ?: 'S/A'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $pct >= 80 ? 'presente' : ($pct >= 50 ? 'retardo' : 'falta'); ?>">
                                        <?php echo $asistieron; ?>/<?php echo $inscritos; ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="pase_lista.php?clase_id=<?php echo $c['id']; ?>" class="btn-filter"
                                       style="padding: 6px 12px; height: auto; font-size: 0.8rem;">
                                        <i class="fa-solid fa-clipboard-user"></i> Pasar lista
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ===================================================================== -->
<!-- ADMIN: AVISOS Y ESTADO DE LAS CAJAS                                   -->
<!-- ===================================================================== -->
<?php if ($rol === 'administrativo'): ?>
    <?php if ($sinTarjeta > 0 || $sinTelegram > 0 || $sinInscripcion > 0): ?>
        <div class="alert alert-danger" style="background: rgba(245,158,11,0.1); border-color: rgba(245,158,11,0.3);">
            <i class="fa-solid fa-triangle-exclamation" style="color: #f59e0b;"></i>
            <span>
                Pendientes de configuración:
                <?php if ($sinTarjeta > 0): ?><a href="usuarios.php"><?php echo $sinTarjeta; ?> alumno<?php echo $sinTarjeta === 1 ? '' : 's'; ?> sin tarjeta RFID</a>. <?php endif; ?>
                <?php if ($sinTelegram > 0): ?><a href="usuarios.php"><?php echo $sinTelegram; ?> sin Telegram</a>. <?php endif; ?>
                <?php if ($sinInscripcion > 0): ?><a href="inscripciones.php"><?php echo $sinInscripcion; ?> sin clases asignadas</a>.<?php endif; ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="panel-card" style="margin-bottom: 20px;">
        <div class="panel-header">
            <div class="panel-title">
                <i class="fa-solid fa-microchip icon-purple" style="margin-right: 8px;"></i>
                Cajas ESP32
            </div>
            <a href="dispositivos.php" class="btn-filter" style="padding: 6px 12px; height: auto; font-size: 0.8rem;">
                Administrar <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <?php if (empty($cajas)): ?>
            <p style="color: var(--text-muted); text-align: center; padding: 24px;">
                Ninguna caja se ha conectado todavía. Graba un <code>DEVICE_ID</code> distinto en cada ESP32.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Caja</th>
                            <th>Aula</th>
                            <th>Estado</th>
                            <th>Última conexión</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cajas as $caja): ?>
                            <?php $enLinea = $caja['ultima_conexion'] && strtotime($caja['ultima_conexion']) > strtotime('-2 minutes'); ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($caja['nombre'] ?: $caja['device_id']); ?></strong>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><code><?php echo htmlspecialchars($caja['device_id']); ?></code></div>
                                </td>
                                <td><?php echo htmlspecialchars($caja['aula'] ?: 'Sin ubicación'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $caja['estado'] === 'activo' ? 'active' : 'inactive'; ?>">
                                        <?php echo $caja['estado'] === 'activo' ? 'activa' : 'inactiva'; ?>
                                    </span>
                                    <?php if ($enLinea): ?>
                                        <span style="color: #22c55e; font-size: 0.7rem;">● en línea</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 0.82rem;">
                                    <?php echo $caja['ultima_conexion'] ? date('d/m/Y h:i A', strtotime($caja['ultima_conexion'])) : 'Nunca'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ===================================================================== -->
<!-- ACTIVIDAD RECIENTE                                                    -->
<!-- ===================================================================== -->
<div class="content-layout">
    <div class="panel-card">
        <div class="panel-header">
            <div class="panel-title">
                <i class="fa-solid fa-clock-rotate-left icon-blue" style="margin-right: 8px;"></i>
                Actividad Reciente
            </div>
            <a href="historial.php" class="btn-filter" style="padding: 6px 12px; height: auto; font-size: 0.8rem;">
                Ver Todo <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <?php if ($rol !== 'alumno'): ?>
                            <th>Usuario / Matrícula</th>
                            <?php if ($rol === 'administrativo'): ?>
                                <th>Rol</th>
                            <?php endif; ?>
                        <?php endif; ?>
                        <th>Clase / Materia</th>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Método</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recientes)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                                No hay registros de asistencia recientes.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recientes as $r): ?>
                            <tr>
                                <?php if ($rol !== 'alumno'): ?>
                                    <td>
                                        <div><strong><?php echo htmlspecialchars($r['alumno_nombre'] ?? $r['usuario_nombre'] ?? 'Desconocido'); ?></strong></div>
                                        <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($r['usuario_id']); ?></div>
                                    </td>
                                    <?php if ($rol === 'administrativo'): ?>
                                        <td>
                                            <span class="badge badge-role-<?php echo $r['usuario_rol']; ?>">
                                                <?php echo htmlspecialchars($r['usuario_rol']); ?>
                                            </span>
                                        </td>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars($r['nombre_materia'] ?? 'General / Sin Horario'); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($r['fecha'])); ?></td>
                                <td><?php echo date('h:i A', strtotime($r['hora'])); ?></td>
                                <td>
                                    <div style="font-size: 0.82rem;">
                                        <?php if ($r['tipo'] === 'RFID_VERIFICADO'): ?>
                                            <i class="fa-solid fa-id-card icon-blue"></i> Tarjeta + Telegram
                                        <?php elseif ($r['tipo'] === 'MATRICULA_VERIFICADA'): ?>
                                            <i class="fa-solid fa-keyboard icon-purple"></i> Matrícula + Telegram
                                        <?php else: ?>
                                            <i class="fa-solid fa-user-check icon-green"></i> Firma Docente
                                        <?php endif; ?>
                                        <?php if (!empty($r['dispositivo_id'])): ?>
                                            <div style="font-size: 0.7rem; color: var(--text-muted);">
                                                <i class="fa-solid fa-microchip"></i> <?php echo htmlspecialchars($r['dispositivo_id']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $r['estado']; ?>">
                                        <?php echo htmlspecialchars($r['estado']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/footer.php';
?>
