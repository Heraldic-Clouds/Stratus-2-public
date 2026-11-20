(() => {
  'use strict';
  const el = id => document.getElementById(id);
  const base = OC.generateUrl('/apps/stratus2_ai');
  let current = null, timer = null;
  async function api(path, method = 'GET', body) {
    const response = await fetch(base + path, {method, credentials: 'same-origin', headers: {'Content-Type': 'application/json', requesttoken: OC.requestToken}, ...(body ? {body: JSON.stringify(body)} : {})});
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'The request could not be completed.');
    return data;
  }
  function message(value) { el('notice').textContent = value; }
  function show(job) {
    current = job.id; el('job-section').hidden = false;
    el('job-status').textContent = `${job.state} · ${job.completed} of ${job.total} files analyzed`;
    el('progress').max = job.total; el('progress').value = job.completed;
    el('apply').disabled = !['ready', 'applying'].includes(job.state);
    el('undo').disabled = !['applied', 'applying', 'undoing', 'batch_done', 'batch_undoing'].includes(job.state);
    el('cancel').disabled = !['queued', 'analyzing', 'ready'].includes(job.state);
    el('changes').replaceChildren(...job.manifest.map(item => {
      const row = document.createElement('tr');
      const reason = [item.action, item.reason, item.original_to && item.original_to !== item.from ? `Original: ${item.original_to}` : '', item.phase, item.error || '', item.similar_to ? `similar to file ${item.similar_to}` : ''].filter(Boolean).join(' · ');
      for (const value of [item.from, item.to, reason]) {
        const cell = document.createElement('td'); cell.textContent = value; row.append(cell);
      }
      return row;
    }));
    clearTimeout(timer);
    if (['queued', 'analyzing', 'applying', 'undoing', 'batch_running', 'batch_undoing'].includes(job.state)) timer = setTimeout(() => load(job.id), 3000);
    if (job.error) message(job.error);
  }
  async function load(id) { try { show(await api('/jobs/' + id)); } catch (error) { message(error.message); } }
  async function history() {
    const rows = await api('/jobs');
    el('history').replaceChildren(...rows.map(job => {
      const item = document.createElement('li'), button = document.createElement('button');
      button.textContent = new Date(Number(job.created_at) * 1000).toLocaleString() + ' Â· ' + job.state;
      button.onclick = () => load(job.id); item.append(button); return item;
    }));
  }
  el('browse').onclick = () => {
    if (OC.dialogs?.filepicker) OC.dialogs.filepicker('Choose a folder', path => { el('folder').value = path.replace(/^\//, ''); }, false, 'httpd/unix-directory', true);
    else { el('folder').focus(); message('Enter the folder path shown in Files. Leave blank to analyze your files folder.'); }
  };
  el('analyze-form').onsubmit = async event => {
    event.preventDefault(); const button = event.submitter; button.disabled = true; message('');
    try {
      const batch = Boolean(window.Stratus2PhotoTools?.batch);
      if (batch && !window.Stratus2PhotoTools.allowed()) throw new Error('Photo enhancement requires an eligible account.');
      const estimate = await api('/estimate?folder=' + encodeURIComponent(el('folder').value));
      if (!window.confirm(`Analyze ${estimate.files} files, including ${estimate.images} images? AI organization is included.`)) return;
      show(await api('/jobs', 'POST', {folder: el('folder').value, prompt: el('prompt').value, consent: el('consent').checked, batch})); await history();
    }
    catch (error) { message(error.message); } finally { button.disabled = Boolean(window.Stratus2PhotoTools?.batch && !window.Stratus2PhotoTools.allowed()); }
  };
  for (const action of ['apply', 'cancel', 'undo']) el(action).onclick = async () => {
    el(action).disabled = true;
    try { show(await api('/jobs/' + current + '/' + action, 'POST')); await history(); }
    catch (error) { message(error.message); await load(current); }
  };
  history().catch(error => message(error.message));
})();
