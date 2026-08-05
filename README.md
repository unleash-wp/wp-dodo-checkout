# WP Dodo Checkout

A WordPress shortcode that opens a Dodo Payments checkout. The payment API key
never reaches the site.

## What it does and does not hold

It renders a button and asks one endpoint for a checkout URL. It holds no
payment credential, knows no prices, and has no list of products: a page names
a **plan key** (`pro`, `ebook`) and the server decides what that maps to and
what it costs.

That split is the point. `DODO_API_KEY` is account-wide, and a WordPress
install runs whatever plugins its owner added — any one of which can read
`wp-config.php` and the options table. So the key stays on the licence server,
this plugin holds a shared secret that can do exactly one thing, and prices
live where a browser cannot reach them.

Nothing here is specific to UnleashWP. No product ids, no prices, no product
copy. Two constants point it at a different site.

## Setup

```php
// wp-config.php — keeps both out of the database, which travels in backups,
// staging copies and support exports.
define( 'WPDC_ENDPOINT', 'https://mcp.unleash-wp.com' );
define( 'WPDC_SECRET', '…' );
```

Both fall back to options (`wpdc_endpoint`, `wpdc_secret`) if
the constants are absent. The constant wins.

The server needs the matching `LUMO_CHECKOUT_SECRET`.

A `plan` is not a Dodo product id and never travels as one: the server holds
that mapping, or anybody who obtained the secret could mint a checkout for any
product in the account at any price. The plan key comes from the product's own
`lumo_plan` metadata in Dodo, so making something buyable from a page is one
field on the product, with no server configuration and no deploy.

## Usage

```
[wpdc_checkout plan="pro"]
[wpdc_checkout plan="team" quantity="20" label="Get Team 20"]
[wpdc_checkout plan="pro" bump="ebook" bump_label="Add the eBook for 9 EUR"]
[wpdc_checkout plan="pro" display="overlay"]
```

| Attribute | Default | Notes |
|---|---|---|
| `plan` | — | Required. Lowercase, digits, underscores. |
| `display` | `inline` | `inline` navigates to Dodo; `overlay` opens their SDK. |
| `bump` | — | A second plan key. Always one copy, never one per seat. |
| `bump_label` | generic | What the checkbox says. Write the price here if you want one shown. |
| `label` | Buy now | Button text. |
| `quantity` | `1` | Seats, 1–50. |

### Why inline is the default

Dodo's documentation states Apple Pay is **not available for overlay
checkout**. On mobile that is the difference between a two-tap purchase and a
form. Inline also navigates to Dodo's own page, which keeps card fields off
this origin.

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

- **No prices, product ids or amounts.** All server-side.
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
which is enough to exercise the two things worth exercising: the failure
vocabulary, and what does and does not reach the outbound request.
