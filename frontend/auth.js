import {createClient} from '@supabase/supabase-js';

const el = id => document.getElementById(id);
const notice = value => { el('auth-notice').textContent = value; };
const pageUrl = new URL(location.href);
const recovery = pageUrl.searchParams.get('mode') === 'recovery';
const authorizationId = recovery ? null : pageUrl.searchParams.get('authorization_id');
const restartKey = 'stratus2-oauth-restart';
let client, config;
function restartStaleAuthorization(error) {
  if (error.code !== 'oauth_authorization_not_found' && !/^authorization not found\.?$/i.test(error.message || '')) return false;
  el('oauth-consent').hidden = true;
  el('auth-form').hidden = true;
  const loginUrl = OC.generateUrl('/login');
  try {
    const last = Number(sessionStorage.getItem(restartKey));
    if (Date.now() - last > 30000) {
      sessionStorage.setItem(restartKey, String(Date.now()));
      location.replace(loginUrl);
      return true;
    }
  } catch {
    // The manual restart also works when browser storage is disabled.
  }
  el('restart-signin').href = loginUrl;
  el('restart-signin').hidden = false;
  notice('This sign-in request expired. Start a fresh sign-in.');
  return true;
}
function redirectSafe(url) {
  const target = new URL(url);
  if (target.protocol !== 'https:' || target.origin !== location.origin) throw new Error('Unexpected redirect destination');
  location.replace(target.href);
}
async function update() {
  const {data, error} = await client.auth.getUser();
  if (error || !data.user) return;
  el('auth-form').hidden = true; el('signout').hidden = false;
  if (!authorizationId) {
    if (recovery) {
      el('auth-form').hidden = false; el('email').value = data.user.email || '';
      el('auth-form').querySelector('[type=submit]').textContent = 'Save new password';
      el('auth-form').onsubmit = async event => {event.preventDefault(); const result = await client.auth.updateUser({password: el('password').value}); notice(result.error ? result.error.message : 'Password updated. You can return to sign in.');};
      notice('Enter your new password and select Sign in to save it.'); return;
    }
    location.assign(OC.generateUrl('/login')); return;
  }
  const result = await client.auth.oauth.getAuthorizationDetails(authorizationId);
  if (result.error) {
    if (restartStaleAuthorization(result.error)) return;
    throw result.error;
  }
  if (result.data.redirect_url) {redirectSafe(result.data.redirect_url); return;}
  if (result.data.client.id !== config.clientId || new URL(result.data.redirect_uri).origin !== location.origin) throw new Error('This request is not for Stratus-2');
  el('oauth-consent').hidden = false;
  el('consent-details').textContent = `Share your account ID and the requested profile information with Stratus-2. Requested access: ${result.data.scope || 'openid email profile'}.`;
}
async function main() {
  config = await (await fetch(OC.generateUrl('/apps/stratus2_ai/auth/config'), {credentials: 'same-origin'})).json();
  if (!config.url || !config.publishableKey) throw new Error('Account sign-in is not configured yet');
  client = createClient(config.url, config.publishableKey, {auth: {flowType: 'pkce', detectSessionInUrl: false}});
  if (pageUrl.searchParams.get('mode') === 'logout') {
    el('auth-form').hidden = true; el('oauth-consent').hidden = true;
    notice('Signing you out…');
    const finish = async () => {
      try {
        const {error} = await client.auth.signOut({scope: 'local'});
        if (error) throw error;
        location.replace(OC.generateUrl('/login'));
      } catch {
        notice('Sign out could not finish. Check your connection and select Retry sign out.');
        el('signout').hidden = false; el('signout').textContent = 'Retry sign out';
        el('signout').onclick = finish;
      }
    };
    await finish(); return;
  }
  const code = pageUrl.searchParams.get('code');
  if (code) {
    const result = await client.auth.exchangeCodeForSession(code);
    if (result.error) throw result.error;
  }
  if (code || (recovery && pageUrl.searchParams.has('authorization_id'))) {
    pageUrl.searchParams.delete('code');
    if (recovery) pageUrl.searchParams.delete('authorization_id');
    history.replaceState(history.state, '', pageUrl);
  }
  el('auth-form').onsubmit = async event => {
    event.preventDefault(); const {error} = await client.auth.signInWithPassword({email: el('email').value, password: el('password').value});
    if (error) notice(error.message); else update().catch(error => notice(error.message));
  };
  el('signup').onclick = async () => {
    const result = await client.auth.signUp({email: el('email').value, password: el('password').value,
      options: {emailRedirectTo: location.origin + OC.generateUrl('/apps/stratus2_ai/auth') + (authorizationId ? '?authorization_id=' + encodeURIComponent(authorizationId) : '')}});
    notice(result.error ? result.error.message : 'If this is a new account, check your email to confirm it. If you already have an account, sign in instead.');
  };
  el('reset').onclick = async () => {
    const result = await client.auth.resetPasswordForEmail(el('email').value, {redirectTo: location.origin + OC.generateUrl('/apps/stratus2_ai/auth') + '?mode=recovery'});
    notice(result.error ? result.error.message : 'If an account exists, a password reset email is on its way.');
  };
  el('signout').onclick = async () => {
    try {
      const {error} = await client.auth.signOut({scope: 'local'});
      if (error) throw error;
      location.reload();
    } catch {notice('Sign out could not finish. Check your connection and try again.');}
  };
  async function respondToConsent(method) {
    const result = await client.auth.oauth[method](authorizationId, {skipBrowserRedirect: true});
    if (!result.error) redirectSafe(result.data.redirect_url);
    else if (!restartStaleAuthorization(result.error)) notice(result.error.message);
  }
  el('allow').onclick = () => respondToConsent('approveAuthorization');
  el('deny').onclick = () => respondToConsent('denyAuthorization');
  await update();
}
main().catch(error => notice(error.message));
