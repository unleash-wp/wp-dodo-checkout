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
    var lang = root.dataset.lang || (navigator.language || '').slice(0, 2).toLowerCase();
    if (/^[a-z]{2}$/.test(lang)) body.lang = lang;
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
        if (res.ok && data.url) return data.url;
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
        if (event.event_type === 'checkout.error') {
          settleLoading(root);
          say(root, (event.data && event.data.message) || cfg.failed);
          return;
        }
        // Dodo's own numbers, arriving when the frame loads and again on every
        // address change that moves the tax. Never computed here: the tax
        // depends on a country the customer has not typed yet.
        if (event.event_type === 'checkout.breakdown') {
          paintTotals(root, (event.data && event.data.message) || {});
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
      row.querySelector('dd').textContent = money(
        value,
        key === 'total' ? totalCurrency : currency
      );
    });

    totals.hidden = !shown;
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
