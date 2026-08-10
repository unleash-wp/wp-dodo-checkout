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

  /**
   * The window is moved to <body>, and the reason is measured rather than
   * defensive.
   *
   * On the real sales page the checkout renders inside a WPBakery column that
   * carries `transform: matrix(1, 0, 0, 1, ...)`. A transform does two things
   * at once: it creates a stacking context, so our `z-index: 99000` only
   * competes INSIDE that column and a pricing card in the next one paints over
   * the checkout; and it becomes the containing block for `position: fixed`,
   * so the window centres itself in the column instead of the viewport.
   * Photographed on /buecher/wordpress-band-1/: the checkout half-covered by a
   * product card, sitting left of centre.
   *
   * There is no z-index that escapes a transformed ancestor. Leaving the tree
   * is the only fix -- and the top layer, which would also escape it, is the
   * one place Apple Pay cannot be reached from.
   *
   * TIMING IS THE WHOLE TRICK: this runs at startup, while the dialog is still
   * empty. Re-parenting an element RELOADS any iframe inside it, so doing this
   * later would tear down a live Dodo session mid-payment. The SDK mounts into
   * a dialog that is already where it will stay.
   *
   * The class travels with it because the armoured rules key on both classes
   * now; see the note at the top of checkout.css.
   */
  /**
   * The BLOCK an element belongs to -- never the window.
   *
   * The lift gave the dialog the `wp-dodo-checkout` class so the armoured CSS
   * would still reach it. That class is also how every handlerfinds its block, so
   * `closest()` inside the window now answered with the WINDOW: a discount code
   * typed into the panel resolved to an element carrying no `data-product`, and
   * the session mint came back `400 missing parameter: product`.
   *
   * One class, two jobs. This is the seam between them: if what we landed on is
   * the dialog, follow `data-wpdc-owner` back to the block that owns it.
   */
  function rootFor(el) {
    var hit = el && el.closest ? el.closest('.wp-dodo-checkout') : null;
    if (!hit) return null;
    if (!hit.classList.contains('wpdc__dialog')) return hit;
    var owner = hit.dataset.wpdcOwner;
    return owner ? document.getElementById(owner) : null;
  }

  /** The dialog belonging to a block, wherever it now lives. */
  function dialogFor(root) {
    if (root && root.id) {
      var lifted = document.querySelector(
        '.wpdc__dialog[data-wpdc-owner="' + root.id + '"]',
      );
      if (lifted) return lifted;
    }
    // Before the lift, and for any block without an id.
    return root ? root.querySelector('.wpdc__dialog') : null;
  }

  /**
   * A part of this checkout, wherever it lives.
   *
   * Since the lift, the block and its window are two separate subtrees: the
   * buy button, the message line and the bump checkbox stayed in the page,
   * while the frame, the panel, the totals and the completion moved to <body>
   * inside the dialog. A single `root.querySelector` sees only half of that.
   *
   * This cost a real regression: `openFrame` looked for `.wpdc__frame` in the
   * block, found nothing, returned false -- and the fallback that exists for a
   * missing SDK sent a paying customer to Dodo's hosted page instead of
   * opening the window. Looking in both places is what makes the split
   * invisible to every caller.
   */
  function part(root, selector) {
    if (!root) return null;
    var dialog = dialogFor(root);
    return (dialog && dialog.querySelector(selector)) || root.querySelector(selector);
  }

  /**
   * A trigger stops being an anchor.
   *
   * `preventDefault` was not enough and the page kept jumping to the top:
   * Impreza does not rely on the default at all, it matches `a[href^="#"]` and
   * ANIMATES the scroll itself. A cancelled default cannot cancel somebody
   * else's animation.
   *
   * So the hash comes off. With no `href` the theme's selector no longer
   * matches, there is no default left to prevent, and nothing to animate
   * towards. `role` and `tabindex` put back what removing the href takes away:
   * it still announces itself as a control and is still reachable by keyboard.
   */
  function disarmTriggers() {
    var triggers = document.querySelectorAll('a[data-wpdc-open]');
    for (var i = 0; i < triggers.length; i += 1) {
      var a = triggers[i];
      var href = a.getAttribute('href') || '';
      if (href !== '' && href.charAt(0) !== '#') continue;
      a.removeAttribute('href');
      if (!a.getAttribute('role')) a.setAttribute('role', 'button');
      if (!a.hasAttribute('tabindex')) a.setAttribute('tabindex', '0');
    }
  }

  var veil = null;

  function showVeil(on) {
    if (veil) veil.hidden = !on;
  }

  function liftDialogs() {
    var dialogs = document.querySelectorAll('.wp-dodo-checkout .wpdc__dialog');
    for (var i = 0; i < dialogs.length; i += 1) {
      var dialog = dialogs[i];
      if (dialog.parentElement === document.body) continue;
      dialog.classList.add('wp-dodo-checkout');
      // The tie back to the block it belongs to. Once it leaves the tree,
      // `root.querySelector` cannot reach it, and a page may carry several
      // blocks -- so the pairing has to be written down rather than inferred
      // from position.
      var owner = dialog.closest('.wp-dodo-checkout[id]');
      if (owner) dialog.dataset.wpdcOwner = owner.id;
      document.body.appendChild(dialog);

      // The backdrop, as a sibling. See the note in checkout.css: as a child of
      // the dialog it painted over the dialog's own background, and on a phone
      // the page showed through the checkout.
      if (!veil) {
        veil = document.createElement('div');
        veil.className = 'wpdc__veil';
        veil.hidden = true;
        document.body.insertBefore(veil, dialog);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      liftDialogs();
      disarmTriggers();
    });
  } else {
    liftDialogs();
    disarmTriggers();
  }

  function say(root, text) {
    var el = part(root, '.wpdc__message');
    if (el) el.textContent = text;
  }

  function requestUrl(root) {
    var bump = part(root, '.wpdc__bump-input');
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
        err.wpdcFromServer = typeof data?.message === 'string';
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
          var scroll = part(root, '.wpdc__scroll');
          var frame = part(root, '.wpdc__frame');
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
          // The whole event, not the sentence we show.
          //
          // What reaches the customer is `event.data.message`, and on Safari
          // that has been the string "Load failed" -- WebKit's generic wording
          // for a fetch that did not complete. It names the symptom and hides
          // every field that says which request, from which frame, and why.
          // Without this line the only thing anybody could send Dodo was that
          // label, which cost two rounds of mail and told them nothing.
          //
          // `console.error`, not a silent collector: the person who needs it is
          // whoever has the failing phone in their hand, and the only tool they
          // have is the inspector.
          if (window.console && console.error) {
            console.error('[wpdc] checkout.error', event);
          }
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
   * Between opening the dialog and Dodo rendering into the container there are a few
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
    var frame = part(root, '.wpdc__frame');
    if (!frame) return;
    // `is-ready` only on the transition OUT of waiting, so a frame that reflows
    // later -- and Dodo's reflows on every address change -- does not replay
    // the entrance under a customer who is mid-typing.
    var wasWaiting = frame.classList.contains('is-loading');
    frame.classList.remove('is-loading');
    if (wasWaiting) frame.classList.add('is-ready');
  }

  function paintTotals(root, b) {
    var totals = part(root, '.wpdc__totals');
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
    el.textContent = percent(rate);
  }

  /**
   * A percentage in brackets, the way both lines that show one want it.
   *
   * One decimal at most, and none when it is whole: "7 %" reads as a rate,
   * "7,0 %" reads as a calculation. This was written out twice, identically,
   * which is two places to get the comma wrong.
   */
  function percent(value) {
    var rounded = Math.round(value * 10) / 10;
    var shown = Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1).replace('.', ',');
    return ' (' + shown + ' %)';
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
    el.textContent = percent(off);
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

    var dialog = dialogFor(root);
    var frame = part(root, '.wpdc__frame');
    if (!dialog || !frame || !frame.id || !dialog.show) return false;

    // One checkout at a time. The veil enforces it -- nothing behind it is
    // clickable -- but a second block's dialog left open underneath would
    // still be a second cart.
    if (openRoot && openRoot !== root) closeFrame(dodo);

    // A second purchase starts from the start.
    //
    // `showDone` hides the frame, reveals the completion and disables the
    // discount controls, and nothing ever put any of that back. So a customer
    // who bought, closed the dialog and clicked Buy again got their previous
    // order's completion panel over a frame that stayed hidden -- the SDK was
    // rendering into a box nobody could see. Opening is the one moment where
    // "this is a fresh checkout" is unambiguously true, so the reset belongs
    // here rather than spread across the three exits.
    var done = part(root, '.wpdc__done');
    if (done) done.hidden = true;
    frame.hidden = false;
    var panel = part(root, '.wpdc__panel');
    if (panel) {
      panel.querySelectorAll('input, button, select').forEach(function (el) {
        el.disabled = false;
      });
    }

    // Shown BEFORE the SDK is told to render: a dialog that is not open has no
    // layout, and an iframe measured inside a zero-height box comes back zero.
    //
    // `show()`, never `showModal()`. See the note above the Escape handler --
    // the top layer is the one place Apple Pay cannot be reached from.
    dialog.show();
    showVeil(true);

    // What showModal() used to do for us. A checkout that opens without moving
    // focus leaves a keyboard or screen-reader customer still standing on the
    // Buy button, tabbing through the shop behind a window they cannot see.
    var closer = dialog.querySelector('.wpdc__close');
    if (closer) closer.focus();
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
    // The same reasoning, applied to the thing it was never applied to. The
    // loading deadline was cancelled here from the start; the completion POLL
    // was not, so a customer who paid and then dismissed the dialog kept a
    // timer chain running for up to a minute -- and on success `leave()` calls
    // location.assign() and navigates somebody who is now reading something
    // else on the page. Bumping the generation makes every reply from the old
    // wait a no-op.
    root.dataset.pollGen = String((parseInt(root.dataset.pollGen || '0', 10) || 0) + 1);
    delete root.dataset.awaiting;
    var dialog = dialogFor(root);
    if (dialog && dialog.open) dialog.close();
    // Unconditional, and not inside the `open` check above: this runs on every
    // close path, and a backdrop left behind is a black sheet over the shop
    // that nothing can dismiss.
    showVeil(false);
    try { if (dodo) dodo.Checkout.close(); } catch (e) { /* already closed */ }
    var button = part(root, '.wpdc__button');
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
   * Two speeds, because one loop serves two different waits.
   *
   * Most orders confirm within seconds, so the first stretch stays fast and the
   * tick appears almost at once. A card payment does not behave that way: 3-D
   * Secure sends the buyer to their bank's app, and approving there routinely
   * takes longer than a minute.
   *
   * THE DEFECT THIS REPLACES: a single phase that gave up at sixty seconds flat
   * and told somebody who had just paid that we could not confirm their order.
   * The worst sentence this screen can say, on the most ordinary payment there
   * is.
   *
   * It survived because it had never once run. Every successful purchase on
   * this shop so far went through a hundred-percent discount code, and a
   * zero-total order has no payment step to be slow -- so the branch that
   * breaks on a real card is the one branch no test purchase ever entered.
   *
   * Five minutes in total. Past that, the mail Dodo sends serves a customer
   * better than a page still asking.
   *
   * POLL_TRIES is bounded by the server's per-session ceiling, and the two are
   * checked against each other in tests/run.php. Raising this one alone would
   * throttle a paying customer into the give-up path -- which is precisely the
   * failure that ceiling exists to avoid causing.
   */
  var POLL_FAST_MS = 2000;
  var POLL_FAST_TRIES = 30;
  var POLL_SLOW_MS = 5000;
  var POLL_TRIES = 78;

  function awaitCompletion(root) {
    var session = root.dataset.session || '';
    var tries = 0;
    // The wait this call owns. `closeFrame` bumps the counter on the element,
    // so a reply that arrives after the customer closed the dialog -- or after
    // they re-minted and started a second checkout -- finds itself stale and
    // stops, instead of navigating a page somebody is now reading.
    var gen = root.dataset.pollGen || '0';
    var stale = function () { return (root.dataset.pollGen || '0') !== gen; };

    /** Every exit runs through here, so the flag cannot survive one of them. */
    function release() {
      if (root.dataset.awaiting === '1') delete root.dataset.awaiting;
    }

    // Reached ONLY on a confirmed success. A configured thank-you page wins;
    // without one the completion is shown right here, because leaving for the
    // front page reads as the popup breaking -- the purchase would end on a
    // page that says nothing about it.
    function leave(where, goods) {
      release();
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
      release();
      settleLoading(root);
      // The panel FIRST, and this is the fix rather than an ordering detail.
      //
      // This used to reach straight for `.wpdc__done-wait`, which only exists
      // once `showDone` has built the panel. The zero-total path calls that on
      // its way in; the paying path never did. So for every paying customer
      // there was no node to write into, and the fallback -- `say()` -- writes
      // to `.wpdc__message`, which sits OUTSIDE the <dialog> -- so that
      // sentence rendered behind the veil, on a page the customer cannot see
      // past. (Then it was the modal's backdrop; it is the dialog's own
      // `::before` now, and the sentence is just as invisible either way.)
      //
      // Net effect: a paying customer whose poll ran out saw nothing change at
      // all. They sat on the payment step with no word either way -- exactly
      // the state the "never report an order we could not confirm" work set
      // out to end, fixed for one branch and not the other.
      showDone(root, false);
      var wait = part(root, '.wpdc__done-wait');
      if (wait) {
        var spinner = wait.querySelector('.wpdc__done-spinner');
        if (spinner) spinner.remove();
        var text = wait.querySelector('.wpdc__done-text');
        if (text) text.textContent = cfg.unconfirmed;
      }
      // Kept for the case where the panel is not there to be built. It is a
      // fallback now, not the message.
      say(root, cfg.unconfirmed);
    }

    // One wait per checkout. Two events can start one -- a zero-total cart on
    // `customer_details_submitted`, everybody else on `pay_button_clicked` --
    // and either can arrive twice when a customer goes back and forward. Two
    // polls would race to swap the panel underneath each other.
    //
    // Released on every exit, not only on the missing-session one. It used to
    // be set here and deleted in exactly one branch, so after a give-up or a
    // completion the flag stayed set for the life of the element: the customer
    // closed the dialog, bought again, and the second wait returned on this
    // line. No poll, no panel, no message.
    if (root.dataset.awaiting === '1') return;
    root.dataset.awaiting = '1';

    /**
     * Fast while a fast answer is plausible, then patient.
     *
     * One function rather than the constant repeated at both call sites: the
     * success path and the network-failure path each schedule the next ask, and
     * two copies of a schedule is one place to change it in and forget the
     * other.
     */
    function nextDelay() {
      return tries < POLL_FAST_TRIES ? POLL_FAST_MS : POLL_SLOW_MS;
    }

    function ask() {
      // Checked here too, not only on the reply: a timer that fires after the
      // dialog closed would otherwise still spend a request, and the ceiling
      // on that route is finite.
      if (stale()) return;
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
          if (stale()) return;
          if (data && data.finished) return leave(data.redirect, data);
          if (tries >= POLL_TRIES) return giveUp();
          setTimeout(ask, nextDelay());
        })
        .catch(function () {
          // A failed poll is not a failed order, and there is nothing a
          // customer could do about a single one. Keep asking until the tries
          // run out -- and then say so, rather than leaving somebody in front
          // of a sentence that never changes.
          if (stale()) return;
          if (tries < POLL_TRIES) {
            setTimeout(ask, nextDelay());
            return;
          }
          giveUp();
        });
    }

    if (!session) {
      release();
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
    var frame = part(root, '.wpdc__frame');
    var done = part(root, '.wpdc__done');
    var wait = part(root, '.wpdc__done-wait');
    var ok = part(root, '.wpdc__done-ok');
    if (frame) frame.hidden = true;
    if (wait) wait.hidden = settled;
    if (ok) ok.hidden = !settled;
    if (done) done.hidden = false;

    // The discount form outlives the frame, and it must not outlive the
    // checkout. Hiding the frame left the panel's Apply button live, so a
    // customer typing a code after paying would re-mint -- a SECOND cart,
    // opened into a frame that is now hidden. They would never see it and it
    // would be a real session at Dodo. Disabled once there is nothing left to
    // decide, in both states: during the wait the cart is already at Dodo.
    var panel = part(root, '.wpdc__panel');
    if (panel) {
      panel.querySelectorAll('input, button, select').forEach(function (el) {
        el.disabled = true;
      });
    }

    // And GONE, not merely dead.
    //
    // Disabled controls still read as controls: a finished order showed a
    // greyed "Rabattcode", an "Einlösen" and a "Code entfernen" beside the
    // licence key, which invites a customer to try the one thing that can no
    // longer work. There is nothing left to discount once the money has moved,
    // so the form is not disabled information -- it is noise on the one screen
    // that should say exactly one thing.
    //
    // The disabling above stays: it is what stops a re-mint in the seconds
    // BEFORE this runs, and it covers anything else the panel grows later.
    var discount = part(root, '.wpdc__discount');
    if (discount) discount.hidden = true;

    return done;
  }

  /**
   * Two icons, drawn as nodes rather than markup.
   *
   * No innerHTML anywhere in this file, and not from superstition: these end up
   * on a page that has just taken money, and a plugin that parses HTML strings
   * into a purchase confirmation is one careless edit away from parsing
   * something it was handed. Nodes cost six lines and close the question.
   */
  var SVG_NS = 'http://www.w3.org/2000/svg';
  var ICON_COPY = [
    { tag: 'rect', x: '9', y: '9', width: '13', height: '13', rx: '2' },
    { tag: 'path', d: 'M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1' },
  ];
  var ICON_TICK = [{ tag: 'polyline', points: '20 6 9 17 4 12' }];

  function icon(shapes) {
    var svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', '16');
    svg.setAttribute('height', '16');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    // Decorative: the button carries the label, so a screen reader that
    // announced the drawing as well would say the action twice.
    svg.setAttribute('aria-hidden', 'true');
    shapes.forEach(function (shape) {
      var node = document.createElementNS(SVG_NS, shape.tag);
      Object.keys(shape).forEach(function (name) {
        if (name !== 'tag') node.setAttribute(name, shape[name]);
      });
      svg.appendChild(node);
    });
    return svg;
  }

  function paintGoods(done, goods) {
    var box = done.querySelector('.wpdc__done-goods');
    if (!box || !goods) return;
    while (box.firstChild) box.removeChild(box.firstChild);

    var files = Array.isArray(goods.files) ? goods.files : [];
    var keys = Array.isArray(goods.keys) ? goods.keys : [];

    /**
     * The format, when there is more than one file and only then.
     *
     * The filename is deliberately never shown: `unleashwp-learn-band-1-v2.pdf`
     * is our bookkeeping, and a customer who has just paid wants a button, not
     * an inventory. But a product can deliver several files -- this shop sells
     * "the PDF plus eBook versions" -- and three buttons all reading "Download
     * now" is worse than a filename, because none of them says which is which.
     *
     * So: one file gets the plain call to action, several get the format
     * appended. An extension is not a filename; it is the one word that tells
     * somebody which button is theirs.
     */
    function fileLabel(file, many) {
      var cta = cfg.downloadCta || '';
      if (!many) return cta;
      var name = typeof file.name === 'string' ? file.name : '';
      var dot = name.lastIndexOf('.');
      var ext = dot > 0 ? name.slice(dot + 1).toUpperCase() : '';
      return ext ? cta + ' · ' + ext : cta;
    }

    var deliverable = files.filter(function (file) {
      return file && typeof file.url === 'string' && file.url.indexOf('https://') === 0;
    });

    /**
     * The key the hosted page needs, if there is one.
     *
     * Read before the files are rendered rather than after, because a hosted
     * link is not a file: it is the shop's download page, and without the key
     * it is an empty form.
     */
    var firstKey = keys.find(function (k) {
      return typeof k === 'string' && k !== '';
    });

    deliverable.forEach(function (file) {
      var a = document.createElement('a');
      a.className = 'wpdc__done-file';

      /*
       * THE defect this replaces, seen on a live order.
       *
       * Dodo delivers two shapes through the same field. An uploaded file
       * arrives as a SIGNED url -- personal, complete, clickable. A hosted
       * `external_url` is the opposite: every buyer gets the same static
       * address, so it proves nothing about who is asking. It is a page, and
       * that page asks for the licence key.
       *
       * The popup rendered it bare: `href="https://downloads.unleash-wp.com/"`.
       * Somebody who had just paid landed on an empty form and was asked to
       * type in a key the popup was displaying two lines below the button.
       *
       * After the '#', which is the one part of an address a browser never
       * sends to a server -- no access log, no proxy log, no Referer. The page
       * reads it and clears the address bar before doing anything else.
       */
      if (file.needs_key && firstKey) {
        a.href = file.url + (file.url.indexOf('#') === -1 ? '#' : '&') + 'k=' + encodeURIComponent(firstKey);
      } else {
        a.href = file.url;
        // A polite request, and only for something that IS a file. On a page it
        // would ask the browser to save the HTML.
        a.setAttribute('download', '');
      }

      a.textContent = fileLabel(file, deliverable.length > 1);
      a.rel = 'noopener';
      box.appendChild(a);
    });

    keys.forEach(function (key) {
      if (typeof key !== 'string' || !key) return;

      // The download, when the key IS the delivery.
      //
      // A product whose files live on the shop's own host has no link in the
      // grant -- the entitlement issues a key and nothing else, because one
      // static URL cannot be personal to a buyer. So the button is built here
      // from the two things this panel already has: the host, and the key.
      //
      // The key goes after the '#'. That is the one part of an address a
      // browser never sends to a server, so it reaches no access log, no proxy
      // log and no Referer; the download page reads it and clears the address
      // bar before doing anything else. A query parameter would be written
      // down by every hop.
      //
      // Only when Dodo delivered nothing itself. If it did, that link is the
      // buyer's file and a second button beside it would be two answers to one
      // question.
      if (cfg.downloadUrl && files.length === 0) {
        var get = document.createElement('a');
        get.className = 'wpdc__done-file';
        get.href = cfg.downloadUrl + '#k=' + encodeURIComponent(key);
        get.textContent = cfg.downloadCta || '';
        get.rel = 'noopener';
        box.appendChild(get);
      }

      var label = document.createElement('p');
      label.className = 'wpdc__done-key-label';
      label.textContent = cfg.doneKey || '';

      var code = document.createElement('code');
      code.className = 'wpdc__done-key';
      code.textContent = key;

      var copy = document.createElement('button');
      copy.type = 'button';
      copy.className = 'wpdc__done-copy';
      copy.title = cfg.copyKey || '';
      copy.setAttribute('aria-label', cfg.copyKey || '');
      copy.appendChild(icon(ICON_COPY));

      var said = document.createElement('p');
      said.className = 'wpdc__done-copied';
      said.setAttribute('role', 'status');
      said.setAttribute('aria-live', 'polite');

      /**
       * Say it, show a tick, and go back after a moment.
       *
       * The tick replaces the icon rather than sitting beside it: two icons on
       * one button is two affordances where there is one action.
       */
      var revert;
      function confirmCopied(message) {
        said.textContent = message;
        while (copy.firstChild) copy.removeChild(copy.firstChild);
        copy.appendChild(icon(ICON_TICK));
        clearTimeout(revert);
        revert = setTimeout(function () {
          said.textContent = '';
          while (copy.firstChild) copy.removeChild(copy.firstChild);
          copy.appendChild(icon(ICON_COPY));
        }, 2500);
      }

      /**
       * When the clipboard is not ours to write to.
       *
       * `navigator.clipboard` is absent outside a secure context and can be
       * refused by permissions policy even inside one. Losing the key is losing
       * the purchase, so the fallback is not an error message: the key is
       * selected, and the customer is told to press copy themselves. That works
       * everywhere and needs no permission.
       */
      function selectInstead() {
        try {
          var range = document.createRange();
          range.selectNodeContents(code);
          var selection = window.getSelection();
          selection.removeAllRanges();
          selection.addRange(range);
        } catch (e) { /* selection is a courtesy, not the message */ }
        said.textContent = cfg.copyManual || '';
      }

      copy.addEventListener('click', function () {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(key).then(
            function () { confirmCopied(cfg.copied || ''); },
            selectInstead,
          );
          return;
        }
        selectInstead();
      });

      var row = document.createElement('div');
      row.className = 'wpdc__done-key-row';
      row.appendChild(code);
      row.appendChild(copy);

      /*
       * Label, field and status as ONE block, not three siblings.
       *
       * As siblings they inherited the goods container's own spacing, and it
       * measured wrong in the obvious way: the label sat 26px from the field it
       * names and 18px from the download button above it. A label closer to a
       * different control than to its own is a label pointing at the wrong
       * thing. Grouping them lets the outer gap separate the two ANSWERS -- the
       * file and the key -- while the inner gap keeps each answer together.
       */
      var block = document.createElement('div');
      block.className = 'wpdc__done-key-block';
      block.appendChild(label);
      block.appendChild(row);
      block.appendChild(said);

      box.appendChild(block);
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
    // Two targets, because the dark area stopped being part of the dialog.
    //
    // It used to be a `::before` INSIDE the dialog, so a click on it reported
    // the dialog as its target and one test covered both. The veil is a
    // sibling now (it had to be -- as a child it painted over the dialog's own
    // background), and a click on it reports the veil. Testing only for the
    // dialog would have left the way out working on desktop, where the window
    // is narrow enough that clicks still land beside it, and dead on a phone,
    // where the veil covers everything the window does not.
    var hit = event.target;
    var outside =
      (hit.tagName === 'DIALOG' && hit.classList.contains('wpdc__dialog')) ||
      (hit.classList && hit.classList.contains('wpdc__veil'));
    if (outside) {
      closeFrame(sdk());
    }
  });
  // The labelled way out of the completion panel. Same handler as the corner X,
  // because there is one way to close this window and two things that ask for it.
  document.addEventListener('click', function (event) {
    var dismiss = event.target.closest && event.target.closest('.wpdc__done-dismiss');
    if (dismiss) closeFrame(sdk());
  });

  document.addEventListener('close', function (event) {
    if (event.target.classList && event.target.classList.contains('wpdc__dialog')) {
      closeFrame(sdk());
    }
  }, true);

  /**
   * Why this checkout is never a modal dialog, and what that costs.
   *
   * `showModal()` puts an element in the browser's TOP LAYER. That is not a
   * z-index -- it is a plane above the whole document -- and two things follow
   * that no stylesheet can undo: everything else paints underneath, whatever
   * number it picks, and the rest of the document becomes INERT.
   *
   * Apple Pay on the desktop opens its sheet as an ordinary element appended to
   * <body>, carrying `z-index: 99998`. Under a modal checkout it renders behind
   * ours and cannot be clicked -- reported here, in those words: "er war da nur
   * unter unserem popup".
   *
   * 0.7.2 tried to detect that sheet and step down to non-modal while it was
   * up. It watched `<apple-pay-modal>`, and Apple paints into a sibling div
   * while leaving that element `visibility: hidden`. The detection was correct
   * about a DOM that is not the one Apple ships -- and that is the flaw in the
   * approach rather than in the guess: it makes our checkout depend on the
   * private structure of somebody else's widget, which changes on their deploy
   * and not on ours.
   *
   * So we never take the top layer. `show()` leaves the dialog in the ordinary
   * stacking order at `z-index: 99000` -- above this theme, below Apple's
   * 99998 -- and a wallet sheet lands on top by arithmetic instead of by
   * detection. Nothing to observe, nothing to guess, nothing of Apple's to
   * break.
   *
   * WHAT IT COSTS, stated rather than discovered. `showModal()` gave three
   * things for free, and each is paid back by hand:
   *
   *   ::backdrop          -> a fixed `::before` on the dialog (checkout.css),
   *                          which also captures the clicks the backdrop used
   *                          to, so the shop behind stays unreachable
   *   focus on open       -> `closer.focus()` where the dialog is shown
   *   Escape closes       -> the listener below
   *
   * The one thing not paid back is inertness for assistive technology:
   * `aria-modal="true"` asks for it rather than enforcing it.
   */
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' && event.key !== 'Esc') return;
    // Not `openRoot`: the dialog is the thing Escape dismisses, and asking the
    // DOM which one is open cannot disagree with what the customer sees.
    if (document.querySelector('.wpdc__dialog[open]')) closeFrame(sdk());
  });

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
    // A re-mint is a different checkout, so any wait belonging to the old one
    // stops here. Without this a reply about the abandoned session could show
    // its completion over the new cart the customer is still deciding on.
    root.dataset.pollGen = String((parseInt(root.dataset.pollGen || '0', 10) || 0) + 1);
    delete root.dataset.awaiting;
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
        var frame = part(root, '.wpdc__frame');
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

    var root = rootFor(clear);
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
        fail(note, err && err.wpdcFromServer ? err.message : cfg.failed);
      });
  });

  document.addEventListener('submit', function (event) {
    var form = event.target.closest('.wpdc__discount');
    if (!form) return;
    event.preventDefault();

    var root = rootFor(form);
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
        fail(note, err && err.wpdcFromServer ? err.message : cfg.failed);
      });
  });

  /**
   * Anything on the page can open the checkout: a cover image, a price, a
   * second button further down. `data-wpdc-open` is the whole contract.
   *
   *   <a href="#" data-wpdc-open="THE-PRODUCT-ID">  names the product
   *   <a href="#" data-wpdc-open>                 inside a block, or the only one
   *
   * WHY THE PRODUCT ID AND NOT THE BLOCK ID: block ids are running numbers in
   * render order, so moving the printed edition above the eBook in the page
   * builder silently repoints every trigger. The customer clicks an eBook
   * cover and gets the 34,99 checkout, and nobody notices while rearranging a
   * page. The product id describes WHAT is bought rather than WHERE it sits.
   *
   * And the rule that matters most: when it cannot be decided, NOTHING opens.
   * Guessing means somebody pays for the wrong book -- an image that does not
   * react is a fault you can see, one that opens the wrong order is not.
   */
  function blockForTrigger(trigger) {
    var inside = rootFor(trigger);
    if (inside) return inside;

    var blocks = document.querySelectorAll('.wp-dodo-checkout');
    var wanted = (trigger.getAttribute('data-wpdc-open') || '').trim();

    if (wanted !== '') {
      for (var i = 0; i < blocks.length; i += 1) {
        if (blocks[i].dataset.product === wanted) return blocks[i];
      }
      console.warn('wp-dodo-checkout: no block on this page sells ' + wanted);
      return null;
    }

    if (blocks.length === 1) return blocks[0];

    console.warn(
      'wp-dodo-checkout: several products on this page -- put the product id in ' +
        'data-wpdc-open, or this trigger cannot know which one you mean',
    );
    return null;
  }

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    var trigger = event.target.closest && event.target.closest('[data-wpdc-open]');
    if (!trigger) return;
    // What a real button gives for free, paid back: an element that says
    // role="button" has to answer the two keys a button answers.
    event.preventDefault();
    trigger.click();
  });

  document.addEventListener(
    'click',
    function (event) {
      var trigger = event.target.closest('[data-wpdc-open]');
      if (!trigger) return;

      /*
       * Stopped here, first thing, and in the CAPTURE phase.
       *
       * These triggers are ordinary links, usually `href="#"`, and two things
       * act on that before anything of ours would: the browser jumps to the
       * top of the document, and Impreza brings its own smooth-scroll for
       * anchors. Preventing the default further down -- after resolving the
       * block, as this first did -- was already too late, and the page shot to
       * the top the moment a cover image was clicked.
       *
       * Unconditional, before we know whether a block can be resolved: a `#`
       * should never scroll anywhere, and a trigger that cannot decide which
       * product it means should do nothing at all rather than nothing plus a
       * jump.
       */
      event.preventDefault();
      // And nobody downstream gets to act on this click either. The theme's
      // anchor handling is a jQuery listener that scrolls on its own; stopping
      // the default never reached it.
      event.stopImmediatePropagation();

      var root = blockForTrigger(trigger);
      if (!root) return;

      // The block's own buy button, clicked. Not a second copy of the purchase
      // path: one way in means a trigger can never drift away from the button
      // that has actually been proven to work.
      var button = part(root, '.wpdc__button');
      if (!button || button.disabled) return;

      button.click();
    },
    true,
  );

  document.addEventListener('click', function (event) {
    var button = event.target.closest('.wpdc__button');
    if (!button) return;

    // The block class as shortcode.php renders it. It was '.wpdc' here and
    // has never been rendered by anything, so every click on every buy button
    // was swallowed at this line: no request, no message, nothing in the
    // console. The whole chain behind it -- REST route, secret, session mint,
    // webhook, ledger -- was correct and unreachable.
    var root = rootFor(button);
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
        say(root, err && err.wpdcFromServer ? err.message : cfg.failed);
      })
      .finally(function () {
        // Re-enabled even on success: the modal can be dismissed, and a button
        // left disabled behind it is a customer who cannot buy.
        button.disabled = false;
      });
  });
})();
