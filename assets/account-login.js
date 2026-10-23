/**
 * The UnleashWP sign-in, on the site itself.
 *
 * Loaded on the first click of an account link, never with the page. Email,
 * then a six-digit code, both answered by the account directly; its cookie is
 * set for the whole of unleash-wp.com, so the Downloads tab opens signed in.
 */
(function () {
  'use strict';

  var c = window.wpdcAccountLogin || {};
  var dialog = null;
  var opener = null;
  var email = '';
  var resendAt = 0;
  var onDone = null;

  function el(tag, attrs, kids) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') node.textContent = attrs[k];
      else node.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (kid) { if (kid) node.appendChild(kid); });
    return node;
  }

  var pow = null;

  function hex(buf) {
    return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  function sha(text) {
    return crypto.subtle.digest('SHA-256', new TextEncoder().encode(text)).then(hex);
  }

  // A small sum the browser does once per code: nothing for a person, a cost per mail for a bot.
  async function solve(ch) {
    for (var start = 0; start <= ch.maxnumber; start += 250) {
      var batch = [];
      for (var i = start; i < start + 250 && i <= ch.maxnumber; i++) batch.push(sha(ch.salt + i));
      var at = (await Promise.all(batch)).indexOf(ch.challenge);
      if (at >= 0) {
        var n = start + at;
        return btoa(JSON.stringify({ salt: ch.salt, number: n, challenge: ch.challenge, signature: ch.signature }));
      }
    }
    return null;
  }

  // Fetched ahead, so it is ready by the time someone has typed an address.
  function prefetch() {
    pow = fetch(c.account + '/api/pow/challenge', { credentials: 'omit', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (ch) { return ch && ch.salt ? solve(ch) : null; })
      .catch(function () { return null; });
  }

  function call(path, body, extra) {
    var init = { credentials: 'include', headers: Object.assign({ 'x-uwp-lang': c.lang || 'en' }, extra || {}) };
    if (body) {
      init.method = 'POST';
      init.headers['content-type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    return fetch(c.account + path, init);
  }

  function say(text) {
    var live = dialog.querySelector('.uwp-login__status');
    live.textContent = text || '';
    live.hidden = !text;
  }

  function busy(button, on, label) {
    button.disabled = on;
    if (on) {
      button.dataset.label = button.textContent;
      button.textContent = label;
    } else if (button.dataset.label) {
      button.textContent = button.dataset.label;
    }
  }

  function openDownloads() {
    window.open(c.account + '/downloads', '_blank', 'noopener');
    dialog.close();
  }

  function step(name) {
    ['email', 'code', 'done'].forEach(function (s) {
      dialog.querySelector('.uwp-login__step--' + s).hidden = s !== name;
    });
    dialog.querySelector('.uwp-login__intro').hidden = name === 'done';
    say('');
    var first = dialog.querySelector('.uwp-login__step--' + name + ' input, .uwp-login__step--' + name + ' button');
    if (first) first.focus();
  }

  function sendCode(button, retried) {
    busy(button, true, c.s.sending);
    if (!pow) prefetch();
    var trap = dialog.querySelector('.uwp-login__hp').value;
    return pow
      .then(function (proof) {
        var extra = {};
        if (proof) extra['x-uwp-pow'] = proof;
        if (trap) extra['x-uwp-hp'] = trap;
        return call('/api/auth/email-otp/send-verification-otp', { email: email, type: 'sign-in' }, extra);
      })
      .then(function (r) {
        // Every proof is single use: the next one starts now.
        prefetch();
        if (r.status === 403) {
          return r.json().catch(function () { return {}; }).then(function (d) {
            // The fresh challenge has to age past the server's two-second floor first;
            // the button stays busy meanwhile, so a second click cannot send twice.
            if (d && d.code === 'POW_REQUIRED' && !retried) {
              return new Promise(function (go) { setTimeout(go, 2100); }).then(function () {
                busy(button, false);
                return sendCode(button, true);
              });
            }
            busy(button, false);
            say(c.s.powFailed);
          });
        }
        busy(button, false);
        // Any answer but a throttle moves on: the wording never says whether the address exists.
        if (r.status === 429) { say(c.s.tooMany); return; }
        resendAt = Date.now() + 30000;
        dialog.querySelector('.uwp-login__sent').textContent = c.s.sent;
        step('code');
      })
      .catch(function () { busy(button, false); say(c.s.offline); });
  }

  function verify(code, button) {
    busy(button, true, c.s.checking);
    call('/api/auth/sign-in/email-otp', { email: email, otp: code })
      .then(function (r) {
        busy(button, false);
        if (r.ok) { step('done'); return; }
        say(r.status === 403 ? c.s.locked : r.status === 429 ? c.s.tooMany : c.s.wrong);
        var input = dialog.querySelector('.uwp-login__code');
        input.value = '';
        input.focus();
      })
      .catch(function () { busy(button, false); say(c.s.offline); });
  }

  function build() {
    var mailInput = el('input', {
      type: 'email', id: 'uwp-login-email', class: 'uwp-login__input', autocomplete: 'email',
      required: 'required', placeholder: c.s.placeholder
    });
    var sendBtn = el('button', { type: 'submit', class: 'uwp-login__primary', text: c.s.send });
    var mailForm = el('form', { class: 'uwp-login__step uwp-login__step--email', novalidate: 'novalidate' }, [
      el('label', { for: 'uwp-login-email', class: 'uwp-login__label', text: c.s.email }),
      mailInput,
      // Filled only by bots that complete every field; a person never sees it. No name
      // that autofill knows ("website", "url"), or a person would be taken for a bot.
      el('input', { type: 'text', name: 'uwp_extra_field', class: 'uwp-login__hp', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }),
      sendBtn
    ]);
    mailForm.addEventListener('submit', function (e) {
      e.preventDefault();
      email = mailInput.value.trim();
      if (!mailInput.checkValidity() || !email) { say(c.s.badEmail); mailInput.focus(); return; }
      sendCode(sendBtn);
    });

    var codeInput = el('input', {
      type: 'text', id: 'uwp-login-code', class: 'uwp-login__input uwp-login__code', inputmode: 'numeric',
      autocomplete: 'one-time-code', maxlength: '6', pattern: '[0-9]{6}', required: 'required'
    });
    var verifyBtn = el('button', { type: 'submit', class: 'uwp-login__primary', text: c.s.signIn });
    var resend = el('button', { type: 'button', class: 'uwp-login__quiet', text: c.s.resend });
    var other = el('button', { type: 'button', class: 'uwp-login__quiet', text: c.s.other });
    var codeForm = el('form', { class: 'uwp-login__step uwp-login__step--code', novalidate: 'novalidate', hidden: '' }, [
      el('p', { class: 'uwp-login__sent' }),
      el('label', { for: 'uwp-login-code', class: 'uwp-login__label', text: c.s.code }),
      codeInput,
      verifyBtn,
      el('div', { class: 'uwp-login__row' }, [resend, other])
    ]);
    codeInput.addEventListener('input', function () {
      codeInput.value = codeInput.value.replace(/\D/g, '').slice(0, 6);
      if (codeInput.value.length === 6) verify(codeInput.value, verifyBtn);
    });
    codeForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (codeInput.value.length === 6) verify(codeInput.value, verifyBtn);
    });
    resend.addEventListener('click', function () {
      var wait = Math.ceil((resendAt - Date.now()) / 1000);
      if (wait > 0) { say(c.s.wait.replace('%d', wait)); return; }
      sendCode(resend);
    });
    other.addEventListener('click', function () { step('email'); });

    var goBtn = el('button', { type: 'button', class: 'uwp-login__primary uwp-login__go', text: c.s.open });
    goBtn.addEventListener('click', function () {
      // Opened from a checkout: back to the purchase, not away to Downloads.
      if (onDone) { var next = onDone; onDone = null; dialog.close(); next(); return; }
      openDownloads();
    });
    var done = el('div', { class: 'uwp-login__step uwp-login__step--done', hidden: '' }, [
      el('p', { class: 'uwp-login__lead', text: c.s.signedIn }),
      goBtn
    ]);

    var close = el('button', { type: 'button', class: 'uwp-login__close', 'aria-label': c.s.close, text: '×' });
    close.addEventListener('click', function () { dialog.close(); });

    dialog = el('dialog', { class: 'uwp-login', 'aria-labelledby': 'uwp-login-title' }, [
      el('div', { class: 'uwp-login__card' }, [
        close,
        c.logo ? el('img', { class: 'uwp-login__logo', src: c.logo, alt: 'UnleashWP', width: '160', height: '36' }) : null,
        el('h2', { id: 'uwp-login-title', class: 'uwp-login__title', text: c.s.title }),
        el('p', { class: 'uwp-login__lead uwp-login__intro', text: c.s.lead }),
        mailForm,
        codeForm,
        done,
        el('p', { class: 'uwp-login__status', role: 'status', 'aria-live': 'polite', hidden: '' })
      ])
    ]);
    dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });
    dialog.addEventListener('close', function () { if (opener) opener.focus(); });
    document.body.appendChild(dialog);
  }

  window.wpdcAccountLogin.open = function (from, opts) {
    opener = from || null;
    onDone = opts && typeof opts.onDone === 'function' ? opts.onDone : null;
    if (!dialog) build();
    dialog.querySelector('.uwp-login__go').textContent = onDone ? c.s.continue : c.s.open;
    if (!pow) prefetch();
    dialog.showModal();
    if (c.signedIn) { step('done'); return; }
    // After a purchase the account has already mailed the code to the paying address.
    if (opts && opts.email) {
      email = opts.email;
      dialog.querySelector('#uwp-login-email').value = email;
      dialog.querySelector('.uwp-login__sent').textContent = c.s.sentTo.replace('%s', email);
      resendAt = Date.now() + 30000;
      step('code');
      return;
    }
    step('email');
  };
})();
