'use strict';
const fs=require('fs');
const assert=require('assert');
const app=fs.readFileSync('public/assets/app.js','utf8');
const index=fs.readFileSync('public/index.php','utf8');
const en=JSON.parse(fs.readFileSync('public/languages/en.json','utf8'));
const fr=JSON.parse(fs.readFileSync('public/languages/fr.json','utf8'));
assert(index.includes('data-action="extract"') && !index.includes('data-action="extract" disabled'), 'Extract header button uses server capabilities');
assert(app.includes('state.capabilities.zipExtract') && app.includes("file?.extension === 'zip'"),'Extract gated to selected ZIP');
assert(app.includes("api('extract',{path:file.path,directory:state.path})"),'Extract requests destination current folder');
assert(app.includes("api('bulk-transfer',{paths,directory:dest,mode:action})"),'Bulk transfer sends all selected sources');
assert(app.includes('result.results.filter(item=>!item.ok)'),'Bulk partial failure is visible');
for(const k of ['Extraire en dossier','Sélectionnez entre 1 et 25 éléments.','Archive extraite vers {path}','{success} opération(s) réussie(s), {failed} échec(s).']){
  assert(Object.hasOwn(en,k) && Object.hasOwn(fr,k), 'Missing language key: '+k);
}
console.log('PASS: ZIP context actions, capabilities, bounded multi-select and bilingual messages');
