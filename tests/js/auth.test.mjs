import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../frontend/auth.js', import.meta.url), 'utf8')
  .replace(/^import .*;\r?\n/, '')
  .replace('main().catch(', 'globalThis.authDone = main().catch(');

async function page({query = '', signedIn = true, outcomes = [{error: null}], authorizationError = null,
  consentError = null, lastRestart = 0} = {}) {
  const events = [];
  const nodes = new Map();
  for (const id of ['auth-form', 'oauth-consent', 'signout', 'auth-notice', 'email', 'password', 'signup', 'reset', 'allow', 'deny', 'consent-details', 'restart-signin']) {
    nodes.set(id, {hidden: ['oauth-consent', 'signout', 'restart-signin'].includes(id), textContent: '', value: '', querySelector: () => ({})});
  }
  let session = signedIn;
  const client = {auth: {
    signOut: async ({scope}) => {
      events.push('signOut:' + scope);
      const result = outcomes.shift() ?? {error: null};
      if (result instanceof Error) throw result;
      if (!result.error) session = false;
      return result;
    },
    getUser: async () => {events.push('getUser'); return {data: {user: session ? {email: 'fixture@example.invalid'} : null}, error: null};},
    exchangeCodeForSession: async () => {events.push('exchangeCode'); return {error: null};},
    oauth: {
      getAuthorizationDetails: async () => authorizationError ? {error: authorizationError}
        : {data: {client: {id: 'fixture-client'}, redirect_uri: 'https://stratus.example.com/apps/user_oidc/code', scope: 'openid email profile'}},
      approveAuthorization: async () => ({error: consentError}),
    },
  }};
  const context = vm.createContext({
    URL, createClient: () => client,
    document: {getElementById: (id) => nodes.get(id)},
    location: {href: 'https://stratus.example.com/apps/stratus2_ai/auth' + query, origin: 'https://stratus.example.com',
      assign: (url) => events.push('assign:' + url), replace: (url) => events.push('replace:' + url), reload: () => events.push('reload')},
    history: {replaceState: (state, title, url) => events.push('clean:' + url.search)},
    sessionStorage: {getItem: () => lastRestart, setItem: (key, value) => {lastRestart = value;}},
    OC: {generateUrl: (path) => '/index.php' + path},
    fetch: async () => ({json: async () => ({url: 'https://fixture.supabase.co', publishableKey: 'public-fixture', clientId: 'fixture-client'})}),
  });
  vm.runInContext(source, context);
  await context.authDone;
  return {events, nodes, session: () => session};
}

test('logout clears the identity session before navigation and never processes an OAuth request', async () => {
  const result = await page({query: '?mode=logout&code=unused&authorization_id=unused'});
  assert.deepEqual(result.events, ['signOut:local', 'replace:/index.php/login']);
  assert.equal(result.session(), false);
  assert.equal(result.nodes.get('auth-form').hidden, true);
  assert.equal(result.nodes.get('oauth-consent').hidden, true);
});

test('failed logout stays on a retry screen instead of automatically signing back in', async () => {
  const result = await page({query: '?mode=logout', outcomes: [{error: Error('network failure')}, {error: null}]});
  assert.deepEqual(result.events, ['signOut:local']);
  assert.equal(result.session(), true);
  assert.equal(result.nodes.get('auth-form').hidden, true);
  assert.equal(result.nodes.get('signout').hidden, false);
  assert.match(result.nodes.get('auth-notice').textContent, /Sign out could not finish/);
  await result.nodes.get('signout').onclick();
  assert.deepEqual(result.events, ['signOut:local', 'signOut:local', 'replace:/index.php/login']);
  assert.equal(result.session(), false);
});

test('a thrown sign-out error also prevents navigation and exposes retry', async () => {
  const result = await page({query: '?mode=logout', outcomes: [Error('disconnected')]});
  assert.deepEqual(result.events, ['signOut:local']);
  assert.equal(result.nodes.get('signout').textContent, 'Retry sign out');
});

test('the signed-out login page remains a sign-in form without an automatic redirect', async () => {
  const result = await page({query: '?authorization_id=fresh-request', signedIn: false});
  assert.deepEqual(result.events, ['getUser']);
  assert.equal(result.nodes.get('auth-form').hidden, false);
  assert.equal(result.nodes.get('oauth-consent').hidden, true);
});

test('identity-account sign-out is scoped to this session and checks errors before reload', async () => {
  const result = await page({query: '?authorization_id=fresh-request', outcomes: [{error: Error('network failure')}, {error: null}]});
  await result.nodes.get('signout').onclick();
  assert.deepEqual(result.events, ['getUser', 'signOut:local']);
  await result.nodes.get('signout').onclick();
  assert.deepEqual(result.events, ['getUser', 'signOut:local', 'signOut:local', 'reload']);
});

test('an expired authorization starts one fresh login and replaces the stale URL', async () => {
  const result = await page({query: '?authorization_id=expired', authorizationError: {code: 'oauth_authorization_not_found'}});
  assert.deepEqual(result.events, ['getUser', 'replace:/index.php/login']);
  assert.equal(result.nodes.get('oauth-consent').hidden, true);
});

test('a repeated stale response offers a link and does not loop', async () => {
  const result = await page({query: '?authorization_id=expired', authorizationError: {message: 'Authorization not found'}, lastRestart: Date.now()});
  assert.deepEqual(result.events, ['getUser']);
  assert.equal(result.nodes.get('restart-signin').href, '/index.php/login');
  assert.equal(result.nodes.get('restart-signin').hidden, false);
});

test('a transient provider error does not automatically restart sign-in', async () => {
  const result = await page({query: '?authorization_id=current', authorizationError: {status: 503, message: 'Provider unavailable'}});
  assert.deepEqual(result.events, ['getUser']);
  assert.match(result.nodes.get('auth-notice').textContent, /Provider unavailable/);
});

test('consent expires safely between presenting it and approving it', async () => {
  const result = await page({query: '?authorization_id=current', consentError: {code: 'oauth_authorization_not_found'}});
  await result.nodes.get('allow').onclick();
  assert.deepEqual(result.events, ['getUser', 'replace:/index.php/login']);
});

test('password recovery ignores an old authorization and removes the used email code', async () => {
  const result = await page({query: '?mode=recovery&code=email-code&authorization_id=old', authorizationError: {code: 'oauth_authorization_not_found'}});
  assert.deepEqual(result.events, ['exchangeCode', 'clean:?mode=recovery', 'getUser']);
  assert.equal(result.nodes.get('auth-form').hidden, false);
});
