<?php
$zip = new ZipArchive();
if ($zip->open(__DIR__ . "/sistema_actualizado.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    
    $files = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS),
            function ($current, $key, $iterator) {
                $name = $current->getFilename();
                if ($name === ".git" || $name === ".gemini" || $name === "sistema_actualizado.zip" || $name === "zip_script.php") return false;
                
                $path = str_replace(DIRECTORY_SEPARATOR, "/", $current->getPathname());
                $rootPath = str_replace(DIRECTORY_SEPARATOR, "/", __DIR__ . "/");
                $relPath = str_replace($rootPath, "", $path);
                
                if (strpos($relPath, "portal/") === 0 || $relPath === "index.php" || $relPath === "descargar.php") {
                    return true;
                }
                return false;
            }
        )
    );

    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen(__DIR__) + 1);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, "/", $relativePath);
            $zip->addFile($filePath, $relativePath);
        }
    }
    $zip->close();
    echo "Zip created with forward slashes.";
} else {
    echo "Failed to create zip.";
}
