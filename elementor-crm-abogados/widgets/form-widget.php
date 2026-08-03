<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Elementor_CRM_Abogados_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'crm_abogados_form';
    }

    public function get_title() {
        return 'Formulario CRM Abogados';
    }

    public function get_icon() {
        return 'eicon-form-horizontal';
    }

    public function get_categories() {
        return [ 'general' ];
    }

    public function get_script_depends() {
        return [ 'crm-abogados-script' ];
    }

    public function get_style_depends() {
        return [ 'crm-abogados-style' ];
    }

    protected function register_controls() {
        $this->start_controls_section(
            'content_section',
            [
                'label' => 'Configuración del Formulario',
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'api_url',
            [
                'label' => 'URL del API (CRM)',
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => 'https://app.leadsabogados.com/api/recepcion_solicitud.php',
                'description' => 'La URL donde se enviarán los datos del formulario.',
            ]
        );

        $this->add_control(
            'form_title',
            [
                'label' => 'Título del Formulario',
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => 'Cuéntanos tu caso',
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'style_section',
            [
                'label' => 'Estilos',
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'btn_color',
            [
                'label' => 'Color del Botón',
                'type' => \Elementor\Controls_Manager::COLOR,
                'default' => '#2e6edd',
                'selectors' => [
                    '{{WRAPPER}} .crm-abogados-submit' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'text_color',
            [
                'label' => 'Color del Texto',
                'type' => \Elementor\Controls_Manager::COLOR,
                'default' => '#1a1a2e',
                'selectors' => [
                    '{{WRAPPER}} .crm-abogados-form-wrapper label' => 'color: {{VALUE}};',
                    '{{WRAPPER}} .crm-abogados-form-wrapper h3' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $api_url = $settings['api_url'];
        ?>
        <div class="crm-abogados-form-wrapper">
            <h3><?php echo esc_html($settings['form_title']); ?></h3>
            <div class="crm-abogados-msg" style="display:none; padding:10px; border-radius:5px; margin-bottom:15px;"></div>
            
            <form class="crm-abogados-form" action="<?php echo esc_url($api_url); ?>" method="POST" enctype="multipart/form-data">
                <div class="crm-form-group">
                    <label>Nombre *</label>
                    <input type="text" name="nombre" required>
                </div>
                <div class="crm-form-group">
                    <label>Apellidos</label>
                    <input type="text" name="apellidos">
                </div>
                <div class="crm-form-group">
                    <label>Correo Electrónico *</label>
                    <input type="email" name="email" required>
                </div>
                <div class="crm-form-group">
                    <label>Teléfono</label>
                    <input type="tel" name="telefono">
                </div>
                <div class="crm-form-group">
                    <label>Área Legal</label>
                    <select name="tipo_problema">
                        <option value="Civil">Derecho Civil</option>
                        <option value="Penal">Derecho Penal</option>
                        <option value="Laboral">Derecho Laboral</option>
                        <option value="Familia">Derecho de Familia</option>
                        <option value="General">Otro / Consulta General</option>
                    </select>
                </div>
                <div class="crm-form-group">
                    <label>Descripción de tu caso *</label>
                    <textarea name="descripcion" rows="4" required></textarea>
                </div>
                <div class="crm-form-group">
                    <label>Adjuntar Documentos (opcional)</label>
                    <input type="file" name="archivos[]" multiple>
                    <small style="color:#64748b; font-size:0.8em;">Máximo 10MB. Documentos y fotos.</small>
                </div>
                <button type="submit" class="crm-abogados-submit">Enviar Consulta</button>
            </form>
        </div>
        <?php
    }
}
