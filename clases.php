<?php
session_start();
require_once __DIR__ . '/includes/conexion.php';

// Verificar sesión
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['usuario'];

// Restringir acceso solo a administrativos
if ($user['rol'] !== 'administrativo') {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';
$editando = null;

$diasSemana = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];

/**
 * Lee y normaliza los campos del formulario de clases.
 */
function leerFormularioClase() {
    $limpiar = function ($valor) {
        $valor = trim((string)$valor);
        return $valor === '' ? null : $valor;
    };

    return [
        'nombre_materia' => trim($_POST['nombre_materia'] ?? ''),
        'docente_id'     => trim($_POST['docente_id'] ?? ''),
        'dia_semana'     => trim($_POST['dia_semana'] ?? ''),
        'hora_inicio'    => trim($_POST['hora_inicio'] ?? ''),
        'hora_fin'       => trim($_POST['hora_fin'] ?? ''),
        'aula'           => $limpiar($_POST['aula'] ?? ''),
        'carrera'        => $limpiar($_POST['carrera'] ?? ''),
        'grupo'          => $limpiar($_POST['grupo'] ?? ''),
        'semestre'       => empty($_POST['semestre']) ? null : (int)$_POST['semestre'],
    ];
}

/**
 * Valida los datos de una clase. Devuelve el mensaje de error o ''.
 */
function validarClase($pdo, $d, $diasSemana) {
    if ($d['nombre_materia'] === '' || $d['docente_id'] === '' || $d['dia_semana'] === ''
        || $d['hora_inicio'] === '' || $d['hora_fin'] === '') {
        return 'Faltan datos: materia, docente, día y horario son obligatorios.';
    }

    if (!in_array($d['dia_semana'], $diasSemana, true)) {
        return 'El día de la semana seleccionado no es válido.';
    }

    if ($d['hora_fin'] <= $d['hora_inicio']) {
        return 'La hora de fin debe ser posterior a la hora de inicio.';
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE matricula = :id AND rol = 'docente' AND estado = 'activo'");
    $stmt->execute(['id' => $d['docente_id']]);
    if ($stmt->fetchColumn() == 0) {
        return 'El docente seleccionado no existe o está dado de baja.';
    }

    return '';
}

/**
 * Busca un empalme de horario del mismo docente. Devuelve el mensaje o ''.
 */
function buscarEmpalme($pdo, $d, $idExcluir = null) {
    $sql = "
        SELECT nombre_materia, hora_inicio, hora_fin FROM clases
        WHERE docente_id = :docente_id
          AND dia_semana = :dia
          AND hora_inicio < :hora_fin
          AND hora_fin > :hora_inicio
    ";
    $params = [
        'docente_id' => $d['docente_id'],
        'dia' => $d['dia_semana'],
        'hora_inicio' => $d['hora_inicio'],
        'hora_fin' => $d['hora_fin'],
    ];

    if ($idExcluir !== null) {
        $sql .= " AND id != :id";
        $params['id'] = $idExcluir;
    }

    $stmt = $pdo->prepare($sql . " LIMIT 1");
    $stmt->execute($params);
    $choque = $stmt->fetch();

    if ($choque) {
        return 'Ese docente ya tiene "' . $choque['nombre_materia'] . '" el mismo día de '
            . substr($choque['hora_inicio'], 0, 5) . ' a ' . substr($choque['hora_fin'], 0, 5) . '.';
    }

    if (!empty($d['aula'])) {
        $sqlAula = "
            SELECT nombre_materia, hora_inicio, hora_fin FROM clases
            WHERE aula = :aula
              AND dia_semana = :dia
              AND hora_inicio < :hora_fin
              AND hora_fin > :hora_inicio
        ";
        $paramsAula = [
            'aula' => $d['aula'],
            'dia' => $d['dia_semana'],
            'hora_inicio' => $d['hora_inicio'],
            'hora_fin' => $d['hora_fin'],
        ];

        if ($idExcluir !== null) {
            $sqlAula .= " AND id != :id";
            $paramsAula['id'] = $idExcluir;
        }

        $stmtAula = $pdo->prepare($sqlAula . " LIMIT 1");
        $stmtAula->execute($paramsAula);
        $choqueAula = $stmtAula->fetch();

        if ($choqueAula) {
            return 'El aula "' . $d['aula'] . '" ya está ocupada por "' . $choqueAula['nombre_materia'] . '" el mismo día de '
                . substr($choqueAula['hora_inicio'], 0, 5) . ' a ' . substr($choqueAula['hora_fin'], 0, 5) . '.';
        }
    }

    return '';
}

// -------------------------------------------------------------------------
// 1. PROCESAR ACCIONES
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $d = leerFormularioClase();

    if ($accion === 'guardar' || $accion === 'actualizar') {
        $claseId = ($accion === 'actualizar') ? (int)($_POST['clase_id'] ?? 0) : null;

        $error = validarClase($pdo, $d, $diasSemana);
        if ($error === '') {
            $error = buscarEmpalme($pdo, $d, $claseId);
        }

        if ($error === '') {
            try {
                if ($accion === 'guardar') {
                    $stmt = $pdo->prepare("
                        INSERT INTO clases (nombre_materia, docente_id, dia_semana, hora_inicio, hora_fin, aula, carrera, grupo, semestre)
                        VALUES (:nombre_materia, :docente_id, :dia_semana, :hora_inicio, :hora_fin, :aula, :carrera, :grupo, :semestre)
                    ");
                    $stmt->execute($d);
                    $success = 'Clase "' . $d['nombre_materia'] . '" agregada al horario.';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE clases SET
                            nombre_materia = :nombre_materia,
                            docente_id = :docente_id,
                            dia_semana = :dia_semana,
                            hora_inicio = :hora_inicio,
                            hora_fin = :hora_fin,
                            aula = :aula,
                            carrera = :carrera,
                            grupo = :grupo,
                            semestre = :semestre
                        WHERE id = :id
                    ");
                    $stmt->execute(array_merge($d, ['id' => $claseId]));
                    $success = 'Clase "' . $d['nombre_materia'] . '" actualizada correctamente.';
                }
            } catch (PDOException $e) {
                $error = mensajeErrorBD($e, 'guardar la clase', $user['rol'] ?? null, 'clases.php');
            }
        }
    }
}

// -------------------------------------------------------------------------
// ELIMINAR CLASE
// -------------------------------------------------------------------------
if (isset($_GET['eliminar'])) {
    $idEliminar = (int)$_GET['eliminar'];
    try {
        $stmt = $pdo->prepare("DELETE FROM clases WHERE id = :id");
        $stmt->execute(['id' => $idEliminar]);
        $success = $stmt->rowCount() > 0
            ? 'Clase eliminada del horario. Las asistencias previas se conservan sin materia.'
            : 'Esa clase ya no existe en el horario.';
    } catch (PDOException $e) {
        $error = mensajeErrorBD($e, 'eliminar la clase', $user['rol'] ?? null, 'clases.php');
    }
}

// -------------------------------------------------------------------------
// CARGAR CLASE A EDITAR
// -------------------------------------------------------------------------
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM clases WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => (int)$_GET['editar']]);
    $editando = $stmt->fetch() ?: null;

    if (!$editando) {
        $error = 'No se encontró la clase que quieres editar.';
    }
}

// -------------------------------------------------------------------------
// LISTADOS
// -------------------------------------------------------------------------
try {
    $docentes = $pdo->query("
        SELECT matricula, nombre FROM usuarios
        WHERE rol = 'docente' AND estado = 'activo' ORDER BY nombre ASC
    ")->fetchAll();

    $clases = $pdo->query("
        SELECT c.*, u.nombre AS docente_nombre,
               (SELECT COUNT(*) FROM inscripciones i WHERE i.clase_id = c.id) AS inscritos
        FROM clases c
        JOIN usuarios u ON c.docente_id = u.matricula
        ORDER BY FIELD(c.dia_semana, 'lunes','martes','miercoles','jueves','viernes','sabado','domingo'), c.hora_inicio ASC
    ")->fetchAll();
} catch (PDOException $e) {
    $error = mensajeErrorBD($e, 'cargar el horario', $user['rol'] ?? null, 'clases.php');
    $docentes = [];
    $clases = [];
}

$v = function ($campo, $defecto = '') use ($editando) {
    return htmlspecialchars($editando[$campo] ?? $defecto);
};

require_once __DIR__ . '/header.php';
?>

<div class="top-bar">
    <div class="page-title">
        <h2>Gestión de Horarios y Clases</h2>
        <p>Configura las materias, el docente asignado y el horario escolar que usa la caja para calcular retardos.</p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-circle-check"></i>
        <span><?php echo htmlspecialchars($success); ?></span>
    </div>
<?php endif; ?>

<div class="content-layout content-layout-split">
    <!-- PANEL IZQUIERDO: FORMULARIO -->
    <div class="panel-card">
        <div class="panel-header">
            <div class="panel-title">
                <?php if ($editando): ?>
                    <i class="fa-solid fa-pen-to-square icon-orange" style="margin-right: 8px;"></i>
                    Editar Clase
                <?php else: ?>
                    <i class="fa-solid fa-circle-plus icon-purple" style="margin-right: 8px;"></i>
                    Agregar Nueva Clase
                <?php endif; ?>
            </div>
            <?php if ($editando): ?>
                <a href="clases.php" class="btn-filter" style="padding: 6px 12px; height: auto; font-size: 0.8rem;">
                    <i class="fa-solid fa-xmark"></i> Cancelar
                </a>
            <?php endif; ?>
        </div>

        <form action="clases.php" method="POST">
            <input type="hidden" name="accion" value="<?php echo $editando ? 'actualizar' : 'guardar'; ?>">
            <?php if ($editando): ?>
                <input type="hidden" name="clase_id" value="<?php echo (int)$editando['id']; ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="nombre_materia">MATERIA / ASIGNATURA *</label>
                <input type="text" id="nombre_materia" name="nombre_materia" class="form-select"
                       placeholder="Ej: Sistemas Inteligentes" required value="<?php echo $v('nombre_materia'); ?>">
            </div>

            <div class="form-group">
                <label for="docente_id">DOCENTE *</label>
                <select id="docente_id" name="docente_id" class="form-select" required>
                    <option value="">Selecciona un docente</option>
                    <?php foreach ($docentes as $doc): ?>
                        <option value="<?php echo htmlspecialchars($doc['matricula']); ?>"
                            <?php echo ($v('docente_id') === $doc['matricula']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($doc['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($docentes)): ?>
                    <p style="font-size: 0.75rem; color: var(--danger); margin-top: 4px;">
                        No hay docentes activos. Primero da de alta uno en Usuarios.
                    </p>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="dia_semana">DÍA DE LA SEMANA *</label>
                <select id="dia_semana" name="dia_semana" class="form-select" required>
                    <option value="">Selecciona el día</option>
                    <?php foreach ($diasSemana as $dia): ?>
                        <option value="<?php echo $dia; ?>" <?php echo ($v('dia_semana') === $dia) ? 'selected' : ''; ?>>
                            <?php echo ucfirst($dia === 'miercoles' ? 'miércoles' : ($dia === 'sabado' ? 'sábado' : $dia)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group">
                    <label for="hora_inicio">HORA INICIO *</label>
                    <input type="time" id="hora_inicio" name="hora_inicio" class="form-select" required
                           value="<?php echo $editando ? substr($editando['hora_inicio'], 0, 5) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="hora_fin">HORA FIN *</label>
                    <input type="time" id="hora_fin" name="hora_fin" class="form-select" required
                           value="<?php echo $editando ? substr($editando['hora_fin'], 0, 5) : ''; ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="aula">AULA / SALÓN</label>
                <input type="text" id="aula" name="aula" class="form-select" placeholder="Ej: A214" value="<?php echo $v('aula'); ?>">
            </div>

            <div style="border-top: 1px solid rgba(255, 255, 255, 0.05); padding-top: 15px; margin-top: 15px;">
                <h4 style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 12px; letter-spacing: 0.5px;">GRUPO OBJETIVO (ALUMNOS)</h4>

                <div class="form-group">
                    <label for="carrera">CARRERA</label>
                    <input type="text" id="carrera" name="carrera" class="form-select"
                           placeholder="Ej: Ingeniería en Tecnologías de la Información" value="<?php echo $v('carrera'); ?>">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label for="grupo">GRUPO</label>
                        <input type="text" id="grupo" name="grupo" class="form-select" placeholder="Ej: 1" value="<?php echo $v('grupo'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="semestre">SEMESTRE</label>
                        <input type="number" id="semestre" name="semestre" class="form-select" min="1" max="12"
                               placeholder="Ej: 9" value="<?php echo $v('semestre'); ?>">
                    </div>
                </div>

                <p style="font-size: 0.75rem; color: var(--text-muted);">
                    Estos datos sirven para inscribir alumnos en bloque desde
                    <a href="inscripciones.php" style="color: var(--primary, #818cf8);">Inscripciones</a>.
                </p>
            </div>

            <button type="submit" class="btn-primary" style="margin-top: 15px;">
                <i class="fa-solid fa-floppy-disk"></i>
                <span><?php echo $editando ? 'Guardar Cambios' : 'Guardar Clase'; ?></span>
            </button>
        </form>
    </div>

    <!-- PANEL DERECHO: TABLA DE HORARIOS -->
    <div class="panel-card">
        <div class="panel-header">
            <div class="panel-title">
                <i class="fa-solid fa-calendar-days icon-blue" style="margin-right: 8px;"></i>
                Horario General (<?php echo count($clases); ?> clases)
            </div>
        </div>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Día</th>
                        <th>Horario</th>
                        <th>Materia</th>
                        <th>Docente</th>
                        <th>Aula</th>
                        <th>Inscritos</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clases)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                                No se han programado clases. Usa el formulario de la izquierda.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clases as $c): ?>
                            <tr>
                                <td>
                                    <span class="badge badge-role-alumno" style="background: rgba(99, 102, 241, 0.15); color: #818cf8;">
                                        <?php echo ucfirst($c['dia_semana']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div><strong><?php echo date('h:i A', strtotime($c['hora_inicio'])); ?></strong></div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);">a <?php echo date('h:i A', strtotime($c['hora_fin'])); ?></div>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($c['nombre_materia']); ?></strong>
                                    <?php if ($c['carrera'] || $c['grupo'] || $c['semestre']): ?>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">
                                            <?php echo htmlspecialchars($c['carrera'] ?: 'Todas'); ?>
                                            <?php echo $c['semestre'] ? ' · ' . $c['semestre'] . '° Sem' : ''; ?>
                                            <?php echo $c['grupo'] ? ' · Grupo ' . htmlspecialchars($c['grupo']) : ''; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($c['docente_nombre']); ?></td>
                                <td><?php echo htmlspecialchars($c['aula'] ?: 'Sin asignar'); ?></td>
                                <td>
                                    <a href="inscripciones.php?clase_id=<?php echo $c['id']; ?>"
                                       class="badge badge-<?php echo $c['inscritos'] > 0 ? 'active' : 'inactive'; ?>"
                                       title="Administrar inscripciones">
                                        <?php echo (int)$c['inscritos']; ?> alumnos
                                    </a>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 6px;">
                                        <a href="clases.php?editar=<?php echo $c['id']; ?>" class="btn-action" title="Editar clase">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>
                                        <a href="clases.php?eliminar=<?php echo $c['id']; ?>" class="btn-action btn-delete"
                                           onclick="return confirm('Se eliminará &quot;<?php echo htmlspecialchars($c['nombre_materia'], ENT_QUOTES); ?>&quot; del horario y sus inscripciones. ¿Continuar?')"
                                           title="Eliminar clase">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    </div>
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
