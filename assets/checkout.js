/**
 * The button.
 *
 * It sends a product id and a checkbox state, and receives a URL. It never sees
 * a price and never a credential, and the id it sends is the same one Dodo puts
 * in its own public payment links -- so there is nothing here for a browser
 * extension or a compromised third-party script to take. Tampering with the id
 * gets a different LIVE product at that product's own price; the server refuses
 * anything Dodo does not currently list.
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
   * for window.DodoPayments, which the bundle never sets, so the overlay branch
   * was unreachable and every `display="overlay"` silently navigated instead.
   * It looked deliberate, because falling through to a navigation IS a real
   * branch with a comment explaining it. The overlay had never once opened.
   *
   * The older name is still accepted in case a future build exposes it, but a
   * miss is reported rather than swallowed: see openOverlay.
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

  function openOverlay(root, url) {
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

    dodo.Initialize({
      mode: environmentFor(url),
      displayType: 'overlay',
      // Required by the SDK contract, and absent here before. Without it a
      // failed or abandoned checkout produced nothing at all: no message for
      // the customer and no line for anyone debugging it.
      onEvent: function (event) {
        if (!event) return;
        if (event.event_type === 'checkout.error') {
          say(root, (event.data && event.data.message) || 'The checkout could not be opened.');
        }
      },
    });
    dodo.Checkout.open({ checkoutUrl: url });
    return true;
  }

  function open(root, url) {
    if (openOverlay(root, url)) return;

    // FALLBACK, not a mode. Reached only when the SDK is not on the page --
    // blocked, cached wrong, CDN down. A customer who has decided to buy must
    // not be stopped by our script loader, so they go to Dodo's own page.
    //
    // On Apple Pay the two sources disagree and this is worth stating where
    // somebody will read it: Dodo's Overlay Checkout page says "Apple Pay is not
    // yet supported in overlay checkout", while its Digital Wallets page lists
    // overlay among the surfaces where all wallets are fully supported. If that
    // first sentence is the true one, this fallback is also the only path on
    // which Apple Pay appears -- which makes it worth keeping working rather
    // than deleting.
    window.location.assign(url);
  }

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
        // Re-enabled even on success: an overlay can be dismissed, and a
        // button left disabled behind it is a customer who cannot buy.
        button.disabled = false;
      });
  });
})();
