# WP Dodo Checkout

A WordPress shortcode that opens a Dodo Payments checkout, inline or as an
overlay, with an optional order bump.

## What it is

It renders a button and asks Dodo for a checkout URL. A page names a Dodo
product id; Dodo owns the price, the tax, the receipt and the delivery. Nothing
here computes an amount and nothing here stores a customer.

Nothing is specific to any shop. No product ids, no prices, no product copy live
in this plugin, and a test asserts it: the products come from whichever Dodo
account the key belongs to.

## Setup

One key.

```php
// wp-config.php — keeps it out of the database, which travels in backups,
// staging copies and support exports.
define( 'WPDC_API_KEY', 'sk_live_…' );
define( 'WPDC_MODE', 'live_mode' ); // optional; test_mode is the default
```

Or paste it under **Settings > Dodo Checkout**. The constant wins, and when one
is set the field is disabled rather than pre-filled: a filled field would be
submitted on the next Save and copy the key into the database, which is the one
thing the constant exists to prevent.

**Dodo has no scoped API keys.** Owner, Editor and Viewer are dashboard *user*
roles, not key permissions, so this key can create payments, issue refunds and
read every customer on the account. That is the same trade any payment plugin
makes, and it is the reason for the wp-config route.

The mode defaults to `test_mode`. Defaulting to live would mean a half-finished
setup charges a real card.

## Usage

The settings screen lists every product the account can sell, each with its
shortcode ready to copy.

```
[wpdc_checkout product="pdt_…"]
[wpdc_checkout product="pdt_…" label="Jetzt kaufen"]
[wpdc_checkout product="pdt_…" quantity="20" label="Get Team 20"]
[wpdc_checkout product="pdt_…" bump="pdt_…" bump_label="Add the eBook for 9 EUR"]
```

| Attribute | Default | Notes |
|---|---|---|
| `product` | — | Required. A Dodo product id (`pdt_…`). |
| `label` | Buy now | Button text. |
| `bump` | — | A second product id. Always one copy, never one per seat. |
| `bump_label` | generic | What the checkbox says. Write the price here if you want one shown. |
| `quantity` | `1` | 1 to 50. |

The button is styled through custom properties, so a theme restyles it without
touching this plugin:

```css
.wpdc__button { --wpdc-bg: #fcbe00; --wpdc-fg: #203159; --wpdc-radius: 8px; }
```

### Why the id and not a nickname

An earlier version required each product to carry a `uwp_plan` metadata key and
the shortcode named that key. It bought a shortcode that survives a product
being recreated under a new id, and a friendlier name in the editor. It cost a
step in the dashboard per product and a layer of indirection that read as a
mistake every time somebody looked at it. The id is not a secret either way:
Dodo's own static payment link is `checkout.dodopayments.com/buy/<product id>`.

### What stops a crafted request selling anything

The REST route is public, because buying does not require an account. So the id
in a request is not trusted on its own: **Dodo's live product list is the
allow-list**, cached ten minutes. An archived, deleted or never-listed id is
refused before any checkout session exists.

It does not stop a request naming a different *live* product than the page
shows. They still pay that product's listed price for that product, so the
exposure is "a live product can be bought". The case where that matters is a
cheap test product left live, which anybody who finds its id can buy. Archive
test products.

Archiving in Dodo is also the off switch: nothing on the site needs changing.

## The checkout is embedded, and why that is the conversion answer

Clicking the button does not navigate anywhere. Dodo renders its checkout in a
frame inside the page, and the wallet buttons sit above the form: a customer
with Apple Pay or Google Pay types nothing at all, because name, email and
address come from the wallet. The form is the path beside that one, not in front
of it.

Card fields still never touch this origin. The frame is an iframe from Dodo's
origin, so the PCI surface is the same as a redirect: this page cannot read what
is typed into it, and neither can anything else running here.

### Inline rather than the overlay, and Apple Pay is the reason

Dodo's documentation contradicts itself:

| Page | Says |
|---|---|
| Overlay Checkout | "Apple Pay is not yet supported in overlay checkout." |
| Inline Checkout | "Apple Pay is not available for overlay checkout." |
| Digital Wallets | "All digital wallets are fully supported in: Overlay Checkout, …" |

Two specific pages against one general list, so the overlay is not a surface to
bet Apple Pay on. Inline supports it, at the cost of one dashboard step:
**register the domain under Settings > Payment Methods > Apple Pay > Manage
domains.** Apple's association file is already served by this plugin, from
`init`, at `/.well-known/apple-developer-merchantid-domain-association`.

Google Pay needs nothing beyond being enabled on the account.

### Payment methods are never restricted

`allowed_payment_method_types` is deliberately not sent. Dodo's own note: adding
a method there does not make it available, and if everything listed is
unavailable the session fails outright. Sending nothing is what leaves the wallet
buttons on. A test pins this, because a future well-meant allow-list is exactly
how express checkout disappears.

### Why no price is written in the shortcode

A price in a shortcode is a price in the page cache, in a browser and in a
translation file, and it is wrong the day it changes. The customer sees the
price on Dodo's checkout, which is the one place it cannot be stale.

## Apple Pay

Put Apple's domain association file in the plugin directory as
`apple-developer-merchantid-domain-association`. It is served from `init` at
`/.well-known/apple-developer-merchantid-domain-association`.

Not as a real file in the web root, deliberately: a dot-directory there is what
rewrite rules, security plugins and "block hidden files" server snippets reach
for first, and the failure is silent — Apple Pay simply never appears, which
reads as an unsupported device. Serving it from PHP also runs before canonical
redirects, and a 301 to a trailing slash fails Apple's check just as surely as
a 404.

## What is deliberately not here

- **No prices or amounts.** Dodo settles what is charged.
- **No product catalogue in the code.** It comes from the account, so a new
  product sells within ten minutes of being created, with no deploy.
- **No `@latest` from a CDN.** The overlay SDK is pinned to a major version.
  `@latest` lets a third party change what executes on a checkout page with no
  deploy here, and makes a subresource integrity hash impossible because the
  file behind the URL is allowed to change.
- **No build step.** No bundler, no `node_modules`, no compiled assets.

## Testing

```bash
php tests/run.php
```

No PHPUnit, no Composer, no WordPress bootstrap — the same convention as
`lumo-wp/tests/run.php`, for the same reason: a guard nobody can run is a guard
nobody runs. The handful of WordPress functions the client touches are stubbed,
which is enough to exercise the things worth exercising: the failure vocabulary,
the allow-list, and what does and does not reach the outbound request.
