// Exercise the real deployed logout adapter and bundled Supabase SDK in a fresh
// browser. Only the test context's identity user/logout endpoints are simulated.
// No real identity credentials, user sessions, sign-ins or emails are used.
import {chromium} from '@playwright/test';
import assert from 'node:assert/strict';

if (!process.argv.includes('--confirm-live-tests')) throw Error('Explicit live-test confirmation is required');
const origin = 'https://stratus.example.com';
const configResponse = await fetch(origin + '/index.php/apps/stratus2_ai/auth/config');
assert(configResponse.ok);
const config = await configResponse.json();
const provider = new URL(config.url);
assert.match(provider.hostname, /^[a-z0-9-]+\.supabase\.co$/);
const key = 'sb-' + provider.hostname.split('.')[0] + '-auth-token';
const now = Math.floor(Date.now() / 1000);
const user = {id: '00000000-0000-4000-8000-000000000001', aud: 'authenticated', role: 'authenticated',
  email: 'logout-fixture@example.invalid', created_at: new Date().toISOString(), app_metadata: {}, user_metadata: {}};
const encode = (value) => Buffer.from(JSON.stringify(value)).toString('base64url');
const accessToken = encode({alg: 'HS256', typ: 'JWT'}) + '.' + encode({sub: user.id, aud: 'authenticated',
  iss: config.url + '/auth/v1', exp: now + 3600, iat: now, session_id: user.id}) + '.unsigned-test-fixture';
const session = {access_token: accessToken, refresh_token: 'invalid-test-refresh-token', token_type: 'bearer',
  expires_in: 3600, expires_at: now + 3600, user};
const browser = await chromium.launch({headless: true});
try {
  for (const outage of [false, true]) {
    const context = await browser.newContext();
    try {
      let failing = outage;
      let signOutCalls = 0;
      let authorizationRequests = 0;
      const scopes = [];
      context.on('request', (request) => {
        const url = new URL(request.url());
        if (url.origin === config.url && url.pathname === '/auth/v1/oauth/authorize') authorizationRequests++;
      });
      await context.route(config.url + '/auth/v1/**', async (route) => {
        const url = new URL(route.request().url());
        if (url.pathname === '/auth/v1/oauth/authorize') return route.continue();
        if (url.pathname === '/auth/v1/logout') {
          signOutCalls++;
          scopes.push(url.searchParams.get('scope'));
          assert.equal(route.request().method(), 'POST');
          assert.equal(route.request().headers().authorization, 'Bearer ' + accessToken);
          return route.fulfill(failing ? {status: 503, contentType: 'application/json', body: JSON.stringify({msg: 'Fixture service unavailable', code: 'fixture_unavailable'})} : {status: 204, body: ''});
        }
        if (url.pathname === '/auth/v1/user') return route.fulfill(route.request().headers().authorization === 'Bearer ' + accessToken
          ? {status: 200, contentType: 'application/json', body: JSON.stringify(user)}
          : {status: 401, contentType: 'application/json', body: JSON.stringify({msg: 'No test session', code: 'session_not_found'})});
        return route.fulfill({status: 401, contentType: 'application/json', body: JSON.stringify({msg: 'Invalid test session', code: 'invalid_token'})});
      });
      const page = await context.newPage();
      await page.goto(origin + '/status.php', {waitUntil: 'domcontentloaded'});
      await page.evaluate(({key, session}) => localStorage.setItem(key, JSON.stringify(session)), {key, session});
      await page.goto(origin + '/index.php/apps/user_oidc/sls', {waitUntil: 'domcontentloaded', timeout: 45000});
      if (outage) {
        await page.getByRole('button', {name: 'Retry sign out'}).waitFor({state: 'visible', timeout: 15000});
        assert.equal(await page.locator('#auth-form').isVisible(), false);
        assert.equal(new URL(page.url()).searchParams.get('mode'), 'logout');
        assert.equal(authorizationRequests, 0);
        failing = false;
        await page.getByRole('button', {name: 'Retry sign out'}).click();
      }
      await page.waitForFunction((key) => !localStorage.getItem(key), key, {timeout: 15000});
      await page.locator('#auth-form').waitFor({state: 'visible', timeout: 15000});
      await page.waitForFunction(() => new URL(location.href).searchParams.has('authorization_id'), undefined, {timeout: 15000});
      const final = new URL(page.url());
      assert.equal(final.origin, origin);
      assert(['/index.php/apps/stratus2_ai/auth', '/apps/stratus2_ai/auth'].includes(final.pathname));
      assert.equal(await page.locator('#oauth-consent').isVisible(), false);
      assert(outage ? signOutCalls >= 1 : signOutCalls === 1);
      assert.equal(authorizationRequests, 1);
      assert(scopes.every((scope) => scope === 'local'));
      await page.reload({waitUntil: 'domcontentloaded'});
      await page.locator('#auth-form').waitFor({state: 'visible', timeout: 15000});
      assert.equal(await page.evaluate((key) => localStorage.getItem(key), key), null);
      console.log(JSON.stringify({simulated_identity_outage: outage, stale_identity_cleared: true,
        login_form_stays_visible_after_reload: true, signout_calls: signOutCalls, scope: 'local',
        identity_endpoints_simulated: true, real_user_credentials_used: false}));
    } finally {await context.close();}
  }
} finally {await browser.close();}
