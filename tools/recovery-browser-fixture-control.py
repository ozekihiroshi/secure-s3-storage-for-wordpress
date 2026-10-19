#!/usr/bin/env python3
"""Inspect or remove only resources recorded by the disposable browser fixture."""
import pathlib, json, sys, hashlib
root=pathlib.Path(__file__).resolve().parents[1]
import importlib.util
spec=importlib.util.spec_from_file_location('fixture',root/'tools/test-recovery-browser-local.py')
fixture=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixture)
state=fixture.state
s=json.loads(state.read_text())
for key in ('network','volume','private','db'):setattr(fixture,key,s[key])
run,wp,db=fixture.run,fixture.wp,s['db']
if '--stop' in sys.argv:
    for kind,item in reversed(s['created']):
        if not item.startswith('odb-status-'):raise RuntimeError('Unexpected resource')
        run('docker',kind,'rm',*(['-f','-v'] if kind=='container' else []),item)
    state.unlink()
elif '--pending' in sys.argv:
    print(wp('eval-file','/repo/tests/manual/seed-recovery-browser-cleanup.php',s['set_id']))
elif '--cron' in sys.argv:
    print(wp('cron','event','list','--format=json'))
else:
    sql="SELECT option_name,option_value,autoload FROM wp_options WHERE option_name LIKE 'odbfs3_%' OR option_name LIKE 'secure_s3_%' OR option_name IN ('cron','active_plugins') ORDER BY option_name"
    data=run('docker','exec',db,'mariadb','-uroot','-pfixture-only','--batch','--skip-column-names','fixture','-e',sql)
    files=wp('eval-file','/repo/tests/manual/seed-cleanup-plan.php',s['set_id'],'snapshot')
    print(json.dumps({'options_sha256':hashlib.sha256(data.encode()).hexdigest(),'workspace':files}))

