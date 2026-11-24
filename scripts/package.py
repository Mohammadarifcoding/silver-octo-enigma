"""Package local website source and a consistent SQLite snapshot for download."""
from contextlib import closing
from pathlib import Path
import sqlite3
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
settings = {}
for line in (ROOT / '.env').read_text(encoding='utf-8').splitlines():
    if '=' in line and not line.lstrip().startswith('#'):
        key, value = line.split('=', 1)
        settings[key.strip()] = value.strip().strip('"\'')
database = Path(settings.get('SQLITE_PATH', 'data/college.sqlite'))
if not database.is_absolute():
    database = ROOT / database
if not database.is_file():
    raise SystemExit('Start the website once with php run.php before packaging its database.')

output = ROOT / 'downloads' / 'college-website.zip'
output.parent.mkdir(exist_ok=True)
files = set(ROOT.glob('*.html'))
for name in ['.env', '.env.example', '.gitignore', 'README.md', 'run.php', 'start.bat']:
    files.add(ROOT / name)
for directory in ['assets', 'public', 'server', 'tests', 'scripts', 'screenshots']:
    files.update(path for path in (ROOT / directory).rglob('*') if path.is_file() and '__pycache__' not in path.parts)

with tempfile.TemporaryDirectory(prefix='kpi-package-') as temp:
    snapshot = Path(temp) / 'college.sqlite'
    with closing(sqlite3.connect(database)) as source, closing(sqlite3.connect(snapshot)) as destination:
        source.backup(destination)
        destination.execute('DELETE FROM request_limits')
        destination.commit()
        assert destination.execute('PRAGMA integrity_check').fetchone()[0] == 'ok'
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
        for file in sorted(files):
            archive.write(file, 'college-website/' + file.relative_to(ROOT).as_posix())
        archive.write(snapshot, 'college-website/data/college.sqlite')
print(f'Ready: {output} ({output.stat().st_size / 1024 / 1024:.1f} MB)')
