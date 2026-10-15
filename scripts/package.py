from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED, ZipInfo
import argparse
import hashlib
import re
root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--channel', choices=['direct', 'woo-marketplace'], default='direct')
args = parser.parse_args()
slug = 'wd29woobridge'
files = [root / 'includes' / 'admin-design.css', root / 'includes' / 'admin-design.js', root / 'includes' / 'product-links-admin.js', root / 'includes' / 'record-panel-admin.js'] + [root / name for name in ['wd29woobridge.php', 'README.md', 'CHANGELOG.md', 'LICENSE', 'OPERATIONS.md', 'ACCEPTANCE.md', 'SUPPLIERS.md', 'GALLERY.md']]
for directory in ['includes', 'controllers']:
    files.extend(sorted((root / directory).rglob('*.php')))
main = (root / (slug + '.php')).read_text()
version = re.search(r"Version: ([0-9.]+)|define\('WD29_WOOBRIDGE_VERSION', '([0-9.]+)'\)", main)
version = version.group(1) or version.group(2)
output = root / 'dist' / (args.channel if args.channel != 'direct' else '') / (slug + '-' + version + '.zip')
output.parent.mkdir(parents=True, exist_ok=True)
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    for file in files:
        relative = file.relative_to(root)
        data = file.read_bytes()
        if str(relative) == 'includes/Distribution.php':
            assert data.count(b"const CHANNEL = 'direct';") == 1
            data = data.replace(b"const CHANNEL = 'direct';", ("const CHANNEL = '" + args.channel + "';").encode())
        info = ZipInfo(str(Path(slug) / relative), (2026, 10, 13, 0, 0, 0))
        info.compress_type = ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, data)
digest = hashlib.sha256(output.read_bytes()).hexdigest()
output.with_suffix('.zip.sha256').write_text(digest + '  ' + output.name + '\n')
print(output)
print(digest)
