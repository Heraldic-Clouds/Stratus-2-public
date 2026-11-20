import test from 'node:test';
import assert from 'node:assert/strict';
import {transferRanges} from '../../frontend/transfer-client.mjs';

const fixture = Uint8Array.from([10, 20, 30, 40, 50, 60]);
const plan = {mode: 'direct', url: 'https://gateway.storjshare.io/test', size: fixture.length,
  expires_at: Date.now() / 1000 + 120, headers: {'If-Match': '"fixture"'}};
function sink() {
  const data = new Uint8Array(fixture.length);
  return {data, write: async ({position, data: chunk}) => data.set(chunk, position)};
}
function range(start, end, body = fixture.slice(start, end + 1), extra = {}) {
  return new Response(body, {status: 206, headers: {'Content-Range': `bytes ${start}-${end}/${fixture.length}`, ETag: '"fixture"', ...extra}});
}
test('streams exact ranges without cookies, and resumes at the requested byte', async () => {
  const output = sink(); output.data.set(fixture.slice(0, 2)); const requests = [];
  const position = await transferRanges({plan, offset: 2, writable: output, rangeSize: 2, refresh: async () => plan,
    request: async (url, options) => {
      assert.equal(options.credentials, 'omit'); assert.equal(options.referrerPolicy, 'no-referrer'); assert.equal(options.headers['If-Match'], '"fixture"');
      requests.push(options.headers.Range); const [start, end] = options.headers.Range.slice(6).split('-').map(Number); return range(start, end);
    }});
  assert.deepEqual(requests, ['bytes=2-3', 'bytes=4-5']); assert.equal(position, 6); assert.deepEqual(output.data, fixture);
});
test('an interrupted range retries from bytes already written, without duplicating data', async () => {
  const output = sink(); const requests = [];
  await transferRanges({plan, writable: output, rangeSize: 4, refresh: async () => plan,
    request: async (url, {headers}) => {
      requests.push(headers.Range); const [start, end] = headers.Range.slice(6).split('-').map(Number);
      return range(start, end, requests.length === 1 ? fixture.slice(0, 2) : fixture.slice(start, end + 1));
    }});
  assert.deepEqual(requests, ['bytes=0-3', 'bytes=2-5']); assert.deepEqual(output.data, fixture);
});
test('a pause aborts before another write and the remaining bytes can resume', async () => {
  const output = sink(); const controller = new AbortController(); let position = 0;
  await assert.rejects(transferRanges({plan, writable: output, rangeSize: 2, signal: controller.signal, refresh: async () => plan,
    progress: (next) => {position = next; controller.abort();}, request: async () => range(0, 1)}));
  assert.equal(position, 2);
  await transferRanges({plan, offset: position, writable: output, rangeSize: 4, refresh: async () => plan, request: async () => range(2, 5)});
  assert.deepEqual(output.data, fixture);
});
for (const [name, response] of [['ignored Range', () => new Response(fixture)], ['changed ETag', () => range(0, 5, fixture, {ETag: '"changed"'})],
  ['wrong range', () => range(1, 5)], ['failed precondition', () => new Response(null, {status: 412})]]) {
  test(`${name} never writes unexpected bytes`, async () => {
    const output = sink(); let writes = 0; output.write = async () => writes++;
    await assert.rejects(transferRanges({plan, writable: output, refresh: async () => plan, request: async () => response()})); assert.equal(writes, 0);
  });
}
test('an expiring URL is renewed before use', async () => {
  let renewed = 0;
  await transferRanges({plan: {...plan, expires_at: 0}, writable: sink(), refresh: async () => {renewed++; return plan;}, request: async () => range(0, 5)});
  assert.equal(renewed, 1);
});
test('range headers and nonzero writes retain offsets across four and eight GiB', async () => {
  for (const offset of [2 ** 32 - 8, 2 ** 33 + 17]) {
    const payload = Uint8Array.from({length: 32}, (_, index) => index + 31);
    const large = {...plan, size: offset + payload.length};
    let requests = 0; const writes = [];
    const position = await transferRanges({plan: large, offset, rangeSize: 16, refresh: async () => large,
      writable: {write: async ({position, data}) => writes.push({position, data: [...data]})},
      request: async (url, {headers}) => {
        const index = requests++, start = offset + index * 16, end = start + 15;
        assert.equal(headers.Range, `bytes=${start}-${end}`);
        return new Response(payload.slice(index * 16, index * 16 + 16), {status: 206,
          headers: {'Content-Range': `bytes ${start}-${end}/${large.size}`, ETag: '"fixture"'}});
      }});
    assert.equal(position, large.size);
    assert.deepEqual(writes, [{position: offset, data: [...payload.slice(0, 16)]},
      {position: offset + 16, data: [...payload.slice(16)]}]);
  }
});
