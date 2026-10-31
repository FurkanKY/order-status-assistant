(function () {
  const config = window.parcelwiseTracking;
  if (!config) return;

  const lang = document.documentElement.lang || undefined;
  const SVG = 'http://www.w3.org/2000/svg';

  document.querySelectorAll('[data-parcelwise-tracking]').forEach(mount);

  function mount(root) {
    const form = root.querySelector('form');
    const result = root.querySelector('.parcelwise-tracking__result');
    const button = form.querySelector('button');

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      // One field for both: customers rarely remember which one the store asks for.
      const owner = form.owner.value.trim();
      const body = { order_number: form.order_number.value.trim() };
      if (owner.includes('@')) body.email = owner;
      else body.postcode = owner;

      button.disabled = true;
      result.replaceChildren(el('p', 'parcelwise-tracking__busy', config.strings.busy));
      try {
        const res = await fetch(config.endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        });
        const data = await res.json();
        result.replaceChildren(res.ok ? card(data) : problem(data.message || config.strings.error, data.code === 'parcelwise_not_found'));
      } catch {
        result.replaceChildren(problem(config.strings.error, false));
      } finally {
        button.disabled = false;
        reveal(result);
      }
    });
  }

  // On a phone the keyboard covers the answer, so close it and bring the answer into view.
  function reveal(result) {
    if (window.matchMedia('(pointer: coarse)').matches && document.activeElement) document.activeElement.blur();
    const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    result.scrollIntoView({ block: 'nearest', behavior: smooth ? 'smooth' : 'auto' });
  }

  // Everything goes in through textContent: order data must never be able to inject markup.
  function card(order) {
    const box = el('div', 'parcelwise-tracking__card');
    // A status-specific text (cancelled, refunded, failed) beats the stage's general one.
    const stage = config.stages[order.status] || config.stages[order.stage];

    box.append(el('h2', 'parcelwise-tracking__status', stage ? stage.title : order.status_label));
    const date = formatDate(order.date_created);
    box.append(el('p', 'parcelwise-tracking__meta', [format(config.strings.order, order.order_number), date].filter(Boolean).join(' · ')));
    if (config.steps.includes(order.stage)) box.append(steps(order.stage));
    if (stage) box.append(el('p', 'parcelwise-tracking__text', stage.text));

    for (const t of order.tracking) box.append(shipment(t));
    if (order.stage === 'shipped' && !order.tracking.length) {
      box.append(el('p', 'parcelwise-tracking__note', config.strings.noTracking));
    }

    if (order.items.length) {
      const items = el('div', 'parcelwise-tracking__items');
      items.append(el('h3', 'parcelwise-tracking__label', config.strings.items));
      const list = el('ul', '');
      for (const item of order.items) list.append(el('li', '', `${item.quantity} × ${item.name}`));
      items.append(list);
      if (order.refunded_total > 0) {
        items.append(el('p', 'parcelwise-tracking__note', format(config.strings.refunded, formatMoney(order.refunded_total, order.currency))));
      }
      box.append(items);
    }

    if (config.support) box.append(contact());
    return box;
  }

  function steps(current) {
    const list = el('ol', 'parcelwise-tracking__steps');
    const reached = config.steps.indexOf(current);
    config.steps.forEach((key, i) => {
      const step = el('li', i <= reached ? 'is-done' : '', config.stages[key].title);
      if (i === reached) step.setAttribute('aria-current', 'step');
      list.append(step);
    });
    return list;
  }

  function shipment(t) {
    const wrap = el('div', 'parcelwise-tracking__shipment');
    const row = el('div', 'parcelwise-tracking__number');
    row.append(el('span', '', format(config.strings.trackingNo, t.number)));
    if (navigator.clipboard && window.isSecureContext) row.append(copyButton(t.number));
    wrap.append(row);

    if (/^https:\/\//.test(t.url)) {
      const onSite = t.url_type === 'carrier_site';
      const a = el('a', `parcelwise-tracking__link button ${config.buttonClass}`.trim(),
        format(onSite ? config.strings.goToCarrier : config.strings.track, t.provider || t.number));
      a.href = t.url;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      // PTT and the like have no page per parcel, so the customer has to bring the number along.
      if (onSite) wrap.append(el('p', 'parcelwise-tracking__note', config.strings.carrierHint));
      wrap.append(a);
    }
    return wrap;
  }

  function copyButton(text) {
    const copy = el('button', 'parcelwise-tracking__copy', config.strings.copy);
    copy.type = 'button';
    copy.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(text);
        copy.textContent = config.strings.copied;
        setTimeout(() => { copy.textContent = config.strings.copy; }, 2000);
      } catch {
        // Clipboard refused; the number is still on screen to select by hand.
      }
    });
    return copy;
  }

  function contact() {
    const p = el('p', 'parcelwise-tracking__contact', `${config.strings.contact} `);
    if (/^(mailto:|https?:)/.test(config.support.href)) {
      const a = el('a', '', config.support.label);
      a.href = config.support.href;
      p.append(a);
    } else {
      p.append(config.support.label);
    }
    return p;
  }

  // The error says what went wrong and, for a lookup that found nothing, what to check.
  function problem(message, notFound) {
    const box = el('div', 'parcelwise-tracking__problem');
    box.append(icon());
    const text = el('div', '');
    text.append(el('p', 'parcelwise-tracking__problem-title', message));
    if (notFound) text.append(el('p', 'parcelwise-tracking__note', config.strings.notFoundHint));
    box.append(text);
    return box;
  }

  function icon() {
    const svg = document.createElementNS(SVG, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('class', 'parcelwise-tracking__icon');
    for (const [tag, attrs] of [
      ['circle', { cx: 12, cy: 12, r: 9 }],
      ['line', { x1: 12, y1: 7.5, x2: 12, y2: 13 }],
      ['line', { x1: 12, y1: 16.5, x2: 12, y2: 16.6 }],
    ]) {
      const node = document.createElementNS(SVG, tag);
      for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
      svg.append(node);
    }
    return svg;
  }

  function formatMoney(amount, currency) {
    try {
      return new Intl.NumberFormat(lang, { style: 'currency', currency }).format(amount);
    } catch {
      return `${amount} ${currency}`;
    }
  }

  function formatDate(iso) {
    if (!iso) return '';
    try {
      return new Intl.DateTimeFormat(lang, { dateStyle: 'long' }).format(new Date(iso));
    } catch {
      return '';
    }
  }

  function format(text, ...values) {
    let i = 0;
    return text.replace(/%(\d)\$s|%s/g, (_, n) => String(n ? values[n - 1] : values[i++]));
  }

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
})();
