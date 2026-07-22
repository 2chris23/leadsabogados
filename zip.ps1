
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zipPath = "c:\xampp\htdocs\abogados\sistema_actualizado.zip"
if (Test-Path $zipPath) { Remove-Item $zipPath }
$zip = [System.IO.Compression.ZipFile]::Open($zipPath, "Create")
$base = "c:\xampp\htdocs\abogados\"
Get-ChildItem -Path $base -Recurse | Where-Object { 
    $_.FullName -match "\\portal" -or $_.Name -in "index.php", "descargar.php" 
} | Where-Object { -not $_.PSIsContainer -and $_.FullName -notmatch "\.git|\.gemini|sistema_actualizado" } | ForEach-Object {
    $relPath = $_.FullName.Substring($base.Length).Replace("\", "/")
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $relPath)
}
$zip.Dispose()

