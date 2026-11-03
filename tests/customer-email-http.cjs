'use strict';
// Exercise public controllers with a captured local sendmail transport; no external mail.
const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const root = path.resolve(__dirname, '..');
const fixture = path.join(__dirname, 'customer-email-http-fixture.php');
function db(mode, email) {
  const run = spawnSync('php', [fixture, mode, ...(email ? [email] : [])], {cwd: root, encoding: 'utf8'});
  assert.equal(run.status, 0, run.stderr || run.stdout);
  return JSON.parse(run.stdout);
}
function csrf(html) {
  const match = html.match(/name="csrf_token" value="([^"]+)"/);
  assert.ok(match, 'CSRF form token must be rendered');
  return match[1];
}
async function main() {
  const data = db('seed');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'gelato-customer-mail-'));
  const capture = path.join(dir, 'mail.txt');
  const reservation = net.createServer();
  await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
  const port = reservation.address().port;
  await new Promise(resolve => reservation.close(resolve));
  const shellQuote = value => `'${value.replace(/'/g, "'\\''")}'`;
  const server = spawn('php', ['-d', `sendmail_path=php ${shellQuote(fixture)} mail`, '-S', `127.0.0.1:${port}`, '-t', root], {
    cwd: root, env: {...process.env, GELATO_CUSTOMER_MAIL_CAPTURE: capture}, stdio: ['ignore', 'ignore', 'pipe'],
  });
  let logs = '', failed = null, cookie = '';
  server.stderr.on('data', chunk => { logs = (logs + chunk.toString()).slice(-8000); });
  server.once('error', error => { failed = error; });
  const base = `http://127.0.0.1:${port}`;
  async function request(route, body) {
    const response = await fetch(base + route, {
      method: body ? 'POST' : 'GET', redirect: 'manual', signal: AbortSignal.timeout(10000),
      headers: {...(cookie ? {Cookie: cookie} : {}), ...(body ? {'Content-Type': 'application/x-www-form-urlencoded'} : {})},
      ...(body ? {body: new URLSearchParams(body)} : {}),
    });
    const headers = response.headers.getSetCookie?.() || [response.headers.get('set-cookie')].filter(Boolean);
    if(headers.length) cookie = headers.at(-1).split(';')[0];
    return {response, html: await response.text()};
  }
  try {
    let ready = false;
    for(let attempt=0;attempt<100;attempt++) {
      if(failed)throw failed;
      if(server.exitCode!==null)throw new Error(`PHP server stopped: ${logs}`);
      try { await fetch(base+'/tests/customer-email-http-fixture.php', {signal: AbortSignal.timeout(300)}); ready=true;break; }
      catch { await new Promise(resolve=>setTimeout(resolve,30)); }
    }
    assert.ok(ready, 'PHP server must start');
    let page = await request('/customer-signup.php');
    assert.equal(page.response.status, 200, page.html);
    const firstCsrf = csrf(page.html);
    page = await request('/customer-signup.php', {csrf_token:'invalid',first_name:'Verified',last_name:'Guest',email:data.email});
    assert.match(page.html, /signup form expired/);
    assert.equal(fs.existsSync(capture), false, 'Invalid CSRF must not send an email');
    page = await request('/customer-signup.php', {csrf_token:firstCsrf,first_name:'Verified',last_name:'Guest',email:data.email,return:'customer-account.php'});
    assert.match(page.html, /Check your email for a verification link/, page.html);
    assert.doesNotMatch(page.html, /customer-verify-email\.php\?token=/, 'Public signup response must not disclose the token');
    assert.doesNotMatch(page.html, /6024440198|Private Customer/, 'Public signup must not disclose existing CRM fields');
    let persisted = db('inspect', data.email);
    assert.equal(persisted.users, 0, 'Email request must not create an account');
    assert.equal(persisted.crm.user_id, null, 'Email request must not claim CRM identity');
    const mail = fs.readFileSync(capture, 'utf8');
    const match = mail.match(/customer-verify-email\.php\?token=([a-f0-9]{64})/);
    assert.ok(match, 'Captured verification email must contain a one-time link');
    const token = match[1];
    page = await request('/customer-verify-email.php?token='+token);
    assert.equal(page.response.headers.get('referrer-policy'), 'no-referrer');
    assert.match(page.response.headers.get('cache-control') || '', /no-store/);
    persisted = db('inspect', data.email);
    assert.equal(persisted.crm.user_id, null, 'Opening the email must not consume or claim anything');
    const verifyCsrf = csrf(page.html);
    page = await request('/customer-verify-email.php', {token,csrf_token:'invalid',password:'Recipient-Password!42',password_confirm:'Recipient-Password!42'});
    assert.match(page.html, /confirmation form expired/);
    page = await request('/customer-verify-email.php', {token,csrf_token:verifyCsrf,password:'Recipient-Password!42',password_confirm:'Recipient-Password!42'});
    assert.equal(page.response.status, 302, page.html);
    assert.equal(page.response.headers.get('location'), 'customer-account.php');
    persisted = db('inspect', data.email);
    assert.equal(persisted.users, 1);
    assert.ok(Number(persisted.crm.user_id)>0, 'Confirmed recipient must receive the CRM link');
    page = await request('/customer-account.php');
    assert.equal(page.response.status, 200, page.html);
    assert.match(page.html, /Verified Guest/, 'A credential-bound session must reach its customer account');
    page = await request('/customer-verify-email.php?token='+token);
    assert.match(page.html, /invalid, expired, or already used/, 'Used link must be rejected');
    assert.doesNotMatch(page.html, /name="password"/, 'Used token must not present a completion form');
    // Model an existing linked account from the previous unverified signup flow.
    db('legacy-unverified', data.email);
    const priorMail = fs.readFileSync(capture, 'utf8');
    for(const route of ['/customer-account.php', '/customer-order-photo.php?order=legacy&share=1', '/customer-order-photos.php?order=legacy']) {
      page = await request(route);
      assert.equal(page.response.status, 302, 'Unverified legacy identity must be denied: '+route);
      assert.match(page.response.headers.get('location') || '', /customer-login\.php.*verify=required/, 'Denied customer access must explain the verification route');
    }
    page = await request('/online-order.php', {csrf_token:'irrelevant',location_id:'1',cart_json:'[]'});
    assert.equal(page.response.status, 302, page.html);
    assert.match(page.response.headers.get('location') || '', /^customer-signup\.php/, 'Legacy identity must not reach checkout');
    page = await request('/customer-login.php');
    page = await request('/customer-login.php', {csrf_token:csrf(page.html),email:data.email,password:'Recipient-Password!42'});
    assert.equal(page.response.status, 200, 'An unverified account must not complete customer login');
    assert.match(page.html, /Verify your email before opening your customer account/);
    assert.match(page.html, /Send email verification link/);
    assert.equal(fs.readFileSync(capture, 'utf8'), priorMail, 'Login or denied access must not automatically send verification email');
    page = await request('/customer-signup.php');
    page = await request('/customer-signup.php', {csrf_token:csrf(page.html),first_name:'Verified',last_name:'Guest',email:data.email});
    assert.match(page.html, /Check your email for a verification link/, page.html);
    const legacyToken = fs.readFileSync(capture, 'utf8').match(/customer-verify-email\.php\?token=([a-f0-9]{64})/)[1];
    assert.notEqual(legacyToken, token);
    page = await request('/customer-verify-email.php?token='+legacyToken);
    page = await request('/customer-verify-email.php', {token:legacyToken,csrf_token:csrf(page.html),password:'Recipient-Password!42',password_confirm:'Recipient-Password!42'});
    assert.equal(page.response.status, 302, page.html);
    page = await request('/customer-account.php');
    assert.equal(page.response.status, 200, 'Verified legacy customer must regain account access');
    assert.match(page.html, /Verified Guest/);
    assert.equal(db('inspect', data.email).users, 1, 'Legacy verification must reuse the existing account');
    console.log('customer email verification HTTP: CSRF, private response, captured mail, recipient password, session, replay and legacy access gates passed');
  } finally {
    server.kill('SIGTERM');
    await new Promise(resolve=>{if(server.exitCode!==null)resolve();else server.once('exit',resolve);});
    fs.rmSync(dir,{recursive:true,force:true});
  }
}
main().catch(error=>{console.error(error);process.exitCode=1;});
