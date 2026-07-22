<?php
/**
 * CRM Abogados - Página de Acceso Denegado
 */
$tituloPagina = 'Acceso Denegado';
include CRM_ROOT . '/templates/layout/header.php';
?>
<div class="d-flex flex-column align-items-center justify-content-center py-80">
    <img src="<?php echo APP_URL; ?>/assets/images/logo.png" alt="Acceso Denegado" class="mb-24" style="max-width:300px;">
    <h3 class="fw-semibold mb-8">Acceso Denegado</h3>
    <p class="text-secondary-light text-lg mb-24">No tiene permisos para acceder a esta sección.</p>
    <a href="<?php echo APP_URL; ?>/index.php" class="btn btn-primary radius-8 px-20 py-11">
        <iconify-icon icon="solar:home-smile-angle-outline" class="me-2"></iconify-icon> Volver al Inicio
    </a>
</div>
<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
