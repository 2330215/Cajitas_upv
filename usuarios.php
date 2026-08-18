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

/**
 * Verifica que un campo único no lo esté usando otro usuario.
 * Devuelve el mensaje de error, o '' si está libre.
 */
function campoOcupado($pdo, $campo, $valor, $matriculaActual, $etiqueta) {
    if (empty($valor)) {
        return '';
    }
    $stmt = $pdo->prepare("SELECT nombre FROM usuarios WHERE $campo = :valor AND matricula != :matricula LIMIT 1");
    $stmt->execute(['valor' => $valor, 'matricula' => $matriculaActual]);
    $duenio = $stmt->fetchColumn();

    return $duenio ? "El $etiqueta \"$valor\" ya lo está usando $duenio." : '';
}

/**
 * Lee y normaliza los campos del formulario.
 */
function leerFormularioUsuario() {
    $limpiar = function ($valor) {
        $valor = trim((string)$valor);
        return $valor === '' ? null : $valor;
    };

    return [
        'matricula'    => strtoupper(trim($_POST['matricula'] ?? '')),
        'nombre'       => trim($_POST['nombre'] ?? ''),
        'rol'          => $_POST['rol'] ?? 'alumno',
        'estado'       => $_POST['estado'] ?? 'activo',
        'id_telegram'  => $limpiar($_POST['id_telegram'] ?? ''),
        'tarjeta_rfid' => $limpiar(strtoupper($_POST['tarjeta_rfid'] ?? '')),
        'contrasena'   => trim($_POST['contrasena'] ?? ''),
        'correo'       => $limpiar($_POST['correo'] ?? ''),
        'carrera'      => $limpiar($_POST['carrera'] ?? ''),
        'grupo'        => $limpiar($_POST['grupo'] ?? ''),
        'semestre'     => empty($_POST['semestre']) ? null : (int)$_POST['semestre'],
    ];
}

// -------------------------------------------------------------------------
// 1. PROCESAR ACCIONES
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $d = leerFormularioUsuario();

    // ---------------------------------------------------------------------
    // ALTA DE USUARIO
    // ---------------------------------------------------------------------
    if ($accion === 'guardar') {
        if ($d['matricula'] === '' || $d['nombre'] === '' || $d['contrasena'] === '') {
            $error = 'Faltan datos: la matrícula, el nombre y la contraseña son obligatorios.';
        } elseif (strlen($d['contrasena']) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } else {
            try {
                $check = $pdo->prepare("SELECT nombre FROM usuarios WHERE matricula = :m");
                $check->execute(['m' => $d['matricula']]);
                $existente = $check->fetchColumn();

                $conflicto = '';
                if ($existente) {
                    $conflicto = "La matrícula {$d['matricula']} ya está registrada a nombre de $existente.";
                }
                foreach ([['correo', 'correo'], ['tarjeta_rfid', 'UID de tarjeta'], ['id_telegram', 'chat ID de Telegram']] as [$campo, $etiqueta]) {
                    if ($conflicto === '') {
                        $conflicto = campoOcupado($pdo, $campo, $d[$campo], $d['matricula'], $etiqueta);
                    }
                }

                if ($conflicto !== '') {
                    $error = $conflicto;
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO usuarios (matricula, nombre, rol, estado, id_telegram, tarjeta_rfid, contrasena, correo, carrera, grupo, semestre)
                        VALUES (:matricula, :nombre, :rol, :estado, :id_telegram, :tarjeta_rfid, :contrasena, :correo, :carrera, :grupo, :semestre)
                    ");
                    $stmt->execute([
                        'matricula' => $d['matricula'],
                        'nombre' => $d['nombre'],
                        'rol' => $d['rol'],
                        'estado' => $d['estado'],
                        'id_telegram' => $d['id_telegram'],
                        'tarjeta_rfid' => $d['tarjeta_rfid'],
                        'contrasena' => password_hash($d['contrasena'], PASSWORD_DEFAULT),
                        'correo' => $d['correo'],
                        'carrera' => $d['carrera'],
                        'grupo' => $d['grupo'],
                        'semestre' => $d['semestre'],
                    ]);
                    $success = "Usuario {$d['nombre']} registrado correctamente.";
                }
            } catch (PDOException $e) {
                $error = mensajeErrorBD($e, 'registrar el usuario', $user['rol'] ?? null, 'usuarios.php');
            }
        }
    }

    // ---------------------------------------------------------------------
    // EDICIÓN DE USUARIO
    // ---------------------------------------------------------------------
    if ($accion === 'actualizar') {
        $matriculaOriginal = strtoupper(trim($_POST['matricula_original'] ?? ''));

        if ($matriculaOriginal === '' || $d['nombre'] === '') {
            $error = 'Faltan datos para actualizar: el nombre es obligatorio.';
        } elseif ($d['contrasena'] !== '' && strlen($d['contrasena']) < 6) {
            $error = 'La contraseña nueva debe tener al menos 6 caracteres.';
        } else {
            try {
                $conflicto = '';
                foreach ([['correo', 'correo'], ['tarjeta_rfid', 'UID de tarjeta'], ['id_telegram', 'chat ID de Telegram']] as [$campo, $etiqueta]) {
                    if ($conflicto === '') {
                        $conflicto = campoOcupado($pdo, $campo, $d[$campo], $matriculaOriginal, $etiqueta);
                    }
                }

                // No permitir que el admin en sesión se quite a sí mismo el acceso
                if ($conflicto === '' && $matriculaOriginal === $user['matricula']
                    && ($d['rol'] !== 'administrativo' || $d['estado'] !== 'activo')) {
                    $conflicto = 'No puedes quitarte a ti mismo el rol de administrativo ni desactivarte.';
                }

                if ($conflicto !== '') {
                    $error = $conflicto;
                } else {
                    $campos = "nombre = :nombre, rol = :rol, estado = :estado, id_telegram = :id_telegram,
                               tarjeta_rfid = :tarjeta_rfid, correo = :correo, carrera = :carrera,
                               grupo = :grupo, semestre = :semestre";
                    $params = [
                        'nombre' => $d['nombre'],
                        'rol' => $d['rol'],
                        'estado' => $d['estado'],
                        'id_telegram' => $d['id_telegram'],
                        'tarjeta_rfid' => $d['tarjeta_rfid'],
                        'correo' => $d['correo'],
                        'carrera' => $d['carrera'],
                        'grupo' => $d['grupo'],
                        'semestre' => $d['semestre'],
                        'matricula' => $matriculaOriginal,
                    ];

                    if ($d['contrasena'] !== '') {
                        $campos .= ", contrasena = :contrasena";
                        $params['contrasena'] = password_hash($d['contrasena'], PASSWORD_DEFAULT);
                    }

                    $stmt = $pdo->prepare("UPDATE usuarios SET $campos WHERE matricula = :matricula");
                    $stmt->execute($params);

                    // Refrescar la sesión si el admin se editó a sí mismo
                    if ($matriculaOriginal === $user['matricula']) {
                        $_SESSION['usuario']['nombre'] = $d['nombre'];
                        $user = $_SESSION['usuario'];
                    }

                    $success = "Datos de {$d['nombre']} actualizados correctamente.";
                }
            } catch (PDOException $e) {
                $error = mensajeErrorBD($e, 'guardar los cambios', $user['rol'] ?? null, 'usuarios.php');
            }
        }
    }
}

// -------------------------------------------------------------------------
// ELIMINAR USUARIO
// -------------------------------------------------------------------------
if (isset($_GET['eliminar'])) {
    $matriculaEliminar = trim($_GET['eliminar']);

    if ($matriculaEliminar === $user['matricula']) {
        $error = 'No puedes eliminar tu propio usuario mientras tienes la sesión abierta.';
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE matricula = :m");
            $stmt->execute(['m' => $matriculaEliminar]);
            $success = $stmt->rowCount() > 0
                ? 'Usuario eliminado junto con su historial de asistencias.'
                : 'Ese usuario ya no existe en el sistema.';
        } catch (PDOException $e) {
            $error = mensajeErrorBD($e, 'eliminar el usuario', $user['rol'] ?? null, 'usuarios.php');
        }
    }
}

// -------------------------------------------------------------------------
// CARGAR USUARIO A EDITAR
// -------------------------------------------------------------------------
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE matricula = :m LIMIT 1");
    $stmt->execute(['m' => trim($_GET['editar'])]);
    $editando = $stmt->fetch() ?: null;

    if (!$editando) {
        $error = 'No se encontró el usuario que quieres editar.';
    }
}

// -------------------------------------------------------------------------
// LISTADO
// -------------------------------------------------------------------------
try {
    $usuarios = $pdo->query("SELECT * FROM usuarios ORDER BY rol ASC, nombre ASC")->fetchAll();
} catch (PDOException $e) {
    $error = mensajeErrorBD($e, 'cargar la lista de usuarios', $user['rol'] ?? null, 'usuarios.php');
    $usuarios = [];
}

// Cajas disponibles para el alta de tarjetas
try {
    $dispositivos = $pdo->query("SELECT device_id, nombre, aula FROM dispositivos WHERE estado = 'activo' ORDER BY nombre ASC")->fetchAll();
} catch (PDOException $e) {
    $dispositivos = [];
}

$v = function ($campo, $defecto = '') use ($editando) {
    return htmlspecialchars($editando[$campo] ?? $defecto);
};

require_once __DIR__ . '/header.php';
?>

<div class="top-bar">
    <div class="page-title">
        <h2>Gestión de Usuarios</h2>
        <p>Da de alta, edita o elimina cuentas. Desde aquí también se registran las tarjetas RFID con el lector de la caja.</p>
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
    <!-- PANEL IZQUIERDO: FORMULARIO (ALTA O EDICIÓN) -->
    <div class="panel-card">
        <div class="panel-header">
            <div class="panel-title">
                <?php if ($editando): ?>
                    <i class="fa-solid fa-user-pen icon-orange" style="margin-right: 8px;"></i>
                    Editar Usuario
                <?php else: ?>
                    <i class="fa-solid fa-user-plus icon-purple" style="margin-right: 8px;"></i>
                    Registrar Usuario
                <?php endif; ?>
            </div>
            <?php if ($editando): ?>
                <a href="usuarios.php" class="btn-filter" style="padding: 6px 12px; height: auto; font-size: 0.8rem;">
                    <i class="fa-solid fa-xmark"></i> Cancelar
                </a>
            <?php endif; ?>
        </div>

        <form action="usuarios.php" method="POST">
            <input type="hidden" name="accion" value="<?php echo $editando ? 'actualizar' : 'guardar'; ?>">
            <?php if ($editando): ?>
                <input type="hidden" name="matricula_original" value="<?php echo $v('matricula'); ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="matricula">MATRÍCULA / ID *</label>
                <input type="text" id="matricula" name="matricula" class="form-select"
                       placeholder="Ej: 2330018" required autocomplete="off"
                       value="<?php echo $v('matricula'); ?>"
                       <?php echo $editando ? 'readonly style="opacity:.6; cursor:not-allowed;"' : ''; ?>>
                <?php if ($editando): ?>
                    <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">La matrícula no se puede cambiar.</p>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="nombre">NOMBRE COMPLETO *</label>
                <input type="text" id="nombre" name="nombre" class="form-select" placeholder="Ej: Carlos Sánchez"
                       required autocomplete="off" value="<?php echo $v('nombre'); ?>">
            </div>

            <div class="form-group">
                <label for="contrasena">CONTRASEÑA <?php echo $editando ? '(dejar vacío para no cambiarla)' : '*'; ?></label>
                <input type="password" id="contrasena" name="contrasena" class="form-select"
                       placeholder="Mínimo 6 caracteres" <?php echo $editando ? '' : 'required'; ?>>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group">
                    <label for="rol">ROL *</label>
                    <select id="rol" name="rol" class="form-select" onchange="toggleStudentFields(this.value)" required>
                        <?php foreach (['alumno' => 'Alumno', 'docente' => 'Docente', 'administrativo' => 'Administrativo'] as $val => $txt): ?>
                            <option value="<?php echo $val; ?>" <?php echo ($v('rol', 'alumno') === $val) ? 'selected' : ''; ?>><?php echo $txt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="estado">ESTADO *</label>
                    <select id="estado" name="estado" class="form-select" required>
                        <option value="activo" <?php echo ($v('estado', 'activo') === 'activo') ? 'selected' : ''; ?>>Activo</option>
                        <option value="inactivo" <?php echo ($v('estado') === 'inactivo') ? 'selected' : ''; ?>>Inactivo</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="correo">CORREO ELECTRÓNICO</label>
                <input type="email" id="correo" name="correo" class="form-select"
                       placeholder="correo@upv.edu.mx" value="<?php echo $v('correo'); ?>">
            </div>

            <div class="form-group">
                <label for="tarjeta_rfid">UID TARJETA RFID (HEX)</label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="tarjeta_rfid" name="tarjeta_rfid" class="form-select"
                           placeholder="Ej: B144B71D" autocomplete="off" style="flex: 1;"
                           value="<?php echo $v('tarjeta_rfid'); ?>">
                    <?php if ($editando): ?>
                        <button type="button" class="btn-filter" style="white-space: nowrap;"
                                onclick="abrirEnrolamiento('<?php echo $v('matricula'); ?>', '<?php echo $v('nombre'); ?>')">
                            <i class="fa-solid fa-wifi"></i> Leer
                        </button>
                    <?php endif; ?>
                </div>
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">
                    Puedes escribirlo a mano o usar el botón "Leer" para capturarlo con la caja.
                </p>
            </div>

            <div class="form-group">
                <label for="id_telegram">TELEGRAM CHAT ID</label>
                <input type="text" id="id_telegram" name="id_telegram" class="form-select"
                       placeholder="Ej: 987654321" autocomplete="off" value="<?php echo $v('id_telegram'); ?>">
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">
                    Sin Telegram, el usuario necesitará la firma del docente para registrar asistencia.
                </p>
            </div>

            <!-- CAMPOS EXCLUSIVOS PARA ALUMNOS -->
            <div id="campos_alumno" style="border-top: 1px solid rgba(255, 255, 255, 0.05); padding-top: 15px; margin-top: 15px;">
                <h4 style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 12px; letter-spacing: 0.5px;">DETALLES ACADÉMICOS (ALUMNO)</h4>

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
            </div>

            <button type="submit" class="btn-primary" style="margin-top: 15px;">
                <i class="fa-solid <?php echo $editando ? 'fa-floppy-disk' : 'fa-user-check'; ?>"></i>
                <span><?php echo $editando ? 'Guardar Cambios' : 'Registrar Usuario'; ?></span>
            </button>
        </form>
    </div>

    <!-- PANEL DERECHO: TABLA -->
    <div class="panel-card">
        <div class="panel-header">
            <div class="panel-title">
                <i class="fa-solid fa-users icon-blue" style="margin-right: 8px;"></i>
                Usuarios Registrados (<?php echo count($usuarios); ?>)
            </div>
        </div>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Usuario / ID</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Contacto / Dispositivos</th>
                        <th>Detalles Académicos</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                                No hay usuarios registrados en el sistema.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usuarios as $u): ?>
                            <tr>
                                <td>
                                    <div><strong><?php echo htmlspecialchars($u['nombre']); ?></strong></div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($u['matricula']); ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-role-<?php echo $u['rol']; ?>">
                                        <?php echo htmlspecialchars($u['rol']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo ($u['estado'] === 'activo') ? 'active' : 'inactive'; ?>">
                                        <?php echo htmlspecialchars($u['estado']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-size: 0.82rem;">
                                        <div><i class="fa-solid fa-envelope" style="width: 16px;"></i> <?php echo htmlspecialchars($u['correo'] ?: 'Sin correo'); ?></div>
                                        <div>
                                            <i class="fa-solid fa-id-card" style="width: 16px;"></i>
                                            <?php if (empty($u['tarjeta_rfid'])): ?>
                                                <span style="color: var(--text-muted);">Sin tarjeta</span>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($u['tarjeta_rfid']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <i class="fa-brands fa-telegram icon-blue" style="width: 16px;"></i>
                                            <?php if (empty($u['id_telegram'])): ?>
                                                <span style="color: var(--danger);" title="Sin Telegram: necesitará la firma del docente.">
                                                    <i class="fa-solid fa-triangle-exclamation"></i> Requiere firma
                                                </span>
                                            <?php else: ?>
                                                <span>ID: <?php echo htmlspecialchars($u['id_telegram']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($u['rol'] === 'alumno'): ?>
                                        <div style="font-size: 0.82rem;">
                                            <div><strong><?php echo htmlspecialchars($u['carrera'] ?: 'Sin carrera'); ?></strong></div>
                                            <div style="color: var(--text-muted);">
                                                <?php echo $u['semestre'] ? $u['semestre'] . '° Sem' : ''; ?>
                                                <?php echo $u['grupo'] ? ' - Grupo "' . htmlspecialchars($u['grupo']) . '"' : ''; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.82rem;">Personal</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 6px;">
                                        <a href="usuarios.php?editar=<?php echo urlencode($u['matricula']); ?>"
                                           class="btn-action" title="Editar usuario">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>
                                        <?php // La tarjeta se registra desde el formulario de edición
                                              // (botón "Leer"), para no duplicar el mismo flujo aquí. ?>
                                        <?php if ($u['matricula'] !== $user['matricula']): ?>
                                            <a href="usuarios.php?eliminar=<?php echo urlencode($u['matricula']); ?>"
                                               class="btn-action btn-delete"
                                               onclick="return confirm('Se eliminará a <?php echo htmlspecialchars($u['nombre'], ENT_QUOTES); ?> y todo su historial de asistencias. ¿Continuar?')"
                                               title="Eliminar usuario">
                                                <i class="fa-solid fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
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

<!-- ===================================================================== -->
<!-- MODAL: REGISTRO DE TARJETA RFID CON EL LECTOR DE LA CAJA              -->
<!-- ===================================================================== -->
<div id="modalRfid" class="modal-fondo">
    <div class="modal-caja">
        <h3 style="margin-bottom: 6px;">
            <i class="fa-solid fa-id-card icon-purple"></i> Registrar tarjeta
        </h3>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 16px;">
            Asignando tarjeta a <strong id="rfidNombre"></strong>
        </p>

        <?php if (count($dispositivos) > 1): ?>
            <div class="form-group">
                <label for="rfidDispositivo">CAJA QUE LEERÁ LA TARJETA</label>
                <select id="rfidDispositivo" class="form-select">
                    <option value="">Cualquier caja disponible</option>
                    <?php foreach ($dispositivos as $dev): ?>
                        <option value="<?php echo htmlspecialchars($dev['device_id']); ?>">
                            <?php echo htmlspecialchars($dev['nombre'] ?: $dev['device_id']); ?>
                            <?php echo $dev['aula'] ? ' — ' . htmlspecialchars($dev['aula']) : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <select id="rfidDispositivo" style="display: none;"><option value=""></option></select>
        <?php endif; ?>

        <div id="rfidEstado" class="rfid-estado">
            <i class="fa-solid fa-circle-nodes fa-spin"></i>
            <span id="rfidMensaje">Preparando...</span>
        </div>

        <div style="display: flex; gap: 8px; margin-top: 18px;">
            <button type="button" class="btn-primary" style="flex: 1;" onclick="iniciarLectura()">
                <i class="fa-solid fa-play"></i> <span id="rfidBotonTexto">Iniciar lectura</span>
            </button>
            <button type="button" class="btn-reset" onclick="cerrarEnrolamiento()">Cerrar</button>
        </div>
    </div>
</div>

<style>
.modal-fondo {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.65);
    backdrop-filter: blur(3px);
    z-index: 999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-fondo.abierto { display: flex; }
.modal-caja {
    background: var(--card-bg, #161a2b);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    padding: 24px;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
}
.rfid-estado {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px;
    border-radius: 10px;
    background: rgba(99, 102, 241, 0.1);
    border: 1px solid rgba(99, 102, 241, 0.25);
    font-size: 0.88rem;
    min-height: 54px;
}
.rfid-estado.ok { background: rgba(34, 197, 94, 0.12); border-color: rgba(34, 197, 94, 0.35); }
.rfid-estado.error { background: rgba(239, 68, 68, 0.12); border-color: rgba(239, 68, 68, 0.35); }
</style>

<script>
// -------------------------------------------------------------------------
// Mostrar u ocultar los campos exclusivos de alumnos
// -------------------------------------------------------------------------
function toggleStudentFields(role) {
    document.getElementById('campos_alumno').style.display = (role === 'alumno') ? 'block' : 'none';
}

document.addEventListener("DOMContentLoaded", function () {
    toggleStudentFields(document.getElementById('rol').value);
});

// -------------------------------------------------------------------------
// Registro de tarjeta RFID usando el lector de la caja ESP32
// -------------------------------------------------------------------------
let enrolId = null;
let enrolTimer = null;
let enrolMatricula = null;

function abrirEnrolamiento(matricula, nombre) {
    enrolMatricula = matricula;
    document.getElementById('rfidNombre').textContent = nombre + ' (' + matricula + ')';
    document.getElementById('modalRfid').classList.add('abierto');
    pintarEstado('Pulsa "Iniciar lectura" y acerca la tarjeta a la caja.', '');
    document.getElementById('rfidBotonTexto').textContent = 'Iniciar lectura';
}

function cerrarEnrolamiento() {
    detenerSondeo();
    if (enrolId) {
        enviar({ accion: 'cancel', enrol_id: enrolId });
        enrolId = null;
    }
    document.getElementById('modalRfid').classList.remove('abierto');
}

function pintarEstado(mensaje, clase) {
    const caja = document.getElementById('rfidEstado');
    caja.className = 'rfid-estado ' + clase;
    document.getElementById('rfidMensaje').textContent = mensaje;
}

function enviar(datos) {
    return fetch('api/rfid_enroll.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(datos)
    }).then(r => r.json());
}

function iniciarLectura() {
    detenerSondeo();
    pintarEstado('Acerca la tarjeta al lector de la caja...', '');
    document.getElementById('rfidBotonTexto').textContent = 'Reintentar';

    enviar({
        accion: 'start',
        matricula: enrolMatricula,
        device_id: document.getElementById('rfidDispositivo').value
    }).then(res => {
        if (!res.success) {
            pintarEstado(res.message || 'No se pudo iniciar la lectura.', 'error');
            return;
        }
        enrolId = res.enrol_id;
        enrolTimer = setInterval(consultarEstado, 1500);
    }).catch(() => pintarEstado('Se perdió la conexión con el servidor.', 'error'));
}

function consultarEstado() {
    if (!enrolId) return;

    enviar({ accion: 'status', enrol_id: enrolId }).then(res => {
        if (!res.success) {
            pintarEstado(res.message || 'Error al consultar.', 'error');
            detenerSondeo();
            return;
        }

        if (res.estado === 'completado') {
            detenerSondeo();
            enrolId = null;
            pintarEstado('¡Listo! Tarjeta ' + res.uid + ' asignada. Recargando...', 'ok');
            const campo = document.getElementById('tarjeta_rfid');
            if (campo && campo.form && campo.form.matricula_original
                && campo.form.matricula_original.value === res.matricula) {
                campo.value = res.uid;
            }
            setTimeout(() => location.reload(), 1200);
        } else if (res.estado === 'expirado') {
            detenerSondeo();
            enrolId = null;
            pintarEstado('Se agotó el tiempo. Pulsa "Reintentar".', 'error');
        } else if (res.estado === 'cancelado') {
            detenerSondeo();
            enrolId = null;
            pintarEstado('La solicitud fue cancelada.', 'error');
        } else {
            pintarEstado(res.message, '');
        }
    }).catch(() => {});
}

function detenerSondeo() {
    if (enrolTimer) {
        clearInterval(enrolTimer);
        enrolTimer = null;
    }
}
</script>

<?php
require_once __DIR__ . '/footer.php';
?>
