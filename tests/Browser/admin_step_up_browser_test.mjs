import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {existsSync} from 'node:fs';
import {mkdtemp, readFile, rm} from 'node:fs/promises';
import {createServer} from 'node:http';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {setTimeout as delay} from 'node:timers/promises';
import {fileURLToPath} from 'node:url';

const moduleRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const listener = await readFile(
  process.env.MP2FA_LISTENER_PATH || resolve(moduleRoot, 'views/js/admin-step-up.js'),
  'utf8',
);
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
let secureSubmitRequests = 0;
let secureSubmitMethod = '';
let secureSubmitQuery = '';
let secureSubmitBody = '';
const server = createServer((request, response) => {
  const url = new URL(request.url, 'http://127.0.0.1');

  if ('/secure-submit' === url.pathname) {
    response.writeHead(200, {'Content-Type': 'text/html; charset=UTF-8'});
    response.end(`<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Secure grid submit test</title>
    <script src="/admin-step-up.js"></script>
    <script src="/admin-step-up.js"></script>
    <script>window.confirm = function () { return true; };</script>
  </head>
  <body>
    <button type="button"
            class="js-mp2fa-secure-submit-row-action"
            data-confirm-message="Continue?"
            data-csrf-token="grid-token+/="
            data-method="POST"
            data-url="/secure-grid-action/42">
      Submit securely
    </button>
    <script>setTimeout(function () { document.querySelector('button').click(); }, 100);</script>
  </body>
</html>`);

    return;
  }

  if ('/secure-grid-action/42' === url.pathname) {
    ++secureSubmitRequests;
    secureSubmitMethod = request.method;
    secureSubmitQuery = url.search;
    request.setEncoding('utf8');
    request.on('data', (chunk) => {
      secureSubmitBody += chunk;
    });
    request.on('end', () => {
      response.writeHead(200, {'Content-Type': 'text/html; charset=UTF-8'});
      response.end('<!doctype html><title>MP2FA_SECURE_SUBMIT_PASSED</title>');
    });

    return;
  }

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
let browserStderr = '';
const browserProcess = spawn(browser, [
  '--headless',
  '--disable-dev-shm-usage',
  '--disable-gpu',
  '--no-sandbox',
  '--remote-allow-origins=*',
  '--remote-debugging-port=0',
  `--user-data-dir=${profile}`,
  'about:blank',
], {
  stdio: ['ignore', 'ignore', 'pipe'],
});
browserProcess.stderr.setEncoding('utf8');
browserProcess.stderr.on('data', (chunk) => {
  browserStderr = (browserStderr + chunk).slice(-4000);
});

let socket;
try {
  const portFile = join(profile, 'DevToolsActivePort');
  const portDeadline = Date.now() + 20000;
  let devToolsPort = 0;
  while (Date.now() < portDeadline) {
    try {
      const [port] = (await readFile(portFile, 'utf8')).trim().split(/\r?\n/);
      devToolsPort = Number.parseInt(port, 10);
      if (devToolsPort > 0) {
        break;
      }
    } catch {
      if (null !== browserProcess.exitCode) {
        throw new Error(`The browser exited before DevTools became ready.\n${browserStderr}`);
      }
    }
    await delay(100);
  }
  assert.ok(devToolsPort > 0, `Chrome DevTools did not become ready.\n${browserStderr}`);

  const targetResponse = await fetch(
    `http://127.0.0.1:${devToolsPort}/json/new?${encodeURIComponent('about:blank')}`,
    {method: 'PUT'},
  );
  assert.equal(targetResponse.status, 200, 'Chrome could not create a DevTools page target.');
  const target = await targetResponse.json();
  assert.ok(target.webSocketDebuggerUrl, 'Chrome did not expose the page DevTools socket.');

  socket = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolveOpen, rejectOpen) => {
    socket.addEventListener('open', resolveOpen, {once: true});
    socket.addEventListener('error', rejectOpen, {once: true});
  });

  let commandId = 0;
  const pendingCommands = new Map();
  socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    const pending = pendingCommands.get(message.id);
    if (!pending) {
      return;
    }
    pendingCommands.delete(message.id);
    if (message.error) {
      pending.reject(new Error(message.error.message));
    } else {
      pending.resolve(message.result);
    }
  });
  const command = (method, params = {}) => new Promise((resolveCommand, rejectCommand) => {
    const id = ++commandId;
    pendingCommands.set(id, {resolve: resolveCommand, reject: rejectCommand});
    socket.send(JSON.stringify({id, method, params}));
  });

  await command('Page.enable');
  await command('Runtime.enable');

  const waitForTitle = async (expected) => {
    const deadline = Date.now() + 20000;
    let title = '';
    while (Date.now() < deadline) {
      try {
        const evaluation = await command('Runtime.evaluate', {
          expression: 'document.title',
          returnByValue: true,
        });
        title = evaluation.result?.value ?? '';
      } catch (error) {
        if (!/navigated|context/i.test(error.message)) {
          throw error;
        }
      }
      if (expected === title) {
        break;
      }
      await delay(100);
    }

    assert.equal(title, expected);
  };

  await command('Page.navigate', {url: `http://127.0.0.1:${address.port}/secure-submit`});
  await waitForTitle('MP2FA_SECURE_SUBMIT_PASSED');

  assert.equal(secureSubmitRequests, 1, 'The secure row action should submit once.');
  assert.equal(secureSubmitMethod, 'POST');
  assert.equal(secureSubmitQuery, '', 'The CSRF token must not appear in the request URL.');
  assert.equal(new URLSearchParams(secureSubmitBody).get('mp2fa_csrf_token'), 'grid-token+/=');

  await command('Page.navigate', {url: `http://127.0.0.1:${address.port}/`});
  await waitForTitle('MP2FA_BROWSER_REDIRECT_PASSED');
  assert.equal(ajaxRequests, 1, 'The browser should issue one sensitive AJAX request.');
  console.log('Secure grid POST and browser step-up redirect passed.');
} finally {
  socket?.close();
  if (null === browserProcess.exitCode) {
    const exited = once(browserProcess, 'exit');
    browserProcess.kill();
    await Promise.race([exited, delay(2000)]);
  }
  if (null === browserProcess.exitCode) {
    const exited = once(browserProcess, 'exit');
    browserProcess.kill('SIGKILL');
    await Promise.race([exited, delay(2000)]);
  }
  await new Promise((resolveClose) => server.close(resolveClose));
  await rm(profile, {recursive: true, force: true});
}
