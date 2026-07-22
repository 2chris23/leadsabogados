import zipfile
import os

with zipfile.ZipFile('landing_original.zip', 'w') as zipf:
    zipf.write('index_para_landing.php', 'index.php')
