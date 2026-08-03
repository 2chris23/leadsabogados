<?php
/**
 * CRM Abogados - Detalle de Caso
 */
$db = Database::getInstance();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/index.php?page=casos'); exit; }

RoleGuard::verificarAccesoCaso($id);


$caso = $db->fetchOne(
    "SELECT c.*, cl.nombre as cliente_nombre, cl.apellidos as cliente_apellidos, cl.email as cliente_email, cl.telefono as cliente_telefono, cl.dni_nif as cliente_dni, cl.direccion as cliente_direccion,
            u.nombre as abogado_nombre, u.apellidos as abogado_apellidos,
            u.tipo_pago_predeterminado as u_tipo_pago, u.tarifa_fija_default as u_tarifa_fija, u.tarifa_mensual_default as u_tarifa_mensual, u.tarifa_exito_default as u_tarifa_exito
     FROM casos c
     JOIN clientes cl ON c.cliente_id = cl.id
     LEFT JOIN usuarios_internos u ON c.abogado_id = u.id
     WHERE c.id = ?", [$id]
);
if (!$caso) { setFlash('error', 'Caso no encontrado'); header('Location: ' . APP_URL . '/index.php?page=casos'); exit; }

// Procesar cambio de estado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estado'])) {
    CSRF::verificarOAbortar();
    $nuevoEstado = $_POST['nuevo_estado'];
    $estadosValidos = ['en_estudio','en_proceso','en_tramitacion','pendiente_juicio','cerrado','archivado'];
    if (in_array($nuevoEstado, $estadosValidos)) {
        $datosUpdate = ['estado' => $nuevoEstado];
        if ($nuevoEstado === 'cerrado') $datosUpdate['fecha_cierre'] = date('Y-m-d');
        $db->update('casos', $datosUpdate, 'id = ?', [$id]);
        AuditLog::registrar('cambiar_estado', 'casos', $id, "Estado cambiado a: $nuevoEstado");
        setFlash('exito', 'Estado actualizado');
        header('Location: ' . APP_URL . '/index.php?page=casos/ver&id=' . $id); exit;
    }
}

// Procesar edición financiera (honorarios + plan de pago + calendario)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_financiero'])) {
    CSRF::verificarOAbortar();
    $honorarios     = (float)$_POST['honorarios_totales'];
    $tipoPago       = $_POST['tipo_pago_cliente'] ?? 'pago_unico';
    $frecuencia     = $_POST['frecuencia_pago'] ?? '';
    $numCuotas      = (int)($_POST['num_cuotas'] ?? 3);

    // Guardar en caso
    $db->update('casos', [
        'honorarios_totales'  => $honorarios,
        'tipo_pago_cliente'   => $tipoPago,
        'frecuencia_pago'     => $frecuencia,
        'plan_pago'           => $tipoPago === 'pago_unico' ? 'Pago Único' : "Cuotas: $numCuotas ($frecuencia)",
    ], 'id = ?', [$id]);

    // Eliminar pagos programados anteriores que no estén pagados
    $db->query("DELETE FROM pagos_programados WHERE caso_id = ? AND estado = 'pendiente'", [$id]);

    // Generar pagos programados
    if ($tipoPago === 'pago_unico') {
        $fecha = $_POST['fecha_pago_unico'] ?? date('Y-m-d');
        $db->insert('pagos_programados', [
            'caso_id'           => $id,
            'numero_cuota'      => 1,
            'fecha_vencimiento' => $fecha,
            'monto'             => $honorarios,
            'concepto'          => 'Pago único',
        ]);
    } elseif ($tipoPago === 'cuotas') {
        $fechaInicio = $_POST['fecha_inicio_cuotas'] ?? date('Y-m-d');
        $montoCuota  = round($honorarios / $numCuotas, 2);
        $diasIntervalo = match($frecuencia) {
            'quincenal' => 15,
            'semanal'   => 7,
            default     => 30, // mensual
        };

        for ($i = 0; $i < $numCuotas; $i++) {
            $fecha = date('Y-m-d', strtotime("+".($i * $diasIntervalo)." days", strtotime($fechaInicio)));
            // Último pago ajusta centavos
            $monto = ($i === $numCuotas - 1) ? round($honorarios - ($montoCuota * ($numCuotas - 1)), 2) : $montoCuota;
            $db->insert('pagos_programados', [
                'caso_id'           => $id,
                'numero_cuota'      => $i + 1,
                'fecha_vencimiento' => $fecha,
                'monto'             => $monto,
                'concepto'          => "Cuota " . ($i + 1) . " de $numCuotas",
            ]);
        }
    } elseif ($tipoPago === 'fechas_custom') {
        $fechasCustom  = $_POST['fechas_custom'] ?? [];
        $montosCustom  = $_POST['montos_custom'] ?? [];
        foreach ($fechasCustom as $idx => $fc) {
            if (empty($fc)) continue;
            $db->insert('pagos_programados', [
                'caso_id'           => $id,
                'numero_cuota'      => $idx + 1,
                'fecha_vencimiento' => $fc,
                'monto'             => (float)($montosCustom[$idx] ?? 0),
                'concepto'          => "Pago programado #" . ($idx + 1),
            ]);
        }
    }

    AuditLog::registrar('editar_financiero', 'casos', $id,
        "Honorarios: €" . number_format($honorarios, 2) . ". Plan: $tipoPago");
    setFlash('exito', 'Plan de pago configurado');
    header('Location: ' . APP_URL . '/index.php?page=casos/ver&id=' . $id); exit;
}


// Procesar edición del caso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_caso'])) {
    CSRF::verificarOAbortar();
    
    // Optimistic locking
    $updatedAtEnviado = $_POST['caso_updated_at'] ?? '';
    $updatedAtActual  = $db->fetchOne("SELECT updated_at FROM casos WHERE id = ?", [$id])['updated_at'] ?? '';
    if ($updatedAtEnviado && $updatedAtEnviado !== $updatedAtActual) {
        setFlash('error', '⚠️ Otro usuario editó este caso mientras trabajabas. Recarga la página para ver los cambios actualizados antes de editarlo.');
        header('Location: ' . APP_URL . '/index.php?page=casos/ver&id=' . $id); exit;
    }

    // Parsear tipo_caso (Tagify envía JSON)
    $tipoCasoStr = trim($_POST['tipo_caso'] ?? '');
    $decodedTipo = json_decode($tipoCasoStr, true);
    if (is_array($decodedTipo) && isset($decodedTipo[0]['value'])) {
        $tipoCasoStr = $decodedTipo[0]['value'];
    }

    // Columnas base — siempre existen
    $datosBase = [
        'titulo'            => trim($_POST['titulo']),
        'tipo_caso'         => $tipoCasoStr,
        'descripcion'       => trim($_POST['descripcion']),
        'abogado_id'        => $_POST['abogado_id'] ?: null,
        'honorarios_totales'=> (float)($_POST['honorarios_totales'] ?? 0),
        'honorarios_abogado'=> (float)($_POST['honorarios_abogado'] ?? 0),
    ];
    $db->update('casos', $datosBase, 'id = ?', [$id]);

    // Columnas financieras opcionales (añadidas por migración)
    try {
        $db->update('casos', [
            'tipo_pago_cliente' => $_POST['tipo_pago_cliente'] ?? 'pago_unico',
            'frecuencia_pago'   => $_POST['frecuencia_pago'] ?? 'mensual',
        ], 'id = ?', [$id]);
    } catch (Throwable $e) {
        // Si las columnas no existen, ignorar silenciosamente
        error_log('[CRM] financiero cols missing: ' . $e->getMessage());
    }

    // Lógica para regenerar pagos_programados (con fallback a la tabla 'pagos' tradicional)
    $errorRegen = null;
    try {
        $tipoPago = $_POST['tipo_pago_cliente'] ?? 'pago_unico';
        $honorariosTotales = (float)($_POST['honorarios_totales'] ?? 0);
        
        // Obtener total pagado de la tabla pagos (fuente de verdad)
        $totalPagado = 0.0;
        try {
            $r = $db->fetchOne("SELECT COALESCE(SUM(cantidad),0) as total FROM pagos WHERE caso_id = ? AND (tipo_pago IS NULL OR tipo_pago != 'pago_abogado')", [$id]);
            $totalPagado = (float)($r['total'] ?? 0);
        } catch (Throwable $ePagado) {
            $totalPagado = 0.0;
        }
        
        $saldoPendiente = max(0, $honorariosTotales - $totalPagado);

        // Desactivar temporalmente restricciones de clave foránea para permitir limpiar cuotas no pagadas
        try { $db->query("SET FOREIGN_KEY_CHECKS = 0"); } catch (Throwable $eFk) {}

        // Borrar cuotas pendientes o vencidas existentes (o todas si no hay pagos)
        try {
            if ($totalPagado <= 0) {
                $db->query("DELETE FROM pagos_programados WHERE caso_id = ?", [$id]);
            } else {
                $db->query("DELETE FROM pagos_programados WHERE caso_id = ? AND estado IN ('pendiente', 'vencido')", [$id]);
            }
        } catch (Throwable $eDel) {
            // Si la tabla no existe aún, la creamos al vuelo
            $db->query("CREATE TABLE IF NOT EXISTS pagos_programados (
                id INT AUTO_INCREMENT PRIMARY KEY,
                caso_id INT NOT NULL,
                numero_cuota INT DEFAULT 1,
                concepto VARCHAR(255) DEFAULT NULL,
                monto DECIMAL(10,2) NOT NULL,
                fecha_vencimiento DATE NOT NULL,
                estado ENUM('pendiente','pagado','vencido') NOT NULL DEFAULT 'pendiente',
                pagado_en DATETIME DEFAULT NULL,
                notas VARCHAR(255) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_caso (caso_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->query("DELETE FROM pagos_programados WHERE caso_id = ?", [$id]);
        }

        if ($saldoPendiente > 0) {
            if ($tipoPago === 'pago_unico') {
                $fecha = $_POST['fecha_pago_unico'] ?? date('Y-m-d');
                $data = [
                    'caso_id'           => $id,
                    'fecha_vencimiento' => $fecha,
                    'monto'             => $saldoPendiente,
                    'estado'            => 'pendiente',
                    'numero_cuota'      => 1,
                    'concepto'          => 'Pago único'
                ];
                $db->insert('pagos_programados', $data);
            } elseif ($tipoPago === 'cuotas') {
                $numCuotas = (int)($_POST['num_cuotas'] ?? 1);
                if ($numCuotas > 0) {
                    $montoCuota = round($saldoPendiente / $numCuotas, 2);
                    $freq = $_POST['frecuencia_pago'] ?? 'mensual';
                    $fecha = $_POST['fecha_inicio_cuotas'] ?? date('Y-m-d');
                    for ($i = 1; $i <= $numCuotas; $i++) {
                        $montoActual = ($i === $numCuotas) ? round($saldoPendiente - ($montoCuota * ($numCuotas - 1)), 2) : $montoCuota;
                        $data = [
                            'caso_id'           => $id,
                            'fecha_vencimiento' => $fecha,
                            'monto'             => $montoActual,
                            'estado'            => 'pendiente',
                            'numero_cuota'      => $i,
                            'concepto'          => "Cuota $i de $numCuotas"
                        ];
                        $db->insert('pagos_programados', $data);

                        $dateObj = new DateTime($fecha);
                        if ($freq === 'semanal')        $dateObj->modify('+1 week');
                        elseif ($freq === 'quincenal')  $dateObj->modify('+15 days');
                        elseif ($freq === 'mensual')    $dateObj->modify('+1 month');
                        elseif ($freq === 'trimestral') $dateObj->modify('+3 months');
                        elseif ($freq === 'semestral')  $dateObj->modify('+6 months');
                        $fecha = $dateObj->format('Y-m-d');
                    }
                }
            } elseif ($tipoPago === 'fechas_custom') {
                $montos = $_POST['montos_custom'] ?? [];
                $fechas = $_POST['fechas_custom'] ?? [];
                foreach ($montos as $i => $m) {
                    $montoCustom = (float)$m;
                    if ($montoCustom > 0) {
                        $data = [
                            'caso_id'           => $id,
                            'fecha_vencimiento' => $fechas[$i] ?? date('Y-m-d'),
                            'monto'             => $montoCustom,
                            'estado'            => 'pendiente',
                            'numero_cuota'      => $i + 1,
                            'concepto'          => "Pago programado #" . ($i + 1)
                        ];
                        $db->insert('pagos_programados', $data);
                    }
                }
            }
        }
        try { $db->query("SET FOREIGN_KEY_CHECKS = 1"); } catch (Throwable $eFk) {}
    } catch (Throwable $eP) {
        try { $db->query("SET FOREIGN_KEY_CHECKS = 1"); } catch (Throwable $eFk) {}
        $errorRegen = $eP->getMessage();
        error_log('[CRM] error en regeneracion de pagos: ' . $errorRegen);
    }

    AuditLog::registrar('editar', 'casos', $id, 'Datos del caso y plan de pagos actualizados');
    if ($errorRegen) {
        setFlash('error', '⚠️ Caso actualizado, pero hubo un detalle con las cuotas: ' . $errorRegen);
    } else {
        setFlash('exito', 'Caso y plan de pagos actualizado correctamente');
    }
    header('Location: ' . APP_URL . '/index.php?page=casos/ver&id=' . $id); exit;
}

// Guardar nueva nota
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_nota_feed'])) {
    CSRF::verificarOAbortar();
    $usuarioAct = $auth->getUsuario();
    $puedeNotas = $auth->esAdmin() || ($auth->esAbogado() && $caso['abogado_id'] == ($usuarioAct['id'] ?? 0));
    
    if ($puedeNotas) {
        $tipo = $_POST['tipo_nota'] === 'interna' ? 'interna' : 'publica';
        $titulo = trim($_POST['titulo_nota'] ?? '');
        $contenido = trim($_POST['contenido_nota']);
        
        if (!empty($contenido) || (isset($_FILES['documento_nota']) && $_FILES['documento_nota']['error'] !== UPLOAD_ERR_NO_FILE)) {
            
            // Insertar la nota
            $notaId = $db->insert('notas_caso', [
                'caso_id' => $id,
                'titulo' => $titulo,
                'tipo' => $tipo,
                'contenido' => $contenido,
                'created_by' => $usuarioAct['id'] ?? null
            ]);

            AuditLog::registrar('crear_nota', 'casos', $id, "Nota $tipo añadida");

            // Subir archivo adjunto si lo hay
            if (isset($_FILES['documento_nota']) && $_FILES['documento_nota']['error'] !== UPLOAD_ERR_NO_FILE) {
                require_once CRM_ROOT . '/includes/FileUpload.php';
                $resultado = FileUpload::subir($_FILES['documento_nota'], $id);
                
                if ($resultado['exito']) {
                    try {
                        $db->insert('documentos', [
                            'caso_id' => $id,
                            'nota_id' => $notaId,
                            'nombre_original' => $resultado['datos']['nombre_original'],
                            'nombre_archivo' => $resultado['datos']['nombre_archivo'] ?? $resultado['datos']['nombre_original'],
                            'ruta' => $resultado['datos']['ruta'],
                            'tipo_mime' => $resultado['datos']['tipo_mime'] ?? null,
                            'tamano_bytes' => $resultado['datos']['tamano_bytes'] ?? null,
                            'descripcion' => 'Adjunto a nota',
                            'subido_por' => $usuarioAct['id'] ?? null
                        ]);
                    } catch (Exception $e) {
                        setFlash('error', 'La nota se guardó pero hubo un error al registrar el documento en la base de datos: ' . $e->getMessage());
                    }
                } else {
                    setFlash('error', 'La nota se guardó pero hubo un error con el archivo: ' . $resultado['mensaje']);
                }
            }

            if (!isset($_SESSION['flash']) || $_SESSION['flash']['tipo'] !== 'error') {
                setFlash('exito', 'Nota añadida correctamente');
            }
        } else {
            setFlash('error', 'La nota no puede estar vacía');
        }
    }
    header('Location: ' . APP_URL . '/index.php?page=casos/ver&id=' . $id); exit;
}

// Editar nota
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_nota_accion'])) {
    CSRF::verificarOAbortar();
    $notaId = (int)$_POST['nota_id'];
    $nota = $db->fetchOne("SELECT * FROM notas_caso WHERE id = ?", [$notaId]);
    
    if ($nota && ($auth->esAdmin() || $nota['created_by'] === ($_SESSION['usuario_id']??0))) {
        $db->update('notas_caso', [
            'titulo' => trim($_POST['titulo_nota'] ?? ''),
            'contenido' => trim($_POST['contenido_nota']),
            'tipo' => $_POST['tipo_nota'] === 'interna' ? 'interna' : 'publica'
        ], 'id = ?', [$notaId]);
        
        // Subir archivo adjunto si lo hay
        if (isset($_FILES['documento_nota']) && $_FILES['documento_nota']['error'] !== UPLOAD_ERR_NO_FILE) {
            require_once CRM_ROOT . '/includes/FileUpload.php';
            $resultado = FileUpload::subir($_FILES['documento_nota'], $id);
            
            if ($resultado['exito']) {
                try {
                    $db->insert('documentos', [
                        'caso_id' => $id,
                        'nota_id' => $notaId,
                        'nombre_original' => $resultado['datos']['nombre_original'],
                        'nombre_archivo' => $resultado['datos']['nombre_archivo'] ?? $resultado['datos']['nombre_original'],
                        'ruta' => $resultado['datos']['ruta'],
                        'tipo_mime' => $resultado['datos']['tipo_mime'] ?? null,
                        'tamano_bytes' => $resultado['datos']['tamano_bytes'] ?? null,
                        'descripcion' => 'Adjunto a nota',
                        'subido_por' => $usuarioAct['id'] ?? null
                    ]);
                } catch (Exception $e) {
                    setFlash('error', 'La nota se actualizó pero hubo un error al registrar el documento en la base de datos: ' . $e->getMessage());
                }
            } else {
                setFlash('error', 'La nota se actualizó pero hubo un error con el archivo: ' . $resultado['mensaje']);
            }
        }
        
        if (!isset($_SESSION['flash']) || $_SESSION['flash']['tipo'] !== 'error') {
            setFlash('exito', 'Nota actualizada correctamente');
        }
    } else {
        setFlash('error', 'No tienes permiso para editar esta nota');
    }
    header('Location: ' . APP_URL . '/index.php?page=casos/ver&id=' . $id); exit;
}

// Eliminar nota
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_nota'])) {
    CSRF::verificarOAbortar();
    $notaId = (int)$_POST['eliminar_nota'];
    $nota = $db->fetchOne("SELECT * FROM notas_caso WHERE id = ?", [$notaId]);
    
    if ($nota && ($auth->esAdmin() || $nota['created_by'] === ($_SESSION['usuario_id']??0))) {
        // Eliminar adjunto si existe
        $doc = $db->fetchOne("SELECT id, ruta FROM documentos WHERE nota_id = ?", [$notaId]);
        if ($doc) {
            if (file_exists(CRM_ROOT . '/' . $doc['ruta'])) {
                unlink(CRM_ROOT . '/' . $doc['ruta']);
            }
            $db->query("DELETE FROM documentos WHERE id = ?", [$doc['id']]);
        }
        $db->query("DELETE FROM notas_caso WHERE id = ?", [$notaId]);
        setFlash('exito', 'Nota eliminada');
    }
    header('Location: ' . APP_URL . '/index.php?page=casos/ver&id=' . $id); exit;
}

// Registrar pago a abogado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_pago_abogado'])) {
    CSRF::verificarOAbortar();
    if (RoleGuard::esAdmin()) {
        try {
            $db->insert('pagos', [
                'caso_id' => $id,
                'cantidad' => (float)$_POST['cantidad'],
                'fecha_pago' => $_POST['fecha_pago'],
                'metodo_pago' => $_POST['metodo_pago'],
                'concepto' => trim($_POST['concepto']),
                'tipo_pago' => 'pago_abogado',
                'registrado_por' => $_SESSION['usuario_id'] ?? 1
            ]);
            AuditLog::registrar('pago_abogado', 'casos', $id, "Pago a abogado por €" . number_format((float)$_POST['cantidad'], 2) . " registrado");
            setFlash('exito', 'Pago al abogado registrado correctamente');
        } catch (Throwable $e) {
            try {
                // Fallback sin registrado_por si la columna no existe
                $db->insert('pagos', [
                    'caso_id' => $id,
                    'cantidad' => (float)$_POST['cantidad'],
                    'fecha_pago' => $_POST['fecha_pago'],
                    'metodo_pago' => $_POST['metodo_pago'],
                    'concepto' => trim($_POST['concepto']),
                    'tipo_pago' => 'pago_abogado'
                ]);
                AuditLog::registrar('pago_abogado', 'casos', $id, "Pago a abogado por €" . number_format((float)$_POST['cantidad'], 2) . " registrado");
                setFlash('exito', 'Pago al abogado registrado correctamente (fallback)');
            } catch (Throwable $e2) {
                setFlash('error', 'Error al registrar pago al abogado: ' . $e2->getMessage());
            }
        }
    } else {
        setFlash('error', 'No tienes permisos');
    }
    header('Location: ' . APP_URL . '/index.php?page=casos/ver&id=' . $id); exit;
}

// pagos del cliente — siempre leemos de la tabla 'pagos' original (donde registrar.php guarda)
$pagos = [];
try {
    $pagos = $db->fetchAll("SELECT id, caso_id, cantidad, fecha_pago, metodo_pago, concepto, notas, created_at FROM pagos WHERE caso_id = ? AND (tipo_pago IS NULL OR tipo_pago = 'pago_cliente' OR tipo_pago NOT IN ('pago_abogado')) ORDER BY fecha_pago DESC, created_at DESC", [$id]);
} catch (Throwable $e1) {
    $pagos = [];
}
$totalPagado = array_sum(array_column($pagos, 'cantidad'));
$saldoPendiente = $caso['honorarios_totales'] - $totalPagado;

try {
    $pagosAbogado = $db->fetchAll("SELECT * FROM pagos WHERE caso_id = ? AND tipo_pago = 'pago_abogado' ORDER BY fecha_pago DESC, created_at DESC", [$id]);
} catch (Throwable $eA) {
    $pagosAbogado = [];
}
$totalPagadoAbogado = array_sum(array_column($pagosAbogado, 'cantidad'));

// Obtener notas del caso
$notasCaso = [];
try {
    $notasCaso = $db->fetchAll("
        SELECT n.*, u.nombre as autor_nombre, u.apellidos as autor_apellidos,
               d.id as doc_id, d.nombre_original as doc_nombre, d.ruta as doc_ruta, d.tipo_mime as doc_tipo, d.tamano_bytes as doc_tamano
        FROM notas_caso n
        LEFT JOIN usuarios_internos u ON n.created_by = u.id
        LEFT JOIN documentos d ON d.nota_id = n.id
        WHERE n.caso_id = ?
        ORDER BY n.created_at DESC
    ", [$id]);
} catch (Exception $e) { $notasCasoError = $e->getMessage(); $notasCaso = []; }

// Documentos: tabla propia + archivos del portal vinculados al caso
$documentos = $db->fetchAll("SELECT * FROM documentos WHERE caso_id = ? AND nota_id IS NULL ORDER BY created_at DESC", [$id]);
// Si no hay documentos propios, buscar en solicitud_archivos via solicitudes del cliente
if (empty($documentos)) {
    $solId = $db->fetchColumn(
        "SELECT id FROM solicitudes WHERE email = (SELECT email FROM clientes WHERE id = ?) ORDER BY id DESC LIMIT 1",
        [$caso['cliente_id']]
    );
    if ($solId) {
        $archivosSol = $db->fetchAll("SELECT *, 'portal' as origen FROM solicitud_archivos WHERE solicitud_id = ? ORDER BY created_at DESC", [$solId]);
        // Normalizar campos para usar la misma vista
        foreach ($archivosSol as &$a) {
            $a['ruta'] = '../portal/' . $a['ruta'];
            $a['descripcion'] = 'Aportado por el cliente';
        }
        $documentos = $archivosSol;
    }
}

$historial = $db->fetchAll("SELECT * FROM audit_log WHERE tabla_afectada = 'casos' AND registro_id = ? ORDER BY created_at DESC LIMIT 20", [$id]);
$abogados = $db->fetchAll("SELECT id, nombre, apellidos FROM usuarios_internos WHERE rol = 'abogado' AND activo = 1");

// Pagos programados (calendario) - protegido si la tabla no existe aún
$pagosProgramados = [];
try {
    $pagosProgramados = $db->fetchAll("SELECT * FROM pagos_programados WHERE caso_id = ? ORDER BY fecha_vencimiento ASC", [$id]);
    // Marcar vencidos
    foreach ($pagosProgramados as &$pp) {
        if ($pp['estado'] === 'pendiente' && $pp['fecha_vencimiento'] < date('Y-m-d')) {
            $db->update('pagos_programados', ['estado' => 'vencido'], 'id = ?', [$pp['id']]);
            $pp['estado'] = 'vencido';
        }
    }
    unset($pp);
} catch (Throwable $ePP) {
    // Tabla pagos_programados aún no existe
    $pagosProgramados = [];
}

$tituloPagina = $caso['referencia'];
include CRM_ROOT . '/templates/layout/header.php';
?>
<link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/caso-ver.css?v=<?php echo time(); ?>">
<?php
$estadoMap = [
    'en_estudio'       => ['cls'=>'cv-state-study', 'label'=>'En Estudio',       'dot'=>'#2563eb'],
    'en_proceso'       => ['cls'=>'cv-state-proc',  'label'=>'En Proceso',        'dot'=>'#d97706'],
    'en_tramitacion'   => ['cls'=>'cv-state-tram',  'label'=>'En Tramitación',    'dot'=>'#0284c7'],
    'pendiente_juicio' => ['cls'=>'cv-state-juic',  'label'=>'Pendiente Juicio',  'dot'=>'#dc2626'],
    'cerrado'          => ['cls'=>'cv-state-closed','label'=>'Cerrado',           'dot'=>'#059669'],
    'archivado'        => ['cls'=>'cv-state-arch',  'label'=>'Archivado',         'dot'=>'#64748b'],
];
$eActual = $estadoMap[$caso['estado']] ?? ['cls'=>'cv-state-arch','label'=>ucfirst($caso['estado']),'dot'=>'#64748b'];
$estados = array_values(array_diff(array_keys($estadoMap), ['archivado']));
$extColors = ['PDF'=>['#fef2f2','#dc2626'],'DOC'=>['#e8f0fe','#2e6edd'],'DOCX'=>['#e8f0fe','#2e6edd'],'XLS'=>['#ecfdf5','#059669'],'XLSX'=>['#ecfdf5','#059669'],'JPG'=>['#fff7ed','#ea580c'],'PNG'=>['#fff7ed','#ea580c'],'ZIP'=>['#f5f3ff','#7c3aed']];
?>

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px">
  <div>
    <h5 style="font-size:1.125rem;font-weight:800;color:#1a1a2e;margin:0"><?php echo e($caso['referencia']); ?></h5>
    <p style="font-size:.8125rem;color:#94a3b8;margin:2px 0 0">
      <span style="font-weight:700;color:#475569"><?php echo ucfirst(e($caso['tipo'] ?? '')); ?></span> 
      &middot; <?php echo e($caso['titulo']); ?> 
      &middot; <?php echo e($caso['cliente_nombre'] ?? '') . ' ' . e($caso['cliente_apellidos'] ?? ''); ?>
    </p>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <?php if (RoleGuard::esAdmin()): ?>
    <form method="POST" style="margin:0" onsubmit="event.preventDefault(); crmConfirm('<?php echo $caso["estado"] === "archivado" ? "Desarchivar caso" : "Archivar caso"; ?>', '<?php echo $caso["estado"] === "archivado" ? "¿Seguro que deseas restaurar este caso?" : "¿Seguro que deseas archivar este caso?"; ?>', () => this.submit());">
      <?php echo CSRF::campo(); ?>
      <input type="hidden" name="cambiar_estado" value="1">
      <input type="hidden" name="nuevo_estado" value="<?php echo $caso['estado'] === 'archivado' ? 'en_estudio' : 'archivado'; ?>">
      <button type="submit" class="cv-btn cv-btn-ghost" style="width:auto;padding:8px 16px;font-size:.8125rem">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="5" rx="2"/><path d="M4 9v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9"/><line x1="10" y1="13" x2="14" y2="13"/></svg>
        <?php echo $caso['estado'] === 'archivado' ? 'Desarchivar' : 'Archivar'; ?>
      </button>
    </form>
    <button class="cv-btn cv-btn-primary" style="width:auto;padding:8px 16px;font-size:.8125rem" data-bs-toggle="modal" data-bs-target="#editarCasoModal">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Editar
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- Stepper de Estados -->
<div class="cv-card mb-24" style="margin-bottom: 24px;">
  <div class="cv-card-body" style="padding: 24px 32px;">
    <div style="display:flex; justify-content:space-between; position:relative;" id="cvStepperContainer">
      <!-- Línea conectora base -->
      <div style="position:absolute; top:12px; left:0; right:0; height:3px; background:#e2e8f0; z-index:1;"></div>
      
      <?php 
      $idxActual = array_search($caso['estado'], $estados);
      $progressWidth = ($idxActual / (count($estados) - 1)) * 100;
      ?>
      <!-- Línea de progreso -->
      <div style="position:absolute; top:12px; left:0; width:<?php echo $progressWidth; ?>%; height:3px; background:#2563eb; z-index:2; transition: width 0.3s ease;"></div>

      <?php foreach ($estados as $index => $est): 
          $mapInfo = $estadoMap[$est];
          $isCompleted = $index <= $idxActual;
          $isCurrent = $index === $idxActual;
          
          $bgColor = $isCurrent ? '#2563eb' : ($isCompleted ? '#bfdbfe' : '#ffffff');
          $borderColor = $isCurrent ? '#2563eb' : ($isCompleted ? '#2563eb' : '#cbd5e1');
          $textColor = $isCurrent ? '#2563eb' : ($isCompleted ? '#64748b' : '#94a3b8');
          $fontWeight = $isCurrent ? '700' : '600';
      ?>
      <div style="position:relative; z-index:3; display:flex; flex-direction:column; align-items:center; cursor:pointer;" 
           class="stepper-step" data-estado="<?php echo $est; ?>" data-label="<?php echo $mapInfo['label']; ?>"
           onclick="confirmarCambioEstado('<?php echo $est; ?>', '<?php echo $mapInfo['label']; ?>')"
           title="Cambiar estado a <?php echo $mapInfo['label']; ?>">
        <div class="stepper-ball <?php echo $isCurrent ? 'current-ball' : ''; ?>" style="width:28px; height:28px; border-radius:50%; background:<?php echo $bgColor; ?>; border:3px solid <?php echo $borderColor; ?>; display:flex; align-items:center; justify-content:center; box-shadow:0 0 0 4px #ffffff; transition:all 0.2s; <?php echo $isCurrent ? 'cursor:grab;' : ''; ?>">
            <?php if($isCurrent): ?>
            <div style="width:8px; height:8px; border-radius:50%; background:#ffffff;"></div>
            <?php elseif($isCompleted): ?>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
            <?php endif; ?>
        </div>
        <span style="margin-top:10px; font-size:0.75rem; font-weight:<?php echo $fontWeight; ?>; color:<?php echo $textColor; ?>; text-transform:uppercase; letter-spacing:0.5px;"><?php echo $mapInfo['label']; ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Script temporal para la alerta del Stepper -->
<script>
function confirmarCambioEstado(nuevoEstado, label) {
    crmConfirm('Cambiar estado', '¿Estás seguro que deseas mover el caso al estado: <strong>' + label + '</strong>?', function() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf_token';
        csrf.value = document.querySelector('input[name="csrf_token"]').value;
        
        const action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'cambiar_estado';
        action.value = '1';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'nuevo_estado';
        input.value = nuevoEstado;
        
        form.appendChild(csrf);
        form.appendChild(action);
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    });
}

// Lógica para que la bola actual sea "arrastrable"
document.addEventListener('DOMContentLoaded', () => {
    const currentBall = document.querySelector('.current-ball');
    if (!currentBall) return;
    
    let isDragging = false;
    let startX = 0;
    
    currentBall.addEventListener('mousedown', (e) => {
        isDragging = true;
        startX = e.clientX;
        currentBall.style.cursor = 'grabbing';
        currentBall.style.transform = 'scale(1.2)';
        currentBall.style.position = 'relative';
        currentBall.style.zIndex = '99';
    });
    
    document.addEventListener('mousemove', (e) => {
        if (!isDragging) return;
        const dx = (e.clientX - startX) / 1.2; // Adjust for scale
        currentBall.style.transform = `scale(1.2) translateX(${dx}px)`;
    });
    
    document.addEventListener('mouseup', (e) => {
        if (!isDragging) return;
        isDragging = false;
        currentBall.style.cursor = 'grab';
        currentBall.style.transform = 'scale(1)';
        currentBall.style.position = 'static';
        currentBall.style.zIndex = 'auto';
        
        // Detectar si soltó sobre otro paso
        const steps = document.querySelectorAll('.stepper-step');
        let droppedOn = null;
        steps.forEach(step => {
            const rect = step.getBoundingClientRect();
            if (e.clientX >= rect.left && e.clientX <= rect.right && e.clientY >= rect.top && e.clientY <= rect.bottom) {
                droppedOn = step;
            }
        });
        
        if (droppedOn && droppedOn !== currentBall.closest('.stepper-step')) {
            confirmarCambioEstado(droppedOn.dataset.estado, droppedOn.dataset.label);
        }
    });
});
</script>

<div class="cv-wrap">
  <!-- ══ COL IZQUIERDA ══ -->
  <div>
    <!-- Info del caso -->
    <div class="cv-card">
      <div class="cv-card-header">
        <div class="cv-icon" style="background:#e8f0fe;color:#2e6edd">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
        </div>
        <h3>Información del Caso</h3>
        <span class="cv-badge <?php echo $eActual['cls']; ?>" style="margin-left:auto">
          <svg width="8" height="8"><circle cx="4" cy="4" r="4" fill="currentColor"/></svg>
          <?php echo $eActual['label']; ?>
        </span>
      </div>
      <div class="cv-card-body">
        <div class="cv-grid">
          <div class="cv-field">
            <?php
            // Parsear tipo de caso si viene como JSON
            $tipoVisual = $caso['tipo_caso'];
            $decodedTipo = json_decode($tipoVisual, true);
            if (is_array($decodedTipo) && isset($decodedTipo[0]['value'])) {
                $tipoVisual = $decodedTipo[0]['value'];
            }
            ?>
            <label>Tipo</label><p>
            <span style="display:inline-block;background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:12px;font-size:0.75rem;font-weight:600;border:1px solid #e2e8f0;"><?php echo e($tipoVisual ?: 'General'); ?></span>
          </p></div>
          <div class="cv-field"><label>Abogado</label><p><?php echo $caso['abogado_nombre'] ? e($caso['abogado_nombre'].' '.$caso['abogado_apellidos']) : '<span style="color:#94a3b8;font-weight:400">Sin asignar</span>'; ?></p></div>
          <div class="cv-field"><label>Apertura</label><p><?php echo date('d/m/Y',strtotime($caso['fecha_apertura'])); ?></p></div>
          <div class="cv-field"><label>Cierre</label><p><?php echo $caso['fecha_cierre'] ? date('d/m/Y',strtotime($caso['fecha_cierre'])) : '—'; ?></p></div>
          <div class="cv-field"><label>Referencia</label><p style="font-family:monospace;font-size:.875rem"><?php echo e($caso['referencia']); ?></p></div>
        </div>
        
        <div style="font-size: 0.875rem; color: #1a1a2e; font-weight: 700; margin: 24px 0 12px; padding-top: 16px; border-top: 1px solid #e2e8f0; text-transform: uppercase;">Datos del Cliente</div>
        <div class="cv-grid">
          <div class="cv-field"><label>Cliente</label><p><a href="<?php echo APP_URL; ?>/index.php?page=clientes/ver&id=<?php echo $caso['cliente_id']; ?>" style="color:#2e6edd;font-weight:600;text-decoration:none"><?php echo e($caso['cliente_nombre'].' '.$caso['cliente_apellidos']); ?></a></p></div>
          <div class="cv-field"><label>Teléfono</label><p><?php echo $caso['cliente_telefono'] ? e($caso['cliente_telefono']) : '—'; ?></p></div>
          <div class="cv-field"><label>Email</label><p><?php echo $caso['cliente_email'] ? e($caso['cliente_email']) : '—'; ?></p></div>
          <div class="cv-field"><label>DNI / NIF</label><p><?php echo $caso['cliente_dni'] ? e($caso['cliente_dni']) : '—'; ?></p></div>
          <div class="cv-field" style="grid-column: 1 / -1;"><label>Dirección</label><p><?php echo $caso['cliente_direccion'] ? e($caso['cliente_direccion']) : '—'; ?></p></div>
        </div>
        <?php if($caso['descripcion']): ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Notas del Caso -->
    <div class="cv-card" style="margin-bottom: 24px;">
      <div class="cv-card-header">
        <div class="cv-icon" style="background:#f3e8ff;color:#9333ea">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <h3>Notas del Caso</h3>
      </div>
      <div class="cv-card-body">
        <!-- Feed de Notas -->
        <form method="POST" enctype="multipart/form-data" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 24px;">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="crear_nota_feed" value="1">
            
            <div style="display:flex; gap:16px; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0;">
                <label style="flex:1; cursor:pointer;">
                    <input type="radio" name="tipo_nota" value="publica" checked style="display:none;" onchange="
                        this.nextElementSibling.style.border='2px solid #2563eb'; 
                        this.nextElementSibling.style.background='#eff6ff';
                        const otherLbl = document.getElementById('lblInternaUI');
                        otherLbl.style.border='1px solid #e2e8f0';
                        otherLbl.style.background='#ffffff';
                    ">
                    <div id="lblPublicaUI" style="border: 2px solid #2563eb; background: #eff6ff; padding: 10px; border-radius: 8px; text-align: center; font-weight: 600; color: #1e293b; transition: all 0.2s;">
                        <span style="color:#2563eb; font-size:1.2rem; vertical-align:middle; margin-right:4px;">●</span> Nota Pública
                    </div>
                </label>
                <label style="flex:1; cursor:pointer;">
                    <input type="radio" name="tipo_nota" value="interna" style="display:none;" onchange="
                        this.nextElementSibling.style.border='2px solid #dc2626'; 
                        this.nextElementSibling.style.background='#fef2f2';
                        const otherLbl = document.getElementById('lblPublicaUI');
                        otherLbl.style.border='1px solid #e2e8f0';
                        otherLbl.style.background='#ffffff';
                    ">
                    <div id="lblInternaUI" style="border: 1px solid #e2e8f0; background: #ffffff; padding: 10px; border-radius: 8px; text-align: center; font-weight: 600; color: #1e293b; transition: all 0.2s;">
                        <span style="color:#dc2626; font-size:1.2rem; vertical-align:middle; margin-right:4px;">●</span> Nota Interna
                    </div>
                </label>
            </div>
            
            <input type="text" name="titulo_nota" class="cv-input" placeholder="Título de la nota..." style="width: 100%; margin-bottom: 12px;" required>
            <textarea name="contenido_nota" class="cv-input" rows="3" placeholder="Escribe la descripción aquí..." style="width: 100%; margin-bottom: 12px; resize: vertical;" required></textarea>
            
            <div style="display:flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <div>
                    <label class="cv-btn cv-btn-ghost" style="padding: 6px 12px; font-size: 0.8125rem; cursor: pointer; display: inline-flex; width: auto; background: #ffffff; border: 1px solid #e2e8f0;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                        <span id="file-name-display" style="margin-left:6px; font-weight: 500;">Adjuntar Archivo</span>
                        <input type="file" name="documento_nota" style="display:none" onchange="document.getElementById('file-name-display').textContent = this.files[0] ? this.files[0].name : 'Adjuntar Archivo'">
                    </label>
                </div>
                <button type="submit" class="cv-btn cv-btn-primary" style="width: auto; padding: 6px 16px;">
                    Publicar Nota
                </button>
            </div>
        </form>

        <!-- Feed de Notas -->
        <div>
            <style>
                .feed-nota-card {
                    border-radius: 16px;
                    padding: 20px;
                    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                    position: relative;
                    overflow: hidden;
                }
                .feed-nota-card:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.08);
                }
                .feed-nota-interna {
                    background: linear-gradient(145deg, #fffafa, #fff1f2);
                    border: 1px solid #ffe4e6;
                }
                .feed-nota-publica {
                    background: #ffffff;
                    border: 1px solid #f1f5f9;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
                }
                .feed-nota-avatar {
                    width: 38px;
                    height: 38px;
                    border-radius: 12px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-weight: 800;
                    font-size: 1rem;
                    color: #fff;
                    box-shadow: 0 4px 10px -2px rgba(0,0,0,0.1);
                }
                .avatar-interna { background: linear-gradient(135deg, #f43f5e, #e11d48); }
                .avatar-publica { background: linear-gradient(135deg, #3b82f6, #2563eb); }
                
                .feed-nota-tag {
                    display: inline-flex;
                    align-items: center;
                    gap: 4px;
                    padding: 4px 10px;
                    border-radius: 20px;
                    font-size: 0.6875rem;
                    font-weight: 700;
                    letter-spacing: 0.05em;
                }
                .tag-interna { background: #ffe4e6; color: #e11d48; }
                .tag-publica { background: #dbeafe; color: #2563eb; }
                .tag-dot { width: 6px; height: 6px; border-radius: 50%; }
                .dot-interna { background: #e11d48; }
                .dot-publica { background: #2563eb; }
                
                .feed-nota-actions {
                    opacity: 0;
                    transition: opacity 0.2s;
                }
                .feed-nota-card:hover .feed-nota-actions {
                    opacity: 1;
                }
                .doc-attachment {
                    transition: all 0.2s;
                }
                .doc-attachment:hover {
                    background: #f1f5f9 !important;
                    border-color: #cbd5e1 !important;
                }
            </style>

            <?php if(empty($notasCaso)): ?>
            <?php if(isset($notasCasoError)): ?>
            <div style="color:red; padding:20px; background:#fee2e2; border-radius:8px; margin-bottom:20px;">
                <strong>Error al cargar las notas:</strong> <?php echo e($notasCasoError); ?>
            </div>
            <?php endif; ?>
            <div style="text-align:center; padding: 40px 20px; background: #f8fafc; border-radius: 16px; border: 1px dashed #cbd5e1;">
                <div style="width: 48px; height: 48px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                </div>
                <h4 style="margin: 0 0 4px; color: #334155; font-size: 1rem;">No hay notas aún</h4>
                <p style="margin: 0; color: #64748b; font-size: 0.875rem;">Las notas que agregues aparecerán aquí.</p>
            </div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap: 20px;">
                <?php foreach($notasCaso as $nota): 
                    $isInterna = $nota['tipo'] === 'interna';
                    $cardClass = $isInterna ? 'feed-nota-interna' : 'feed-nota-publica';
                    $avatarClass = $isInterna ? 'avatar-interna' : 'avatar-publica';
                    $tagClass = $isInterna ? 'tag-interna' : 'tag-publica';
                    $dotClass = $isInterna ? 'dot-interna' : 'dot-publica';
                ?>
                    <div class="feed-nota-card <?php echo $cardClass; ?>">
                        <div style="display:flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                            <div style="display:flex; align-items:center; gap: 12px;">
                                <div class="feed-nota-avatar <?php echo $avatarClass; ?>">
                                    <?php echo strtoupper(substr($nota['autor_nombre'] ?? 'S', 0, 1)); ?>
                                </div>
                                <div>
                                    <div style="display:flex; align-items:center; gap: 8px;">
                                        <div style="margin:0; font-weight: 700; font-size: 0.9375rem; color: #0f172a; letter-spacing: -0.01em;">
                                            <?php echo e($nota['titulo'] ?? 'Nota sin título'); ?>
                                        </div>
                                        <span class="feed-nota-tag <?php echo $tagClass; ?>">
                                            <span class="tag-dot <?php echo $dotClass; ?>"></span>
                                            <?php echo $isInterna ? 'INTERNA' : 'PÚBLICA'; ?>
                                        </span>
                                    </div>
                                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">
                                        Por <strong style="color: #475569;"><?php echo e(($nota['autor_nombre'] ?? 'Sistema') . ' ' . ($nota['autor_apellidos'] ?? '')); ?></strong> 
                                        &bull; <?php echo date('d M, Y \a \l\a\s H:i', strtotime($nota['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="feed-nota-actions" style="display:flex; align-items:center; gap: 8px;">
                                <?php if($nota['created_by'] === ($_SESSION['usuario_id']??0) || RoleGuard::esAdmin()): ?>
                                <button type="button" class="btn btn-sm btn-editar-nota" style="background:#f1f5f9; color:#475569; border:none; border-radius:6px; padding: 6px; cursor:pointer; transition:background 0.2s;" data-id="<?php echo $nota['id']; ?>" data-titulo="<?php echo e($nota['titulo']); ?>" data-contenido="<?php echo e($nota['contenido']); ?>" data-tipo="<?php echo $nota['tipo']; ?>" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                </button>
                                <form method="POST" style="margin:0; display:inline;" onsubmit="event.preventDefault(); crmConfirm('Eliminar nota', '¿Eliminar esta nota de forma permanente? Esta acción no se puede deshacer.', () => this.submit(), true);">
                                    <?php echo CSRF::campo(); ?>
                                    <input type="hidden" name="eliminar_nota" value="<?php echo $nota['id']; ?>">
                                    <button type="submit" style="background:#fef2f2; color:#ef4444; border:none; border-radius:6px; padding: 6px; cursor:pointer; transition:background 0.2s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div style="font-size: 0.875rem; color: #334155; line-height: 1.6; white-space: pre-wrap; padding-left: 50px;"><?php echo e($nota['contenido']); ?></div>
                        
                        <?php if(!empty($nota['doc_id'])): ?>
                        <div class="doc-attachment" style="margin-top: 16px; margin-left: 50px; padding: 12px 16px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; display:flex; align-items:center; justify-content: space-between; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                            <div style="display:flex; align-items:center; gap: 12px;">
                                <div style="background: linear-gradient(135deg, #eff6ff, #dbeafe); color: #2563eb; padding: 8px; border-radius: 8px;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                </div>
                                <div>
                                    <div style="font-size: 0.875rem; font-weight: 600; color: #1e293b;"><?php echo e($nota['doc_nombre']); ?></div>
                                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;"><?php echo round($nota['doc_tamano']/1024, 1); ?> KB &bull; Adjunto</div>
                                </div>
                            </div>
                            <a href="<?php echo APP_URL; ?>/index.php?page=casos/descargar&id=<?php echo $nota['doc_id']; ?>" target="_blank" style="padding: 6px 12px; font-size: 0.8125rem; font-weight: 600; color: #2563eb; background: #eff6ff; border-radius: 8px; text-decoration: none; transition: background 0.2s;" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">
                                Descargar
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
      </div>
    </div>

  </div> <!-- Close Left Column -->

  <!-- ══ COL DERECHA ══ -->
  <div style="min-width: 0;">

    <?php if (RoleGuard::esAdmin()): ?>
    <!-- Financiero -->
    <div class="cv-card" style="margin-bottom: 24px;">
      <div class="cv-card-header">
        <div style="display:flex;align-items:center;justify-content:space-between;">
          <div style="display:flex;align-items:center;gap:12px">
            <div class="cv-icon" style="background:#ecfdf5;color:#059669"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
            <h3 style="margin:0;font-size:1.125rem">Módulo Financiero</h3>
            <!-- v2 -->
          </div>
        </div>
      </div>
      <div class="cv-card-body" style="padding: 24px;">
        <!-- FILA 1: PANEL CLIENTE -->
        <div style="border:1.5px solid #e2e8f0; border-radius:14px; padding:16px 20px; margin-bottom:16px; background:#f8fafc;">
          <!-- Header -->
          <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
            <div style="display:flex;align-items:center;gap:8px;">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              <span style="font-size:0.8125rem;font-weight:700;color:#1e293b;">Estado de Pagos del Cliente</span>
              <?php if(!empty($pagosProgramados)): ?>
              <span style="font-size:0.75rem;padding:2px 8px;border-radius:99px;background:#dcfce7;color:#15803d;font-weight:600;"><?php echo count($pagosProgramados); ?> cuota<?php echo count($pagosProgramados) != 1 ? 's' : ''; ?></span>
              <?php endif; ?>
            </div>
            <span style="font-size:1rem;font-weight:800;color:#1e293b;">Total: €<?php echo number_format((float)$caso['honorarios_totales'],2,',','.'); ?></span>
          </div>

          <?php if(!empty($pagosProgramados)): 
            $totalPagadoReal   = array_sum(array_column($pagos, 'cantidad'));
            $acumulado         = 0.0;
            $cuotaActualIdx    = -1;
            foreach ($pagosProgramados as $idx => $pp) {
                $acumulado += (float)$pp['monto'];
                if ($totalPagadoReal >= $acumulado) {
                    $pagosProgramados[$idx]['_estadoReal'] = 'pagado';
                } elseif ($totalPagadoReal > ($acumulado - (float)$pp['monto'])) {
                    $pagosProgramados[$idx]['_estadoReal'] = 'progreso';
                    $cuotaActualIdx = $idx;
                } else {
                    $pagosProgramados[$idx]['_estadoReal'] = $pp['estado'];
                }
            }
            $totalCuotas  = count($pagosProgramados);
            $pctGlobal    = $caso['honorarios_totales'] > 0 ? min(100, round(($totalPagadoReal / $caso['honorarios_totales']) * 100)) : 0;
            $pendienteCliente = max(0, (float)$caso['honorarios_totales'] - $totalPagadoReal);
          ?>
          <!-- Barra de progreso -->
          <div style="margin:14px 0 10px;">
            <div style="height:10px;background:#e2e8f0;border-radius:99px;overflow:hidden;">
              <div style="height:100%;width:<?php echo $pctGlobal; ?>%;background:linear-gradient(90deg,#2563eb,#6ba3ff);border-radius:99px;transition:width .4s;"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:0.7rem;color:#64748b;margin-top:4px;">
              <span><?php echo $pctGlobal; ?>% pagado</span>
              <span>€<?php echo number_format($pendienteCliente,2,',','.'); ?> pendiente</span>
            </div>
          </div>
          <?php else: ?>
             <div style="margin:14px 0;"></div>
          <?php endif; ?>

          <!-- Cajitas Pagado / Pendiente -->
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:<?php echo empty($pagosProgramados) ? '0' : '20px'; ?>;">
            <div style="flex:1;min-width:90px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 12px;text-align:center;">
              <div style="font-size:0.65rem;font-weight:700;color:#15803d;text-transform:uppercase;margin-bottom:4px;">Pagado</div>
              <div style="font-size:1.1rem;font-weight:800;color:#16a34a;">€<?php echo number_format($totalPagado,2,',','.'); ?></div>
            </div>
            <div style="flex:1;min-width:90px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 12px;text-align:center;">
              <div style="font-size:0.65rem;font-weight:700;color:#dc2626;text-transform:uppercase;margin-bottom:4px;">Pendiente</div>
              <div style="font-size:1.1rem;font-weight:800;color:#dc2626;">€<?php echo number_format($saldoPendiente,2,',','.'); ?></div>
            </div>
          </div>

          <?php if(!empty($pagosProgramados)): ?>
          <!-- Círculos de cuotas -->
          <div style="display:flex; justify-content:space-between; position:relative; width:100%; padding-top:10px; overflow-x:auto; gap:4px;">
            <div style="position:absolute; top:22px; left:0; right:0; height:3px; background:#e2e8f0; z-index:1;"></div>
            <?php
            $cuotasPagadas = count(array_filter($pagosProgramados, fn($p) => ($p['_estadoReal'] ?? '') === 'pagado'));
            $lineaPct = $totalCuotas > 1 ? ($cuotasPagadas / ($totalCuotas - 1)) * 100 : ($cuotasPagadas > 0 ? 100 : 0);
            ?>
            <div style="position:absolute; top:22px; left:0; height:3px; width:<?php echo $lineaPct; ?>%; background:linear-gradient(90deg,#10b981,#34d399); z-index:2; transition:width .5s;"></div>

            <?php foreach ($pagosProgramados as $index => $pp):
                $estadoReal = $pp['_estadoReal'] ?? 'pendiente';
                $esPagado   = $estadoReal === 'pagado';
                $esProgreso = $estadoReal === 'progreso';
                $esVencido  = $estadoReal === 'vencido' || (!$esPagado && !$esProgreso && strtotime($pp['fecha_vencimiento']) < time());
                $esActivo   = $index === $cuotaActualIdx;

                if ($esPagado)       { $bg='#10b981'; $bord='#10b981'; $tc='#059669'; }
                elseif ($esProgreso) { $bg='#dbeafe'; $bord='#2563eb'; $tc='#1d4ed8'; }
                elseif ($esVencido)  { $bg='#fef2f2'; $bord='#ef4444'; $tc='#dc2626'; }
                else                 { $bg='#fffbeb'; $bord='#f59e0b'; $tc='#d97706'; }

                $numCuota = $pp['numero_cuota'] ?? ($index + 1);
                $totalNum = $pp['concepto'] ? (preg_match('/de (\d+)/', $pp['concepto'], $m) ? $m[1] : $totalCuotas) : $totalCuotas;
            ?>
            <div style="position:relative; z-index:3; display:flex; flex-direction:column; align-items:center; flex:1; min-width:52px;">
              <div style="width:24px;height:24px;border-radius:50%;background:<?php echo $bg; ?>;border:3px solid <?php echo $bord; ?>;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 4px #f8fafc;z-index:2;position:relative;<?php echo $esActivo?'box-shadow:0 0 0 4px #dbeafe, 0 0 0 2px #2563eb;':'' ?>">
                <?php if ($esPagado): ?>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                <?php elseif ($esProgreso): ?>
                <div style="width:8px;height:8px;border-radius:50%;background:#2563eb;"></div>
                <?php endif; ?>
              </div>
              <span style="margin-top:10px;font-size:.6rem;font-weight:700;color:<?php echo $tc; ?>;text-transform:uppercase;text-align:center;line-height:1.2;width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                CUOTA <?php echo $numCuota; ?> DE <?php echo $totalNum; ?>
              </span>
              <span style="margin-top:2px;font-size:.65rem;color:#64748b;font-weight:600;">€<?php echo number_format($pp['monto'], 2, ',', '.'); ?></span>
              <?php if ($esPagado): ?>
              <span style="font-size:.55rem;color:#10b981;font-weight:700;margin-top:1px;">Pagada</span>
              <?php elseif ($esProgreso): ?>
              <span style="font-size:.55rem;color:#2563eb;font-weight:700;margin-top:1px;">En curso</span>
              <?php elseif ($esVencido): ?>
              <span style="font-size:.55rem;color:#dc2626;font-weight:700;margin-top:1px;">Vencida</span>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- FILA 2: PANEL ABOGADO -->
        <?php
            $honorarios_abogado_real = (float)($caso['honorarios_abogado'] ?? 0);
            if ($honorarios_abogado_real == 0 && !empty($caso['abogado_id'])) {
                $uTipo = $caso['u_tipo_pago'] ?? 'fijo';
                if ($uTipo === 'hitos' || $uTipo === 'fijo') $honorarios_abogado_real = (float)$caso['u_tarifa_fija'];
                elseif ($uTipo === 'mensual') $honorarios_abogado_real = (float)$caso['u_tarifa_mensual'];
                elseif ($uTipo === 'exito') $honorarios_abogado_real = (float)$caso['u_tarifa_exito'];
            }
            $bono           = (float)($caso['bono_abogado'] ?? 0);
            $totalAbogado   = $honorarios_abogado_real + $bono;
            $pagadoAbogado  = $totalPagadoAbogado;
            $pendienteAb    = max(0, $totalAbogado - $pagadoAbogado);
            $pctAb          = $totalAbogado > 0 ? min(100, round(($pagadoAbogado / $totalAbogado) * 100)) : 0;

            // Bono: se paga primero
            $bonoPagado     = min($pagadoAbogado, $bono);
            $bonoPendiente  = max(0, $bono - $bonoPagado);
            $tarifaPagada   = max(0, $pagadoAbogado - $bonoPagado);
            $tarifaPendiente= max(0, $honorarios_abogado_real - $tarifaPagada);
        ?>
        <div style="border:1.5px solid #e2e8f0; border-radius:14px; padding:16px 20px; margin-bottom:16px; background:#f8fafc;">
          <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
            <div style="display:flex;align-items:center;gap:8px;">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#1d4ed8" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M6 20v-2a6 6 0 0 1 12 0v2"/></svg>
              <span style="font-size:0.8125rem;font-weight:700;color:#1e293b;">Honorarios Abogado</span>
              <span style="font-size:0.75rem;padding:2px 8px;border-radius:99px;background:#e0e7ff;color:#3730a3;font-weight:600;">
                <?php 
                  $tpa = $caso['tipo_pago_abogado'] ?? ($caso['u_tipo_pago'] ?? 'fijo');
                  $labelTpa = ['mensual_predeterminado'=>'Mensual Fijo','mensual_sin_predeterminar'=>'Mensual','por_hitos'=>'Por Hitos','de_exito'=>'De Éxito','hitos'=>'Por Hitos','mensual'=>'Mensual','fijo'=>'Fijo','exito'=>'De Éxito'];
                  echo $labelTpa[$tpa] ?? ucfirst(str_replace('_',' ',$tpa));
                ?>
              </span>
            </div>
            <span style="font-size:1rem;font-weight:800;color:#1e293b;">Total: €<?php echo number_format($totalAbogado,2,',','.'); ?></span>
          </div>

          <!-- Barra de progreso -->
          <div style="margin:14px 0 10px;">
            <div style="height:10px;background:#e2e8f0;border-radius:99px;overflow:hidden;">
              <div style="height:100%;width:<?php echo $pctAb; ?>%;background:linear-gradient(90deg,#22c55e,#16a34a);border-radius:99px;transition:width .4s;"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:0.7rem;color:#64748b;margin-top:4px;">
              <span><?php echo $pctAb; ?>% pagado</span>
              <span>€<?php echo number_format($pendienteAb,2,',','.'); ?> pendiente</span>
            </div>
          </div>

          <!-- Desglose en 3 columnas -->
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">
            <!-- Pagado -->
            <div style="flex:1;min-width:90px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 12px;text-align:center;">
              <div style="font-size:0.65rem;font-weight:700;color:#15803d;text-transform:uppercase;margin-bottom:4px;">Pagado</div>
              <div style="font-size:1.1rem;font-weight:800;color:#16a34a;">€<?php echo number_format($pagadoAbogado,2,',','.'); ?></div>
            </div>
            <!-- Pendiente -->
            <div style="flex:1;min-width:90px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 12px;text-align:center;">
              <div style="font-size:0.65rem;font-weight:700;color:#dc2626;text-transform:uppercase;margin-bottom:4px;">Pendiente</div>
              <div style="font-size:1.1rem;font-weight:800;color:#dc2626;">€<?php echo number_format($pendienteAb,2,',','.'); ?></div>
            </div>
            <!-- Bono -->
            <?php if ($bono > 0): ?>
            <div style="flex:1;min-width:90px;background:<?php echo $bonoPendiente==0?'#f0fdf4':'#fefce8'; ?>;border:1px solid <?php echo $bonoPendiente==0?'#bbf7d0':'#fde68a'; ?>;border-radius:10px;padding:10px 12px;text-align:center;">
              <div style="font-size:0.65rem;font-weight:700;color:<?php echo $bonoPendiente==0?'#15803d':'#92400e'; ?>;text-transform:uppercase;margin-bottom:4px;">
                Bono <?php echo $bonoPendiente==0?'(Pagado)':'(Pend.)'; ?>
              </div>
              <div style="font-size:1.1rem;font-weight:800;color:<?php echo $bonoPendiente==0?'#16a34a':'#d97706'; ?>;">€<?php echo number_format($bono,2,',','.'); ?></div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Botones acción -->
        <div style="display:flex;gap:16px; flex-wrap: wrap;">
          <a href="<?php echo APP_URL; ?>/index.php?page=pagos/registrar&caso_id=<?php echo $id; ?>" class="cv-btn cv-btn-primary" style="flex:1; padding:12px; font-size:.9375rem; text-decoration:none; justify-content:center; text-align:center;">+ Registrar Pago Cliente</a>
          <button class="cv-btn cv-btn-primary" style="flex:1; padding:12px; font-size:.9375rem; justify-content:center; background:#1d4ed8;" data-bs-toggle="modal" data-bs-target="#modalRegistrarPagoAbogado">+ Registrar Pago Abogado</button>
        </div>



      </div>
    </div>
    <?php endif; /* esAdmin financiero */ ?>



    <?php if (RoleGuard::esAdmin()): ?>
    <!-- Historial -->
    <div class="cv-card">
      <div class="cv-card-header">
        <div class="cv-icon" style="background:#fff7ed;color:#d97706">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <h3>Historial</h3>
      </div>
      <div class="cv-card-body" style="max-height:400px;overflow-y:auto">
        <?php if(empty($historial)): ?>
        <p style="color:#94a3b8;font-size:.875rem;text-align:center;padding:16px 0">Sin actividad registrada</p>
        <?php else: ?>
        <?php foreach($historial as $h): ?>
        <div class="cv-log">
          <div class="cv-log-dot"></div>
          <div><div class="cv-log-txt"><?php echo e($h['detalles']);?></div><div class="cv-log-date"><?php echo e($h['usuario_nombre']??'Sistema');?> &middot; <?php echo date('d/m/Y H:i',strtotime($h['created_at']));?></div></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- El antiguo bloque de notas internas fue eliminado -->
  </div>
</div>

<!-- Modal Registrar Pago Abogado -->
<div class="modal fade" id="modalRegistrarPagoAbogado" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="" class="modal-content radius-8">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="registrar_pago_abogado" value="1">
            <div class="modal-header"><h6 class="modal-title">Registrar Pago a Abogado</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <?php
                    // Calcular el máximo a pagar
                    $honorarios_abogado_real = (float)($caso['honorarios_abogado'] ?? 0);
                    if ($honorarios_abogado_real == 0 && !empty($caso['abogado_id'])) {
                        $uTipo = $caso['u_tipo_pago'] ?? 'fijo';
                        if ($uTipo === 'hitos' || $uTipo === 'fijo') $honorarios_abogado_real = (float)$caso['u_tarifa_fija'];
                        elseif ($uTipo === 'mensual') $honorarios_abogado_real = (float)$caso['u_tarifa_mensual'];
                        elseif ($uTipo === 'exito') $honorarios_abogado_real = (float)$caso['u_tarifa_exito'];
                    }
                    $maxPagoAbogado = max(0, ($honorarios_abogado_real + (float)$caso['bono_abogado']) - $totalPagadoAbogado);
                ?>
                <div class="mb-3">
                    <label class="form-label">Cantidad (&euro;)</label>
                    <input type="number" name="cantidad" step="0.01" max="<?php echo $maxPagoAbogado; ?>" class="form-control" required>
                    <div class="form-text text-muted" style="font-size:0.75rem;">
                        Límite restante para este caso: &euro;<?php echo number_format($maxPagoAbogado, 2, ',', '.'); ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Fecha de Pago</label>
                    <input type="date" name="fecha_pago" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Método de Pago</label>
                    <select name="metodo_pago" class="form-select" required>
                        <option value="transferencia">Transferencia</option>
                        <option value="efectivo">Efectivo</option>
                        <option value="tarjeta">Tarjeta</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Concepto (Opcional)</label>
                    <input type="text" name="concepto" class="form-control" placeholder="Ej: Pago hito 1">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary radius-8" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary radius-8">Guardar Pago</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Editar Nota -->
<div class="modal fade" id="modalEditarNota" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" enctype="multipart/form-data" class="modal-content radius-8">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="editar_nota_accion" value="1">
            <input type="hidden" name="nota_id" id="editar_nota_id">
            <div class="modal-header"><h6 class="modal-title">Editar Nota</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Título de la nota</label>
                    <input type="text" name="titulo_nota" id="editar_nota_titulo" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Tipo</label>
                    <select name="tipo_nota" id="editar_tipo_nota" class="form-select">
                        <option value="publica">Pública (Visible al cliente)</option>
                        <option value="interna">Interna (Privada)</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Descripción</label>
                    <textarea name="contenido_nota" id="editar_nota_contenido" class="form-control" rows="4" required></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Adjuntar Archivo (opcional)</label>
                    <input type="file" name="documento_nota" class="form-control">
                    <small class="text-muted">Si subes un archivo, se añadirá a la nota.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary radius-8" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary radius-8">Actualizar Nota</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editarCasoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content radius-8">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="editar_caso" value="1">
            <input type="hidden" name="caso_updated_at" value="<?php echo e($caso['updated_at']); ?>">
            <div class="modal-header"><h6 class="modal-title">Editar Caso</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row gy-3">
                    <div class="col-sm-8"><label class="form-label">Título</label><input type="text" name="titulo" class="form-control" value="<?php echo e($caso['titulo']); ?>" required></div>
                    <div class="col-sm-4"><label class="form-label">Tipo de Caso</label><input type="text" name="tipo_caso" id="inputTipoCaso" class="form-control" value="<?php echo e($caso['tipo_caso']); ?>" placeholder="Ej: Penal, Civil..."></div>
                    <div class="col-sm-6"><label class="form-label fw-semibold" style="font-size:.8125rem">Abogado Asignado</label>
                        <?php
                        $abSel = $caso['abogado_id'] ?? '';
                        $abNom = 'Sin asignar';
                        foreach($abogados as $ab){ if($ab['id']==$abSel){ $abNom=e($ab['nombre'].' '.$ab['apellidos']); break; } }
                        ?>
                        <div class="cs-w" id="csModalW">
                          <div class="cs-btn <?php echo $abSel?'hv':''; ?>" id="csModalBtn">
                            <?php if($abSel): ?><div class="cs-av" style="width:22px;height:22px;border-radius:6px;background:linear-gradient(135deg,#2e6edd,#6ba3ff);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.625rem;font-weight:800;margin-right:6px"><?php echo strtoupper(substr($abNom,0,1)); ?></div><?php endif; ?>
                            <span id="csModalLbl"><?php echo $abNom; ?></span>
                          </div>
                          <svg class="cs-arr" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                          <div class="cs-drop" id="csModalDrop">
                            <div class="cs-item <?php echo !$abSel?'sel':''; ?>" data-val="" data-nom="Sin asignar" data-ini=""><span style="color:#94a3b8;font-size:.8125rem">Sin asignar</span></div>
                            <?php foreach($abogados as $ab): $ini=strtoupper(substr($ab['nombre'],0,1)); ?>
                            <div class="cs-item <?php echo $ab['id']==$abSel?'sel':''; ?>" data-val="<?php echo $ab['id']; ?>" data-nom="<?php echo e($ab['nombre'].' '.$ab['apellidos']); ?>" data-ini="<?php echo $ini; ?>">
                              <div style="width:22px;height:22px;border-radius:6px;background:linear-gradient(135deg,#2e6edd,#6ba3ff);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.625rem;font-weight:800;flex-shrink:0"><?php echo $ini; ?></div>
                              <?php echo e($ab['nombre'].' '.$ab['apellidos']); ?>
                            </div>
                            <?php endforeach; ?>
                          </div>
                          <input type="hidden" name="abogado_id" id="csModalHid" value="<?php echo $abSel; ?>">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">Cobro Cliente (&euro;)</label>
                        <input type="number" name="honorarios_totales" class="form-control"
                               step="0.01" min="0" value="<?php echo $caso['honorarios_totales']; ?>" required>
                    </div>

                    <?php $tpc = $caso['tipo_pago_cliente'] ?? 'pago_unico'; ?>
                    <div class="col-sm-6">
                        <label class="form-label">Tipo de Pago del Cliente</label>
                        <select name="tipo_pago_cliente" id="tipo_pago_cliente_select" class="form-select" onchange="
                            document.getElementById('wrapPagoUnico').style.display = this.value === 'pago_unico' ? 'block' : 'none';
                            document.getElementById('wrapCuotas').style.display = this.value === 'cuotas' ? 'block' : 'none';
                            document.getElementById('wrapCustom').style.display = this.value === 'fechas_custom' ? 'block' : 'none';
                        ">
                            <option value="pago_unico" <?php echo $tpc==='pago_unico'?'selected':''; ?>>Pago Único</option>
                            <option value="cuotas" <?php echo $tpc==='cuotas'?'selected':''; ?>>Pago por Cuotas</option>
                            <option value="fechas_custom" <?php echo $tpc==='fechas_custom'?'selected':''; ?>>Fechas Personalizadas</option>
                        </select>
                    </div>
                    
                    <!-- PAGO ÚNICO -->
                    <div class="col-12" id="wrapPagoUnico" style="<?php echo $tpc !== 'pago_unico' ? 'display:none' : ''; ?>">
                        <label class="cv-label">Fecha de Pago</label>
                        <input type="date" name="fecha_pago_unico" class="cv-input" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                    </div>

                    <!-- CUOTAS -->
                    <div class="col-12" id="wrapCuotas" style="<?php echo $tpc !== 'cuotas' ? 'display:none' : ''; ?>">
                        <div class="row gy-3">
                            <div class="col-sm-4">
                                <label class="cv-label">Número de Cuotas</label>
                                <div class="cs-w" id="csCuotasW">
                                  <div class="cs-btn hv" id="csCuotasBtn"><span id="csCuotasLbl">3 cuotas</span></div>
                                  <svg class="cs-arr" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                                  <div class="cs-drop" id="csCuotasDrop">
                                    <?php for($n=2;$n<=12;$n++): ?>
                                    <div class="cs-item" data-val="<?php echo $n;?>" data-nom="<?php echo $n;?> cuotas"><?php echo $n;?> cuotas</div>
                                    <?php endfor;?>
                                  </div>
                                  <input type="hidden" name="num_cuotas" id="csCuotasHid" value="3">
                                </div>
                            </div>
                            <?php $freq = $caso['frecuencia_pago'] ?? 'mensual'; ?>
                            <div class="col-sm-4">
                                <label class="cv-label">Frecuencia</label>
                                <div class="cs-w" id="csFreqW">
                                  <div class="cs-btn hv" id="csFreqBtn"><span id="csFreqLbl"><?php echo ucfirst($freq); ?></span></div>
                                  <svg class="cs-arr" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                                  <div class="cs-drop" id="csFreqDrop">
                                    <div class="cs-item <?php echo $freq==='mensual'?'sel':'';?>" data-val="mensual" data-nom="Mensual">Mensual (30 días)</div>
                                    <div class="cs-item <?php echo $freq==='quincenal'?'sel':'';?>" data-val="quincenal" data-nom="Quincenal">Quincenal (15 días)</div>
                                    <div class="cs-item <?php echo $freq==='semanal'?'sel':'';?>" data-val="semanal" data-nom="Semanal">Semanal (7 días)</div>
                                  </div>
                                  <input type="hidden" name="frecuencia_pago" id="csFreqHid" value="<?php echo $freq; ?>">
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <label class="cv-label">Fecha 1ª Cuota</label>
                                <input type="date" name="fecha_inicio_cuotas" class="cv-input" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        <div id="cuotaHelper" style="font-size:.8125rem;color:#059669;font-weight:600;margin-top:8px"></div>
                    </div>

                    <!-- FECHAS CUSTOM -->
                    <div class="col-12" id="wrapCustom" style="<?php echo $tpc !== 'fechas_custom' ? 'display:none' : ''; ?>">
                        <label class="cv-label">Fechas y Montos Personalizados</label>
                        <div id="customRows">
                            <div style="display:flex;gap:8px;margin-bottom:8px">
                                <input type="date" name="fechas_custom[]" class="cv-input" style="flex:1">
                                <input type="number" name="montos_custom[]" class="cv-input" style="flex:1" step="0.01" placeholder="Monto €">
                                <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:1.2rem;padding:0 8px">&times;</button>
                            </div>
                        </div>
                        <button type="button" onclick="addCustomRow()" class="cv-btn cv-btn-ghost" style="width:auto;padding:6px 14px;font-size:.8125rem;margin-top:4px">+ Añadir Fecha</button>
                    </div>
                    <div class="col-12"><label class="form-label">Descripción</label><textarea name="descripcion" class="form-control" rows="3"><?php echo e($caso['descripcion']); ?></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary radius-8" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary radius-8">Guardar</button></div>
        </form>
    </div>
</div>




<script>
// Generic CS init
function initCS(wId,btnId,dropId,lblId,hidId,onSelect){
  const btn=document.getElementById(btnId),drop=document.getElementById(dropId),lbl=document.getElementById(lblId),hid=document.getElementById(hidId);
  if(!btn)return;
  function pos(){const r=btn.getBoundingClientRect();drop.style.top=(r.bottom+window.scrollY+4)+'px';drop.style.left=(r.left+window.scrollX)+'px';drop.style.width=r.width+'px';document.body.appendChild(drop);}
  btn.addEventListener('click',()=>{pos();btn.classList.toggle('op');drop.classList.toggle('op');});
  drop.querySelectorAll('.cs-item').forEach(i=>{
    i.addEventListener('click',()=>{
      hid.value=i.dataset.val;if(lbl)lbl.textContent=i.dataset.nom;
      const dot=btn.querySelector('.cs-dot')||document.getElementById(btnId.replace('Btn','Dot'));
      if(dot&&i.dataset.dot)dot.style.background=i.dataset.dot;
      drop.querySelectorAll('.cs-item').forEach(x=>x.classList.remove('sel'));i.classList.add('sel');
      btn.classList.remove('op');drop.classList.remove('op');
      if(onSelect)onSelect(i.dataset.val,i);
    });
  });
  document.addEventListener('click',e=>{if(!e.target.closest('#'+wId)&&!e.target.closest('#'+dropId)){btn.classList.remove('op');drop.classList.remove('op');}});
}
// Abogado select (modal editar caso)
(function(){
  const btn=document.getElementById('csModalBtn'),drop=document.getElementById('csModalDrop'),lbl=document.getElementById('csModalLbl'),hid=document.getElementById('csModalHid');
  if(!btn)return;
  function pos(){const r=btn.getBoundingClientRect();drop.style.top=(r.bottom+window.scrollY+4)+'px';drop.style.left=(r.left+window.scrollX)+'px';drop.style.width=r.width+'px';document.body.appendChild(drop);}
  btn.addEventListener('click',()=>{pos();btn.classList.toggle('op');drop.classList.toggle('op');});
  drop.querySelectorAll('.cs-item').forEach(i=>{
    i.addEventListener('click',()=>{
      hid.value=i.dataset.val;lbl.textContent=i.dataset.nom;
      const av=btn.querySelector('.cs-av');
      if(i.dataset.ini){if(av)av.textContent=i.dataset.ini;else{const d=document.createElement('div');d.className='cs-av';d.style.cssText='width:22px;height:22px;border-radius:6px;background:linear-gradient(135deg,#2e6edd,#6ba3ff);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.625rem;font-weight:800;margin-right:6px';d.textContent=i.dataset.ini;btn.insertBefore(d,btn.firstChild);}}
      else{if(av)av.remove();}
      drop.querySelectorAll('.cs-item').forEach(x=>x.classList.remove('sel'));i.classList.add('sel');
      btn.classList.remove('op');drop.classList.remove('op');
    });
  });
  document.addEventListener('click',e=>{if(!e.target.closest('#csModalW')&&!e.target.closest('#csModalDrop')){btn.classList.remove('op');drop.classList.remove('op');}});
})();
// Estado
initCS('csEstW','csEstBtn','csEstDrop','csEstLbl','csEstHid');
// Tipo de Pago
initCS('csTipoPagoW','csTipoPagoBtn','csTipoPagoDrop','csTipoPagoLbl','csTipoPagoHid',function(v){
  document.getElementById('wrapPagoUnico').style.display=v==='pago_unico'?'block':'none';
  document.getElementById('wrapCuotas').style.display=v==='cuotas'?'block':'none';
  document.getElementById('wrapCustom').style.display=v==='fechas_custom'?'block':'none';
  calcCuota();
});
// Cuotas
initCS('csCuotasW','csCuotasBtn','csCuotasDrop','csCuotasLbl','csCuotasHid',calcCuota);
// Frecuencia
initCS('csFreqW','csFreqBtn','csFreqDrop','csFreqLbl','csFreqHid');
// Calc cuota helper
function calcCuota(){
  const h=parseFloat(document.getElementById('finHonorarios')?.value)||0;
  const n=parseInt(document.getElementById('csCuotasHid')?.value)||1;
  const el=document.getElementById('cuotaHelper');
  if(el&&h>0)el.textContent='\u2248 \u20ac'+(h/n).toLocaleString('es-ES',{minimumFractionDigits:2,maximumFractionDigits:2})+' / cuota';
  else if(el)el.textContent='';
}
function addCustomRow(){
  const c=document.getElementById('customRows');
  const d=document.createElement('div');d.style.cssText='display:flex;gap:8px;margin-bottom:8px';
  d.innerHTML='<input type="date" name="fechas_custom[]" class="cv-input" style="flex:1"><input type="number" name="montos_custom[]" class="cv-input" style="flex:1" step="0.01" placeholder="Monto \u20ac"><button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:1.2rem;padding:0 8px">&times;</button>';
  c.appendChild(d);
}
document.addEventListener('DOMContentLoaded',()=>{
  document.getElementById('finHonorarios')?.addEventListener('input',calcCuota);
  calcCuota();
});
</script>

<!-- Scripts para Notas y Tagify -->
<script src="https://cdn.jsdelivr.net/npm/@yaireo/tagify"></script>
<link href="https://cdn.jsdelivr.net/npm/@yaireo/tagify/dist/tagify.css" rel="stylesheet" type="text/css" />
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Configurar Tagify para Tipo de Caso
    const inputTipoCaso = document.querySelector('#inputTipoCaso');
    if (inputTipoCaso) {
        new Tagify(inputTipoCaso, {
            maxTags: 1, // Solo un tipo principal o varios si quieren, la base de datos es VARCHAR
            dropdown: {
                maxItems: 20,
                classname: "tags-look",
                enabled: 0,
                closeOnSelect: true
            }
        });
    }

    // Editar nota
    document.querySelectorAll('.btn-editar-nota').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const titulo = btn.dataset.titulo;
            const contenido = btn.dataset.contenido;
            const tipo = btn.dataset.tipo;
            
            document.getElementById('editar_nota_id').value = id;
            document.getElementById('editar_nota_titulo').value = titulo;
            document.getElementById('editar_nota_contenido').value = contenido;
            document.getElementById('editar_tipo_nota').value = tipo;
            
            new bootstrap.Modal(document.getElementById('modalEditarNota')).show();
        });
    });
});
</script>

<!-- ===== Modal de confirmación personalizado ===== -->
<div id="crmConfirmOverlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:99999;background:rgba(15,23,42,.6);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);">
  <div style="display:flex;align-items:center;justify-content:center;min-height:100%;padding:16px;">
    <div id="crmConfirmBox" style="background:#ffffff;border-radius:20px;padding:32px;max-width:400px;width:100%;box-shadow:0 32px 64px rgba(0,0,0,.22);position:relative;">
      <!-- Icon -->
      <div style="display:flex;align-items:flex-start;gap:16px;margin-bottom:24px;">
        <div id="crmConfirmIcon" style="width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:22px;">?</div>
        <div style="padding-top:2px;flex:1;">
          <div id="crmConfirmTitle" style="font-size:1.0625rem;font-weight:800;color:#0f172a;margin-bottom:6px;line-height:1.3;">Confirmar</div>
          <div id="crmConfirmMsg" style="font-size:.875rem;color:#64748b;line-height:1.6;"></div>
        </div>
      </div>
      <!-- Buttons -->
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button id="crmConfirmCancel" type="button" style="padding:10px 22px;border-radius:10px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#475569;font-size:.875rem;font-weight:600;cursor:pointer;">Cancelar</button>
        <button id="crmConfirmOk" type="button" style="padding:10px 22px;border-radius:10px;border:none;background:#2563eb;color:#fff;font-size:.875rem;font-weight:700;cursor:pointer;">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<script>
function crmConfirm(title, message, onConfirm, isDanger) {
  var overlay = document.getElementById('crmConfirmOverlay');
  var icon    = document.getElementById('crmConfirmIcon');
  var ttl     = document.getElementById('crmConfirmTitle');
  var msg     = document.getElementById('crmConfirmMsg');
  var okBtn   = document.getElementById('crmConfirmOk');
  var cancelBtn = document.getElementById('crmConfirmCancel');

  ttl.textContent = title;
  msg.innerHTML   = message;

  if (isDanger) {
    icon.style.background = '#fef2f2';
    icon.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';
    okBtn.style.background = '#ef4444';
    okBtn.textContent = 'Eliminar';
  } else {
    icon.style.background = '#eff6ff';
    icon.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r="0.5" fill="#2563eb"/></svg>';
    okBtn.style.background = '#2563eb';
    okBtn.textContent = 'Confirmar';
  }

  overlay.style.display = 'block';

  // Clone buttons to remove old listeners
  var newOk = okBtn.cloneNode(true);
  var newCancel = cancelBtn.cloneNode(true);
  okBtn.parentNode.replaceChild(newOk, okBtn);
  cancelBtn.parentNode.replaceChild(newCancel, cancelBtn);

  function close() { overlay.style.display = 'none'; }

  newOk.addEventListener('click', function() { close(); onConfirm(); });
  newCancel.addEventListener('click', close);
  overlay.addEventListener('click', function(e) { if (e.target === overlay || e.target.parentElement === overlay) close(); });
}
</script>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>


