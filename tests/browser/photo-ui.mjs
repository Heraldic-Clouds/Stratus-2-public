import {chromium} from '@playwright/test';
import assert from 'node:assert/strict';
import {readFile, mkdir} from 'node:fs/promises';

const template = (await readFile('apps/stratus2_ai/templates/index.php','utf8')).replace(/<\?php[\s\S]*?\?>/g,'');
const css = await readFile('apps/stratus2_ai/css/main.css','utf8');
const organizer = await readFile('apps/stratus2_ai/js/organizer.js','utf8');
const photos = await readFile('apps/stratus2_ai/js/photos.js','utf8');
const html = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>html,body{margin:0;height:100%;font-family:Arial,sans-serif}#content{height:100%;overflow:hidden}${css}</style></head><body><div id="content">${template}</div><script>window.OC={generateUrl:p=>p,requestToken:'isolated-ui-test'};</script><script>${organizer}</script><script>${photos}</script></body></html>`;
const browser = await chromium.launch({headless:true,...(process.env.CHROME_PATH ? {executablePath:process.env.CHROME_PATH}:{})});
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=','base64');
await mkdir('artifacts',{recursive:true});
try {
  for (const [name,width,height] of [['desktop',1366,768],['mobile',390,844]]) {
    const context = await browser.newContext({viewport:{width,height}});
    let paid=false, generated=0, requestBody;
    const job={id:'a'.repeat(32),state:'ready',total:2,completed:2,manifest:[{from:'Family/black.jpg',to:'Family/Colorized/black.png',original_to:'Family/Original/black.jpg',action:'edit',phase:'pending',reason:'black and white'}]};
    await context.route('https://stratus.test/**',async route=> {
      const url=new URL(route.request().url()), path=url.pathname;
      if (path==='/') return route.fulfill({contentType:'text/html',body:html});
      let data=[];
      if (path.endsWith('/billing/status')) data={paid,extra_blocks:0,capacity_gb:paid?100:1,enhancement_enabled:true,legacy:false};
      else if (path.includes('/estimate')) data={files:2,images:2};
      else if (path.endsWith('/jobs') && route.request().method()==='POST') {generated++;requestBody=route.request().postDataJSON();data=job;}
      else if (path.endsWith('/apply')) data={...job,state:'batch_done'};
      else if (path.includes('/jobs/')) data=job;
      return route.fulfill({contentType:'application/json',body:JSON.stringify(data)});
    });
    const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.goto('https://stratus.test/');await page.getByText('Your account uses the free storage allowance.').waitFor();
    await page.locator('#mode-photo').click();assert(await page.locator('#analyze-submit').isDisabled());assert.equal(generated,0);
    paid=true;await page.reload();await page.getByText('Your allowance: 100 GB.').waitFor();
    await page.locator('#mode-photo').click();await page.locator('#folder').fill('Family');
    await page.locator('#prompt').fill('Find black and white photos, colorize them, and save them in folder Colorized and originals in Original.');
    await page.locator('#consent').check();page.once('dialog',dialog=>dialog.accept());
    await page.locator('#analyze-submit').click();await page.getByText('Family/Colorized/black.png').waitFor();
    assert.equal(generated,1);assert.equal(requestBody.batch,true);assert.equal(requestBody.folder,'Family');
    await page.locator('#apply').click();await page.getByText('batch_done · 2 of 2 files analyzed').waitFor();
    await page.locator('#storage-blocks').fill('99');assert.equal(await page.locator('#storage-summary').textContent(),'10000 GB · $208/month');
    await page.locator('#storage-blocks').fill('100');assert.match(await page.locator('#storage-summary').textContent(),/0–99/);
    await page.locator('#storage-blocks').fill('0');await page.locator('#job-section').scrollIntoViewIfNeeded();
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Horizontal page overflow');
    assert.deepEqual(errors,[]);await page.screenshot({path:`artifacts/photo-enhancement-${name}.png`});
    await context.close();console.log(`PASS: ${name} paid/free UI, directory plan, approval, storage pricing and overflow`);
  }
} finally {await browser.close();}
