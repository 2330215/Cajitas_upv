<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar si el usuario está autenticado
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['usuario'];
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Asistencia ESP32</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome para Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- CSS del Dashboard -->
    <link rel="stylesheet" href="public/dashboard.css">
</head>
<body>
    <!-- Sidebar de Navegación -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon">
                <i class="fa-solid fa-clock"></i>
            </div>
            <div class="brand-name">IoT Attendance</div>
        </div>

        <div class="sidebar-profile">
            <div class="profile-avatar">
                <?php echo strtoupper(substr($user['nombre'], 0, 1)); ?>
            </div>
            <div class="profile-info">
                <div class="profile-name" title="<?php echo htmlspecialchars($user['nombre']); ?>">
                    <?php echo htmlspecialchars($user['nombre']); ?>
                </div>
                <div class="profile-role">
                    <?php if ($user['rol'] === 'alumno'): ?>
                        <span class="badge badge-role-alumno" style="padding: 2px 6px; font-size: 0.65rem;">Alumno</span>
                    <?php elseif ($user['rol'] === 'docente'): ?>
                        <span class="badge badge-role-docente" style="padding: 2px 6px; font-size: 0.65rem;">Docente</span>
                    <?php else: ?>
                        <span class="badge badge-role-admin" style="padding: 2px 6px; font-size: 0.65rem;">Admin</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <ul class="sidebar-menu">
            <li class="menu-item <?php echo $current_page === 'index.php' ? 'active' : ''; ?>">
                <a href="index.php">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            
            <li class="menu-item <?php echo $current_page === 'historial.php' ? 'active' : ''; ?>">
                <a href="historial.php">
                    <i class="fa-solid fa-list-check"></i>
                    <span>Historial</span>
                </a>
            </li>

            <?php if ($user['rol'] === 'alumno'): ?>
                <li class="menu-item <?php echo $current_page === 'mis_clases.php' ? 'active' : ''; ?>">
                    <a href="mis_clases.php">
                        <i class="fa-solid fa-graduation-cap"></i>
                        <span>Mis Clases</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($user['rol'] === 'docente'): ?>
                <li class="menu-item <?php echo $current_page === 'pase_lista.php' ? 'active' : ''; ?>">
                    <a href="pase_lista.php">
                        <i class="fa-solid fa-clipboard-user"></i>
                        <span>Pase de Lista</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($user['rol'] === 'administrativo'): ?>
                <li class="menu-item <?php echo $current_page === 'clases.php' ? 'active' : ''; ?>">
                    <a href="clases.php">
                        <i class="fa-solid fa-calendar-days"></i>
                        <span>Horarios / Clases</span>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_page === 'usuarios.php' ? 'active' : ''; ?>">
                    <a href="usuarios.php">
                        <i class="fa-solid fa-users"></i>
                        <span>Usuarios</span>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_page === 'inscripciones.php' ? 'active' : ''; ?>">
                    <a href="inscripciones.php">
                        <i class="fa-solid fa-user-plus"></i>
                        <span>Inscripciones</span>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_page === 'pase_lista.php' ? 'active' : ''; ?>">
                    <a href="pase_lista.php">
                        <i class="fa-solid fa-clipboard-user"></i>
                        <span>Pase de Lista</span>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_page === 'dispositivos.php' ? 'active' : ''; ?>">
                    <a href="dispositivos.php">
                        <i class="fa-solid fa-microchip"></i>
                        <span>Cajas ESP32</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>

        <div class="sidebar-footer">
            <a href="logout.php" class="btn-primary" style="background: var(--danger); box-shadow: 0 4px 10px var(--danger-glow); padding: 10px;">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Cerrar Sesión</span>
            </a>
        </div>
    </aside>

    <!-- Wrapper para Contenido Principal -->
    <div class="main-wrapper">
