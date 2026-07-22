<?php
/**
 * CRM Abogados - Header Template
 * Adaptado del template WowDash Bootstrap 5
 */
if (!defined('CRM_ROOT')) die('Acceso prohibido');

$usuario = $auth->getUsuario();
$nombreDespacho = getConfig('nombre_despacho', 'CRM Abogados');

// Colores del tema desde la BD
$colorPrimario = getConfig('color_primario', '#487fff');
$logoVersion = @filemtime(CRM_ROOT . '/assets/images/logo.png') ?: time();
?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?php echo e($colorPrimario); ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="CRM Abogados">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="CRM Abogados">
    <link rel="manifest" href="<?php echo APP_URL; ?>/manifest.php">
    <link rel="apple-touch-icon" href="<?php echo APP_URL; ?>/assets/images/icon-192.png">
    <title><?php echo e($tituloPagina ?? 'Dashboard'); ?> — <?php echo e($nombreDespacho); ?></title>
    <link rel="icon" type="image/png" href="<?php echo APP_URL; ?>/assets/images/logo.png?v=<?php echo $logoVersion; ?>" sizes="16x16">
    
    <!-- RemixIcon -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/remixicon.css">
    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/lib/bootstrap.min.css">
    <!-- ApexCharts -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/lib/apexcharts.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/lib/dataTables.min.css">
    <!-- Date Picker -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/lib/flatpickr.min.css">
    <!-- File Upload -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/lib/file-upload.css">
    <!-- Main CSS -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    <!-- CRM Personalización y Responsividad -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/crm-custom.css">

    <!-- Colores personalizados del tema -->
    <style>
        :root {
            --primary-color: <?php echo e($colorPrimario); ?>;
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<?php include CRM_ROOT . '/templates/layout/sidebar.php'; ?>

<main class="dashboard-main">
    <!-- Navbar superior -->
    <div class="navbar-header">
        <div class="row align-items-center justify-content-between">
            <div class="col-auto">
                <div class="d-flex flex-wrap align-items-center gap-4">
                    <button type="button" class="sidebar-toggle">
                        <iconify-icon icon="heroicons:bars-3-solid" class="icon text-2xl non-active"></iconify-icon>
                        <iconify-icon icon="iconoir:arrow-right" class="icon text-2xl active"></iconify-icon>
                    </button>
                    <button type="button" class="sidebar-mobile-toggle">
                        <iconify-icon icon="heroicons:bars-3-solid" class="icon"></iconify-icon>
                    </button>
                </div>
            </div>
            <div class="col-auto">
                <div class="d-flex flex-wrap align-items-center gap-3">

                    <!-- Notificaciones -->
                    <?php
                    // Obtener notificaciones (Solicitudes pendientes)
                    $notifDb = Database::getInstance();
                    // Si el usuario es abogado, solo ver sus propias notificaciones o todas si es admin. Pero las nuevas solicitudes no tienen abogado asignado aún.
                    // Así que todos los admins/abogados ven las pendientes.
                    $notifSolicitudes = $notifDb->fetchAll("SELECT id, nombre, apellidos, tipo_problema, created_at FROM solicitudes WHERE estado = 'pendiente' ORDER BY created_at DESC LIMIT 5");
                    $numNotif = count($notifSolicitudes);
                    // Para contar el total exacto si hay más de 5
                    $totalNotif = $notifDb->fetchColumn("SELECT COUNT(*) FROM solicitudes WHERE estado = 'pendiente'");
                    ?>
                    <div class="dropdown">
                        <button class="has-indicator w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center position-relative"
                            type="button" data-bs-toggle="dropdown">
                            <iconify-icon icon="iconoir:bell" class="text-primary-light text-xl"></iconify-icon>
                            <?php if ($totalNotif > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem; transform: translate(-30%, 30%) !important;">
                                <?php echo $totalNotif > 9 ? '+9' : $totalNotif; ?>
                            </span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu to-top dropdown-menu-sm">
                            <div class="py-12 px-16 radius-8 bg-primary-50 mb-16 d-flex align-items-center justify-content-between gap-2">
                                <div>
                                    <h6 class="text-lg text-primary-light fw-semibold mb-0">Notificaciones</h6>
                                </div>
                                <?php if ($totalNotif > 0): ?>
                                <span class="badge bg-primary-600 px-8 py-2 radius-4 text-white text-xs"><?php echo $totalNotif; ?> nuevas</span>
                                <?php endif; ?>
                            </div>
                            <div class="max-h-400-px overflow-y-auto scroll-sm pe-4">
                                <?php if ($numNotif > 0): ?>
                                    <ul class="list-unstyled mb-0">
                                        <?php foreach ($notifSolicitudes as $ns): ?>
                                            <li class="mb-12">
                                                <a href="<?php echo APP_URL; ?>/index.php?page=solicitudes/ver&id=<?php echo $ns['id']; ?>" class="d-flex align-items-start gap-12 text-decoration-none px-12 py-8 radius-8 hover-bg-neutral-50">
                                                    <div class="w-32-px h-32-px bg-primary-50 text-primary-600 rounded-circle d-flex justify-content-center align-items-center flex-shrink-0">
                                                        <iconify-icon icon="solar:document-add-outline"></iconify-icon>
                                                    </div>
                                                    <div>
                                                        <h6 class="text-sm fw-semibold text-primary-light mb-4">Nueva Solicitud: <?php echo e($ns['tipo_problema']); ?></h6>
                                                        <p class="text-xs text-secondary-light mb-0">De <?php echo e($ns['nombre'] . ' ' . $ns['apellidos']); ?></p>
                                                        <span class="text-xs text-neutral-400 mt-4 d-block"><?php echo date('d/m/Y H:i', strtotime($ns['created_at'])); ?></span>
                                                    </div>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <div class="text-center mt-12 pt-12 border-top">
                                        <a href="<?php echo APP_URL; ?>/index.php?page=solicitudes" class="text-primary-600 text-sm fw-semibold hover-text-primary-700">Ver todas las solicitudes</a>
                                    </div>
                                <?php else: ?>
                                    <p class="text-center text-secondary-light py-3">Sin notificaciones nuevas</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Perfil de usuario -->
                    <div class="dropdown">
                        <button class="d-flex justify-content-center align-items-center rounded-circle" type="button" data-bs-toggle="dropdown">
                            <div class="w-40-px h-40-px rounded-circle bg-primary-600 d-flex justify-content-center align-items-center">
                                <span class="text-white fw-semibold text-lg">
                                    <?php echo strtoupper(substr($usuario['nombre'], 0, 1)); ?>
                                </span>
                            </div>
                        </button>
                        <div class="dropdown-menu to-top dropdown-menu-sm">
                            <div class="py-12 px-16 radius-8 bg-primary-50 mb-16 d-flex align-items-center justify-content-between gap-2">
                                <div>
                                    <h6 class="text-lg text-primary-light fw-semibold mb-0">
                                        <?php echo e($usuario['nombre'] . ' ' . $usuario['apellidos']); ?>
                                    </h6>
                                    <span class="text-secondary-light fw-medium text-sm">
                                        <?php echo e(ucfirst($usuario['rol'])); ?>
                                    </span>
                                </div>
                            </div>
                            <ul class="to-top-list">

                                <li>
                                    <a class="dropdown-item text-black px-0 py-8 hover-bg-transparent hover-text-danger d-flex align-items-center gap-3"
                                        href="<?php echo APP_URL; ?>/index.php?page=logout">
                                        <iconify-icon icon="lucide:power" class="icon text-xl"></iconify-icon> Cerrar Sesión
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-main-body">
        <?php
        // Mostrar mensajes flash
        $flash = getFlash();
        if ($flash): ?>
        <div class="alert alert-<?php echo $flash['tipo'] === 'exito' ? 'success' : ($flash['tipo'] === 'error' ? 'danger' : $flash['tipo']); ?> alert-dismissible fade show" role="alert">
            <?php echo e($flash['mensaje']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
