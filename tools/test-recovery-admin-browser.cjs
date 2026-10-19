// Run with PLAYWRIGHT_MODULE and BROWSER_EXECUTABLE pointing to installed test tooling.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const state=JSON.parse(fs.readFileSync(process.argv[2],'utf8').replace(/^\uFEFF/,''));
const output=process.argv[3];fs.mkdirSync(output,{recursive:true});
const base='http://localhost:18763',url=base+'/wp-admin/options-general.php?page=odbfs3-recovery-sets';
let checks=[];
function check(ok,name){assert.ok(ok,name);checks.push(name)}
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.BROWSER_EXECUTABLE,headless:true});
 try {
 const ctx=await browser.newContext({viewport:{width:1440,height:1000}});
 await ctx.route('**/*',r=>new URL(r.request().url()).hostname==='localhost'?r.continue():r.abort());
 const p=await ctx.newPage();let errors=[];p.on('pageerror',e=>errors.push(e.message));
 async function login(page,user){await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill(user);await page.locator('#user_pass').fill('fixture-only-long-password');await Promise.all([page.waitForURL(u=>u.pathname.startsWith('/wp-admin/')),page.locator('#wp-submit').click()]);}
 await login(p,'fixture');
 await p.goto(base+'/wp-admin/options-general.php');
 await p.getByRole('link',{name:'Recovery Sets',exact:true}).click();
 check(await p.locator('h1').innerText()==='Recovery Sets','administrator menu opens page');
 let wrap=p.locator('#wpbody-content .wrap');
 check((await wrap.innerText()).includes('complete')&&(await wrap.innerText()).includes('failed'),'complete and failed rows visible');
 await p.screenshot({path:path.join(output,'inventory.png'),fullPage:true});
 await p.getByRole('link',{name:state.set_id,exact:true}).click();
 let text=await wrap.innerText();
 for(const value of ['Manifest SHA-256','available','failed',(process.env.EXPECT_CLEANUP || 'not_started'),'databaseのセットID','mediaのセットID'])check(text.includes(value),'detail '+value);
 check(!text.includes('/inspection')&&!text.includes('SECRET'),'no internal paths');
 check(await wrap.locator('form[method="get"]').count()===1 && await wrap.locator('form[method="post"]').count()===0,'only GET form');
 check(await wrap.locator('button').count()===1,'no mutation buttons');
 await p.screenshot({path:path.join(output,'detail.png'),fullPage:true});
 await wrap.locator('#odb-set').fill('f'.repeat(32));await wrap.getByRole('button',{name:'状態を表示'}).click();
 check((await wrap.innerText()).includes('セット記録がありません'),'GET search missing set');
 await p.goto(url+'&set_id='+encodeURIComponent('<script>alert(1)</script>'));
 check((await wrap.innerText()).includes('32桁'),'invalid ID rejected');
 await p.goto(url+'&set_id[]='+state.set_id);check((await wrap.innerText()).includes('32桁'),'array ID rejected');
 await p.goto(url+'&set_id='+'d'.repeat(32));check((await wrap.innerText()).includes('保存完了前'),'failed set does not claim validation');
 const other=await browser.newContext();await other.route('**/*',r=>new URL(r.request().url()).hostname==='localhost'?r.continue():r.abort());
 const q=await other.newPage();await login(q,'fixtureviewer');
 check(await q.getByRole('link',{name:'Recovery Sets',exact:true}).count()===0,'subscriber menu absent');
 let response=await q.goto(url);check(response.status()===403,'subscriber direct URL rejected');
 response=await q.goto(url+'&set_id='+state.set_id);check(response.status()===403,'subscriber detail rejected');
 check(!(await q.locator('body').innerText()).includes(state.set_id),'subscriber sees no set data');
 const guest=await browser.newContext();const g=await guest.newPage();await g.goto(url+'&set_id='+state.set_id);
 check(g.url().includes('wp-login.php'),'guest redirected to login');
 check(errors.length===0,'no browser JavaScript errors');
 fs.writeFileSync(path.join(output,'result.json'),JSON.stringify({passed:true,checks,browser:browser.version(),scope:'activated full plugin; synthetic Collector and observations; localhost Docker'},null,2));
 console.log(JSON.stringify({passed:true,checks:checks.length}));
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});


