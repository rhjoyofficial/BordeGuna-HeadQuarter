// The render self-test, run by a person at gate P6-G2 BEFORE the service is used (docs/85 C.16).
//
// It renders the two words GraphicsMagick broke on 12 July 2026 (the same cases as
// apps/hq/tests/fixtures/content/bangla-shaping.json), writes each card to ./selftest-out/, and prints
// whether the text in the rendered DOM equals the source. The DOM check proves the exact words reached
// the shaping engine; the PICTURES are what a person looks at: "গার্ডেন" must show the vowel sign ে
// before ড, and "তেল" must show ে before ত. A card that looks like "গার্ডনে" or "তলে" fails the gate.

import fs from 'node:fs';
import { chromium } from 'playwright';

const cases = ['গার্ডেন', 'তেল', 'সরিষার তেল', 'বর দে গুনা'];
fs.mkdirSync('selftest-out', { recursive: true });

const browser = await chromium.launch({ args: ['--no-sandbox'] });
let failed = 0;
for (const [i, text] of cases.entries()) {
  const page = await browser.newPage({ viewport: { width: 800, height: 300 } });
  await page.setContent(`<!doctype html><html lang="bn"><head><meta charset="utf-8"><style>#card{font-family:"Noto Sans Bengali";font-size:96px;padding:40px}</style></head><body><div id="card">${text}</div></body></html>`);
  const dom = ((await page.textContent('#card')) ?? '').normalize('NFC');
  await page.locator('#card').screenshot({ path: `selftest-out/${i}.jpeg`, type: 'jpeg' });
  const ok = dom === text.normalize('NFC');
  if (!ok) failed++;
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${i}.jpeg  source=${text}  dom=${dom}`);
  await page.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED` : 'DOM text matches for every case. Now look at every picture in selftest-out/.');
process.exit(failed ? 1 : 0);
