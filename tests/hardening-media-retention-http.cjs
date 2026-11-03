'use strict';
const assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process');
const path=require('node:path');
const {start}=require('./hardening-agent-confirmation-server.cjs');
const root=path.resolve(__dirname,'..');
function fixture(...args){
  const r=spawnSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'hardening-media-retention-fixture.php'),...args.map(String)],{cwd:root,encoding:'utf8'});
  assert.equal(r.status,0,r.stderr||r.stdout);return JSON.parse(r.stdout);
}
async function main(){
  const server=await start();let checks=0;
  function check(value,message){assert.ok(value,message);checks++;}
  try{
    const {data,Client}=server,owner=new Client(),viewer=new Client(),foreign=new Client();
    const seeded=fixture('seed',data.organizationId,data.users.owner.id);
    fixture('viewer',data.users.coworker.id,data.organizationId);
    await owner.login('owner');await viewer.login('coworker');await foreign.login('foreign');
    const live=seeded.media[0].publicId,expired=seeded.media[3].publicId;
    let r=await owner.request('/api/glasses-vision-media.php?publicId='+live);
    check(r.status===200&&r.headers.get('content-type')==='image/png','Manager reads retained private image.');
    check(/no-store/.test(r.headers.get('cache-control')||''),'Private bytes are not cached.');
    r=await owner.request('/api/glasses-vision-media.php?publicId='+expired);
    check(r.status===410,'Expired active image returns HTTP410 before cleanup.');
    check(!r.text.includes('PNG'),'Expired response contains no image bytes.');
    r=await viewer.request('/api/glasses-vision-media.php?publicId='+live);
    check(r.status===403,'View-only account cannot read private training bytes.');
    r=await foreign.request('/api/glasses-vision-media.php?publicId='+live);
    check(r.status===404,'Foreign manager cannot read another tenant image.');
    r=await new Client().request('/api/glasses-vision-media.php?publicId='+live);
    check([302,401,403].includes(r.status),'Unauthenticated client cannot retrieve media.');
    r=await owner.request('/api/glasses-vision-media.php',{action:'export_manifest',datasetPublicId:seeded.dataset});
    check(r.status===200&&r.json?.manifest?.mediaCount===3,'Authenticated export excludes expired image before cleanup.');
    check(!r.json.manifest.items.some(x=>x.publicId===expired),'Expired image never reaches exported manifest.');
    r=await viewer.request('/api/glasses-vision-media.php',{action:'export_manifest',datasetPublicId:seeded.dataset});
    check(r.status===403,'Export remains restricted to management.');
    r=await owner.request('/api/glasses-vision-media.php',{action:'expire'},false,'wrong-token');
    check(r.status===419||r.status===403,'Cleanup requires a valid CSRF token.');
    r=await owner.request('/api/glasses-vision-training-release.php?publicId='+seeded.release.publicId);
    check(r.status===410,'Previously built training ZIP is unavailable when a source expires.');
    const retainedRelease=fixture('fresh-release',data.organizationId,data.users.owner.id,seeded.dataset);
    r=await owner.request('/api/glasses-vision-training-release.php?publicId='+retainedRelease.publicId);
    check(r.status===200&&r.headers.get('content-type')==='application/zip'&&r.headers.get('x-gelato-sha256')===retainedRelease.artifactSha256,'Manager can download a retained checksum-addressed training ZIP.');
    r=await viewer.request('/api/glasses-vision-training-release.php?publicId='+retainedRelease.publicId);
    check(r.status===403,'View-only account cannot bypass private-image authority through a ZIP.');
    r=await foreign.request('/api/glasses-vision-training-release.php?publicId='+seeded.release.publicId);
    check(r.status===404,'Release retention guard preserves tenant non-disclosure.');
    r=await owner.request('/api/glasses-vision-media.php',{action:'expire',limit:1});
    check(r.status===200&&r.json?.result?.expired===1,'Authorized CSRF-protected cleanup removes expired bytes.');
    r=await owner.request('/api/glasses-vision-media.php?publicId='+expired);
    check(r.status===410,'Deleted image remains unavailable after cleanup.');
    fixture('expire',data.organizationId,live);
    r=await owner.request('/api/glasses-vision-media.php?publicId='+live);
    check(r.status===410,'A retained image becomes unavailable after passage of its stored deadline.');
    for(const route of ['/tests/hardening-media-retention-fixture.php','/tests/hardening-media-retention-contract.php','/scripts/expire-vision-training-media.php']){
      r=await new Client().request(route);check(r.status===404,'Maintenance/test PHP is inaccessible over HTTP: '+route);
    }
    console.log(`media-retention HTTP: ${checks} checks passed`);
  }catch(e){console.error(server.logs());throw e;}finally{await server.stop();}
}
main().catch(e=>{console.error(e);process.exitCode=1;});
