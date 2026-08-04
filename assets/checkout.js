/**
 * The button, in both modes.
 *
 * It sends a plan KEY and a checkbox state, and receives a URL. It never sees
 * a price, a product id or a payment credential, so there is nothing here for
 * a browser extension or a compromised third-party script to take, and nothing
 * to tamper with that would change what is charged.
 */
(function () {
  'use strict';

  var cfg = window.uwpCheckout || {};

  function say(root, text) {
    var el = root.querySelector('.uwp-checkout__message');
    if (el) el.textContent = text;
  }

  function requestUrl(root) {
    var bump = root.querySelector('.uwp-checkout__bump-input');
    var body = {
      plan: root.dataset.plan,
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

  function open(root, url) {
    if (root.dataset.display === 'overlay' && window.DodoPayments) {
      window.DodoPayments.Initialize({ mode: 'overlay', displayType: 'overlay' });
      window.DodoPayments.Checkout.open({ checkoutUrl: url });
      return;
    }
    // Inline is a navigation to Dodo's own page. That is what keeps card
    // fields off this origin, and it is the only mode where Apple Pay is
    // available at all.
    window.location.assign(url);
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('.uwp-checkout__button');
    if (!button) return;

    var root = button.closest('.uwp-checkout');
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
