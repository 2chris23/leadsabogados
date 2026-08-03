jQuery(document).ready(function($) {
    $('.crm-abogados-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $msg = $form.siblings('.crm-abogados-msg');
        var $btn = $form.find('button[type="submit"]');
        var url = $form.attr('action');
        
        var formData = new FormData(this);
        
        $btn.prop('disabled', true).text('Enviando...');
        $msg.hide().removeClass('success error');
        
        $.ajax({
            url: url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                // PHP endpoint returns JSON
                if (typeof response === 'string') {
                    try { response = JSON.parse(response); } catch(e) {}
                }
                
                if (response.success) {
                    $msg.css({background: '#dcfce7', color: '#166534', border: '1px solid #bbf7d0'})
                        .text(response.message || 'Solicitud enviada correctamente.')
                        .addClass('success').show();
                    $form[0].reset();
                } else {
                    $msg.css({background: '#fee2e2', color: '#991b1b', border: '1px solid #fecaca'})
                        .text(response.error || 'Ocurrió un error al enviar el formulario.')
                        .addClass('error').show();
                }
            },
            error: function(xhr) {
                var errorMsg = 'Error de conexión. Intente de nuevo.';
                if (xhr.responseJSON && xhr.responseJSON.error) {
                    errorMsg = xhr.responseJSON.error;
                }
                $msg.css({background: '#fee2e2', color: '#991b1b', border: '1px solid #fecaca'})
                    .text(errorMsg)
                    .addClass('error').show();
            },
            complete: function() {
                $btn.prop('disabled', false).text('Enviar Consulta');
            }
        });
    });
});
