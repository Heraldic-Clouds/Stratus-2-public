export const RANGE_SIZE = 64 * 1024 * 1024;

// Streams to disk. Never buffers a whole file or falls back to a blob in RAM.
export async function transferRanges({plan, offset = 0, writable, request = fetch, refresh, signal, progress = () => {}, rangeSize = RANGE_SIZE}) {
  if (!Number.isSafeInteger(plan.size) || !Number.isSafeInteger(offset) || offset < 0 || offset > plan.size) throw Error('Invalid file size');
  let position = offset, failures = 0;
  while (position < plan.size) {
    signal?.throwIfAborted();
    if (plan.mode === 'direct' && plan.expires_at * 1000 < Date.now() + 15000) plan = {...plan, ...await refresh()};
    const end = Math.min(plan.size - 1, position + rangeSize - 1);
    let reader;
    try {
      const response = await request(plan.url, {method: 'GET', headers: {...plan.headers, Range: `bytes=${position}-${end}`},
        credentials: plan.mode === 'direct' ? 'omit' : 'same-origin', referrerPolicy: 'no-referrer', cache: 'no-store', signal});
      if (response.status === 412) throw Object.assign(Error('The file changed. Start a new download.'), {permanent: true});
      if (!response.ok) throw Error('Storage request failed');
      if (response.status !== 206 || response.headers.get('Content-Range') !== `bytes ${position}-${end}/${plan.size}`
        || response.headers.get('ETag') !== plan.headers['If-Match']) {
        throw Object.assign(Error('Storage did not return the expected file range. Use Nextcloud.'), {permanent: true});
      }
      reader = response.body.getReader();
      let remaining = end - position + 1;
      while (true) {
        signal?.throwIfAborted();
        const {done, value} = await reader.read();
        if (done) break;
        if (!value || value.byteLength > remaining) throw Object.assign(Error('Invalid range length'), {permanent: true});
        await writable.write({type: 'write', position, data: value});
        position += value.byteLength; remaining -= value.byteLength;
        progress(position); // Includes bytes received before an interrupted range.
      }
      if (remaining !== 0) throw Error('Range interrupted');
      failures = 0;
    } catch (error) {
      if (signal?.aborted || error.permanent || ++failures > 3) throw error;
      plan = {...plan, ...await refresh()}; // Rechecks Nextcloud access and the original ETags.
      await new Promise((resolve, reject) => {
        const timer = setTimeout(done, 250 * 2 ** failures);
        function done() {signal?.removeEventListener('abort', abort); resolve();}
        function abort() {clearTimeout(timer); signal.removeEventListener('abort', abort); reject(signal.reason);}
        signal?.addEventListener('abort', abort, {once: true});
        if (signal?.aborted) abort();
      });
    } finally {await reader?.cancel().catch(() => {});}
  }
  return position;
}
