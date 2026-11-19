/* Run with node tools/browser/photo-upload-check.cjs. Uses local Playwright/Chromium. */
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
  const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox']});
  try {
    const page = await browser.newPage();
    await page.setContent('<form><label for="photo">Profile photo</label><input id="photo" type="file" accept="image/png,image/jpeg,image/webp" data-photo-min-side="200" required><button>Save photo</button></form>');
    await page.addScriptTag({path: path.resolve(__dirname, '../../public/assets/js/photo-upload.js')});
    // Real original photograph, not a drawn fixture or new illustration.
    const fs = require('node:fs');
    const root = path.resolve(__dirname, '../../public/assets/img');
    function photos(dir) { return fs.readdirSync(dir,{withFileTypes:true}).flatMap(d=>d.isDirectory()?photos(path.join(dir,d.name)):[path.join(dir,d.name)]); }
    const photo = photos(root).find(f => /\.webp$/i.test(f) && !f.includes('/utility/'));
    assert(photo);
    const input = page.getByLabel('Profile photo');
    await input.setInputFiles(photo);
    await page.waitForFunction(() => document.querySelector('.ag-photo-upload__preview img')?.naturalWidth > 0);
    assert.match(await page.getByRole('status').textContent(), /not yet saved/);
    assert(await input.evaluate(e=>e.validity.valid));
    await page.getByRole('button',{name:'Clear selection'}).click();
    assert.equal(await input.evaluate(e=>e.files.length), 0);
    assert(await input.evaluate(e=>document.activeElement===e));
    await input.setInputFiles({name:'invalid.svg',mimeType:'image/svg+xml',buffer:Buffer.from('<svg/>')});
    assert.match(await page.getByRole('status').textContent(), /not supported/);
    assert.equal(await input.getAttribute('aria-invalid'), 'true');
    await input.setInputFiles({name:'huge.jpg',mimeType:'image/jpeg',buffer:Buffer.alloc(11*1024*1024)});
    assert.match(await page.getByRole('status').textContent(), /too large/);
    await input.setInputFiles(photo);
    await page.waitForFunction(() => document.querySelector('.ag-photo-upload__preview img')?.naturalWidth > 0);
    assert(await input.evaluate(e=>e.validity.valid));
    await page.evaluate(()=>document.querySelector('form').reset());
    assert.equal(await page.locator('.ag-photo-upload__preview img').count(),0);
    assert.equal(await input.getAttribute('aria-invalid'),null);
    for(const width of [320,390,768,1440]) {
      await page.setViewportSize({width,height:900});
      await page.addStyleTag({path:path.resolve(__dirname,'../../public/assets/css/components/photo-upload.css')});
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    }
    console.log('PASS: previews, clear/focus, format/size validation, retry, reset and four viewport widths');
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1});
