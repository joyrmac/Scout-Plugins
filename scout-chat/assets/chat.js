/* Scout Chat widget. Plain JS, no dependencies. Design: Scout features
   DESIGN.md 5.1 (panel variant). Config arrives as window.scoutChatConfig. */
(function () {
  'use strict';
  var C = window.scoutChatConfig;
  if (!C || document.getElementById('scout-chat')) return;

  var TEAM = C.team, Team = TEAM.charAt(0).toUpperCase() + TEAM.slice(1);
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var KEY = 'scoutChat';
  var state = { open: false, mode: 'ask', pending: '', kind: 'question', msgs: [], done: false };
  var busy = false, queue = [];

  var CHIPS = {
    cost: 'What does a site cost?',
    work: 'Show me your work',
    book: 'Book a call',
    client: "I'm an existing client",
    other: 'Ask something else',
    change: 'Request a change',
    person: 'Talk to a person'
  };
  function startChips() {
    var c = [CHIPS.cost];
    if (C.workUrl) c.push(CHIPS.work);
    c.push(CHIPS.book, CHIPS.client, CHIPS.person, CHIPS.other);
    return c;
  }

  function track(name, params) { if (typeof window.scoutTrack === 'function') window.scoutTrack(name, params || {}); }
  function save() { try { sessionStorage.setItem(KEY, JSON.stringify({ msgs: state.msgs.slice(-40), mode: state.mode, kind: state.kind, pending: state.pending, done: state.done })); } catch (e) {} }
  function load() { try { var s = JSON.parse(sessionStorage.getItem(KEY) || 'null'); if (s && s.msgs) { state.msgs = s.msgs; state.mode = s.mode; state.kind = s.kind; state.pending = s.pending; state.done = s.done; } } catch (e) {} }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function bolt() { var s = el('span', 'sc-bolt'); s.setAttribute('aria-hidden', 'true'); return s; }

  function avatars(size) {
    var wrap = el('span', 'sc-stack sc-stack-' + size);
    var a = el('span', 'sc-ai'); a.appendChild(bolt()); wrap.appendChild(a);
    (C.photos || []).forEach(function (src) { var i = el('img'); i.src = src; i.alt = ''; i.width = 24; i.height = 24; i.loading = 'lazy'; wrap.appendChild(i); });
    return wrap;
  }

  /* ---- Shell ---- */
  var root = el('div'); root.id = 'scout-chat'; root.className = 'sc-root';
  var launcher = el('button', 'sc-launcher'); launcher.type = 'button';
  launcher.setAttribute('aria-haspopup', 'dialog'); launcher.setAttribute('aria-expanded', 'false');
  launcher.appendChild(avatars('sm')); launcher.appendChild(el('span', 'sc-launcher-label', C.launcher));
  if (!reduce) launcher.classList.add('sc-pulse');

  var teaser = el('div', 'sc-teaser'); teaser.hidden = true;
  var tClose = el('button', 'sc-teaser-x', '×'); tClose.type = 'button'; tClose.setAttribute('aria-label', 'Dismiss');
  var tBody = el('button', 'sc-teaser-body'); tBody.type = 'button';
  if (C.teaserBy) tBody.appendChild(el('span', 'sc-teaser-by', C.teaserBy));
  tBody.appendChild(el('span', 'sc-teaser-text', 'Questions about your website? Ask here and get an answer right away.'));
  teaser.appendChild(tClose); teaser.appendChild(tBody);

  var panel = el('div', 'sc-panel'); panel.hidden = true;
  panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-modal', 'false'); panel.setAttribute('aria-label', C.name);
  var head = el('div', 'sc-head');
  head.appendChild(avatars('md'));
  var ht = el('div', 'sc-head-text');
  ht.appendChild(el('strong', null, C.name));
  var status = el('span', 'sc-status'); status.appendChild(el('span', 'sc-dot'));
  status.appendChild(document.createTextNode(C.hasTeam ? C.team.replace(' and ', ' & ') + ' on call' : 'Quick answers, real people'));
  ht.appendChild(status); head.appendChild(ht);
  var personBtn = el('button', 'sc-person', 'Talk to a person'); personBtn.type = 'button';
  var minBtn = el('button', 'sc-min'); minBtn.type = 'button'; minBtn.setAttribute('aria-label', 'Close chat');
  minBtn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
  head.appendChild(personBtn); head.appendChild(minBtn);

  var stream = el('div', 'sc-stream');
  var live = el('div', 'sc-sr'); live.setAttribute('aria-live', 'polite');
  var form = el('form', 'sc-composer');
  var input = el('input'); input.type = 'text'; input.placeholder = 'Type your question'; input.setAttribute('aria-label', 'Your message'); input.maxLength = 1000;
  var send = el('button', 'sc-send'); send.type = 'submit'; send.setAttribute('aria-label', 'Send');
  send.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
  form.appendChild(input); form.appendChild(send);
  var foot = el('p', 'sc-foot', C.ai ? 'AI answers, reviewed by ' + TEAM + '. A person is one tap away.' : Team + ' read every question. A person is one tap away.');

  panel.appendChild(head); panel.appendChild(stream); panel.appendChild(form); panel.appendChild(foot); panel.appendChild(live);
  root.appendChild(teaser); root.appendChild(panel); root.appendChild(launcher);
  document.body.appendChild(root);

  /* ---- Rendering ---- */
  function scroll() { stream.scrollTop = stream.scrollHeight; }
  function clearChips() { [].forEach.call(stream.querySelectorAll('.sc-chips'), function (c) { c.remove(); }); }

  function render(m, animate) {
    var row;
    if (m.from === 'user') {
      row = el('div', 'sc-msg sc-user'); row.appendChild(el('div', 'sc-bubble', m.text));
    } else if (m.kind === 'tiers') {
      row = el('div', 'sc-msg sc-bot');
      var card = el('div', 'sc-card');
      card.appendChild(el('p', 'sc-eyebrow', 'How we work'));
      [['01', 'The Build', 'A custom website with content and search built in. You own all of it.'],
       ['02', 'The Partnership', 'Ongoing content, local search, your Google profile, and quarterly strategy meetings.'],
       ['03', 'The Whole System', 'We run your marketing end to end, with monthly reporting.']].forEach(function (t) {
        var r = el('div', 'sc-tier'); r.appendChild(el('span', 'sc-num', t[0]));
        var b = el('div'); b.appendChild(el('strong', null, t[1])); b.appendChild(el('span', null, t[2])); r.appendChild(b); card.appendChild(r);
      });
      row.appendChild(card);
    } else if (m.kind === 'link') {
      row = el('div', 'sc-msg sc-bot');
      var a = el('a', 'sc-linkbtn', m.label); a.href = m.url; a.setAttribute('data-scout-track', m.track || 'chat_link'); row.appendChild(a);
    } else if (m.kind === 'form') {
      row = el('div', 'sc-msg sc-bot'); row.appendChild(leadForm(m));
    } else if (m.kind === 'done') {
      row = el('div', 'sc-msg sc-bot');
      var d = el('div', 'sc-done'); var ic = el('span', 'sc-check'); ic.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>';
      d.appendChild(ic); var db = el('div'); db.appendChild(el('strong', null, m.title)); db.appendChild(el('span', null, m.text)); d.appendChild(db); row.appendChild(d);
    } else {
      row = el('div', 'sc-msg sc-bot'); var bub = el('div', 'sc-bubble'); row.appendChild(bub);
      if (animate && !reduce) { streamText(bub, m.text, function () { live.textContent = m.text; chips(m); }); stream.appendChild(row); scroll(); return; }
      bub.textContent = m.text;
    }
    stream.appendChild(row); scroll();
    if (m.kind !== 'form') chips(m);
  }

  function chips(m) {
    if (!m.chips || !m.chips.length || m !== state.msgs[state.msgs.length - 1]) return;
    clearChips();
    var w = el('div', 'sc-chips');
    m.chips.forEach(function (label) {
      var b = el('button', 'sc-chip', label); b.type = 'button';
      b.addEventListener('click', function () { track('chip_click', { link_label: label }); clearChips(); user(label); route(label); });
      w.appendChild(b);
    });
    stream.appendChild(w); scroll();
  }

  function streamText(node, text, done) {
    var words = text.split(' '), n = 0, caret = el('span', 'sc-caret');
    node.appendChild(caret);
    var iv = setInterval(function () {
      n += 1; node.textContent = words.slice(0, n).join(' '); node.appendChild(caret); scroll();
      if (n >= words.length) { clearInterval(iv); caret.remove(); done(); }
    }, 28);
  }

  /* Bot messages play one at a time, with a short "typing" pause. */
  function bot(items) {
    items.forEach(function (m) { m.from = 'bot'; queue.push(m); });
    if (!busy) next();
  }
  function next() {
    var m = queue.shift();
    if (!m) { busy = false; save(); return; }
    busy = true;
    var dots = el('div', 'sc-msg sc-bot sc-typing'); dots.innerHTML = '<span></span><span></span><span></span>';
    stream.appendChild(dots); scroll();
    setTimeout(function () {
      dots.remove(); state.msgs.push(m); render(m, true);
      var wait = (m.kind && m.kind !== 'text') || reduce ? 250 : m.text.split(' ').length * 28 + 250;
      setTimeout(next, wait);
    }, reduce ? 150 : 650);
  }
  function user(text) { var m = { from: 'user', text: text }; state.msgs.push(m); render(m); save(); }

  /* ---- Flows ---- */
  function route(label) {
    if (label === CHIPS.cost) {
      bot([{ kind: 'tiers' }, { text: 'Every project starts with The Build. We quote after a short call, once we know what you need, so the price fits your site and nothing else. Want to pick a time?', chips: [CHIPS.book, CHIPS.other] }]);
    } else if (label === CHIPS.work) {
      bot([{ text: 'Here is some of what we have built. Each one is custom, made around the business it serves.' }, { kind: 'link', label: 'See our work', url: C.workUrl, track: 'chat_work' }, { text: 'Anything else I can help with?', chips: [CHIPS.book, CHIPS.other] }]);
    } else if (label === CHIPS.book) {
      bot([{ text: 'Pick a time that works for you. It takes about 20 minutes, and you will talk with ' + TEAM + ' directly.' }, { kind: 'link', label: 'See open times', url: C.bookUrl, track: 'chat_book' }]);
    } else if (label === CHIPS.client) {
      bot([{ text: 'Good to see you. What do you need today?', chips: [CHIPS.change, CHIPS.person] }]);
    } else if (label === CHIPS.change) {
      state.mode = 'change'; bot([{ text: 'Tell me what you would like changed, in a sentence or two. Include the page if you know it.' }]); input.focus();
    } else if (label === CHIPS.person) {
      handoff();
    } else if (label === CHIPS.other) {
      state.mode = 'ask'; bot([{ text: 'Go ahead and type your question.' }]); input.focus();
    }
  }

  function handoff(note) {
    track('handoff');
    state.kind = 'person';
    bot([{ text: (note || 'Happy to bring in ' + TEAM + '.') + ' Where should they reach you?' }, { kind: 'form' }]);
  }

  function askForContact(kind, lead) {
    state.kind = kind;
    bot([{ text: lead + ' Where should we send it?' }, { kind: 'form' }]);
  }

  function onUserText(text) {
    if (state.mode === 'change') {
      state.mode = 'ask'; state.pending = text;
      askForContact('change', 'Got it. ' + Team + ' will take care of it and confirm by email.');
      return;
    }
    // A hello or a thanks is not a question: greet back and offer the buttons.
    if (/^((hi+|hello|hey+|hiya|howdy|yo|sup|wh?at'?s ?up|good (morning|afternoon|evening))( there| y'?all| guys)?|thanks?( you)?|thank you|ty|ok(ay)?|cool)[\s!.,?]*$/i.test(text.trim())) {
      bot([{ text: 'Hi there. What can we help you with? Type your question, or pick one below.', chips: startChips() }]);
      return;
    }
    state.pending = text;
    var lead = /\?\s*$/.test(text) ? 'Good question. ' : 'Thanks. ';
    if (!C.ai) { askForContact('question', lead + Team + ' answer these personally, usually within one business day.'); return; }
    var dots = el('div', 'sc-msg sc-bot sc-typing'); dots.innerHTML = '<span></span><span></span><span></span>'; stream.appendChild(dots); scroll(); busy = true;
    post('/ask', { question: text, history: history(), page: location.href }).then(function (r) {
      dots.remove(); busy = false;
      if (r && r.answer) {
        bot([{ text: r.answer, chips: r.handoff ? [CHIPS.person, CHIPS.book] : [CHIPS.book, CHIPS.person] }]);
      } else {
        askForContact('question', lead + Team + ' will answer it personally, usually within one business day.');
      }
    });
  }

  function history() { return state.msgs.filter(function (m) { return m.text; }).map(function (m) { return { from: m.from, text: m.text }; }); }

  function post(path, body) {
    return fetch(C.rest + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'omit' })
      .then(function (r) { return r.json().then(function (j) { return r.ok ? j : { error: j && j.message ? j.message : 'Something went wrong. Please try again.' }; }); })
      .catch(function () { return { error: 'We could not reach the site. Please try again.' }; });
  }

  function leadForm() {
    var f = el('form', 'sc-form'); f.noValidate = true;
    var n = el('input'); n.type = 'text'; n.name = 'name'; n.autocomplete = 'name'; n.required = true;
    var t = el('input'); t.type = 'tel'; t.name = 'phone'; t.autocomplete = 'tel'; t.inputMode = 'tel';
    var e = el('input'); e.type = 'email'; e.name = 'email'; e.autocomplete = 'email';
    var hp = el('input'); hp.type = 'text'; hp.name = 'website'; hp.tabIndex = -1; hp.autocomplete = 'off'; hp.className = 'sc-hp'; hp.setAttribute('aria-hidden', 'true');
    var id = 'sc' + Math.random().toString(36).slice(2, 7);
    n.id = id + 'n'; t.id = id + 't'; e.id = id + 'e';
    var ln = el('label', null, 'Your name'); ln.htmlFor = n.id;
    var lt = el('label', null, 'Phone, to text you back'); lt.htmlFor = t.id;
    var le = el('label', null, 'Or email'); le.htmlFor = e.id;
    var err = el('p', 'sc-err'); err.setAttribute('role', 'alert');
    var b = el('button', 'sc-submit', 'Send'); b.type = 'submit';
    [ln, n, lt, t, le, e, hp, err, b].forEach(function (x) { f.appendChild(x); });
    f.addEventListener('submit', function (ev) {
      ev.preventDefault(); err.textContent = '';
      if (!n.value.trim()) { err.textContent = 'Add your name so we know who we are talking to.'; n.focus(); return; }
      var digits = t.value.replace(/\D/g, ''), mail = e.value.trim();
      if (!digits && !mail) { err.textContent = 'Add a phone number or an email so we can reply.'; t.focus(); return; }
      if (digits && (digits.length < 10 || digits.length > 11)) { err.textContent = 'That phone number does not look quite right.'; t.focus(); return; }
      if (mail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail)) { err.textContent = 'That email does not look quite right.'; e.focus(); return; }
      b.disabled = true; b.textContent = 'Sending';
      post('/lead', { name: n.value.trim(), phone: t.value.trim(), email: e.value.trim(), website: hp.value, kind: state.kind, history: history(), page: location.href }).then(function (r) {
        if (r.error) { err.textContent = r.error; b.disabled = false; b.textContent = 'Send'; return; }
        track('lead_submit', { link_label: state.kind });
        f.querySelectorAll('input,button').forEach(function (x) { x.disabled = true; }); b.textContent = 'Sent';
        state.done = true;
        bot([{ kind: 'done', title: 'Sent', text: 'Thanks, ' + n.value.trim().split(' ')[0] + '. ' + Team + (digits ? ' will text you at ' + t.value.trim() : ' will reply to ' + mail) + ', usually within one business day.' },
             { text: 'Anything else?', chips: [CHIPS.book, CHIPS.other] }]);
      });
    });
    setTimeout(function () { n.focus(); }, 50);
    return f;
  }

  /* ---- Open, close, teaser ---- */
  function open() {
    if (state.open) return;
    state.open = true; panel.hidden = false; teaser.hidden = true; launcher.setAttribute('aria-expanded', 'true'); root.classList.add('is-open');
    try { sessionStorage.setItem(KEY + 'Teaser', '1'); } catch (e) {}
    track('chat_open');
    if (!stream.childNodes.length) {
      if (state.msgs.length) { state.msgs.forEach(function (m) { if (m.kind !== 'form') render(m, false); }); }
      else bot([{ text: 'Hi, I\'m ' + C.name + '. I can answer common questions right away, and ' + TEAM + ' are a tap away whenever you want a person.' },
                { text: 'What brings you by?', chips: startChips() }]);
    }
    setTimeout(function () { input.focus(); }, 60);
  }
  function close() { state.open = false; panel.hidden = true; launcher.setAttribute('aria-expanded', 'false'); root.classList.remove('is-open'); launcher.focus(); }

  launcher.addEventListener('click', function () { state.open ? close() : open(); });
  minBtn.addEventListener('click', close);
  personBtn.addEventListener('click', function () { if (!state.open) open(); clearChips(); handoff(); });
  panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  form.addEventListener('submit', function (e) {
    e.preventDefault(); var t = input.value.trim(); if (!t || busy) return;
    input.value = ''; clearChips(); user(t); onUserText(t);
  });
  tBody.addEventListener('click', open);
  tClose.addEventListener('click', function () { teaser.hidden = true; try { sessionStorage.setItem(KEY + 'Teaser', '1'); } catch (e) {} });

  load();
  var seen = false; try { seen = !!sessionStorage.getItem(KEY + 'Teaser'); } catch (e) {}
  /* The greeting waits for the cookie notice to be closed, so they never overlap. */
  function showTeaser(tries) {
    if (state.open) return;
    var notice = document.getElementById('scout-consent');
    if (notice && !notice.hidden) { if (tries < 30) setTimeout(function () { showTeaser(tries + 1); }, 1000); return; }
    teaser.hidden = false;
  }
  if (C.teaser && !seen && !state.msgs.length) setTimeout(function () { showTeaser(0); }, 2500);
})();
