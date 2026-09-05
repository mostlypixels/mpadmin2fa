import assert from 'node:assert/strict';
import {createHash, createHmac, X509Certificate} from 'node:crypto';
import {mkdir, readFile, writeFile} from 'node:fs/promises';
import {join} from 'node:path';
import {setTimeout as delay} from 'node:timers/promises';
import {chromium} from 'playwright';

const runtime = process.env.MP2FA_REQUEST_RUNTIME;
assert.ok(process.env.MP2FA_INTEGRATION === '1' && runtime && process.env.MP2FA_TEST_EMAIL && process.env.MP2FA_TEST_PASSWORD,
  'Browser tests require a disposable installed shop and generated credentials.');
const routes = JSON.parse(await readFile(join(runtime, 'browser-routes.json'), 'utf8'));
const certificate = new X509Certificate(await readFile(join(runtime, 'server.crt')));
const spki = createHash('sha256').update(certificate.publicKey.export({type: 'spki', format: 'der'})).digest('base64');
// Trust only the newly generated test certificate, never arbitrary HTTPS errors.
await mkdir(join(runtime, 'browser-config'), {recursive: true, mode: 0o700});
await mkdir(join(runtime, 'browser-cache'), {recursive: true, mode: 0o700});
const browser = await chromium.launch({
  env: {...process.env, XDG_CONFIG_HOME: join(runtime, 'browser-config'), XDG_CACHE_HOME: join(runtime, 'browser-cache')},
  executablePath: process.env.CHROME_BIN || undefined,
  args: ['--ignore-certificate-errors-spki-list=' + spki],
});
let count = 0;
let phase = 'initialization';
const check = (condition, label) => {
  assert.ok(condition, label);
  ++count;
  console.log('PASS: ' + label);
};
const newContext = (options = {}) => browser.newContext({
  baseURL: 'https://localhost:8443', viewport: {width: 1440, height: 1000}, ...options,
});
const goto = async (page, path) => {
  const response = await page.goto(path, {waitUntil: 'domcontentloaded'});
  if (response && response.status() >= 500) await writeFile(join(runtime, 'browser-page.html'), await page.content(), {mode: 0o600});
  assert.ok(response && response.status() < 500, 'Admin page must render without a server error: ' + await page.title());
};
const login = async (page) => {
  await goto(page, '/admin-dev/index.php?controller=AdminLogin');
  await page.locator('#email').fill(process.env.MP2FA_TEST_EMAIL);
  await page.locator('#passwd').fill(process.env.MP2FA_TEST_PASSWORD);
  await page.locator('#submit_login').click();
  await page.waitForURL((url) => !url.searchParams.has('controller') || url.searchParams.get('controller') !== 'AdminLogin');
};
let lastCounter = -1;
const code = async (secret) => {
  while (Math.floor(Date.now() / 30000) <= lastCounter || Date.now() % 30000 > 27000) {
    await delay(250);
  }
  lastCounter = Math.floor(Date.now() / 30000);
  const bits = [...secret].map((c) => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5, '0')).join('');
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(lastCounter));
  const hmac = createHmac('sha1', key).update(counter).digest();
  const offset = hmac[hmac.length - 1] & 15;
  return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(6, '0');
};
const verify = async (page, secret) => {
  await page.locator('[name="one_time_code[code]"]').fill(await code(secret));
  const verification = page.waitForResponse((r) => r.request().method() === 'POST' && /\/(challenge|enroll)$/.test(new URL(r.url()).pathname));
  await page.locator('[name="one_time_code[submit]"]').click();
  const submitted = await verification;
  assert.equal(submitted.status(), 302, 'Valid authenticator form submission redirects');
  await page.waitForURL((url) => !url.pathname.endsWith('/challenge') && !url.pathname.endsWith('/enroll'));
};

try {
  const context = await newContext();
  const page = await context.newPage();
  page.setDefaultTimeout(30000);
  phase = 'native login and enrollment';
  await login(page);
  await goto(page, routes.enroll);
  check(await page.getByRole('heading', {name: 'Set up an authenticator app', exact: true, level: 3}).isVisible(),
    'native password login reaches first-SuperAdmin enrollment');
  const qr = page.getByAltText('QR code for setting up your authenticator app');
  check(await qr.evaluate((img) => img.complete && img.naturalWidth > 0), 'enrollment QR image renders in the browser');
  const secret = (await page.locator('.card-body p code').innerText()).trim();
  check(/^[A-Z2-7]{32}$/.test(secret), 'manual setup key is available');
  const formMarker = await page.evaluate((url) => new Promise((resolve, reject) => {
    window.mp2faBrowserFormMarker = true;
    const request = new XMLHttpRequest();
    request.open('GET', url);
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    request.onloadend = () => resolve(request.status);
    request.onerror = reject;
    request.send();
  }), routes.settings);
  await delay(500);
  check(formMarker === 403 && await page.evaluate(() => window.mp2faBrowserFormMarker === true),
    'gated background XHR does not reload the enrollment form already open');
  await verify(page, secret);
  check(await page.getByRole('heading', {name: 'Save your recovery codes', exact: true, level: 3}).isVisible(),
    'authenticator confirmation displays recovery codes');
  check(await page.locator('.card-body li code').count() === 10, 'ten recovery codes are displayed');
  const recovery = await page.locator('.card-body li code').first().innerText();
  const codesUrl = page.url();
  await page.getByText('I saved these codes securely.', {exact: true}).click();
  check(await page.getByLabel('I saved these codes securely.').isChecked(), 'the recovery-code acknowledgement checkbox responds to its visible label');
  await page.getByRole('button', {name: 'Continue', exact: true}).click();
  await page.waitForURL((url) => url.searchParams.get('controller') === 'AdminDashboard');
  check(true, 'recovery acknowledgement returns to the native dashboard');
  await goto(page, codesUrl);
  check(!page.url().includes('/recovery-codes'), 'acknowledged recovery codes cannot be displayed again');

  phase = 'fresh password login and MFA';
  const loginContext = await newContext();
  const admin = await loginContext.newPage();
  admin.setDefaultTimeout(30000);
  await login(admin);
  await goto(admin, routes.settings);
  check(admin.url().includes('/challenge'), 'a new browser session is blocked at MFA after password login');
  await verify(admin, secret);
  await goto(admin, routes.settings);
  check(await admin.evaluate(() => window.mpadmin2faStepUpListenerInstalled === true),
    'native admin pages load the packaged step-up listener');

  phase = 'XHR listener and expired verification';
  const originalSend = await admin.evaluateHandle(() => XMLHttpRequest.prototype.send);
  await admin.addScriptTag({url: '/modules/mpadmin2fa/views/js/admin-step-up.js'});
  check(await admin.evaluate((send) => send === XMLHttpRequest.prototype.send, originalSend),
    'loading the listener twice preserves one XHR wrapper');
  await originalSend.dispose();
  const currentUrl = admin.url();
  const normalStatus = await admin.evaluate((url) => new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open('GET', url);
    request.onload = () => resolve(request.status);
    request.onerror = reject;
    request.send();
  }), routes.settings);
  check(normalStatus === 200 && admin.url() === currentUrl, 'ordinary XHR responses do not redirect the page');
  const savedSession = await loginContext.storageState();
  console.log('Waiting for the real 60-second step-up window to expire.');
  await delay(61000);
  const denied = admin.waitForResponse((response) => response.url().includes('/mpadmin2fa/security') && response.request().method() === 'POST');
  // A real browser XHR, real server policy, and the installed package's response listener.
  await admin.evaluate((url) => {
    const request = new XMLHttpRequest();
    request.open('POST', url);
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    request.send();
  }, routes.policy);
  const response = await denied;
  check(response.status() === 403 && (response.headers()['x-mpadmin2fa-redirect'] || '').includes('step_up=1'),
    'expired sensitive XHR receives the server step-up contract');
  await admin.waitForURL((url) => url.pathname.endsWith('/challenge') && url.searchParams.get('step_up') === '1');
  check(await admin.getByRole('heading', {name: 'Confirm it is you to continue', exact: true, level: 3}).isVisible(),
    'the packaged XHR listener navigates to the visible step-up form');

  phase = 'JavaScript-disabled legacy enforcement';
  const noJs = await newContext({javaScriptEnabled: false, storageState: savedSession});
  const plain = await noJs.newPage();
  await goto(plain, routes.legacySensitive);
  check(plain.url().includes('/challenge') && plain.url().includes('step_up=1'),
    'legacy sensitive navigation requires step-up with JavaScript disabled');
  await verify(plain, secret);
  await goto(plain, routes.dashboard);
  check(new URL(plain.url()).searchParams.get('controller') === 'AdminDashboard',
    'native challenge form submission succeeds with JavaScript disabled');
  await noJs.close();

  phase = 'browser step-up completion';
  // Both contexts shared the same authenticated session; request a new explicit challenge.
  await goto(admin, routes.challenge + '&step_up=1');
  await verify(admin, secret);
  await goto(admin, routes.policy);
  const admitted = await admin.evaluate((url) => new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open('POST', url);
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    request.onload = () => resolve({status: request.status, redirect: request.getResponseHeader('X-Mpadmin2fa-Redirect')});
    request.onerror = reject;
    request.send();
  }), routes.policy);
  check(admitted.status === 200 && !admitted.redirect, 'fresh browser step-up admits an invalid, non-mutating policy POST');

  phase = 'recovery interaction';
  const recoveryContext = await newContext();
  const recoveryPage = await recoveryContext.newPage();
  await login(recoveryPage);
  await goto(recoveryPage, routes.challenge);
  const recoveryField = recoveryPage.locator('[name="recovery_code_challenge[recovery_code]"]');
  await recoveryPage.getByText('Use a recovery code', {exact: true}).click();
  const background = await recoveryPage.evaluate((url) => new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open('GET', url);
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    request.onloadend = () => resolve(request.status);
    request.onerror = reject;
    request.send();
  }), routes.settings);
  await delay(500);
  check(background === 403 && await recoveryField.isVisible(),
    'background MFA redirects preserve the expanded recovery form when step_up=0 is omitted');
  await recoveryField.fill(recovery);
  await recoveryPage.getByRole('button', {name: 'Use recovery code', exact: true}).click();
  await recoveryPage.waitForURL((url) => url.pathname.endsWith('/enroll'));
  check(await recoveryPage.getByRole('heading', {name: 'Set up an authenticator app', exact: true, level: 3}).isVisible(),
    'expanding and submitting the recovery form requires authenticator replacement');
  await goto(recoveryPage, routes.dashboard);
  check(recoveryPage.url().includes('/enroll'), 'recovery browser session cannot bypass replacement via the dashboard');
  console.log('Installed-package browser checks passed: ' + count);
} catch (error) {
  await writeFile(join(runtime, 'browser-error.log'), String(error.stack), {mode: 0o600});
  // Never print Playwright call logs: fill arguments and tokenized URLs contain secrets.
  console.error('Installed-package browser checks failed during: ' + phase);
  process.exitCode = 1;
} finally {
  await browser.close();
}
