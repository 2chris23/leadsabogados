<?php
/**
 * app.leadsabogados.com — CRM directo en la raíz
 * El CRM se carga aquí sin redirección
 */
define('CRM_AT_ROOT', true);
define('CRM_ROOT', __DIR__ . '/portal/crm');
require CRM_ROOT . '/index.php';
