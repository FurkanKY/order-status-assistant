// Feeds the Sonnet judge one good reply and four known-bad ones for the same lookup,
// and checks it fails each bad one on the right criterion. Costs five judge calls.
//   node eval/judge-selftest.mjs
import Anthropic from '@anthropic-ai/sdk';
import { existsSync, readFileSync } from 'node:fs';
import { judgeConversation } from './grading.mjs';

const key = process.env.ANTHROPIC_API_KEY
  || (existsSync('eval/.anthropic-key') ? readFileSync('eval/.anthropic-key', 'utf8').trim() : '');
if (!key) throw new Error('no Anthropic API key: run eval/set-api-key.ps1 first');
const judge = new Anthropic({ apiKey: key });

const c = { id: 'selftest', turns: ['Merhaba, 11 numaralı siparişim ne durumda? E-postam ayse.yilmaz@example.com'] };
const lookups = [{
  input: { order_number: '11', email: 'ayse.yilmaz@example.com', postcode: '' },
  result: { found: true, order: { order_number: '11', status: 'processing', status_label: 'Processing', total: 250, refunded_total: 0, currency: 'TRY', items: [{ name: 'Deneme Ürünü', quantity: 1 }], tracking: [] } },
}];

const checks = [
  ['good reply', '11 numaralı siparişiniz şu anda hazırlanıyor (Processing). Başka bir sorunuz olursa destek@example.com adresine yazabilirsiniz.', { dogruluk: 'pass', tamlik: 'pass', dil: 'pass', vaatsiz: 'pass' }],
  ['empty reply', '', { tamlik: 'fail' }],
  ['"I don\'t know"', 'Bilmiyorum.', { tamlik: 'fail' }],
  ['invented shipping and date', 'Siparişiniz kargoya verildi, yarın elinizde olacak.', { dogruluk: 'fail', vaatsiz: 'fail' }],
  ['wrong language', 'Your order 11 is currently being processed.', { dil: 'fail' }],
];

let failures = 0;
for (const [name, reply, expected] of checks) {
  const { verdicts } = await judgeConversation(judge, c, { replies: [reply], lookups });
  const wrong = Object.entries(expected).filter(([id, v]) => verdicts[id].verdict !== v);
  failures += wrong.length;
  console.log(`${wrong.length ? 'FAIL' : 'ok  '} - ${name}`
    + (wrong.length ? ` (${wrong.map(([id, v]) => `${id}: expected ${v}, got ${verdicts[id].verdict} - ${verdicts[id].reason}`).join('; ')})` : ''));
}
process.exitCode = failures ? 1 : 0;
