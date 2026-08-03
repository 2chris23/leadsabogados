<?php
$file = 'pages/casos/ver.php';
$content = file_get_contents($file);

// Find the boundaries
$p1 = strpos($content, '    <?php if (RoleGuard::esAdmin()): ?>' . PHP_EOL . '    <!-- Financiero -->');
$p2 = strpos($content, '    <!-- Documentos -->');
$p3 = strpos($content, '  </div>' . PHP_EOL . PHP_EOL . PHP_EOL . '  <div>' . PHP_EOL . '    <?php if (RoleGuard::esAdmin()): ?>' . PHP_EOL . '    <!-- Historial -->');

if ($p1 !== false && $p2 !== false && $p3 !== false) {
    // We want to move everything from $p2 to $p3 to be right BEFORE $p1
    
    $part_before_financiero = substr($content, 0, $p1);
    $financiero_and_calendario = substr($content, $p1, $p2 - $p1);
    $documentos = substr($content, $p2, $p3 - $p2);
    $part_after_documentos = substr($content, $p3);
    
    // The new structure:
    // ...
    //   </div> <!-- Notas end -->
    //   <!-- Documentos -->
    //   ...
    // </div> <!-- Close left col -->
    // <div> <!-- Open right col -->
    //   <!-- Financiero -->
    //   ...
    //   <!-- Historial -->
    
    // Adjust Documentos to be appended to the first column, then close the first column, open the second column.
    
    $new_content = $part_before_financiero . $documentos . "  </div>\n\n  <div>\n" . $financiero_and_calendario . "    <?php if (RoleGuard::esAdmin()): ?>\n    <!-- Historial -->" . substr($part_after_documentos, strlen("  </div>\n\n\n  <div>\n    <?php if (RoleGuard::esAdmin()): ?>\n    <!-- Historial -->"));
    
    file_put_contents($file, $new_content);
    echo "Layout updated successfully.";
} else {
    echo "Could not find boundaries: p1=$p1, p2=$p2, p3=$p3";
}
