#!/usr/bin/env python3
"""Run offline retention/integrity regressions with the actual scoped ZIP runtime."""
import hashlib, json, pathlib, re, subprocess, sys, tempfile, zipfile

root = pathlib.Path(__file__).resolve().parents[1]
archive = pathlib.Path(sys.argv[1]).resolve()
report = pathlib.Path(sys.argv[2]).resolve()
with tempfile.TemporaryDirectory(prefix='odbfs3-retention-package-') as temporary:
    base = pathlib.Path(temporary)
    with zipfile.ZipFile(archive) as z:
        files = [n for n in z.namelist() if not n.endswith('/')]
        assert all(n.startswith('ozeki-database-backup-for-s3/') and '..' not in pathlib.PurePosixPath(n).parts for n in files)
        expected = {n: hashlib.sha256(z.read(n)).hexdigest() for n in files}
        z.extractall(base)
    runtime = base / 'ozeki-database-backup-for-s3'
    tests = runtime / 'tests/manual'
    tests.mkdir(parents=True)
    names = ['test-retention-safety.php', 'test-database-integrity.php', 'database-s3-test-transport.php']
    for name in names:
        content = (root / 'tests/manual' / name).read_text()
        # Only the external fixture's SDK names change; packaged runtime is untouched.
        content = re.sub(r'(?<![A-Za-z0-9_\\])(\\?)(Aws|GuzzleHttp)\\',
                         lambda m: m[1] + 'SecureS3StorageForWordpressVendor\\' + m[2] + '\\', content)
        (tests / name).write_text(content)
    outputs = []
    for name in names[:2]:
        p = subprocess.run(['php', '-d', 'memory_limit=32M', str(tests / name)], text=True, capture_output=True)
        if p.returncode: raise RuntimeError(p.stdout + p.stderr)
        outputs.append(p.stdout.strip())
    assert all(hashlib.sha256((base / n).read_bytes()).hexdigest() == h for n, h in expected.items())
    result = {'passed': True, 'zip_sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
              'all_packaged_files_unchanged': True, 'packaged_files': len(files), 'results': outputs}
    report.parent.mkdir(parents=True, exist_ok=True)
    report.write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result))
