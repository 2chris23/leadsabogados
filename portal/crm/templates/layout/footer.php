<?php
/**
 * CRM Abogados - Footer Template
 * Scripts JS del template WowDash
 */
if (!defined('CRM_ROOT')) die('Acceso prohibido');
?>
    </div> <!-- /.dashboard-main-body -->
</main> <!-- /.dashboard-main -->

<!-- jQuery -->
<script src="<?php echo APP_URL; ?>/assets/js/lib/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5 -->
<script src="<?php echo APP_URL; ?>/assets/js/lib/bootstrap.bundle.min.js"></script>
<!-- ApexCharts -->
<script src="<?php echo APP_URL; ?>/assets/js/lib/apexcharts.min.js"></script>
<!-- DataTables -->
<script src="<?php echo APP_URL; ?>/assets/js/lib/dataTables.min.js"></script>
<!-- Iconify -->
<script src="<?php echo APP_URL; ?>/assets/js/lib/iconify-icon.min.js"></script>
<!-- jQuery UI -->
<script src="<?php echo APP_URL; ?>/assets/js/lib/jquery-ui.min.js"></script>
<!-- WowDash App JS -->
<script src="<?php echo APP_URL; ?>/assets/js/app.js"></script>

<!-- Configuración global de DataTables (responsiva, sin CDN) -->
<script>
var dtEsLang = {
    "emptyTable": "No hay datos disponibles",
    "info": "Mostrando _START_ a _END_ de _TOTAL_",
    "infoEmpty": "Mostrando 0 a 0 de 0",
    "infoFiltered": "(filtrado de _MAX_ registros)",
    "thousands": ".",
    "lengthMenu": "Mostrar _MENU_",
    "loadingRecords": "Cargando...",
    "processing": "Procesando...",
    "search": "Buscar:",
    "zeroRecords": "Sin resultados",
    "paginate": { "first": "«", "last": "»", "next": "›", "previous": "‹" },
    "aria": { "sortAscending": ": ascendente", "sortDescending": ": descendente" }
};

// Inicializar todas las tablas con clase .dt-table automáticamente
$(function() {
    if ($.fn.DataTable) {
        $.extend(true, $.fn.dataTable.defaults, {
            language: dtEsLang,
            pageLength: 15,
            responsive: false,
            scrollX: false,
            autoWidth: false,
            info: true, // Habilitar info por defecto
            dom: '<"d-flex flex-wrap align-items-center justify-content-between gap-3 mb-16"lf>t<"d-flex flex-wrap align-items-center justify-content-between gap-3 mt-16"ip>'
        });

        // Inicializar tablas que no tengan dtNoAuto
        $('table[id^="tabla"]:not(.dataTable):not(.dtNoAuto)').each(function() {
            var $t = $(this);
            if (!$.fn.DataTable.isDataTable($t)) {
                $t.DataTable({ order: [] });
            }
        });
    }
});
</script>

<?php if (isset($scriptsExtra)): ?>
    <?php echo $scriptsExtra; ?>
<?php endif; ?>

<!-- PWA Service Worker -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('<?php echo APP_URL; ?>/sw.php')
            .then(function(reg) { console.log('SW registrado:', reg.scope); })
            .catch(function(err) { console.log('SW error:', err); });
    });
}
</script>

<!-- ================================================ -->
<!--  GLOBAL CUSTOM CONFIRM MODAL (reemplaza confirm) -->
<!-- ================================================ -->
<div class="modal fade" id="crmConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
    <div class="modal-content" style="border-radius:20px;border:none;box-shadow:0 24px 64px rgba(0,0,0,.18)">
      <div class="modal-body text-center" style="padding:36px 32px 24px">
        <div id="crmConfirmIcon" style="width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;background:#fef2f2">
          <iconify-icon icon="solar:danger-triangle-bold" style="font-size:28px;color:#dc2626"></iconify-icon>
        </div>
        <p id="crmConfirmMsg" class="fw-semibold mb-0" style="font-size:1rem;color:#1e293b;line-height:1.5"></p>
      </div>
      <div class="modal-footer justify-content-center gap-2 pb-28" style="border:none;padding-bottom:28px">
        <button type="button" class="btn btn-outline-secondary radius-8" style="min-width:100px" data-bs-dismiss="modal" id="crmConfirmCancel">Cancelar</button>
        <button type="button" class="btn btn-danger radius-8" style="min-width:100px" id="crmConfirmOk">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<script>
// Helper global: confirmAction(msg, onConfirm, {okText, okClass, icon, iconColor, iconBg})
function confirmAction(msg, onConfirm, opts) {
    opts = opts || {};
    var modal = document.getElementById('crmConfirmModal');
    document.getElementById('crmConfirmMsg').innerHTML = msg;

    var iconEl = document.getElementById('crmConfirmIcon');
    var iconBg   = opts.iconBg    || '#fef2f2';
    var iconName = opts.icon      || 'solar:danger-triangle-bold';
    var iconClr  = opts.iconColor || '#dc2626';
    iconEl.style.background = iconBg;
    iconEl.innerHTML = '<iconify-icon icon="'+iconName+'" style="font-size:28px;color:'+iconClr+'"></iconify-icon>';

    var okBtn = document.getElementById('crmConfirmOk');
    okBtn.textContent  = opts.okText  || 'Confirmar';
    okBtn.className    = 'btn radius-8 ' + (opts.okClass || 'btn-danger');
    okBtn.style.minWidth = '100px';

    var bsModal = bootstrap.Modal.getOrCreateInstance(modal);
    bsModal.show();

    // Clone to remove old listeners
    var newOk = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOk, okBtn);
    newOk.addEventListener('click', function() {
        bsModal.hide();
        if (typeof onConfirm === 'function') onConfirm();
    });
}

// Auto-intercept: forms & links with data-confirm attribute
document.addEventListener('DOMContentLoaded', function() {
    // Forms
    document.body.addEventListener('submit', function(e) {
        var form = e.target;
        var btn  = document.activeElement;
        var msg  = (btn && btn.dataset && btn.dataset.confirm) ? btn.dataset.confirm
                 : (form.dataset.confirm || null);
        if (!msg) return;
        e.preventDefault();
        confirmAction(msg, function() { form.submit(); });
    }, true);

    // Links
    document.body.addEventListener('click', function(e) {
        var el = e.target.closest('a[data-confirm]');
        if (!el) return;
        e.preventDefault();
        var href = el.href;
        confirmAction(el.dataset.confirm, function() { window.location.href = href; });
    }, true);
});
</script>

</body>
</html>

