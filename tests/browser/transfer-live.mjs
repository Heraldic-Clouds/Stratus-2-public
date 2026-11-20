// Operator-run verification against an explicitly prepared, disposable account.
// Never pass credentials as arguments or include URLs/response bodies in reports.
import {chromium} from '@playwright/test';
import {readFileSync, writeFileSync, mkdtempSync, realpathSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, dirname, basename} from 'node:path';
import {createHmac} from 'node:crypto';
import assert from 'node:assert/strict';

if (!process.argv.includes('--confirm-live-tests')) throw Error('Explicit live-test confirmation is required');
const fixture = JSON.parse(readFileSync('.tools/transfer-verification.json', 'utf8'));
const settings = JSON.parse(readFileSync('secrets.local.json', 'utf8'));
assert.match(fixture.uid, /^stratus_transfer_verify_[a-f0-9]{10}$/);
assert(!fixture.cleaned_up, 'Prepare a fresh disposable account; this fixture was removed');
const password = createHmac('sha256', settings.WORKER_HMAC_SECRET).update('transfer-verification:' + fixture.uid).digest('hex');
const privateValues = Object.entries(settings).filter(([key, value]) => /PASSWORD|SECRET/.test(key) && typeof value === 'string' && value.length >= 16).map(([, value]) => value);
const origin = 'https://stratus.example.com';
const storageHost = new URL(settings.STORJ_ENDPOINT).hostname;
const only = process.argv.find((arg) => arg.startsWith('--file='))?.slice(7);
const files = only ? [only] : ['100MB.bin', '1GB.bin', '10GB.bin'];
assert(files.every((name) => ['100MB.bin', '1GB.bin', '10GB.bin'].includes(name)));
const verifyMetricsOutage = process.argv.includes('--verify-metrics-outage');
if (verifyMetricsOutage) assert.deepEqual(files, ['100MB.bin']);
const verifyExpiry = process.argv.includes('--verify-expiry');
if (verifyExpiry) assert.deepEqual(files, ['100MB.bin']);
const temporaryRoot = realpathSync(tmpdir());
const profileDirectory = realpathSync(mkdtempSync(join(temporaryRoot, 'stratus-transfer-verify-')));
assert.equal(dirname(profileDirectory), temporaryRoot);
assert(basename(profileDirectory).startsWith('stratus-transfer-verify-'));
let context;
let stage = 'login';
let activePage;
const heartbeat = setInterval(async () => {
  const jobs = await activePage?.locator('#transfer-jobs li').evaluateAll((items) => items.map((item) => ({
    file: item.querySelector('strong')?.textContent, bytes: item.querySelector('progress')?.value,
    status: item.querySelector('p')?.textContent,
  }))).catch(() => []);
  console.log(JSON.stringify({stage, jobs}));
}, 30000);
const report = {sizes: [], storage_range_requests: 0, storage_response_bytes: 0, aws_control_response_bytes: 0, aws_bulk_get_bytes: 0};
const network = [];
try {
  // A new persistent profile provides real disk-backed OPFS capacity. Chromium's
  // private contexts impose a much smaller origin quota, unsuitable for 10 GB.
  context = await chromium.launchPersistentContext(profileDirectory,
    {headless: true, locale: 'en-US', viewport: {width: 1365, height: 900}});
  context.setDefaultTimeout(90000);
  // The test substitutes only the chooser, using a real browser FileSystem handle
  // in its isolated origin-private filesystem. Production uses the native chooser.
  await context.addInitScript(() => {
    window.showSaveFilePicker = async ({suggestedName}) => {
      const root = await navigator.storage.getDirectory();
      const directory = await root.getDirectoryHandle('stratus-transfer-verification', {create: true});
      return directory.getFileHandle(suggestedName, {create: true});
    };
  });
  const page = activePage = await context.newPage();
  // New disposable users see Nextcloud's first-run welcome dialog. Dismiss it
  // through its normal UI rather than changing global application settings.
  await page.addLocatorHandler(page.getByRole('button', {name: /^Close(?: dialog| modal)?$/i}).first(), async (button) => {
    await button.click();
  });
  const loginResponse = await page.goto(origin + '/index.php/login?direct=1', {waitUntil: 'domcontentloaded'});
  assert.equal(loginResponse.status(), 200, 'Login page was unavailable');
  await page.locator('input[name="user"]').fill(fixture.uid);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', {name: 'Log in', exact: true}).click();
  await page.waitForURL((url) => !url.pathname.includes('/login'), {timeout: 30000});
  const open = async () => {
    await page.goto(origin + '/index.php/apps/stratus_transfer/', {waitUntil: 'domcontentloaded'});
    await page.locator('#transfer-files tr').filter({hasText: 'Transfer verification'}).getByRole('button', {name: 'Open', exact: true}).click();
    await page.waitForFunction(() => document.getElementById('transfer-path')?.textContent === 'Transfer verification');
  };
  stage = 'open transfer page'; await open();
  const storage = await page.evaluate(() => navigator.storage.estimate());
  const required = Math.max(...files.map((name) => ({'100MB.bin': 100000000, '1GB.bin': 1000000000, '10GB.bin': 10000000000})[name]));
  assert(storage.quota - storage.usage > required + 100000000, 'Insufficient browser storage for fixture');
  report.browser_storage_quota_bytes = storage.quota;
  const api = async (action, data = {}) => {
    const result = await page.evaluate(async ({action, data}) => {
    const response = await fetch(OC.generateUrl('/apps/stratus_transfer/api/' + action), {method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', requesttoken: OC.requestToken}, body: JSON.stringify(data)});
    return {status: response.status, body: await response.json()};
    }, {action, data});
    assert(privateValues.every((value) => !JSON.stringify(result).includes(value)));
    return result;
  };
  const listing = await api('browse', {path: 'Transfer verification'});
  assert.equal(listing.status, 200);
  const initial = listing.body.files.map(({id, etag, size, name}) => ({id, etag, size, name}));
  const capabilities = await api('status');
  assert.equal(capabilities.body.direct_downloads, true); assert.equal(capabilities.body.direct_uploads, false);
  report.direct_uploads_safely_disabled = true;
  let failedMetricsTicket;
  if (verifyMetricsOutage) {
    await page.route('**/apps/stratus_transfer/api/progress', async (route) => {
      const data = route.request().postDataJSON();
      if (data.position === 100000000) {
        failedMetricsTicket = data.ticket;
        await route.fulfill({status: 503, json: {error: 'Simulated tracking outage'}});
      } else await route.continue();
    });
  }
  const cdp = await context.newCDPSession(page); await cdp.send('Network.enable');
  const paths = new Map();
  const storageRequests = new Set(), credentialHeaders = new Map();
  let credentialFailure = false, inspectedApiBodies = 0;
  cdp.on('Network.requestWillBeSent', ({requestId, request}) => {
    const url = new URL(request.url);
    const kind = url.hostname === storageHost && request.method === 'GET' ? 'storage_response_bytes'
      : url.origin === origin && url.pathname.includes('/stratus_transfer/api/') ? 'aws_control_response_bytes'
      : url.origin === origin && url.pathname.includes('/remote.php/dav/files/') && request.method === 'GET' ? 'aws_bulk_get_bytes' : null;
    if (kind) paths.set(requestId, kind);
    if (kind === 'storage_response_bytes') {report.storage_range_requests++; storageRequests.add(requestId);}
  });
  cdp.on('Network.dataReceived', ({requestId, dataLength}) => {const kind = paths.get(requestId); if (kind) report[kind] += dataLength;});
  // ExtraInfo contains the actual wire headers, including browser-added cookies.
  // It can arrive before requestWillBeSent, so correlate only after collection.
  cdp.on('Network.requestWillBeSentExtraInfo', ({requestId, headers}) => {
    credentialHeaders.set(requestId, Object.keys(headers).some((name) => /^(cookie|authorization)$/i.test(name)));
  });
  cdp.on('Network.loadingFinished', ({requestId}) => {
    if (paths.get(requestId) !== 'aws_control_response_bytes') return;
    network.push(cdp.send('Network.getResponseBody', {requestId}).then(({body, base64Encoded}) => {
      if (base64Encoded) body = Buffer.from(body, 'base64').toString('utf8');
      inspectedApiBodies++; if (privateValues.some((value) => body.includes(value))) credentialFailure = true;
    }).catch(() => {credentialFailure = true;}));
  });
  for (const name of files) {
    stage = 'download ' + name;
    const file = initial.find((item) => item.name === name); assert(file);
    const started = Date.now();
    await page.locator('#transfer-files tr').filter({hasText: name}).getByRole('button', {name: 'Save file', exact: true}).click();
    const job = page.locator('#transfer-jobs li').filter({hasText: name}).first();
    if (name === '1GB.bin') {
      await page.waitForFunction(() => [...document.querySelectorAll('#transfer-jobs li')].some((item) => item.querySelector('strong')?.textContent === '1GB.bin' && item.querySelector('progress')?.value > 8 * 1024 * 1024), undefined, {timeout: 120000});
      await job.getByRole('button', {name: 'Pause', exact: true}).click();
      await job.getByText(/^Paused at /).waitFor();
      await open();
      const paused = page.locator('#transfer-jobs li').filter({hasText: name}).first();
      await paused.getByRole('button', {name: 'Resume', exact: true}).click();
      await paused.getByRole('button', {name: 'Pause', exact: true}).waitFor();
      report.resume_after_reload = true;
    }
    const complete = page.locator('#transfer-jobs li').filter({hasText: name}).first().getByText('Download complete', {exact: true});
    await page.waitForFunction((name) => {
      const item = [...document.querySelectorAll('#transfer-jobs li')].find((item) => item.querySelector('strong')?.textContent === name);
      const label = item?.querySelector('p')?.textContent;
      const active = [...(item?.querySelectorAll('button') || [])].some((button) => button.textContent === 'Pause');
      return label === 'Download complete' || (!!label && !active);
    }, name, {timeout: 60 * 60 * 1000});
    assert(await complete.isVisible(), 'Download ended without completing');
    if (verifyMetricsOutage) {
      assert(failedMetricsTicket);
      assert.match(await page.locator('#transfer-notice').textContent(), /download is saved.*statistics could not be updated/i);
      await page.unroute('**/apps/stratus_transfer/api/progress');
      assert.equal((await api('progress', {ticket: failedMetricsTicket, position: file.size})).status, 200);
      report.metrics_outage_did_not_fail_download = true;
    }
    stage = 'verify saved bytes ' + name;
    const verified = await page.evaluate(async ({name}) => {
      const root = await navigator.storage.getDirectory(); const dir = await root.getDirectoryHandle('stratus-transfer-verification');
      const file = await (await dir.getFileHandle(name)).getFile();
      const reader = file.stream().getReader(); let bytes = 0, zero = true;
      while (true) {const {done, value} = await reader.read(); if (done) break; bytes += value.byteLength; if (value.some((byte) => byte !== 0)) {zero = false; break;}}
      await reader.cancel(); await dir.removeEntry(name); return {bytes, zero};
    }, {name});
    assert.equal(verified.bytes, file.size); assert.equal(verified.zero, true);
    const result = {file: name, bytes: verified.bytes, integrity: true, seconds: Math.round((Date.now() - started) / 1000)};
    report.sizes.push(result); console.log(JSON.stringify(result));
    writeFileSync(`.tools/transfer-browser-sizes-${only?.slice(0, -4) || 'all'}.json`, JSON.stringify(report, null, 2));
  }
  report.aws_bulk_get_bytes_direct_downloads = report.aws_bulk_get_bytes;
  assert.equal(report.aws_bulk_get_bytes_direct_downloads, 0);
  stage = 'metadata comparison';
  const after = await api('browse', {path: 'Transfer verification'});
  assert.deepEqual(after.body.files.map(({id, etag, size, name}) => ({id, etag, size, name})), initial);
  stage = 'ownership rejection';
  const denied = await api('download', {fileId: 1, etag: 'not-an-owned-fixture'}); assert.notEqual(denied.status, 200);
  stage = 'forged receipt rejection';
  const forgedProgress = await api('progress', {ticket: '0'.repeat(32), position: 1}); assert.equal(forgedProgress.status, 404);
  stage = 'csrf rejection';
  const noCsrf = await page.evaluate(async () => (await fetch(OC.generateUrl('/apps/stratus_transfer/api/status'), {method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}'})).status);
  assert.notEqual(noCsrf, 200);
  report.metadata_unchanged = true; report.forged_ticket_rejected = true; report.csrf_required = true;
  if (files.includes('100MB.bin')) {
    stage = 'signed request scope';
    const plan = (await api('download', {fileId: initial.find((file) => file.name === '100MB.bin').id,
      etag: initial.find((file) => file.name === '100MB.bin').etag})).body;
    assert.equal(plan.mode, 'direct');
    assert(new URL(plan.url).searchParams.get('X-Amz-SignedHeaders').split(';').includes('if-match'));
    const unsigned = new URL(plan.url); unsigned.search = '';
    for (const [label, url, method, headers] of [
      ['changed signed precondition', plan.url, 'GET', {...plan.headers, 'If-Match': '"forged-object-etag"', Range: 'bytes=0-0'}],
      ['missing signed precondition', plan.url, 'GET', {Range: 'bytes=0-0'}],
      ['unsigned object access', unsigned.href, 'GET', {Range: 'bytes=0-0'}],
      ['changed signed method', plan.url, 'HEAD', plan.headers],
    ]) {
      stage = label;
      const denied = await fetch(url, {method, headers, redirect: 'error', signal: AbortSignal.timeout(15000)});
      // Storj reports a missing signed header as InvalidRequest (400), while a
      // changed signed value is SignatureDoesNotMatch (403).
      const allowed = label === 'missing signed precondition' ? [400, 403] : [403];
      assert(allowed.includes(denied.status), 'Unexpected rejection status ' + denied.status); await denied.body?.cancel();
    }
    report.signed_precondition_required = true; report.unsigned_access_rejected = true; report.changed_method_rejected = true;
    if (verifyExpiry) {
      stage = 'verify valid capability before expiry';
      const valid = await fetch(plan.url, {headers: {...plan.headers, Range: 'bytes=0-0'}, signal: AbortSignal.timeout(15000)});
      assert.equal(valid.status, 206); assert.equal((await valid.arrayBuffer()).byteLength, 1);
      stage = 'presigned URL expiry';
      const deadline = (plan.expires_at + 2) * 1000;
      while (Date.now() < deadline) await new Promise((resolve) => setTimeout(resolve, Math.min(15000, deadline - Date.now())));
      const expired = await fetch(plan.url, {headers: {...plan.headers, Range: 'bytes=0-0'}, signal: AbortSignal.timeout(15000)});
      assert.equal(expired.status, 403); await expired.body?.cancel(); report.expired_link_rejected = true;
    }
    stage = 'upload planning quota';
    assert.equal((await api('upload', {folder: 'Transfer verification', size: 1_000_000_000_000})).status, 507);
    assert.equal((await api('upload', {folder: 'Transfer verification', size: 65536})).body.mode, 'nextcloud');
    report.upload_quota_planning_rejected = true;
    stage = 'native WebDAV fallback';
    const fallback = await page.evaluate(async ({uid}) => {
      const base = OC.generateUrl('/remote.php/dav/files') + '/' + encodeURIComponent(uid) + '/Transfer%20verification/';
      const headers = {requesttoken: OC.requestToken};
      const range = await fetch(base + '100MB.bin', {headers: {...headers, Range: 'bytes=4096-5119'}});
      const bytes = new Uint8Array(await range.arrayBuffer());
      const names = ['native-fallback-a-', 'native-fallback-b-'].map((prefix) => prefix + Date.now() + '.bin');
      let put, get;
      try {
        put = await Promise.all(names.map(async (name) => (await fetch(base + name,
          {method: 'PUT', headers: {...headers, 'If-None-Match': '*'}, body: new Uint8Array(65536)})).status));
        get = await Promise.all(names.map(async (name) => {
          const response = await fetch(base + name, {method: 'PROPFIND', headers: {...headers, Depth: '0'}});
          const xml = await response.text(); return {status: response.status, correctSize: xml.includes('65536')};
        }));
      } finally {
        await Promise.all(names.map((name) => fetch(base + name, {method: 'DELETE', headers})));
      }
      return {rangeStatus: range.status, rangeHeader: range.headers.get('Content-Range'), bytes: bytes.length,
        zero: bytes.every((byte) => byte === 0), put, get};
    }, {uid: fixture.uid});
    assert.equal(fallback.rangeStatus, 206); assert.equal(fallback.rangeHeader, 'bytes 4096-5119/100000000');
    assert.equal(fallback.bytes, 1024); assert.equal(fallback.zero, true);
    assert(fallback.put.every((status) => status === 201)); assert(fallback.get.every((item) => item.status === 207 && item.correctSize));
    report.fallback_response_bytes = fallback.bytes; report.webdav_range_fallback = true; report.concurrent_native_uploads = true;
    stage = 'unsupported browser capability fallback';
    const fallbackPage = await context.newPage();
    await fallbackPage.goto(origin + '/index.php/apps/stratus_transfer/', {waitUntil: 'domcontentloaded'});
    await fallbackPage.locator('#transfer-files tr').filter({hasText: 'Transfer verification'}).getByRole('button', {name: 'Open', exact: true}).click();
    await fallbackPage.evaluate(() => {
      Object.defineProperty(window, 'showSaveFilePicker', {value: undefined, configurable: true, writable: false});
      window.addEventListener('click', (event) => {
        const link = event.target.closest?.('a[download]');
        if (link && new URL(link.href).pathname.includes('/remote.php/dav/files/')) {
          event.preventDefault(); event.stopImmediatePropagation(); window.__stratusNativeFallback = new URL(link.href).pathname;
        }
      }, true);
    });
    await fallbackPage.locator('#transfer-files tr').filter({hasText: '100MB.bin'}).getByRole('button', {name: 'Save file', exact: true}).click();
    try {await fallbackPage.waitForFunction(() => typeof window.__stratusNativeFallback === 'string', undefined, {timeout: 15000});}
    catch (error) {
      console.log(JSON.stringify({fallback_debug: await fallbackPage.evaluate(() => ({picker: typeof window.showSaveFilePicker,
        notice: document.getElementById('transfer-notice')?.textContent,
        jobs: [...document.querySelectorAll('#transfer-jobs li p')].map((item) => item.textContent)}))}));
      throw error;
    }
    const nativePath = await fallbackPage.evaluate(() => window.__stratusNativeFallback);
    assert.equal(decodeURIComponent(nativePath), '/remote.php/dav/files/' + fixture.uid + '/Transfer verification/100MB.bin');
    report.unsupported_capability_uses_native_dav = true;
    await fallbackPage.close();
  }
  stage = 'screenshot';
  await page.screenshot({path: '.tools/stratus-transfer-verified.png', fullPage: true, timeout: 15000});
  stage = 'network evidence';
  await Promise.race([Promise.allSettled(network), new Promise((_, reject) => setTimeout(() => reject(Error('Network inspection timed out')), 15000))]);
  for (const id of storageRequests) {assert(credentialHeaders.has(id), 'Missing wire-header evidence'); assert.equal(credentialHeaders.get(id), false);}
  assert(inspectedApiBodies > 0);
  assert.equal(report.aws_bulk_get_bytes, report.fallback_response_bytes || 0); assert(report.storage_response_bytes >= report.sizes.reduce((sum, file) => sum + file.bytes, 0));
  assert.equal(credentialFailure, false);
  const html = await page.content(); assert(privateValues.every((value) => !html.includes(value)));
  report.permanent_secret_keys_in_browser = false;
  report.note = 'Real Chromium/Storj traffic; test chooser uses a real origin-private FileSystem handle. Native chooser interaction and physical desktop/mobile apps require manual checks.';
  writeFileSync('.tools/transfer-browser-report.json', JSON.stringify(report, null, 2));
  writeFileSync(`.tools/transfer-browser-report-${only?.slice(0, -4) || 'all'}.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report));
} catch (error) {
  if (activePage && stage === 'login') {
    console.log(JSON.stringify({login_view: {title: await activePage.title().catch(() => ''),
      path: new URL(activePage.url()).pathname, username_fields: await activePage.locator('input[name="user"]').count()}}));
  }
  if (activePage && stage !== 'login') {
    await activePage.screenshot({path: '.tools/stratus-transfer-browser-failure.png', fullPage: true}).catch(() => {});
    const debug = await activePage.evaluate(() => ({notice: document.getElementById('transfer-notice')?.textContent,
      scriptLoaded: !!document.getElementById('transfer-files'), rowCount: document.querySelectorAll('#transfer-files tr').length,
      jobs: [...document.querySelectorAll('#transfer-jobs li')].map((item) => ({file: item.querySelector('strong')?.textContent,
        bytes: item.querySelector('progress')?.value, status: item.querySelector('p')?.textContent})),
      requestTokenAvailable: typeof OC?.requestToken === 'string',
      visibleButtons: [...document.querySelectorAll('button')].filter((button) => button.getBoundingClientRect().width > 0).map((button) => button.getAttribute('aria-label') || button.title || button.textContent.trim()).slice(0, 40)})).catch(() => null);
    console.log(JSON.stringify({debug}));
  }
  let safeMessage = String(error.message).split('\n')[0];
  for (const value of [...privateValues, password]) safeMessage = safeMessage.replaceAll(value, '[PRIVATE]');
  safeMessage = safeMessage.replace(/https?:\/\/\S+/g, '[URL]');
  console.log(JSON.stringify({live_verification_failed: true, stage, error_type: error.name, message: safeMessage,
    actual_number: typeof error.actual === 'number' ? error.actual : undefined})); process.exitCode = 1;
} finally {
  clearInterval(heartbeat); await context?.close();
  // Delete only the freshly created, verified temporary test-profile directory.
  assert.equal(dirname(profileDirectory), temporaryRoot);
  assert(basename(profileDirectory).startsWith('stratus-transfer-verify-'));
  rmSync(profileDirectory, {recursive: true, force: true, maxRetries: 10, retryDelay: 200});
}
