// Isolated, generated-data fixture only. Uses an independent browser profile.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const fixture=JSON.parse(fs.readFileSync(process.argv[2],'utf8').replace(/^\uFEFF/,''));
const output=process.argv[3];fs.mkdirSync(output,{recursive:true});
const base='http://localhost:18763',root=base+'/wp-admin/options-general.php?page=odbfs3-recovery-sets';
const checks=[];function check(ok,label){assert.ok(ok,label);checks.push(label);}
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.BROWSER_EXECUTABLE,headless:true});
 try {
  async function context(){const c=await browser.newContext({viewport:{width:1440,height:1100}});await c.route('**/*',r=>new URL(r.request().url()).hostname==='localhost'?r.continue():r.abort());return c;}
  async function login(p,user){await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(user);await p.locator('#user_pass').fill('fixture-only-long-password');await Promise.all([p.waitForURL(u=>u.pathname.startsWith('/wp-admin/')),p.locator('#wp-submit').click()]);}
  const admin=await context(),p=await admin.newPage();let errors=[];p.on('pageerror',e=>{errors.push({url:p.url(),name:e.name,message:e.message,stack:e.stack});fs.writeFileSync(path.join(output,'page-errors.json'),JSON.stringify(errors,null,2));});await login(p,'fixture');
  const viewer=await context(),q=await viewer.newPage();await login(q,'fixtureviewer');
  await p.goto(root+'&set_id='+fixture.set_id);
  if(process.argv.includes('--warmup')){console.log('PASS browser initialization only');return;}
  await p.goto(base+'/wp-admin/options-general.php');await p.getByRole('link',{name:'Recovery Sets',exact:true}).click();
  await p.getByRole('link',{name:fixture.set_id,exact:true}).click();
  const form=page=>page.locator('form[method="post"]').filter({has:page.locator('input[value="odbfs3_save_schedule_expectation"]')});
  const source=await form(p).locator('[name="source_id"]').inputValue();const url=root+'&source_id='+source;
  check(await form(p).count()===1,'one dedicated settings form');
  if(!process.argv.includes('--existing'))check(await form(p).locator('[name="mode"]').inputValue()==='stopped','new form defaults stopped');
  check((await p.locator('body').innerText()).includes('実Cronの起動・停止・周期'),'scope distinction visible');
  const future=new Date(Date.now()+2*86400000).toISOString().slice(0,16);
  async function submit(page,expected=200){
   const response=page.waitForResponse(r=>r.url().includes('/wp-admin/admin-post.php')&&r.request().method()==='POST');
   await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form(page).locator('button[type="submit"]').click()]);const r=await response;
   check(r.status()===(expected===200?302:expected),'POST status '+expected);
   if(expected===200){await page.waitForURL(u=>u.searchParams.get('schedule_saved')==='1');await page.locator('h1').waitFor();}
   else await page.locator('body').filter({hasText:'保存を確認できませんでした'}).waitFor();
  }
  await form(p).locator('[name="mode"]').selectOption('enabled');await form(p).locator('[name="anchor"]').fill(future);
  await form(p).locator('[name="interval"]').fill('86400');await form(p).locator('[name="start_grace"]').fill('600');await form(p).locator('[name="completion_grace"]').fill('7200');
  await form(p).locator('[name="acknowledge"]').check();await submit(p);
  check(await form(p).locator('[name="mode"]').inputValue()==='enabled','enabled persisted via browser');
  check((await p.locator('body').innerText()).includes('初回予定前'),'saved policy assessment displayed');
  const stale=await admin.newPage();await stale.goto(url);
  await form(p).locator('[name="interval"]').fill('172800');await form(p).locator('[name="start_grace"]').fill('120');await form(p).locator('[name="completion_grace"]').fill('3600');await form(p).locator('[name="acknowledge"]').check();await submit(p);
  check(await form(p).locator('[name="interval"]').inputValue()==='172800' && await form(p).locator('[name="start_grace"]').inputValue()==='120' && await form(p).locator('[name="completion_grace"]').inputValue()==='3600','period and grace persisted');
  await form(stale).locator('[name="interval"]').fill('86400');await form(stale).locator('[name="acknowledge"]').check();await submit(stale,400);
  await stale.screenshot({path:path.join(output,'stale-rejected.png'),fullPage:true});
  await p.goto(url);check(await form(p).locator('[name="interval"]').inputValue()==='172800','stale page did not overwrite');
  await form(p).locator('[name="mode"]').selectOption('stopped');await form(p).locator('[name="acknowledge"]').check();await submit(p);
  check((await p.locator('body').innerText()).includes('予定判定を停止中'),'assessment stopped');
  await p.screenshot({path:path.join(output,'stopped.png'),fullPage:true});
  await form(p).locator('[name="mode"]').selectOption('enabled');await form(p).locator('[name="anchor"]').fill('2020-01-01T00:00');await form(p).locator('[name="acknowledge"]').check();await submit(p,400);
  await p.goto(url);check(await form(p).locator('[name="mode"]').inputValue()==='stopped','invalid resume kept stopped');
  await form(p).locator('[name="mode"]').selectOption('enabled');await form(p).locator('[name="anchor"]').fill(future);await form(p).locator('[name="acknowledge"]').check();await submit(p);
  check(await form(p).locator('[name="mode"]').inputValue()==='enabled','resume with future anchor');
  await p.screenshot({path:path.join(output,'resumed.png'),fullPage:true});
  await form(p).locator('[name="completion_grace"]').fill('200000');await form(p).locator('[name="acknowledge"]').check();await submit(p,400);
  await p.goto(url);check(await form(p).locator('[name="completion_grace"]').inputValue()==='3600','invalid grace no change');
  await form(p).locator('[name="_wpnonce"]').evaluate(e=>e.value='invalid');await form(p).locator('[name="acknowledge"]').check();await submit(p,403);
  await p.goto(url);await form(p).evaluate(e=>e.noValidate=true);await submit(p,400);
  await p.goto(url);
  const payload=await form(p).evaluate(e=>Object.fromEntries(new FormData(e)));payload.acknowledge='1';payload.mode='stopped';
  check(await q.getByRole('link',{name:'Recovery Sets',exact:true}).count()===0,'subscriber menu absent');
  check((await q.goto(url)).status()===403,'subscriber direct GET denied');
  const denied=await q.evaluate(async data=>{const r=await fetch('/wp-admin/admin-post.php',{method:'POST',body:new URLSearchParams(data)});return r.status;},payload);
  check(denied===403,'subscriber POST denied even with admin payload');
  const guest=await context(),g=await guest.newPage();await g.goto(url);check(g.url().includes('wp-login.php'),'guest GET requires login');
  const guestCode=await g.evaluate(async data=>(await fetch('/wp-admin/admin-post.php',{method:'POST',body:new URLSearchParams(data)})).status,payload);
  check(guestCode>=400,'guest POST cannot save');
  await p.goto(url);check(await form(p).locator('[name="mode"]').inputValue()==='enabled','unauthorized requests preserved policy');
  fs.writeFileSync(path.join(output,'functional-result.json'),JSON.stringify({passed:true,browser:browser.version(),source_id:source,checks,errors},null,2));
  // Edge reports a native cross-document transition cancellation when WP returns
  // a deliberate wp_die error page. Preserve it, but do not call it plugin JS.
  const unexpected=errors.filter(e=>!(e.name==='InvalidStateError' && e.message==='Transition was aborted because of invalid state. ViewTransition opt-in disabled' && e.url===base+'/wp-admin/admin-post.php'));
  check(unexpected.length===0,'no unexpected browser script errors');
  fs.writeFileSync(path.join(output,'result.json'),JSON.stringify({passed:true,browser:browser.version(),source_id:source,checks,browser_observations:errors,scope:'isolated full plugin; generated data; actual browser GET/POST'},null,2));
  console.log(JSON.stringify({passed:true,checks:checks.length}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
