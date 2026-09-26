// The Bangla card render service (docs/85 A.6, C.8). DEFINED, NOT STARTED.
//
// POST /render {engine: "chromium", html, width, height, format: "jpeg", text_sha256}
//   -> the card as image/jpeg, with two headers HQ checks (App\Content\Render\BrowserCardRenderer):
//      X-Render-Engine: chromium
//      X-Text-Sha256:   sha256 of the text laid out in the rendered #card element (innerText, NFC, whitespace folded)
//
// It renders HQ's own escaped template and nothing else: no network access from the page (every request
// the page tries is aborted), no scripts in the template, one page per request, closed afterwards.

import http from 'node:http';
import crypto from 'node:crypto';
import { chromium } from 'playwright';

const PORT = Number(process.env.PORT || 8080);
const MAX_BODY = 512 * 1024;

const browser = await chromium.launch({ args: ['--no-sandbox'] });

function send(res, status, body, headers = {}) {
  res.writeHead(status, { 'Content-Type': 'application/json', ...headers });
  res.end(typeof body === 'string' ? body : JSON.stringify(body));
}

async function render(job) {
  const context = await browser.newContext({ viewport: { width: job.width, height: job.height }, javaScriptEnabled: false });
  const page = await context.newPage();
  await page.route('**/*', (route) => route.abort());
  try {
    await page.setContent(job.html, { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready).catch(() => {});
    const text = (await page.innerText('#card')) ?? '';
    const shaped = text.normalize('NFC').replace(/\s+/gu, ' ').trim();
    const image = await page.locator('#card').screenshot({ type: 'jpeg', quality: 92 });
    return { image, textSha: crypto.createHash('sha256').update(shaped, 'utf8').digest('hex') };
  } finally {
    await context.close();
  }
}

http.createServer((req, res) => {
  if (req.method === 'GET' && req.url === '/health') return send(res, 200, { ok: true, engine: 'chromium' });
  if (req.method !== 'POST' || req.url !== '/render') return send(res, 404, { error: 'not found' });

  let size = 0;
  const chunks = [];
  req.on('data', (c) => {
    size += c.length;
    if (size > MAX_BODY) { req.destroy(); return; }
    chunks.push(c);
  });
  req.on('end', async () => {
    let job;
    try { job = JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch { return send(res, 400, { error: 'invalid json' }); }
    if (job.engine !== 'chromium' || job.format !== 'jpeg' || typeof job.html !== 'string') return send(res, 422, { error: 'unsupported job' });
    try {
      const { image, textSha } = await render(job);
      res.writeHead(200, { 'Content-Type': 'image/jpeg', 'X-Render-Engine': 'chromium', 'X-Text-Sha256': textSha });
      res.end(image);
    } catch (e) {
      send(res, 500, { error: String(e?.message ?? e) });
    }
  });
}).listen(PORT, () => console.log(`content-render listening on ${PORT}`));
