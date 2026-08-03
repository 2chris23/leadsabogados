<?php
/**
 * Plugin Name: CRM Abogados - Elementor Widget
 * Description: Widget de Elementor para integrar el formulario de solicitud del CRM.
 * Version: 1.0.0
 * Author: LeadsAbogados
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

function register_crm_abogados_widget( $widgets_manager ) {
    require_once( __DIR__ . '/widgets/form-widget.php' );
    $widgets_manager->register( new \Elementor_CRM_Abogados_Widget() );
}
add_action( 'elementor/widgets/register', 'register_crm_abogados_widget' );

function crm_abogados_widget_scripts() {
    wp_register_script( 'crm-abogados-script', plugins_url( '/assets/js/form-script.js', __FILE__ ), [], '1.0.0', true );
    wp_register_style( 'crm-abogados-style', plugins_url( '/assets/css/form-style.css', __FILE__ ), [], '1.0.0' );
}
add_action( 'wp_enqueue_scripts', 'crm_abogados_widget_scripts' );
