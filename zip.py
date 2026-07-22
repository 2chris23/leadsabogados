
import os
import zipfile
import stat

zip_filename = "c:/xampp/htdocs/abogados/sistema_actualizado.zip"
if os.path.exists(zip_filename):
    os.remove(zip_filename)

with zipfile.ZipFile(zip_filename, "w", zipfile.ZIP_DEFLATED) as zf:
    for root, dirs, files in os.walk("c:/xampp/htdocs/abogados"):
        if ".git" in root or ".gemini" in root:
            continue
            
        for file in files:
            if file in ["sistema_actualizado.zip", "zip.py", "zip.ps1", "zip_script.php"]:
                continue
                
            file_path = os.path.join(root, file)
            rel_path = os.path.relpath(file_path, "c:/xampp/htdocs/abogados").replace(os.sep, "/")
            
            if rel_path.startswith("portal/") or rel_path in ["index.php", "descargar.php", "limpiar_plesk.php", "diagnostico.php"]:
                info = zipfile.ZipInfo(rel_path)
                # Set permissions to 644 for files
                info.external_attr = (stat.S_IFREG | 0o644) << 16
                with open(file_path, "rb") as f:
                    zf.writestr(info, f.read())

