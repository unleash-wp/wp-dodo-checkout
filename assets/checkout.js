/**
 * The button.
 *
 * It sends a product id and a checkbox state, receives a URL, and hands that URL
 * to Dodo's SDK to render a checkout frame inside a modal on the page. It never sees a
 * price and never a credential, and the id it sends is the same one Dodo puts in
 * its own public payment links -- so there is nothing here for a browser
 * extension or a compromised third-party script to take. Tampering with the id
 * gets a different LIVE product at that product's own price; the server refuses
 * anything Dodo does not currently list.
 *
 * Card fields are never in this document. The frame is an iframe served from
 * Dodo's origin, so the PCI surface is the same as a redirect: this page cannot
 * read what is typed into it, and neither can anything else running here.
 */
(function () {
  'use strict';

  var cfg = window.wpdcCheckout || {};

  function say(root, text) {
    var el = root.querySelector('.wpdc__message');
    if (el) el.textContent = text;
  }

  function requestUrl(root) {
    var bump = root.querySelector('.wpdc__bump-input');
    var body = {
      product: root.dataset.product,
      quantity: Number(root.dataset.quantity || 1),
    };
    // The shortcode first, then the BROWSER -- never WordPress.
    //
    // The site locale describes the shop: one answer where a shop selling a
    // German edition and an English one has two, and on this install it said
    // en_US while the customer was reading German. The browser is the only
    // party that knows what the person in front of the checkout reads.
    // German for German speakers, English for everyone else. The shop sells
    // internationally and speaks two languages; handing a visitor's own through
    // meant a French browser got a French checkout beside English labels, which
    // reads as a fault rather than as a shop that speaks two languages.
    if (root.dataset.discount) body.discount = root.dataset.discount;

    var tag = root.dataset.lang || navigator.language || '';
    body.lang = /^de/i.test(tag) ? 'de' : 'en';
    // The checkbox decides which cart is asked for, and nothing else. No
    // price arithmetic anywhere in this file, on purpose: the amount is
    // settled on Dodo's page, where a browser cannot reach it.
    if (bump && bump.checked) body.bump = bump.value;

    return fetch(cfg.endpoint, {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'x-wp-nonce': cfg.nonce },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    }).then(function (res) {
      return res.json().then(function (data) {
        if (res.ok && data.url) {
          // Kept so this checkout can be asked about later. Written on the
          // root rather than closed over, because a re-mint replaces it.
          if (data.session) root.dataset.session = data.session;
          return data.url;
        }
        // The server's sentence when it sent one; it is written for a
        // visitor. The generic line only when there was none.
        // Only a sentence the SERVER wrote. A parse failure or a browser
        // network error carries text nobody wrote for a customer, and
        // client.php takes care never to pass one on -- this is the other
        // end of the same rule.
        const err = new Error(typeof data?.message === 'string' ? data.message : cfg.failed);
        err.uwpFromServer = typeof data?.message === 'string';
        throw err;
      });
    });
  }

  /**
   * The SDK, where the CDN build actually puts it.
   *
   * The UMD bundle attaches ONE global and exports the namespace inside it:
   *
   *   (globalThis).DodoPaymentsCheckout = {} ... e.DodoPayments = W
   *
   * so the path is window.DodoPaymentsCheckout.DodoPayments. This used to look
   * for window.DodoPayments, which the bundle never sets, so the embed branch
   * was unreachable and every purchase silently navigated instead. It looked
   * deliberate, because falling through to a navigation IS a real branch with a
   * comment explaining it. The frame had never once opened.
   *
   * The older name is still accepted in case a future build exposes it, but a
   * miss is reported rather than swallowed: see openFrame.
   */
  function sdk() {
    var ns = window.DodoPaymentsCheckout;
    return (ns && ns.DodoPayments) || window.DodoPayments || null;
  }

  /**
   * Which Dodo environment the SDK should talk to, read off the URL it is about
   * to open.
   *
   * `mode` is required and is "test" or "live". This passed 'overlay', which is
   * neither, and nothing in the plugin configured it -- there was no setting to
   * get wrong because there was no setting.
   *
   * Deriving it from the session URL rather than adding one is deliberate: the
   * server already decided which environment it minted this session in, and a
   * second setting beside that decision is a thing that can disagree with it.
   * A mismatch is not cosmetic -- test and live are different accounts with
   * different money.
   *
   * Unknown host shapes resolve to 'live'. A live session opened in test mode
   * is the direction that quietly takes a real card into a test flow; the
   * reverse fails visibly, which is what an unknown should do.
   */
  function environmentFor(url) {
    var host = '';
    try {
      host = new URL(url, window.location.href).hostname;
    } catch (e) {
      return 'live';
    }
    return /(^|\.)test\./.test(host) ? 'test' : 'live';
  }

  /**
   * Initialize exactly once, and not before the first session exists.
   *
   * Dodo's documentation is explicit that initialization happens once when the
   * application loads. It cannot happen at load HERE, because the mode is read
   * off the session URL and no session exists until somebody clicks. So: first
   * click initializes, later clicks reuse. Re-initializing per click was the
   * previous behaviour and is the kind of thing that works until the SDK starts
   * keeping state.
   */
  var ready = false;
  var openRoot = null;

  function ensureReady(dodo, url) {
    if (ready) return;
    dodo.Initialize({
      mode: environmentFor(url),
      displayType: 'inline',
      onEvent: function (event) {
        if (!event) return;
        var root = openRoot;
        if (!root) return;

        // Dodo's own guidance: show a loading state until the frame reports
        // itself open. Either event will do -- `opened` means the frame is
        // there, `form_ready` means it can be typed into -- and whichever
        // arrives first is the moment the skeleton has done its job.
        if (event.event_type === 'checkout.opened' || event.event_type === 'checkout.form_ready') {
          settleLoading(root);
          return;
        }
        // Step one to step two. The frame swaps its whole contents, and on a
        // phone the DIALOG is the scroller -- so a customer who had scrolled to
        // reach "Weiter zur Zahlung" stayed at that offset and arrived on the
        // payment step already scrolled past the wallet button at the top of
        // it. Both scrollers go back to the start, because the new screen
        // begins at its own beginning.
        // A cart discounted to zero finishes without ever saying so.
        //
        // There is no payment to collect, and Dodo's frame renders the payment
        // step regardless: it fetches a payment link that does not exist for a
        // zero total, takes a 404, and stops. No `checkout.redirect`, no
        // `checkout.error` -- nothing their SDK or this file reacts to. Their
        // side meanwhile marks the order `succeeded` within seconds.
        //
        // Measured, not assumed: pay_..., total 0, `payment_method: null`,
        // against the same session id the stuck frame was showing.
        //
        // So the shop finishes it: their dead step goes, and the wait takes its
        // place where the answer will appear.
        if (event.event_type === 'checkout.customer_details_submitted' && '0' === root.dataset.due) {
          settleLoading(root);
          showDone(root, false);
          awaitCompletion(root);
          return;
        }

        // And a checkout with something to pay ends the same way, one screen
        // later. Their frame reports the payment page, the customer pays, and
        // their SDK leaves only if a return_url was configured -- which is the
        // shop's own thank-you page and a fine place to land. Without one it
        // stays put, and until this branch existed nobody finished the order:
        // the panel with the download, the key and the word about the mail was
        // built for the ONE case that pays nothing.
        //
        // On the CLICK, not on the payment screen appearing. Starting when the
        // screen opens would run the minute of asking while the customer is
        // still typing a card number, and then tell somebody mid-payment that
        // their order could not be confirmed.
        //
        // Nothing is swapped here: the panel only takes over once Dodo says
        // succeeded. Until then the customer keeps the screen they are using,
        // and if the minute runs out they keep it too -- the honest sentence
        // appears under the dialog rather than over their payment.
        if (event.event_type === 'checkout.pay_button_clicked') {
          awaitCompletion(root);
          return;
        }
        if (event.event_type === 'checkout.customer_details_submitted') {
          var scroll = root.querySelector('.wpdc__scroll');
          var frame = root.querySelector('.wpdc__frame');
          if (scroll) scroll.scrollTop = 0;
          if (frame) {
            frame.scrollTop = 0;
            // Their payment step brings no top spacing of its own while their
            // contact step does, so one padding value cannot be right for both.
            // This is the moment the screen changes, and the only place that
            // knows it.
            frame.classList.add('is-payment');
          }
          return;
        }
        if (event.event_type === 'checkout.error') {
          settleLoading(root);
          say(root, (event.data && event.data.message) || cfg.failed);
          return;
        }
        // Dodo's own numbers, arriving when the frame loads and again on every
        // address change that moves the tax. Never computed here: the tax
        // depends on a country the customer has not typed yet.
        if (event.event_type === 'checkout.breakdown') {
          var breakdown = (event.data && event.data.message) || {};
          paintTotals(root, breakdown);
          // Remembered for the step below, which cannot ask for it again: the
          // breakdown arrives once per screen, and the screen it is needed on
          // is the one that never loads.
          var due = typeof breakdown.finalTotal === 'number' ? breakdown.finalTotal : breakdown.total;
          root.dataset.due = typeof due === 'number' ? String(due) : '';
        }
      },
    });
    ready = true;
  }

  /**
   * Money as Dodo reports it, in the panel beside their frame.
   *
   * Amounts arrive as integers in the smallest unit, so they are divided by 100
   * and formatted by the BROWSER's locale rules rather than by arithmetic here.
   * `finalTotal` wins over `total` when present: it is what the card is actually
   * charged, including currency conversion the basic breakdown does not carry.
   *
   * A row with no number stays hidden rather than showing a zero. Before the
   * customer gives a country there IS no tax, and printing "0.00" would be a
   * statement about their bill rather than an admission that it is not known
   * yet.
   */
  /**
   * The wait, and the end of it.
   *
   * Between showModal() and Dodo rendering into the container there are a few
   * seconds of empty white beside a panel that is already full. It reads as
   * broken, and the operator photographed exactly that.
   *
   * A skeleton fills it, and a deadline ends it. The deadline is the part worth
   * arguing for: a spinner with no timeout is how a customer who has decided to
   * buy sits in front of nothing until they give up, and nobody ever hears
   * about it. If the frame has not reported itself open by then, they go to
   * Dodo's own page -- the same fallback used when the SDK is missing, for the
   * same reason.
   */
  var LOAD_DEADLINE_MS = 20000;
  var loadTimer = null;

  function startLoading(root, frame, url) {
    frame.classList.remove('is-ready');
    frame.classList.remove('is-payment');
    frame.classList.add('is-loading');
    clearTimeout(loadTimer);
    loadTimer = setTimeout(function () {
      if (!frame.classList.contains('is-loading')) return;
      if (window.console && console.warn) {
        console.warn('[wp-dodo-checkout] the checkout frame never reported itself open; redirecting.');
      }
      window.location.assign(url);
    }, LOAD_DEADLINE_MS);
  }

  function settleLoading(root) {
    clearTimeout(loadTimer);
    var frame = root.querySelector('.wpdc__frame');
    if (!frame) return;
    // `is-ready` only on the transition OUT of waiting, so a frame that reflows
    // later -- and Dodo's reflows on every address change -- does not replay
    // the entrance under a customer who is mid-typing.
    var wasWaiting = frame.classList.contains('is-loading');
    frame.classList.remove('is-loading');
    if (wasWaiting) frame.classList.add('is-ready');
  }

  function paintTotals(root, b) {
    var totals = root.querySelector('.wpdc__totals');
    if (!totals) return;

    var currency = b.currency || b.finalTotalCurrency || '';
    var rows = {
      subtotal: b.subTotal,
      discount: b.discount,
      tax: b.tax,
      total: b.finalTotal != null ? b.finalTotal : b.total,
    };
    var totalCurrency = b.finalTotal != null ? (b.finalTotalCurrency || currency) : currency;
    var shown = false;

    Object.keys(rows).forEach(function (key) {
      var row = totals.querySelector('[data-row="' + key + '"]');
      if (!row) return;
      var value = rows[key];
      // Discount is hidden at zero; the others are hidden only when absent, so
      // a genuine zero tax still shows as 0.00 once a country is known.
      var has = value != null && (key !== 'discount' || value !== 0);
      row.hidden = !has;
      if (!has) return;
      shown = true;
      // A discount is money coming OFF, and printing it the same way as the
      // subtotal makes a 24,99 discount on a 24,99 item read as a second charge
      // -- which is exactly what the operator saw.
      row.querySelector('dd').textContent =
        (key === 'discount' ? '\u2212' : '') +
        money(value, key === 'total' ? totalCurrency : currency);
    });

    totals.hidden = !shown;
    paintRate(totals, b);
    paintOff(totals, b);
  }

  /**
   * The VAT rate, derived from Dodo's own two numbers.
   *
   * Their breakdown carries an amount and no rate, and a customer reading
   * "VAT 1,63" cannot tell 7% from 19% -- which is the difference between a
   * book and everything else, and the number they will look for on the invoice.
   *
   * tax / (subTotal - discount), because the discount comes off before tax is
   * charged. Shown only when it lands on a sane figure: a rate computed from a
   * partial breakdown, or from a zero base, would be a statement about somebody
   * tax affairs that we invented.
   *
   * The COUNTRY is deliberately absent. It is not in the event, it is not
   * anywhere else we can see, and a country printed on a tax line is the kind
   * of guess that ends up on a receipt.
   */
  function paintRate(totals, b) {
    var el = totals.querySelector('.wpdc__rate');
    if (!el) return;
    el.textContent = '';

    var base = (b.subTotal || 0) - (b.discount || 0);
    if (!base || b.tax == null || b.tax <= 0) return;

    var rate = (b.tax / base) * 100;
    if (!isFinite(rate) || rate <= 0 || rate > 40) return;

    // One decimal at most, and none when it is whole: "7 %" reads as a rate,
    // "7,0 %" reads as a calculation.
    var rounded = Math.round(rate * 10) / 10;
    var shown = Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1).replace('.', ',');
    el.textContent = ' (' + shown + ' %)';
  }

  /**
   * How much came off, as a percentage of what it came off.
   *
   * Same reasoning as the VAT rate beside it: "Rabatt 24,99" does not say
   * whether that was ten percent or everything, and the customer is about to
   * decide whether the code they typed did what they expected.
   */
  function paintOff(totals, b) {
    var el = totals.querySelector('.wpdc__off');
    if (!el) return;
    el.textContent = '';

    if (!b.subTotal || !b.discount || b.discount <= 0) return;
    var off = (b.discount / b.subTotal) * 100;
    if (!isFinite(off) || off <= 0 || off > 100) return;

    var rounded = Math.round(off * 10) / 10;
    var shown = Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1).replace('.', ',');
    el.textContent = ' (' + shown + ' %)';
  }

  /** One place for a refusal, so every one of them looks the same. */
  function fail(note, message) {
    note.textContent = message;
    note.classList.add('is-error');
  }

  function money(minor, currency) {
    var amount = minor / 100;
    try {
      return new Intl.NumberFormat(document.documentElement.lang || undefined, {
        style: currency ? 'currency' : 'decimal',
        currency: currency || undefined,
      }).format(amount);
    } catch (e) {
      // An unknown currency code throws rather than degrading, and a checkout
      // must not lose its totals over a formatting nicety.
      return amount.toFixed(2) + (currency ? ' ' + currency : '');
    }
  }

  function openFrame(root, url) {
    var dodo = sdk();
    if (!dodo) {
      // Not silent. The navigation still happens, because a customer trying to
      // pay must not be stopped by our script loader, but the reason is stated
      // where a developer will see it. Swallowing this is what hid the bug
      // above for as long as it existed.
      if (window.console && console.warn) {
        console.warn(
          '[wp-dodo-checkout] Dodo checkout SDK not found on the page; ' +
            'falling back to a redirect. Expected window.DodoPaymentsCheckout.DodoPayments.',
        );
      }
      return false;
    }

    ensureReady(dodo, url);

    var dialog = root.querySelector('.wpdc__dialog');
    var frame = root.querySelector('.wpdc__frame');
    if (!dialog || !frame || !frame.id || !dialog.showModal) return false;

    // One checkout at a time. The modal enforces it by itself -- nothing behind
    // it is clickable -- but a second block's dialog left open underneath would
    // still be a second cart.
    if (openRoot && openRoot !== root) closeFrame(dodo);

    // Shown BEFORE the SDK is told to render: a dialog that is not open has no
    // layout, and an iframe measured inside a zero-height box comes back zero.
    dialog.showModal();
    startLoading(root, frame, url);
    dodo.Checkout.open({ checkoutUrl: url, elementId: frame.id });
    watchForLateGrowth(frame);
    openRoot = root;
    say(root, '');
    return true;
  }

  /**
   * Keep the top of the checkout at the top when it grows under the customer.
   *
   * The SDK injects TWO elements: an express wallet element, then the checkout
   * itself. The wallet one arrives late and starts at nearly zero height, so
   * when it fills in it pushes everything down while the scroll position stays
   * where it was -- and the customer, who was looking at the payment step, is
   * suddenly looking at the middle of a region that did not exist a moment ago.
   * It reads as a jump into empty space, and that is exactly what it is.
   *
   * The wallet element lives in OUR document, not inside Dodo's frame, so its
   * size is observable from here. On the growth that matters -- from collapsed
   * to real -- the scroll goes back to the top, once. Later resizes are left
   * alone: a customer who has scrolled down deliberately must not be yanked
   * back every time something reflows.
   */
  function watchForLateGrowth(frame) {
    if (!window.ResizeObserver) return;

    var settled = false;
    var observer = new ResizeObserver(function (entries) {
      if (settled) return;
      for (var i = 0; i < entries.length; i++) {
        // 24px, not 0: the collapsed element is a few pixels tall rather than
        // absent, so "did it appear" is a threshold and not a truthiness test.
        if (entries[i].contentRect.height > 24) {
          settled = true;
          frame.scrollTop = 0;
          observer.disconnect();
          return;
        }
      }
    });

    // The element does not exist yet at open time; it is injected with the rest.
    // Watching the container for the child to turn up is one observer rather
    // than a poll, and it stops itself.
    var finder = new MutationObserver(function () {
      var wallet = frame.querySelector('[id^="dodo-wallet"]');
      if (!wallet) return;
      finder.disconnect();
      observer.observe(wallet);
    });
    finder.observe(frame, { childList: true, subtree: true });

    var existing = frame.querySelector('[id^="dodo-wallet"]');
    if (existing) { finder.disconnect(); observer.observe(existing); }
  }

  /**
   * Close the modal and tell the SDK, in that order.
   *
   * Closing the dialog without closing the checkout leaves Dodo holding a live
   * frame in a hidden element, and the next open would find it already there.
   */
  function closeFrame(dodo) {
    var root = openRoot;
    if (!root) return;

    // Claimed FIRST, and this is the fix rather than tidiness.
    //
    // `dialog.close()` fires the `close` event SYNCHRONOUSLY, and the listener
    // for it calls this function again -- with openRoot still set, because the
    // old version cleared it at the end. So everything below ran twice,
    // including Checkout.close() on an SDK that has already been told once.
    // Clearing the marker before doing anything makes the second entry a
    // no-op, which is what a close should be.
    openRoot = null;

    // A deadline that outlives the window it belonged to would redirect
    // somebody who deliberately closed the checkout.
    settleLoading(root);
    var dialog = root.querySelector('.wpdc__dialog');
    if (dialog && dialog.open) dialog.close();
    try { if (dodo) dodo.Checkout.close(); } catch (e) { /* already closed */ }
    var button = root.querySelector('.wpdc__button');
    if (button) button.disabled = false;
  }

  /**
   * Ask the shop's own server whether Dodo finished, then leave.
   *
   * Polling rather than listening, because there is nothing to listen to. The
   * server asks Dodo `GET /checkouts/{id}` and answers a boolean -- the API key
   * never leaves it, and neither does the name and email that come back in the
   * same response.
   *
   * A minute of asking, every two seconds. Long enough for an order that takes
   * a moment, short enough that a customer is not left watching a sentence
   * forever: when it runs out they are sent to the same place anyway, because
   * by then the likeliest truth is that it worked and we stopped asking too
   * early -- and their own confirmation is on the other side of that page.
   */
  var POLL_EVERY_MS = 2000;
  var POLL_TRIES = 30;

  function awaitCompletion(root) {
    var session = root.dataset.session || '';
    var tries = 0;

    // Reached ONLY on a confirmed success. A configured thank-you page wins;
    // without one the completion is shown right here, because leaving for the
    // front page reads as the popup breaking -- the purchase would end on a
    // page that says nothing about it.
    function leave(where, goods) {
      if (where) {
        window.location.assign(where);
        return;
      }
      var done = showDone(root, true);
      if (done) paintGoods(done, goods);
      say(root, '');
    }

    /**
     * Out of tries, and no confirmation. Say that, and nothing more.
     *
     * The previous version showed the completion panel here, or navigated to
     * the thank-you page -- a claim the shop cannot support. Somebody whose
     * payment failed read "order complete", and with a thank-you page
     * configured they landed on "thanks for your order" for an order that may
     * not exist. That is the worst answer available on this screen, and the
     * rate ceiling on the poll route made reaching it more likely rather than
     * less.
     *
     * The dialog stays open. The customer can close it, or write to us with a
     * screen that says what actually happened.
     */
    function giveUp() {
      settleLoading(root);
      // Into the panel the customer is already looking at, not under the
      // dialog where it would need scrolling to.
      var wait = root.querySelector('.wpdc__done-wait');
      if (wait) {
        var spinner = wait.querySelector('.wpdc__done-spinner');
        if (spinner) spinner.remove();
        var text = wait.querySelector('.wpdc__done-text');
        if (text) text.textContent = cfg.unconfirmed;
      }
      say(root, cfg.unconfirmed);
    }

    // One wait per checkout. `payment_page_opened` can arrive again when a
    // customer goes back and forward, and two polls would race to swap the
    // panel underneath each other.
    if (root.dataset.awaiting === '1') return;
    root.dataset.awaiting = '1';

    function ask() {
      tries += 1;
      // POST, so the session id travels in a body rather than in a URL. It is
      // the capability that unlocks this order's downloads and licence key, and
      // a URL is written down by every server it passes. This also sidesteps
      // the permalink shapes: rest_url() returns `.../wp-json/...` on pretty
      // permalinks and `.../index.php?rest_route=/...` on plain ones, and
      // appending a query to the second one puts the session inside the ROUTE.
      fetch(cfg.status, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'x-wp-nonce': cfg.nonce },
        credentials: 'same-origin',
        body: JSON.stringify({ session: session }),
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data && data.finished) return leave(data.redirect, data);
          if (tries >= POLL_TRIES) return giveUp();
          setTimeout(ask, POLL_EVERY_MS);
        })
        .catch(function () {
          // A failed poll is not a failed order, and there is nothing a
          // customer could do about a single one. Keep asking until the tries
          // run out -- and then say so, rather than leaving somebody in front
          // of a sentence that never changes.
          if (tries < POLL_TRIES) {
            setTimeout(ask, POLL_EVERY_MS);
            return;
          }
          giveUp();
        });
    }

    if (!session) {
      delete root.dataset.awaiting;
      return;
    }
    ask();
  }

  /**
   * The goods, straight into the completion panel.
   *
   * Download links and the licence key, when the purchase delivered any --
   * the same things Dodo puts in its mail, shown a minute earlier at the
   * moment the customer is actually looking. Built with DOM nodes and
   * textContent throughout: filenames and keys come from an API response,
   * and this file does not paste API responses into markup.
   */
  /**
   * The panel in place of Dodo's frame, in one of its two states.
   *
   * `settled` false is the wait, true is the completion. The frame goes away
   * either way: once there is nothing to pay, their step has nothing to draw,
   * and a customer should not be looking at it.
   */
  function showDone(root, settled) {
    var dodo = sdk();
    if (settled) {
      try { if (dodo) dodo.Checkout.close(); } catch (e) { /* already gone */ }
    }
    var frame = root.querySelector('.wpdc__frame');
    var done = root.querySelector('.wpdc__done');
    var wait = root.querySelector('.wpdc__done-wait');
    var ok = root.querySelector('.wpdc__done-ok');
    if (frame) frame.hidden = true;
    if (wait) wait.hidden = settled;
    if (ok) ok.hidden = !settled;
    if (done) done.hidden = false;
    return done;
  }

  function paintGoods(done, goods) {
    var box = done.querySelector('.wpdc__done-goods');
    if (!box || !goods) return;
    while (box.firstChild) box.removeChild(box.firstChild);

    var files = Array.isArray(goods.files) ? goods.files : [];
    var keys = Array.isArray(goods.keys) ? goods.keys : [];

    files.forEach(function (file) {
      if (!file || typeof file.url !== 'string' || file.url.indexOf('https://') !== 0) return;
      var a = document.createElement('a');
      a.className = 'wpdc__done-file';
      a.href = file.url;
      // The name is the filename for an uploaded file and the entitlement's
      // instructions line for a hosted link, which may be empty. Empty means
      // the label stands alone rather than trailing a colon into nothing.
      var name = typeof file.name === 'string' ? file.name.trim() : '';
      a.textContent = name ? (cfg.doneFiles || '') + ': ' + name : (cfg.doneFiles || '');
      // A download attribute is a polite request; Dodo's signed URL decides.
      a.setAttribute('download', '');
      a.rel = 'noopener';
      box.appendChild(a);
    });

    keys.forEach(function (key) {
      if (typeof key !== 'string' || !key) return;
      var label = document.createElement('p');
      label.className = 'wpdc__done-key-label';
      label.textContent = cfg.doneKey || '';
      var code = document.createElement('code');
      code.className = 'wpdc__done-key';
      code.textContent = key;
      box.appendChild(label);
      box.appendChild(code);
    });
  }

  function open(root, url) {
    if (openFrame(root, url)) return;

    // FALLBACK, not a mode. Reached only when the SDK is not on the page --
    // blocked, cached wrong, CDN down. A customer who has decided to buy must
    // not be stopped by our script loader, so they go to Dodo's own page, which
    // sells the same thing at the same price.
    window.location.assign(url);
  }

  // Escape and the backdrop are the dialog's own doing; this is the X, and the
  // bookkeeping that has to happen however it closes.
  document.addEventListener('click', function (event) {
    if (event.target.closest('.wpdc__close')) {
      closeFrame(sdk());
      return;
    }

    // Clicking the dark area beside the checkout.
    //
    // A native <dialog> does NOT close on a backdrop click -- the platform
    // gives you Escape and nothing else, and everything a customer has learned
    // from every other modal says the outside is a way out. Without this they
    // hunt for the X, and on a phone the X was the thing they could not find.
    //
    // The test is `event.target === dialog` rather than a hit against the
    // ::backdrop, which no selector can reach: a click inside the checkout
    // lands on an element WITHIN the dialog, so the dialog itself is only ever
    // the target when the click was outside its content box.
    if (event.target.tagName === 'DIALOG' && event.target.classList.contains('wpdc__dialog')) {
      closeFrame(sdk());
    }
  });
  document.addEventListener('close', function (event) {
    if (event.target.classList && event.target.classList.contains('wpdc__dialog')) {
      closeFrame(sdk());
    }
  }, true);

  /**
   * A discount code, applied by minting the session again.
   *
   * Dodo's checkout cannot be told about a code after the fact -- the code is
   * part of the session -- so applying one means a NEW session and a new frame,
   * and the old one has to be closed first. Two live sessions for one customer
   * is two carts.
   *
   * On failure the checkout that was already open is left alone, and the code
   * is forgotten so the next attempt is not poisoned by it. Somebody who
   * mistypes must not lose the checkout they had.
   */
  /**
   * Mint the session again and swap the frame under it.
   *
   * Applying a code and removing one are the same operation with a different
   * value, because the code is part of the SESSION -- Dodo's checkout cannot be
   * told about one after the fact, or told to forget one. So both go through
   * here, and the only difference is what `root.dataset.discount` holds when it
   * runs.
   */
  function remintWith(root, code, note, controls) {
    var previous = root.dataset.discount || '';
    if (code) {
      root.dataset.discount = code;
    } else {
      delete root.dataset.discount;
    }
    controls.forEach(function (el) { if (el) el.disabled = true; });
    note.classList.remove('is-error');
    note.textContent = cfg.busy;

    return requestUrl(root)
      .then(function (url) {
        var dodo = sdk();
        var frame = root.querySelector('.wpdc__frame');
        if (dodo) {
          try { dodo.Checkout.close(); } catch (e) { /* nothing open */ }
        }
        if (frame) {
          frame.classList.remove('is-ready');
          frame.classList.remove('is-payment');
          startLoading(root, frame, url);
        }
        if (dodo && frame) {
          dodo.Checkout.open({ checkoutUrl: url, elementId: frame.id });
        } else {
          window.location.assign(url);
        }
      })
      .catch(function (err) {
        // The checkout that was already open stays open, and the value goes
        // back: somebody who mistypes must not lose the checkout they had, and
        // a failed removal must not leave the code half-gone.
        if (previous) {
          root.dataset.discount = previous;
        } else {
          delete root.dataset.discount;
        }
        throw err;
      })
      .finally(function () {
        controls.forEach(function (el) { if (el) el.disabled = false; });
      });
  }

  // Removing the code. Same operation, empty value.
  document.addEventListener('click', function (event) {
    var clear = event.target.closest('.wpdc__discount-clear');
    if (!clear) return;

    var root = clear.closest('.wp-dodo-checkout');
    var form = clear.closest('.wpdc__discount');
    var input = form.querySelector('.wpdc__discount-input');
    var apply = form.querySelector('.wpdc__discount-apply');
    var note = form.querySelector('.wpdc__discount-note');

    remintWith(root, '', note, [clear, apply, input])
      .then(function () {
        input.readOnly = false;
        input.value = '';
        note.textContent = '';
        clear.hidden = true;
      })
      .catch(function (err) {
        note.textContent = err && err.uwpFromServer ? err.message : cfg.failed;
        note.classList.add('is-error');
      });
  });

  document.addEventListener('submit', function (event) {
    var form = event.target.closest('.wpdc__discount');
    if (!form) return;
    event.preventDefault();

    var root = form.closest('.wp-dodo-checkout');
    var input = form.querySelector('.wpdc__discount-input');
    var apply = form.querySelector('.wpdc__discount-apply');
    var note = form.querySelector('.wpdc__discount-note');
    if (!root || !input || !note) return;

    var code = input.value.trim();

    // Silence was the old behaviour, and on a button that reports nothing it is
    // indistinguishable from a broken button: the customer clicks, waits, and
    // concludes the code does not work. Both refusals say which one they are.
    if (!code) {
      fail(note, cfg.discountEmpty);
      input.focus();
      return;
    }
    // The same shape the server enforces, checked here so a typo answers
    // instantly instead of after a round trip -- and so a customer is told WHAT
    // is wrong rather than that something is.
    if (!/^[A-Za-z0-9_-]{1,64}$/.test(code)) {
      fail(note, cfg.discountShape);
      input.focus();
      input.select();
      return;
    }

    var clear = form.querySelector('.wpdc__discount-clear');

    remintWith(root, code, note, [apply, input, clear])
      .then(function () {
        note.textContent = cfg.discountApplied;
        input.readOnly = true;
        if (clear) clear.hidden = false;
      })
      .catch(function (err) {
        note.textContent = err && err.uwpFromServer ? err.message : cfg.failed;
        note.classList.add('is-error');
      });
  });

  document.addEventListener('click', function (event) {
    var button = event.target.closest('.wpdc__button');
    if (!button) return;

    // The block class as shortcode.php renders it. It was '.wpdc' here and
    // has never been rendered by anything, so every click on every buy button
    // was swallowed at this line: no request, no message, nothing in the
    // console. The whole chain behind it -- REST route, secret, session mint,
    // webhook, ledger -- was correct and unreachable.
    var root = button.closest('.wp-dodo-checkout');
    if (!root) return;

    event.preventDefault();
    button.disabled = true;
    say(root, cfg.busy);

    requestUrl(root)
      .then(function (url) {
        open(root, url);
      })
      .catch(function (err) {
        // err.message is only ever a server sentence (see above) or a browser
        // failure. Anything not recognised falls back to our own wording.
        say(root, err && err.uwpFromServer ? err.message : cfg.failed);
      })
      .finally(function () {
        // Re-enabled even on success: the modal can be dismissed, and a button
        // left disabled behind it is a customer who cannot buy.
        button.disabled = false;
      });
  });
})();
