(function () {
  const config = window.parcelwiseWidget;
  if (!config) return;

  document.querySelectorAll('[data-parcelwise-chat]').forEach(mount);

  function mount(root) {
    let conversationId = '';
    let busy = false;

    const log = el('div', 'parcelwise-chat__log');
    const form = el('form', 'parcelwise-chat__form');
    const input = el('input', 'parcelwise-chat__input');
    const button = el('button', `parcelwise-chat__send button ${config.buttonClass}`.trim());

    log.setAttribute('aria-live', 'polite');
    input.type = 'text';
    input.maxLength = 1000;
    input.placeholder = config.strings.placeholder;
    input.setAttribute('aria-label', config.strings.placeholder);
    input.autocomplete = 'off';
    button.type = 'submit';
    button.textContent = config.strings.send;

    form.append(input, button);
    root.append(log, form);
    add('assistant', config.strings.greeting);

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const message = input.value.trim();
      if (!message || busy) return;

      add('customer', message);
      input.value = '';
      const typing = setBusy(true);
      try {
        const res = await fetch(config.endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ message, conversation_id: conversationId }),
        });
        const data = await res.json();
        if (data.conversation_id) conversationId = data.conversation_id;
        typing.remove();
        add('assistant', res.ok ? data.reply : data.message || config.strings.error);
        if (res.ok) addLinks(data.links || []);
      } catch {
        typing.remove();
        add('assistant', config.strings.error);
      } finally {
        setBusy(false);
      }
    });

    // textContent, never innerHTML: replies come from a model and must not be able to inject markup.
    function add(who, text) {
      const line = el('p', `parcelwise-chat__msg parcelwise-chat__msg--${who}`);
      line.textContent = text;
      log.append(line);
      log.scrollTop = log.scrollHeight;
      return line;
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
        a.textContent = (link.url_type === 'carrier_site' ? config.strings.goToCarrier : config.strings.track)
          .replace('%s', link.label);
        row.append(a);
      }
      log.append(row);
      log.scrollTop = log.scrollHeight;
    }

    // The field stays editable while the assistant answers: disabling it would close the
    // keyboard on a phone after every message.
    function setBusy(state) {
      busy = state;
      button.disabled = state;
      root.classList.toggle('parcelwise-chat--busy', state);
      if (!state) return null;

      const typing = add('assistant', '');
      typing.classList.add('parcelwise-chat__typing');
      const label = el('span', 'parcelwise-chat__sr');
      label.textContent = config.strings.typing;
      typing.append(label, el('span', 'parcelwise-chat__dot'), el('span', 'parcelwise-chat__dot'), el('span', 'parcelwise-chat__dot'));
      return typing;
    }
  }

  function el(tag, className) {
    const node = document.createElement(tag);
    node.className = className;
    return node;
  }
})();
