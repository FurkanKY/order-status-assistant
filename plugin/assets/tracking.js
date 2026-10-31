(function () {
  const config = window.parcelwiseTracking;
  if (!config) return;

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
      result.replaceChildren(el('p', 'parcelwise-tracking__note', config.strings.busy));
      try {
        const res = await fetch(config.endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        });
        const data = await res.json();
        result.replaceChildren(res.ok ? card(data) : el('p', 'parcelwise-tracking__error', data.message || config.strings.error));
      } catch {
        result.replaceChildren(el('p', 'parcelwise-tracking__error', config.strings.error));
      } finally {
        button.disabled = false;
      }
    });
  }

  // Everything goes in through textContent: order data must never be able to inject markup.
  function card(order) {
    const box = el('div', 'parcelwise-tracking__card');
    // A status-specific text (cancelled, refunded, failed) beats the stage's general one.
    const stage = config.stages[order.status] || config.stages[order.stage];
    box.append(el('h3', 'parcelwise-tracking__order', format(config.strings.order, order.order_number)));

    if (config.steps.includes(order.stage)) box.append(steps(order.stage));
    box.append(el('p', 'parcelwise-tracking__stage', stage ? stage.title : order.status_label));
    if (stage) box.append(el('p', 'parcelwise-tracking__text', stage.text));

    for (const t of order.tracking) box.append(trackingRow(t));

    if (order.items.length) {
      box.append(el('p', 'parcelwise-tracking__label', config.strings.items));
      const list = el('ul', 'parcelwise-tracking__items');
      for (const item of order.items) list.append(el('li', '', `${item.quantity} × ${item.name}`));
      box.append(list);
    }
    if (order.refunded_total > 0) {
      box.append(el('p', 'parcelwise-tracking__note', format(config.strings.refunded, order.refunded_total, order.currency)));
    }
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

  function trackingRow(t) {
    const row = el('div', 'parcelwise-tracking__shipment');
    row.append(el('span', 'parcelwise-tracking__number', format(config.strings.trackingNo, t.number)));
    if (/^https:\/\//.test(t.url)) {
      const a = el('a', 'parcelwise-tracking__link',
        format(t.url_type === 'carrier_site' ? config.strings.openCarrier : config.strings.track, t.provider || t.number));
      a.href = t.url;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      row.append(a);
    }
    return row;
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
