import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';
import {existsSync} from 'node:fs';
import {mkdtemp, readFile, rm} from 'node:fs/promises';
import {createServer} from 'node:http';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {promisify} from 'node:util';

const execFileAsync = promisify(execFile);
const moduleRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const listener = await readFile(resolve(moduleRoot, 'views/js/admin-step-up.js'), 'utf8');
const browserCandidates = [
  process.env.CHROME_BIN,
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
].filter(Boolean);
const browser = browserCandidates.find((candidate) => existsSync(candidate));

assert.ok(browser, 'A Chromium-based browser is required for the step-up browser test.');

let ajaxRequests = 0;
const server = createServer((request, response) => {
  const url = new URL(request.url, 'http://127.0.0.1');

  if ('/' === url.pathname) {
    response.writeHead(200, {'Content-Type': 'text/html; charset=UTF-8'});
    response.end(`<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Step-up redirect test</title>
    <script src="/admin-step-up.js"></script>
    <script src="/admin-step-up.js"></script>
    <script>
      window.addEventListener('load', function () {
        var request = new XMLHttpRequest();
        request.open('POST', '/ajax-sensitive-action');
        request.send('action=upgrade');
      });
    </script>
  </head>
  <body>Waiting for the AJAX step-up response.</body>
</html>`);

    return;
  }

  if ('/admin-step-up.js' === url.pathname) {
    response.writeHead(200, {'Content-Type': 'text/javascript; charset=UTF-8'});
    response.end(listener);

    return;
  }

  if ('/ajax-sensitive-action' === url.pathname) {
    ++ajaxRequests;
    response.writeHead(403, {
      'Content-Type': 'application/json; charset=UTF-8',
      'X-Mpadmin2fa-Redirect': '/challenge-reached?step_up=1',
    });
    response.end('{"status":false}');

    return;
  }

  if ('/challenge-reached' === url.pathname && '1' === url.searchParams.get('step_up')) {
    response.writeHead(200, {'Content-Type': 'text/html; charset=UTF-8'});
    response.end('<!doctype html><title>MP2FA_BROWSER_REDIRECT_PASSED</title>');

    return;
  }

  response.writeHead(404, {'Content-Type': 'text/plain; charset=UTF-8'});
  response.end('Not found.');
});

await new Promise((resolveListen, rejectListen) => {
  server.once('error', rejectListen);
  server.listen(0, '127.0.0.1', resolveListen);
});

const address = server.address();
assert.ok(address && 'object' === typeof address);
const profile = await mkdtemp(join(tmpdir(), 'mp2fa-browser-'));

try {
  const {stdout} = await execFileAsync(browser, [
    '--headless=new',
    '--disable-dev-shm-usage',
    '--disable-gpu',
    '--no-sandbox',
    `--user-data-dir=${profile}`,
    '--virtual-time-budget=5000',
    '--dump-dom',
    `http://127.0.0.1:${address.port}/`,
  ], {
    maxBuffer: 1024 * 1024,
    timeout: 20000,
  });

  assert.match(stdout, /MP2FA_BROWSER_REDIRECT_PASSED/);
  assert.equal(ajaxRequests, 1, 'The browser should issue one sensitive AJAX request.');
  console.log('Browser step-up redirect passed.');
} finally {
  await new Promise((resolveClose) => server.close(resolveClose));
  await rm(profile, {recursive: true, force: true});
}
