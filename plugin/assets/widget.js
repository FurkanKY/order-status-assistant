(function () {
  const config = window.parcelwiseWidget;
  if (!config) return;

  document.querySelectorAll('[data-parcelwise-chat]').forEach(mount);

  function mount(root) {
    let conversationId = '';

    const log = el('div', 'parcelwise-chat__log');
    const form = el('form', 'parcelwise-chat__form');
    const input = el('input', 'parcelwise-chat__input');
    const button = el('button', 'parcelwise-chat__send');

    log.setAttribute('aria-live', 'polite');
    input.type = 'text';
    input.maxLength = 1000;
    input.placeholder = config.strings.placeholder;
    input.setAttribute('aria-label', config.strings.placeholder);
    button.type = 'submit';
    button.textContent = config.strings.send;

    form.append(input, button);
    root.append(log, form);
    add('assistant', config.strings.greeting);

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const message = input.value.trim();
      if (!message) return;

      add('customer', message);
      input.value = '';
      setBusy(true);
      try {
        const res = await fetch(config.endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ message, conversation_id: conversationId }),
        });
        const data = await res.json();
        if (data.conversation_id) conversationId = data.conversation_id;
        add('assistant', res.ok ? data.reply : data.message || config.strings.error);
        if (res.ok) addLinks(data.links || []);
      } catch {
        add('assistant', config.strings.error);
      } finally {
        setBusy(false);
        input.focus();
      }
    });

    // textContent, never innerHTML: replies come from a model and must not be able to inject markup.
    function add(who, text) {
      const line = el('p', `parcelwise-chat__msg parcelwise-chat__msg--${who}`);
      line.textContent = text;
      log.append(line);
      log.scrollTop = log.scrollHeight;
    }

    // Tracking buttons are built from the server's verified order data, not from the reply text.
    function addLinks(links) {
      const safe = links.filter((link) => /^https:\/\//.test(link.url));
      if (!safe.length) return;
      const row = el('div', 'parcelwise-chat__links');
      for (const link of safe) {
        const a = el('a', 'parcelwise-chat__link');
        a.href = link.url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.textContent = (link.url_type === 'carrier_site' ? config.strings.openCarrier : config.strings.track)
          .replace('%s', link.label);
        row.append(a);
      }
      log.append(row);
      log.scrollTop = log.scrollHeight;
    }

    function setBusy(busy) {
      input.disabled = busy;
      button.disabled = busy;
      root.classList.toggle('parcelwise-chat--busy', busy);
    }
  }

  function el(tag, className) {
    const node = document.createElement(tag);
    node.className = className;
    return node;
  }
})();
