// Local FileSystem API verification complements the live zero-filled fixtures.
// Uses a fresh profile and public HTTPS origin; no login or provider credentials.
import {chromium} from '@playwright/test';
import {mkdtempSync, realpathSync, rmSync, statfsSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, dirname, basename} from 'node:path';
import assert from 'node:assert/strict';

assert(process.argv.includes('--confirm-local-disk-test'), 'Explicit disk-test confirmation is required');
const root = realpathSync(tmpdir());
const disk = statfsSync(root);
assert(disk.bavail * disk.bsize > 12e9, 'At least 12 GB free disk is required');
const profile = realpathSync(mkdtempSync(join(root, 'stratus-offset-verify-')));
assert.equal(dirname(profile), root); assert(basename(profile).startsWith('stratus-offset-verify-'));
let context;
try {
  context = await chromium.launchPersistentContext(profile, {headless: true});
  const page = await context.newPage();
  await page.goto('https://stratus.example.com/robots.txt');
  console.log(JSON.stringify({local_offset_test_started: true}));
  const result = await page.evaluate(async () => {
    const storage = await navigator.storage.getDirectory();
    const dir = await storage.getDirectoryHandle('stratus-transfer-offset-verification', {create: true});
    const handle = await dir.getFileHandle('offsets.bin', {create: true});
    const positions = [2 ** 32 - 8, 2 ** 32 + 32, 2 ** 33 + 32];
    const size = positions.at(-1) + 32;
    const quota = await navigator.storage.estimate();
    if (quota.quota - quota.usage < size + 1e8) throw Error('Insufficient origin quota');
    let writer;
    try {
      writer = await handle.createWritable({mode: 'exclusive'});
      for (const [index, position] of positions.entries()) {
        await writer.write({type: 'write', position, data: Uint8Array.from({length: 16}, (_, byte) => 17 + index * 64 + byte)});
      }
      await writer.truncate(size); await writer.close(); writer = null;
      const file = await handle.getFile();
      if (file.size !== size) throw Error('Incorrect large file size');
      for (const [index, position] of positions.entries()) {
        const bytes = new Uint8Array(await file.slice(position, position + 16).arrayBuffer());
        if (!bytes.every((byte, at) => byte === 17 + index * 64 + at)) throw Error('Incorrect nonzero bytes at large offset');
        const before = new Uint8Array(await file.slice(position - 1, position).arrayBuffer());
        const after = new Uint8Array(await file.slice(position + 16, position + 17).arrayBuffer());
        if (before[0] !== 0 || after[0] !== 0) throw Error('Unexpected adjacent bytes');
      }
      return {native_large_offsets_verified: true, logical_size: size, positions, nonzero_markers_and_adjacent_zeros_verified: true};
    } finally {
      await writer?.abort().catch(() => {});
      await dir.removeEntry('offsets.bin');
    }
  });
  writeFileSync('.tools/transfer-local-offsets.json', JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result));
} catch (error) {
  console.log(JSON.stringify({local_offset_test_failed: true, error_type: error.name})); process.exitCode = 1;
} finally {
  await context?.close();
  assert.equal(dirname(profile), root); assert(basename(profile).startsWith('stratus-offset-verify-'));
  rmSync(profile, {recursive: true, force: true, maxRetries: 10, retryDelay: 200});
}
