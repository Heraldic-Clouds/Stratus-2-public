import {transferRanges} from './transfer-client.mjs';

const $ = (id) => document.getElementById(id);
const notice = (message) => { $('transfer-notice').textContent = message; };
let state, folder = '';
const sizes = (n) => n >= 1e9 ? `${(n / 1e9).toFixed(2)} GB` : `${(n / 1e6).toFixed(1)} MB`;
const dav = (path) => OC.generateUrl('/remote.php/dav/files') + '/' + encodeURIComponent(state.uid) + '/' + path.split('/').map(encodeURIComponent).join('/');
async function api(action, data = {}) {
  const response = await fetch(OC.generateUrl('/apps/stratus_transfer/api/' + action), {method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json', requesttoken: OC.requestToken}, body: JSON.stringify(data), cache: 'no-store'});
  if (!response.ok) throw Error((await response.json()).error || 'Transfer unavailable');
  return response.json();
}
async function database() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('stratus-transfer-downloads', 1);
    request.onupgradeneeded = () => request.result.createObjectStore('downloads', {keyPath: 'id'});
    request.onsuccess = () => resolve(request.result); request.onerror = () => reject(Error('Resume storage unavailable'));
  });
}
async function saved(action, value) {
  const db = await database();
  try {
    return await new Promise((resolve, reject) => {
      const transaction = db.transaction('downloads', action === 'getAll' ? 'readonly' : 'readwrite');
      const request = transaction.objectStore('downloads')[action](value);
      transaction.oncomplete = () => resolve(request.result); transaction.onerror = () => reject(Error('Could not save resume information'));
    });
  } finally {db.close();}
}
function button(label, handler) {const node = document.createElement('button'); node.textContent = label; node.addEventListener('click', handler); return node;}
function fallbackLink(file) {const link = document.createElement('a'); link.href = dav(file.path); link.textContent = 'Download with Nextcloud'; link.setAttribute('download', file.name); return link;}
async function metrics() {
  state = await api('status'); const m = state.metrics;
  $('transfer-metrics').textContent = `${sizes(m.direct_bytes)} direct · ${sizes(m.proxy_bytes)} through AWS · approximately $${m.estimated_savings_usd.toFixed(2)} avoided AWS egress`;
}
async function browse(path) {
  const result = await api('browse', {path}); folder = path;
  $('transfer-path').textContent = path || 'Home'; $('transfer-up').disabled = !path;
  const body = $('transfer-files'); body.replaceChildren();
  for (const file of result.files.sort((a, b) => Number(b.folder) - Number(a.folder) || a.name.localeCompare(b.name))) {
    const row = document.createElement('tr'); const name = document.createElement('td'); const size = document.createElement('td'); const action = document.createElement('td');
    name.textContent = file.name; size.textContent = file.folder ? 'Folder' : sizes(file.size);
    if (file.folder) action.append(button('Open', () => browse(file.path).catch((e) => notice(e.message))));
    else {action.append(button('Save file', () => start(file)), document.createTextNode(' '), fallbackLink(file));}
    row.append(name, size, action); body.append(row);
  }
  notice(result.truncated ? 'This folder has more files. Use Nextcloud to see the complete list.' : 'Choose a file to save. Paused downloads appear below.');
}
function jobView(file) {
  const item = document.createElement('li'); const title = document.createElement('strong'); title.textContent = file.name;
  const label = document.createElement('p'); const bar = document.createElement('progress'); bar.max = Math.max(file.size, 1); bar.value = 0;
  const controls = document.createElement('p'); controls.append(fallbackLink(file)); item.append(title, label, bar, controls); $('transfer-jobs').prepend(item);
  return {label, bar, controls};
}
async function start(file, stored = null, view = null) {
  if (!window.showSaveFilePicker || !navigator.locks) {notice('This browser uses Nextcloud downloads.'); const link = fallbackLink(file); document.body.append(link); link.click(); link.remove(); return;}
  let handle;
  try {
    // Must happen during the user gesture, before any network request.
    if (stored) {handle = stored.handle; if (await handle.requestPermission({mode: 'readwrite'}) !== 'granted') return;}
    else handle = await window.showSaveFilePicker({suggestedName: file.name});
  } catch (error) {if (error.name !== 'AbortError') notice('File saving is unavailable. Use Download with Nextcloud.'); return;}
  view ||= jobView(file);
  await navigator.locks.request('stratus-transfer-' + file.id, {ifAvailable: true}, async (lock) => {
    if (!lock) {view.label.textContent = 'This file is already downloading in another tab.'; return;}
    const downloadUid = state.uid;
    let plan, writer, position = stored?.position || 0, cancelled = false, closed = false, lastReport = 0;
    const abort = new AbortController();
    const pause = button('Pause', () => abort.abort());
    const cancel = button('Cancel', () => {cancelled = true; abort.abort();}); view.controls.replaceChildren(pause, cancel, fallbackLink(file));
    try {
      plan = stored ? await api('refresh', {ticket: stored.ticket}) : await api('download', {fileId: file.id, etag: file.etag});
      if (stored && (plan.etag !== stored.etag || plan.object_etag !== stored.object_etag || plan.size !== stored.size)) throw Error('The file changed. Start a new download.');
      if (stored) {
        const partial = await handle.getFile();
        if (partial.size !== stored.partial_size || partial.lastModified !== stored.partial_modified || partial.size < position) throw Error('The partial file changed. Start a new download.');
      }
      if (plan.mode === 'proxy') {plan.url = dav(plan.path); plan.headers = {'If-Match': '"' + plan.etag.replace(/^"|"$/g, '') + '"'};}
      writer = await handle.createWritable({keepExistingData: !!stored, mode: 'exclusive'});
      view.label.textContent = plan.mode === 'direct' ? 'Downloading directly from storage…' : 'Downloading through Nextcloud…';
      const progress = (next) => {
        position = next; view.bar.value = next;
        view.label.textContent = `${sizes(next)} of ${sizes(plan.size)} · ${plan.mode === 'direct' ? 'direct storage' : 'through AWS'}`;
        if (Date.now() - lastReport > 5000) {lastReport = Date.now(); api('progress', {ticket: plan.ticket, position}).catch(() => {});}
      };
      await transferRanges({plan, offset: position, writable: writer, signal: abort.signal, progress,
        refresh: async () => {
          const renewed = await api('refresh', {ticket: plan.ticket});
          if (plan.mode === 'proxy') return {...renewed, url: dav(renewed.path), headers: plan.headers};
          return renewed;
        }});
      await writer.truncate(plan.size); await writer.close(); closed = true;
      await api('progress', {ticket: plan.ticket, position: plan.size})
        .catch(() => notice('Your download is saved. Transfer statistics could not be updated.'));
      await saved('delete', downloadUid + ':' + plan.ticket); view.label.textContent = 'Download complete'; view.controls.replaceChildren(fallbackLink(file));
    } catch (error) {
      if (writer && !closed) {try {await writer.close(); closed = true;} catch {view.label.textContent = 'Could not save the partial file. Use Nextcloud.';}}
      if (closed && plan && !cancelled && position < plan.size) {
        const partial = await handle.getFile();
        const record = {id: downloadUid + ':' + plan.ticket, uid: downloadUid, ticket: plan.ticket, file, handle, position,
          etag: plan.etag, object_etag: plan.object_etag, size: plan.size, partial_size: partial.size, partial_modified: partial.lastModified};
        try {await saved('put', record);} catch {notice('The browser could not retain resume information. Keep this tab open.');}
        view.label.textContent = abort.signal.aborted ? `Paused at ${sizes(position)}. The partial file is saved.` : error.message;
        view.controls.replaceChildren(button('Resume', () => start(file, record, view)), fallbackLink(file));
      } else {view.label.textContent = cancelled ? 'Cancelled. Any partial file remains on your computer.' : error.message; view.controls.replaceChildren(fallbackLink(file));}
      if (cancelled && plan) await saved('delete', downloadUid + ':' + plan.ticket).catch(() => {});
    } finally {if (writer && !closed) await writer.abort().catch(() => {}); await metrics().catch(() => {});}
  });
}
async function init() {
  await metrics(); $('transfer-upload').href = OC.generateUrl('/apps/files/');
  $('transfer-up').addEventListener('click', () => browse(folder.split('/').slice(0, -1).join('/')).catch((e) => notice(e.message)));
  await browse('');
  try {
    for (const record of await saved('getAll')) {
      if (record.uid !== state.uid) continue;
      const view = jobView(record.file); view.bar.value = record.position; view.label.textContent = `Paused at ${sizes(record.position)}`;
      view.controls.prepend(button('Resume', () => start(record.file, record, view)));
    }
  } catch {notice('Downloads work in this tab; this browser cannot retain resume information.');}
}
init().catch((error) => notice(error.message));
