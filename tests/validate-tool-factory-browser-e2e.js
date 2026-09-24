const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { spawn } = require('node:child_process');
const { chromium } = require('playwright');

const root = process.cwd();
const pointer = path.join(root, 'storage', 'e2e-test-ci', 'production-e2e-workspace.txt');
if (!fs.existsSync(pointer)) throw new Error('Production E2E workspace pointer is missing.');
const workspace = fs.readFileSync(pointer, 'utf8').trim();
const generatedDir = path.join(workspace, 'generated');
const pageFile = path.join(generatedDir, 'parking-fee-calculator.html');
if (!fs.existsSync(pageFile)) throw new Error('Generated browser-test artifact is missing: ' + pageFile);

const port = 41731;
const BROWSER_TIMEOUT_MS = 30000;
const server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', generatedDir], { stdio: ['ignore', 'pipe', 'pipe'] });
let serverOutput = '';
server.stdout.on('data', d => { serverOutput += d.toString(); });
server.stderr.on('data', d => { serverOutput += d.toString(); });

const waitForServer = () => new Promise((resolve, reject) => {
  const deadline = Date.now() + 10000;
  const probe = () => {
    const req = http.get('http://127.0.0.1:' + port + '/parking-fee-calculator.html', res => {
      res.resume();
      if (res.statusCode === 200) return resolve();
      if (Date.now() > deadline) return reject(new Error('PHP server returned HTTP ' + res.statusCode));
      setTimeout(probe, 100);
    });
    req.on('error', () => {
      if (Date.now() > deadline) reject(new Error('PHP server did not start. ' + serverOutput));
      else setTimeout(probe, 100);
    });
  };
  probe();
});

(async () => {
  await waitForServer();
  const browser = await chromium.launch({ headless: true, timeout: BROWSER_TIMEOUT_MS });
  const page = await browser.newPage();
  page.setDefaultTimeout(10000);
  page.setDefaultNavigationTimeout(15000);
  const consoleErrors = [];
  const pageErrors = [];
  const externalRequests = [];
  page.on('console', msg => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
  page.on('pageerror', err => pageErrors.push(String(err)));
  page.on('request', req => {
    const url = req.url();
    if (/^https?:/i.test(url) && !url.startsWith('http://127.0.0.1:' + port)) externalRequests.push(url);
  });

  await page.goto('http://127.0.0.1:' + port + '/parking-fee-calculator.html', { waitUntil: 'domcontentloaded', timeout: 15000 });
  if (!await page.locator('h1').isVisible()) throw new Error('Generated tool h1 is not visible.');
  if (await page.title() !== 'Parking Fee Calculator | Free Online Tool | JunctionTools') throw new Error('Generated tool title is incorrect.');
  if (await page.locator('#run').count() !== 1 || await page.locator('#reset').count() !== 1) throw new Error('Run/Reset controls are missing.');

  const input = page.locator('input').first();
  await page.locator('#run').click();
  if (!await page.locator('#result').innerText().then(t => t.includes('Please complete the required inputs.'))) {
    throw new Error('Empty-input validation did not fire.');
  }

  await input.fill('100');
  await page.locator('#run').click();
  if ((await page.locator('#result').innerText()).trim() !== '100') throw new Error('Run action did not produce the expected deterministic result.');

  await page.locator('#reset').click();
  if ((await input.inputValue()) !== '' || (await page.locator('#result').innerText()) !== '') throw new Error('Reset did not clear input/result.');

  if (consoleErrors.length) throw new Error('Browser console errors: ' + consoleErrors.join(' | '));
  if (pageErrors.length) throw new Error('Browser page errors: ' + pageErrors.join(' | '));
  if (externalRequests.length) throw new Error('Unexpected external runtime requests: ' + externalRequests.join(' | '));

  console.log('[PASS] Browser loaded generated parking-fee-calculator artifact.');
  console.log('[PASS] Empty-input validation fired.');
  console.log('[PASS] Run action produced a deterministic result.');
  console.log('[PASS] Reset cleared input and result.');
  console.log('[PASS] No browser console/page errors.');
  console.log('[PASS] No external runtime network requests.');
  console.log('Tool Factory browser runtime E2E: PASS');

  await browser.close();
})().catch(async err =>(async err => {
  console.error('[FAIL] ' + err.message);
  process.exitCode = 1;
}).finally(() => {
  server.kill('SIGTERM');
});
