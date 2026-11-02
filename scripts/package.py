from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED, ZipInfo
import argparse
import hashlib
import re
import os
import subprocess
from datetime import datetime, timezone
root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--channel', choices=['direct', 'woo-marketplace'], default='direct')
args = parser.parse_args()
slug = 'wd29woobridge'
files = [root / 'includes' / 'admin-design.css', root / 'includes' / 'admin-design.js', root / 'includes' / 'product-links-admin.js', root / 'includes' / 'record-panel-admin.js'] + [root / name for name in ['wd29woobridge.php', 'README.md', 'CHANGELOG.md', 'LICENSE', 'OPERATIONS.md', 'ACCEPTANCE.md', 'SUPPLIERS.md', 'GALLERY.md', 'docs/KERAWEN-COMMERCE.md']]
for directory in ['includes', 'controllers', 'upgrade']:
    files.extend(sorted((root / directory).rglob('*.php')))
main = (root / (slug + '.php')).read_text()
version = re.search(r"Version: ([0-9.]+)|define\('WD29_WOOBRIDGE_VERSION', '([0-9.]+)'\)", main)
version = version.group(1) or version.group(2)
output = root / 'dist' / (args.channel if args.channel != 'direct' else '') / (slug + '-' + version + '.zip')
output.parent.mkdir(parents=True, exist_ok=True)
# A fixed timestamp shared by different releases can leave PHP OPcache serving
# the previous included files after a native module import. Releases keep a
# reproducible commit timestamp; source-only builds may set SOURCE_DATE_EPOCH.
release_epoch = os.environ.get('SOURCE_DATE_EPOCH')
if release_epoch is None:
    try:
        release_epoch = subprocess.check_output(['git', 'log', '-1', '--format=%ct'], cwd=root, stderr=subprocess.DEVNULL).decode().strip()
    except (OSError, subprocess.CalledProcessError):
        release_epoch = str(int(max(file.stat().st_mtime for file in files)))
release_time = datetime.fromtimestamp(int(release_epoch), timezone.utc).timetuple()[:6]
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    for file in files:
        relative = file.relative_to(root)
        data = file.read_bytes()
        if str(relative) == 'includes/Distribution.php':
            assert data.count(b"const CHANNEL = 'direct';") == 1
            data = data.replace(b"const CHANNEL = 'direct';", ("const CHANNEL = '" + args.channel + "';").encode())
        info = ZipInfo(str(Path(slug) / relative), release_time)
        info.compress_type = ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, data)
# Native module discovery may retain a newer-dated generated config_fr.xml
# from an older loaded class during upload. Ship current metadata explicitly.
metadata = ('<?xml version="1.0" encoding="UTF-8"?>\n<module>'
            '<name>wd29woobridge</name><displayName><![CDATA[Inklura Sync]]></displayName>'
            '<version><![CDATA[' + version + ']]></version>'
            '<description><![CDATA[Synchronisation PrestaShop ↔ WooCommerce : catalogue, stocks, commandes et clients.]]></description>'
            '<author><![CDATA[Inklura]]></author><tab>administration</tab>'
            '<is_configurable>1</is_configurable><need_instance>0</need_instance></module>\n').encode()
with ZipFile(output, 'a', ZIP_DEFLATED) as archive:
    for name in ['config.xml', 'config_fr.xml']:
        info = ZipInfo(str(Path(slug) / name), release_time)
        info.compress_type = ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, metadata)
digest = hashlib.sha256(output.read_bytes()).hexdigest()
output.with_suffix('.zip.sha256').write_text(digest + '  ' + output.name + '\n')
print(output)
print(digest)
