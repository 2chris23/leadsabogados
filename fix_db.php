<?php
define('CRM_ROOT', __DIR__);
require 'c:/xampp/htdocs/abogados/portal/crm/includes/config.php';
require 'c:/xampp/htdocs/abogados/portal/crm/includes/Database.php';
$db = Database::getInstance();
$db->query('UPDATE clientes c JOIN solicitudes s ON c.solicitud_id = s.id SET c.dni_nif = s.dni_nif WHERE c.dni_nif IS NULL AND s.dni_nif IS NOT NULL');
$db->query('UPDATE clientes c JOIN solicitudes s ON c.solicitud_id = s.id SET c.fecha_nacimiento = s.fecha_nacimiento WHERE c.fecha_nacimiento IS NULL AND s.fecha_nacimiento IS NOT NULL');

// Update portal_cuentas as well, just in case
$db->query('UPDATE portal_cuentas pc JOIN clientes c ON pc.cliente_id = c.id SET pc.dni_nif = c.dni_nif WHERE pc.dni_nif IS NULL AND c.dni_nif IS NOT NULL');
$db->query('UPDATE portal_cuentas pc JOIN clientes c ON pc.cliente_id = c.id SET pc.fecha_nacimiento = c.fecha_nacimiento WHERE pc.fecha_nacimiento IS NULL AND c.fecha_nacimiento IS NOT NULL');

echo "Done.";
