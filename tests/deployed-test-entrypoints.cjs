'use strict';
// Real HTTP checks: a deployed test URL must stop before configuration/database access.
const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const net = require('node:net');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const scripts = [
  'need-more-vendor-intelligence-contract.php',
  'purchasing-vendor-integrity-contract.php',
  'need-more-vendor-agent-contract.php',
];
async function main() {
  const reservation = net.createServer();
  await new Promise((resolve, reject) => { reservation.once('error', reject); reservation.listen(0, '127.0.0.1', resolve); });
  const port = reservation.address().port;
  await new Promise(resolve => reservation.close(resolve));
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root], {cwd: root, stdio: ['ignore', 'ignore', 'pipe']});
  let failure = null, logs = '', checks = 0;
  server.once('error', error => { failure = error; });
  server.stderr.on('data', chunk => { logs = (logs + chunk.toString()).slice(-4000); });
  try {
    let ready = false;
    for(let attempt=0;attempt<100;attempt++) {
      if(failure) throw failure;
      if(server.exitCode !== null) throw new Error(`PHP server exited early: ${logs}`);
      try { await fetch(`http://127.0.0.1:${port}/tests/${scripts[0]}`, {signal: AbortSignal.timeout(500)}); ready=true; break; }
      catch { await new Promise(resolve => setTimeout(resolve, 30)); }
    }
    assert.ok(ready, 'PHP HTTP server did not become ready');
    for(const script of scripts) for(const method of ['GET','POST','HEAD']) {
      const response = await fetch(`http://127.0.0.1:${port}/tests/${script}`, {method, signal: AbortSignal.timeout(3000)});
      assert.equal(response.status, 404, `${script} accepted ${method} execution`);
      assert.match(response.headers.get('content-type') || '', /^text\/plain/i, `${script} did not stop at its entry guard`);
      if(method !== 'HEAD') assert.equal(await response.text(), 'Not found.', `${script} loaded app/bootstrap content`);
      checks++;
    }
    console.log(`deployed test entrypoints: ${checks} HTTP denials passed`);
  } finally {
    server.kill('SIGTERM');
    await new Promise(resolve => { if(server.exitCode!==null)resolve(); else server.once('exit',resolve); });
  }
}
main().catch(error => { console.error(error); process.exitCode=1; });
