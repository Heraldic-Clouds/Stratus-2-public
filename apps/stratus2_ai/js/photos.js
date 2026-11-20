(() => {
  'use strict';
  const el = id => document.getElementById(id), base = OC.generateUrl('/apps/stratus2_ai');
  let paid = false, enabled = false;
  async function api(path, method = 'GET', body) {
    const response = await fetch(base + path, {method, credentials: 'same-origin', headers: {'Content-Type': 'application/json', requesttoken: OC.requestToken}, ...(body ? {body: JSON.stringify(body)} : {})});
    const data = await response.json(); if (!response.ok) throw new Error(data.error || 'Unable to complete the request.'); return data;
  }
  function redirect(raw, hosts) {
    const url = new URL(raw); if (url.protocol !== 'https:' || !hosts.includes(url.hostname)) throw new Error('Unexpected payment destination.'); location.assign(url.href);
  }
  function blocks() {
    const value = Number(el('storage-blocks').value);
    if (!Number.isInteger(value) || value < 0 || value > 99) throw new Error('Choose 0–99 additional blocks.'); return value;
  }
  function summary() {
    try { const value = blocks(); el('storage-summary').textContent = `${100 * (1 + value)} GB · $${10 + 2 * value}/month`; }
    catch (error) { el('storage-summary').textContent = error.message; }
  }
  window.Stratus2PhotoTools = {batch: false, allowed: () => paid && enabled};
  const drafts = {organize: el('prompt').value, photo: 'Find black and white photos, colorize them, and save them in folder Colorized and originals in Original.'};
  let mode = 'organize';
  for (const name of ['organize', 'photo']) el('mode-' + name).onclick = () => {
    drafts[mode] = el('prompt').value; mode = name; window.Stratus2PhotoTools.batch = name === 'photo';
    el('photo-panel').hidden = name !== 'photo';
    for (const item of ['organize', 'photo']) el('mode-' + item).setAttribute('aria-pressed', String(name === item));
    el('prompt').value = drafts[name];
    el('folder-label').textContent = name === 'photo' ? 'Folder to enhance and organize' : 'Folder to organize';
    el('prompt-label').textContent = name === 'photo' ? 'What should happen to these files?' : 'How would you like things organized?';
    el('analyze-submit').disabled = name === 'photo' && !(paid && enabled);
  };
  async function refreshBilling() {
    const data = await api('/billing/status'); paid = data.paid; enabled = data.enhancement_enabled;
    el('analyze-submit').disabled = mode === 'photo' && !(paid && enabled);
    el('photo-access').textContent = !enabled ? 'Photo improvement is not enabled yet.' : paid ? 'Photo enhancement is included with your account.' : 'Photo enhancement requires a paid storage plan.';
    el('billing-status').textContent = paid ? `Your allowance: ${data.capacity_gb} GB.${data.legacy ? ' You have a legacy subscription. Changing storage switches to the $10 base plan.' : ''}` : 'Your account uses the free storage allowance.';
    const paymentsEnabled = data.payments_enabled !== false;
    el('checkout').hidden = paid || !paymentsEnabled;
    el('storage-change').hidden = !paid || !paymentsEnabled;
    el('portal').hidden = !paid || !(data.portal_enabled ?? paymentsEnabled);
    el('storage-blocks').value = data.extra_blocks; summary();
  }
  el('storage-blocks').oninput = summary;
  el('checkout').onclick = async () => { try { const data = await api('/billing/checkout', 'POST', {extraBlocks: blocks()}); redirect(data.url, ['checkout.stripe.com']); } catch (error) { el('billing-status').textContent = error.message; } };
  el('portal').onclick = async () => { try { redirect((await api('/billing/portal', 'POST')).url, ['billing.stripe.com']); } catch (error) { el('billing-status').textContent = error.message; } };
  el('storage-change').onclick = async () => {
    el('storage-change').disabled = true;
    try {
      const quote = await api('/billing/preview', 'POST', {extraBlocks: blocks()});
      if (!window.confirm(`Change to ${100 * (1 + quote.extraBlocks)} GB for $${quote.monthly_usd}/month? Amount due now: $${quote.due_usd.toFixed(2)}. Stripe may charge your saved payment method. Existing files are kept if you reduce storage.`)) return;
      const result = await api('/billing/storage', 'POST', {extraBlocks: quote.extraBlocks, prorationDate: quote.prorationDate, confirmation: quote.confirmation});
      if (result.pending && result.payment_url) redirect(result.payment_url, ['invoice.stripe.com']);
      else { await refreshBilling(); el('billing-status').textContent += result.pending ? ' Payment is pending. Complete it in Manage subscription.' : ' Storage change applied.'; }
    } catch (error) { el('billing-status').textContent = error.message; } finally { el('storage-change').disabled = false; }
  };
  refreshBilling().catch(() => { el('photo-access').textContent = 'Unable to verify your subscription. Please refresh to retry.'; el('billing-status').textContent = 'Billing is temporarily unavailable.'; });
})();
