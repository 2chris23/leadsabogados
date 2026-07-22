<?php
/**
 * CRM Abogados - Sidebar Template
 * Menú lateral adaptado de WowDash, filtrado por rol
 */
if (!defined('CRM_ROOT')) die('Acceso prohibido');

$paginaActual = $page ?? '';
$menuItems = RoleGuard::getMenuItems();
$nombreDespacho = getConfig('nombre_despacho', 'CRM Abogados');

// Separar Administración del resto
$adminItem = null;
$menuPrincipal = [];
foreach ($menuItems as $item) {
    if (($item['titulo'] ?? '') === 'Administración') {
        $adminItem = $item;
    } else {
        $menuPrincipal[] = $item;
    }
}
?>

<div class="body-overlay"></div>

<aside class="sidebar">
    <button type="button" class="sidebar-close-btn">
        <iconify-icon icon="radix-icons:cross-2"></iconify-icon>
    </button>
    <div>
        <a href="<?php echo APP_URL; ?>/index.php?page=dashboard" class="sidebar-logo">
            <img src="<?php echo APP_URL; ?>/assets/images/logo.png?v=<?php echo $logoVersion; ?>" alt="<?php echo e($nombreDespacho); ?>" class="light-logo" style="max-height:40px;">
            <img src="<?php echo APP_URL; ?>/assets/images/logo.png?v=<?php echo $logoVersion; ?>" alt="<?php echo e($nombreDespacho); ?>" class="dark-logo" style="max-height:40px;">
            <img src="<?php echo APP_URL; ?>/assets/images/logo.png?v=<?php echo $logoVersion; ?>" alt="<?php echo e($nombreDespacho); ?>" class="logo-icon" style="max-height:36px;">
        </a>
    </div>
    <div class="sidebar-menu-area" style="display:flex;flex-direction:column;height:calc(100vh - 80px)">
        <ul class="sidebar-menu" id="sidebar-menu" style="flex:1">

            <li class="sidebar-menu-group-title">Principal</li>

            <?php foreach ($menuPrincipal as $item): ?>
                <?php if (isset($item['submenu'])): ?>
                    <!-- Menú con submenú -->
                    <li class="dropdown<?php
                        $subActivo = false;
                        foreach ($item['submenu'] as $sub) {
                            if ($paginaActual === $sub['url']) { $subActivo = true; break; }
                        }
                        echo $subActivo ? ' open' : '';
                    ?>">
                        <a href="javascript:void(0)">
                            <iconify-icon icon="<?php echo e($item['icono']); ?>" class="menu-icon"></iconify-icon>
                            <span><?php echo e($item['titulo']); ?></span>
                        </a>
                        <ul class="sidebar-submenu"<?php echo $subActivo ? ' style="display:block"' : ''; ?>>
                            <?php foreach ($item['submenu'] as $sub): ?>
                            <li>
                                <a href="<?php echo APP_URL; ?>/index.php?page=<?php echo e($sub['url']); ?>"
                                   class="<?php echo $paginaActual === $sub['url'] ? 'active-page' : ''; ?>">
                                    <i class="ri-circle-fill circle-icon <?php echo e($sub['icono_color'] ?? 'text-primary-600'); ?> w-auto"></i>
                                    <?php echo e($sub['titulo']); ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php else: ?>
                    <!-- Menú simple -->
                    <li>
                        <a href="<?php echo APP_URL; ?>/index.php?page=<?php echo e($item['url']); ?>"
                           class="<?php echo $paginaActual === $item['url'] ? 'active-page' : ''; ?>">
                            <iconify-icon icon="<?php echo e($item['icono']); ?>" class="menu-icon"></iconify-icon>
                            <span><?php echo e($item['titulo']); ?></span>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>

        </ul>

        <?php if ($adminItem): ?>
        <!-- Administración fija abajo -->
        <ul class="sidebar-menu" style="border-top:1px solid rgba(255,255,255,.08);padding-top:8px;margin-top:auto;flex-shrink:0">
            <?php
            $subActivo = false;
            foreach ($adminItem['submenu'] as $sub) {
                if ($paginaActual === $sub['url']) { $subActivo = true; break; }
            }
            ?>
            <li class="dropdown<?php echo $subActivo ? ' open' : ''; ?>">
                <a href="javascript:void(0)">
                    <iconify-icon icon="<?php echo e($adminItem['icono']); ?>" class="menu-icon"></iconify-icon>
                    <span><?php echo e($adminItem['titulo']); ?></span>
                </a>
                <ul class="sidebar-submenu"<?php echo $subActivo ? ' style="display:block"' : ''; ?>>
                    <?php foreach ($adminItem['submenu'] as $sub): ?>
                    <li>
                        <a href="<?php echo APP_URL; ?>/index.php?page=<?php echo e($sub['url']); ?>"
                           class="<?php echo $paginaActual === $sub['url'] ? 'active-page' : ''; ?>">
                            <i class="ri-circle-fill circle-icon <?php echo e($sub['icono_color'] ?? 'text-primary-600'); ?> w-auto"></i>
                            <?php echo e($sub['titulo']); ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        </ul>
        <?php endif; ?>
    </div>
</aside>
