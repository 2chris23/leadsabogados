<?php
/**
 * CRM Abogados - Listado de Pagos
 */
RoleGuard::requireRole('admin');
$tituloPagina = 'Pagos';
include CRM_ROOT . '/templates/layout/header.php';
$db = Database::getInstance();

$where = '1=1';
$params = [];
if ($auth->esAbogado()) {
    $where .= ' AND c.abogado_id = ?';
    $params[] = $usuario['id'];
}

$pagos = $db->fetchAll(
    "SELECT p.*, c.titulo as caso_titulo, c.referencia, cl.nombre as cliente_nombre, cl.apellidos as cliente_apellidos,
            u.nombre as registrado_nombre
     FROM pagos p
     JOIN casos c ON p.caso_id = c.id
     JOIN clientes cl ON c.cliente_id = cl.id
     LEFT JOIN usuarios_internos u ON p.registrado_por = u.id
     WHERE $where ORDER BY p.fecha_pago DESC, p.created_at DESC", $params
);
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Pagos</h6>
</div>

<div class="card radius-8 border">
    <div class="card-body p-24">
        <div class="table-responsive scroll-sm">
            <table class="table bordered-table sm-table mb-0" id="tablaPagos">
                <thead>
                    <tr><th>Fecha</th><th>Cliente</th><th>Caso</th><th>Concepto</th><th>Método</th><th>Cantidad</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($pagos as $p): 
                        $esEgreso = ($p['tipo_pago'] === 'pago_abogado');
                        $colorCls = $esEgreso ? 'text-danger-main' : 'text-success-main';
                        $signo = $esEgreso ? '-€' : '+€';
                        $rowBg = $esEgreso ? 'style="background-color: #fffafb;"' : '';
                    ?>
                    <tr <?php echo $rowBg; ?>>
                        <td class="text-sm">
                            <?php echo date('d/m/Y', strtotime($p['fecha_pago'])); ?>
                            <div class="text-xs text-secondary-light mt-1"><?php echo date('H:i', strtotime($p['created_at'])); ?></div>
                        </td>
                        <td><?php echo e($p['cliente_nombre'].' '.$p['cliente_apellidos']); ?></td>
                        <td><a href="<?php echo APP_URL; ?>/index.php?page=casos/ver&id=<?php echo $p['caso_id']; ?>" class="text-primary-600"><?php echo e($p['referencia']); ?></a></td>
                        <td>
                            <?php echo e($p['concepto']); ?>
                            <?php if($esEgreso): ?><span class="badge bg-danger-50 text-danger-main text-xs ms-2">Honorarios Abog.</span><?php endif; ?>
                        </td>
                        <td class="text-sm"><?php echo e(ucfirst($p['metodo_pago'])); ?></td>
                        <td class="fw-semibold <?php echo $colorCls; ?>"><?php echo $signo . number_format($p['cantidad'],2,',','.'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
include CRM_ROOT . '/templates/layout/footer.php';



