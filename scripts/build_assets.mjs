import {createHash} from 'node:crypto';
import {readFile,writeFile,readdir} from 'node:fs/promises';
import path from 'node:path';

const root=path.resolve(process.argv[2] || 'apps/stratus2_ai');
const manifest={};
for (const directory of ['js','css']) {
  for (const file of await readdir(path.join(root,directory))) {
    if (!file.endsWith('.'+directory) || /-[a-f0-9]{20}\./.test(file)) continue;
    const bytes=await readFile(path.join(root,directory,file));
    const stem=path.parse(file).name;
    const name=stem+'-'+createHash('sha256').update(bytes).digest('hex').slice(0,20);
    await writeFile(path.join(root,directory,name+'.'+directory),bytes);
    manifest[directory+'/'+file]=name;
  }
}
await writeFile(path.join(root,'asset-manifest.json'),JSON.stringify(manifest,null,2)+'\n');
