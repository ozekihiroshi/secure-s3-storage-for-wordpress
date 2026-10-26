#!/usr/bin/env python3
"""Exercise only synthetic keys on the existing S3 test host, using one fixed ZIP."""
import hashlib, json, pathlib, re, subprocess, sys

root = pathlib.Path(__file__).resolve().parents[1]
archive = pathlib.Path(sys.argv[1]).resolve()
destination = pathlib.Path(sys.argv[2]).resolve()
digest = hashlib.sha256(archive.read_bytes()).hexdigest()

def run(args):
    p = subprocess.run(args, text=True, capture_output=True, timeout=180)
    if p.returncode:
        raise RuntimeError('Fixture command failed: ' + args[0] + '\n' + p.stderr[-1000:])
    return p.stdout.strip()

ssh = ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', 'community']
remote = run(ssh + ['mktemp -d /tmp/odbfs3-retention-XXXXXXXX'])
assert re.fullmatch(r'/tmp/odbfs3-retention-[A-Za-z0-9]+', remote)
run(['scp', '-q', str(archive), 'community:' + remote + '/candidate.zip'])
run(['scp', '-q', str(root / 'tools/test-retention-s3-release.php'), 'community:' + remote + '/test.php'])
prepare = '''import hashlib,json,pathlib,zipfile
p=pathlib.Path(%r)
assert hashlib.sha256((p/'candidate.zip').read_bytes()).hexdigest()==%r
with zipfile.ZipFile(p/'candidate.zip') as z:
 files=[n for n in z.namelist() if not n.endswith('/')]
 assert all(n.startswith('ozeki-database-backup-for-s3/') and '..' not in pathlib.PurePosixPath(n).parts for n in files)
 z.extractall(p)
 expected={n:hashlib.sha256(z.read(n)).hexdigest() for n in files}
(p/'hashes.json').write_text(json.dumps(expected))
''' % (remote, digest)
p = subprocess.run(ssh + ['python3 -'], input=prepare, text=True, capture_output=True, timeout=180)
if p.returncode: raise RuntimeError('ZIP verification/extraction failed')
print('Remote fixture: ' + remote, flush=True)
try:
    result = json.loads(run(ssh + ['php ' + remote + '/test.php ' + remote + '/ozeki-database-backup-for-s3 ' + digest]))
    verify = '''import hashlib,json,pathlib
p=pathlib.Path(%r)
assert all(hashlib.sha256((p/n).read_bytes()).hexdigest()==h for n,h in json.loads((p/'hashes.json').read_text()).items())
print('verified')
''' % remote
    p = subprocess.run(ssh + ['python3 -'], input=verify, text=True, capture_output=True, timeout=180)
    assert p.returncode == 0 and p.stdout.strip() == 'verified'
    result['all_packaged_files_unchanged'] = True
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result), flush=True)
finally:
    # Preserve exact fixture keys and private evidence for inspection; never broad-clean S3.
    print('Private fixture and remaining synthetic S3 objects retained for evidence.', flush=True)
