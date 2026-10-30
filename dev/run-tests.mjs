// Runs every lookup scenario against a fresh WooCommerce store in WordPress
// Playground: once with HPOS order storage and once with classic post storage.
import { spawn, execSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PLAYGROUND_CLI = '@wp-playground/cli@3.1.61';
const MODES = [
  { key: 'hpos', label: 'HPOS', port: 9401 },
  { key: 'legacy', label: 'Klasik', port: 9402 },
];
// A fake key: chat tests never reach Anthropic, the dev plugin answers in Claude's place.
const CHAT_CONFIG = { api_key: 'sk-ant-dev-test-key-0000', model: 'claude-opus-5-5' };

const results = new Map();

for (const mode of MODES) {
  console.log(`[${mode.label}] Playground başlatılıyor...`);
  const playground = startPlayground(mode.port);
  const base = `http://127.0.0.1:${mode.port}`;
  try {
    await waitUntilReady(base, playground);
    console.log(`[${mode.label}] Mağaza hazır, siparişler oluşturuluyor...`);
    await post(base, '/osa-dev/v1/storage', { mode: mode.key });
    const seeded = await post(base, '/osa-dev/v1/seed');
    if (seeded.status !== 200) throw new Error(`Seed failed: ${JSON.stringify(seeded.json)}`);
    if (seeded.json.storage !== mode.key) {
      throw new Error(`Expected ${mode.key} storage, store reports ${seeded.json.storage}`);
    }
    for (const [name, run] of scenarios(seeded.json)) {
      record(name, mode.key, await run(base).catch((e) => String(e)));
    }
  } catch (e) {
    record('Kurulum', mode.key, e.message);
  } finally {
    stop(playground.child);
  }
}

printTable();

function scenarios({ orders: o, refund_id: refundId, chat_page_id: chatPageId }) {
  const configure = (base, overrides = {}) => post(base, '/osa-dev/v1/config', { ...CHAT_CONFIG, ...overrides });
  const chat = (base, message, conversationId = '') =>
    post(base, '/parcelwise/v1/chat', { message, conversation_id: conversationId });
  const claudeCalls = async (base) => (await post(base, '/osa-dev/v1/claude-log')).json;
  const chatTest = (run) => async (base) => {
    await configure(base);
    return run(base);
  };

  const expectOrder = (body, fixture, extra) => async (base) => {
    const res = await lookup(base, body);
    if (res.status !== 200) return `HTTP ${res.status} ${res.json?.code ?? ''}`;
    if (res.json.order_number !== fixture.number) return `yanlış sipariş ${res.json.order_number}`;
    return extra ? extra(res.json) : null;
  };
  const expectStatus = (body, status) => async (base) => {
    const res = await lookup(base, body);
    return res.status === status ? null : `HTTP ${res.status}, beklenen ${status}`;
  };
  const tracking = (expected) => (r) => {
    const got = r.tracking.map((t) => t.number);
    return JSON.stringify(got) === JSON.stringify(expected) ? null : `takip ${JSON.stringify(got)}`;
  };

  return [
    ['Düz sipariş + e-posta', expectOrder({ order_number: o.plain.number, email: 'Ayse.Yilmaz@Example.com' }, o.plain)],
    ['E-posta farklı harf/boşlukla', expectOrder({ order_number: o.plain.number, email: '  ayse.yilmaz@EXAMPLE.com ' }, o.plain)],
    ['Numara başında #', expectOrder({ order_number: `#${o.plain.number}`, email: 'ayse.yilmaz@example.com' }, o.plain)],
    ['Posta kodu boşluklu', expectOrder({ order_number: o.plain.number, postcode: '34 710' }, o.plain)],
    ['Hesap e-postası (faturadakinden farklı)', expectOrder({ order_number: o.account.number, email: 'mehmet@example.com' }, o.account)],
    ['Özel numara (ID içeriyor)', expectOrder({ order_number: o.customWithId.number, email: 'can@example.com' }, o.customWithId)],
    ['Özel numara küçük harfle', expectOrder({ order_number: o.customWithId.number.toLowerCase(), email: 'can@example.com' }, o.customWithId)],
    ['Sıralı numara (ID ile ilgisiz)', expectOrder({ order_number: o.sequential.number, email: ' Zeynep.Demir@example.com' }, o.sequential)],
    ['Tam iade', expectOrder({ order_number: o.refunded.number, email: 'iade@example.com' }, o.refunded, (r) =>
      r.status === 'refunded' && r.refunded_total === r.total ? null : `durum ${r.status}, iade ${r.refunded_total}/${r.total}`)],
    ['Kısmi iade', expectOrder({ order_number: o.partial.number, email: 'kismi@example.com' }, o.partial, (r) =>
      r.refunded_total === 50 ? null : `iade ${r.refunded_total}`)],
    ['Takip no sipariş notunda', expectOrder({ order_number: o.noteTracking.number, email: 'kargo@example.com' }, o.noteTracking,
      tracking(['7312345678901']))],
    ['Takip no kargo eklentisinde', expectOrder({ order_number: o.metaTracking.number, email: 'takip@example.com' }, o.metaTracking,
      tracking(['1Z999AA10123456784']))],
    ['Yanlış e-posta bilgi vermez', expectStatus({ order_number: o.plain.number, email: 'baskasi@example.com' }, 404)],
    ['İade kaydı sipariş sanılmaz', expectStatus({ order_number: String(refundId), email: 'kismi@example.com' }, 404)],
    ['Olmayan sipariş', expectStatus({ order_number: '999999', email: 'ayse.yilmaz@example.com' }, 404)],
    ['Doğrulama bilgisi yok', expectStatus({ order_number: o.plain.number }, 400)],
    ['Sohbet: numara + e-posta ile durum', chatTest(async (base) => {
      const res = await chat(base, `Merhaba, sipariş: ${o.plain.number} e-posta: ayse.yilmaz@example.com`);
      if (res.status !== 200) return `HTTP ${res.status} ${res.json?.code ?? ''}`;
      if (!res.json.reply.includes('Processing')) return `cevap: ${res.json.reply}`;
      const calls = await claudeCalls(base);
      if (calls.length !== 2) return `${calls.length} API çağrısı, beklenen 2`;
      const [, assistant, toolTurn] = calls[1].body.messages;
      if (!assistant.content.some((b) => b.type === 'thinking')) return 'thinking bloğu geri gönderilmedi';
      return JSON.parse(toolTurn.content[0].content).found ? null : 'araç sonucu found=false';
    })],
    ['Sohbet: eksik bilgiyi sorar, sonra hatırlar', chatTest(async (base) => {
      const first = await chat(base, 'Siparişim nerede?');
      if (first.status !== 200 || !first.json.reply.includes('e-posta')) return `ilk cevap: ${first.json?.reply ?? first.status}`;
      const second = await chat(base, `sipariş: ${o.sequential.number} e-posta: zeynep.demir@example.com`, first.json.conversation_id);
      if (second.json?.conversation_id !== first.json.conversation_id) return 'sohbet kimliği değişti';
      if (!second.json.reply.includes(o.sequential.number)) return `ikinci cevap: ${second.json.reply}`;
      const history = (await claudeCalls(base)).at(-1).body.messages;
      return history[0].content === 'Siparişim nerede?' ? null : 'önceki mesajlar gönderilmedi';
    })],
    ['Sohbet: başkasının siparişini göstermez', chatTest(async (base) => {
      const res = await chat(base, `sipariş: ${o.plain.number} e-posta: tahmin@example.com`);
      if (res.status !== 200) return `HTTP ${res.status}`;
      if (/Processing|Ayşe/.test(res.json.reply)) return `bilgi sızdı: ${res.json.reply}`;
      const toolTurn = (await claudeCalls(base))[1].body.messages.at(-1);
      const result = JSON.parse(toolTurn.content[0].content);
      return result.found === false && !('order' in result) ? null : 'araç sonucu sipariş içeriyor';
    })],
    ['Sohbet: kargo takip numarasını söyler', chatTest(async (base) => {
      const res = await chat(base, `sipariş: ${o.noteTracking.number} e-posta: kargo@example.com`);
      return res.json?.reply?.includes('7312345678901') ? null : `cevap: ${res.json?.reply ?? res.status}`;
    })],
    ['Sohbet: model reddederse güvenli cevap', chatTest(async (base) => {
      const refused = await chat(base, 'REFUSE_TEST');
      if (refused.status !== 200 || !refused.json.reply.includes('destek@example.com')) {
        return `cevap: ${refused.json?.reply ?? refused.status}`;
      }
      await chat(base, 'Siparişim nerede?', refused.json.conversation_id);
      const history = (await claudeCalls(base)).at(-1).body.messages;
      return history.some((m) => m.content === 'REFUSE_TEST') ? 'reddedilen mesaj geçmişe yazıldı' : null;
    })],
    ['Sohbet: API isteğinin biçimi', chatTest(async (base) => {
      await chat(base, 'Siparişim nerede?');
      const [call] = await claudeCalls(base);
      const headers = Object.fromEntries(Object.entries(call.headers).map(([k, v]) => [k.toLowerCase(), v]));
      const body = call.body;
      const tool = body.tools?.[0];
      const problems = [
        headers['x-api-key'] !== CHAT_CONFIG.api_key && 'x-api-key',
        headers['anthropic-version'] !== '2026-10-21' && 'anthropic-version',
        headers['anthropic-beta'] !== 'server-side-fallback-2026-07-01' && 'anthropic-beta',
        body.model !== 'claude-opus-5-5' && 'model',
        body.fallbacks !== 'default' && 'fallbacks',
        'thinking' in body && 'thinking',
        'tool_choice' in body && 'tool_choice',
        body.output_config?.effort !== 'low' && 'effort',
        !(tool?.strict && tool.input_schema?.additionalProperties === false) && 'strict araç',
      ].filter(Boolean);
      return problems.length ? problems.join(', ') : null;
    })],
    ['Sohbet: Haiku seçilince fallback gönderilmez', async (base) => {
      await configure(base, { model: 'claude-haiku-5-5' });
      await chat(base, 'Siparişim nerede?');
      const [call] = await claudeCalls(base);
      return 'fallbacks' in call.body || 'anthropic-beta' in call.headers ? 'fallback gönderildi' : null;
    }],
    ['Sohbet: API anahtarı sayfada görünmez', chatTest(async (base) => {
      const html = await (await fetch(`${base}/?page_id=${chatPageId}`)).text();
      if (!html.includes('data-parcelwise-chat')) return 'sohbet kutusu sayfada yok';
      return html.includes(CHAT_CONFIG.api_key) ? 'API anahtarı sayfada!' : null;
    })],
    ['Sohbet: mesaj sınırı (maliyet)', async (base) => {
      await configure(base, { max_chat: 2 });
      for (let i = 1; i <= 2; i++) {
        const res = await chat(base, 'Siparişim nerede?');
        if (res.status !== 200) return `${i}. mesaj HTTP ${res.status}`;
      }
      const blocked = await chat(base, 'Siparişim nerede?');
      return blocked.status === 429 ? null : `3. mesaj HTTP ${blocked.status}`;
    }],
    ['Sohbet: API anahtarı yoksa', async (base) => {
      await configure(base, { api_key: '' });
      const res = await chat(base, 'Siparişim nerede?');
      return res.status === 503 ? null : `HTTP ${res.status}`;
    }],
    // Reaches the real API with a made-up key, which Anthropic turns away with a free 401.
    ['Sohbet: geçersiz anahtar ayarlarda görünür', async (base) => {
      await configure(base, { api_key: 'sk-ant-not-a-real-key', real_claude: true });
      const res = await chat(base, 'Siparişim nerede?');
      if (res.status !== 502) return `HTTP ${res.status}, beklenen 502`;
      const error = (await post(base, '/osa-dev/v1/last-error')).json;
      await configure(base);
      return error?.code === 401 ? null : `kaydedilen hata ${JSON.stringify(error)}`;
    }],
    // WordPress 7.0's AI Client, through the Anthropic connector provider, answered by the same fake Claude.
    ['Bağlantı: site AI bağlantısıyla çalışır', async (base) => {
      await configure(base, { api_key: '', connector_key: 'sk-ant-connector-test' });
      const first = await chat(base, `Merhaba, sipariş: ${o.plain.number} e-posta: ayse.yilmaz@example.com`);
      if (first.status !== 200) return `HTTP ${first.status} ${first.json?.code ?? ''}`;
      if (!first.json.reply.includes('Processing')) return `cevap: ${first.json.reply}`;
      const second = await chat(base, `Peki sipariş: ${o.noteTracking.number} e-posta: kargo@example.com`, first.json.conversation_id);
      if (!second.json?.reply?.includes('7312345678901')) return `ikinci cevap: ${second.json?.reply ?? second.status}`;
      const keys = new Set((await claudeCalls(base)).map((call) => headerOf(call, 'x-api-key')));
      await configure(base);
      return keys.size === 1 && keys.has('sk-ant-connector-test') ? null : `kullanılan anahtar(lar): ${[...keys].join(', ')}`;
    }],
    ['Bağlantı: kendi anahtarı site bağlantısından önce gelir', async (base) => {
      await configure(base, { connector_key: 'sk-ant-connector-test' });
      await chat(base, 'Siparişim nerede?');
      const [call] = await claudeCalls(base);
      await configure(base);
      return headerOf(call, 'x-api-key') === CHAT_CONFIG.api_key ? null : `kullanılan anahtar ${headerOf(call, 'x-api-key')}`;
    }],
    ['Deneme sınırı (tahminle deneme)', async (base) => {
      await post(base, '/osa-dev/v1/limit', { max: 3 });
      const guess = { order_number: o.plain.number, email: 'tahmin@example.com' };
      for (let i = 1; i <= 3; i++) {
        const res = await lookup(base, guess);
        if (res.status !== 404) return `${i}. deneme HTTP ${res.status}`;
      }
      const blocked = await lookup(base, guess);
      return blocked.status === 429 ? null : `4. deneme HTTP ${blocked.status}`;
    }],
  ];
}

function headerOf(call, name) {
  const entry = Object.entries(call?.headers ?? {}).find(([key]) => key.toLowerCase() === name);
  return entry ? String(entry[1]) : undefined;
}

function lookup(base, body) {
  return post(base, '/parcelwise/v1/lookup', body);
}

async function post(base, route, body = {}) {
  const res = await fetch(`${base}/?rest_route=${route}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return { status: res.status, json: await res.json().catch(() => null) };
}

function startPlayground(port) {
  const args = [
    '-y', PLAYGROUND_CLI, 'server',
    `--port=${port}`,
    '--blueprint=./dev/blueprint.json',
    '--mount=./plugin:/wordpress/wp-content/plugins/parcelwise',
    '--mount=./dev/osa-dev:/wordpress/wp-content/plugins/osa-dev',
  ];
  // A single command string: npx is a .cmd shim on Windows, which needs a shell to start.
  const child = spawn(`npx ${args.join(' ')}`, { cwd: ROOT, shell: true, detached: process.platform !== 'win32' });
  let log = '';
  child.stdout.on('data', (d) => (log += d));
  child.stderr.on('data', (d) => (log += d));
  return { child, log: () => log };
}

async function waitUntilReady(base, playground, timeoutMs = 10 * 60_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (playground.child.exitCode !== null) throw new Error(`Playground exited:\n${playground.log()}`);
    try {
      if ((await fetch(`${base}/?rest_route=/osa-dev/v1`)).ok) return;
    } catch {
      // Server not listening yet.
    }
    await new Promise((r) => setTimeout(r, 3000));
  }
  throw new Error(`Playground did not start in time:\n${playground.log()}`);
}

function stop(child) {
  try {
    if (process.platform === 'win32') execSync(`taskkill /pid ${child.pid} /T /F`, { stdio: 'ignore' });
    else process.kill(-child.pid);
  } catch {
    // Already gone.
  }
}

function record(name, mode, error) {
  if (!results.has(name)) results.set(name, {});
  results.get(name)[mode] = error;
}

function printTable() {
  const cell = (v) => (v === undefined ? '-' : v === null ? 'OK' : `HATA: ${v}`);
  let failures = 0;
  console.log(`\n${'Senaryo'.padEnd(48)}${MODES.map((m) => m.label.padEnd(14)).join('')}`);
  for (const [name, byMode] of results) {
    failures += MODES.filter((m) => typeof byMode[m.key] === 'string').length;
    console.log(`${name.padEnd(48)}${MODES.map((m) => cell(byMode[m.key]).padEnd(14)).join('')}`);
  }
  console.log(failures ? `\n${failures} hata` : '\nHepsi geçti');
  process.exitCode = failures ? 1 : 0;
}
