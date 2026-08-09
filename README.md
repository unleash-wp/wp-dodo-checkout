# WP Dodo Checkout

A WordPress shortcode that opens a Dodo Payments checkout in a modal on your
own page, with the wallet buttons on top and an optional order bump.

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
| `label` | Buy now | Button text. Set it — the default is English. |
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

## The checkout is a modal on your page

Clicking the button does not navigate anywhere. The page stays exactly as it
was, dimmed behind a `<dialog>` this plugin renders, and Dodo's checkout lives
in a frame inside it. Escape, a click on the backdrop and the X all close it.

The wallet buttons sit above the form: a customer with Apple Pay or Google Pay
types nothing at all, because name, email and address come from the wallet. The
form is the path beside that one, not in front of it.

### Why the window is ours and the frame is Dodo's

Dodo's *overlay* and *inline* modes do not differ in how they look. They differ
in who owns the window — and Dodo's Overlay Checkout page says Apple Pay is not
supported in theirs. So the SDK runs in `inline` mode, injecting its iframe into
an element inside our own dialog. A popup, on the surface that carries Apple Pay.

A native `<dialog>` rather than a div with a z-index: `showModal()` brings the
focus trap, Escape, the backdrop and the top layer. Those are individually easy
and collectively where hand-rolled modals fail, on the one page a customer must
not get stuck.

Card fields still never touch this origin. The frame is an iframe from Dodo's
origin, so the PCI surface is the same as a redirect: this page cannot read what
is typed into it, and neither can anything else running here.

### Apple Pay, and what was actually measured

Dodo's documentation contradicts itself:

| Page | Says |
|---|---|
| Overlay Checkout | "Apple Pay is not yet supported in overlay checkout." |
| Inline Checkout | "Apple Pay is not available for overlay checkout." |
| Digital Wallets | "All digital wallets are fully supported in: Overlay Checkout, …" |

Two specific pages against one general list, so their overlay is not a surface
to bet Apple Pay on. What settles the technical half is the frame itself, read
out of a real browser:

```
iframe allow = "payment keyboard-map *"
```

The payment permission **is** delegated to Dodo's frame, so a wallet is not
blocked by permissions policy in an embed. Whether a wallet button *appears* is
then a dashboard matter:

- **Google Pay** — enable it on the account, nothing else.
- **Apple Pay** — register the domain under **Settings > Payment Methods > Apple
  Pay > Manage domains**. Apple's association file is already served by this
  plugin, from `init`, at
  `/.well-known/apple-developer-merchantid-domain-association`.

Without those two steps the customer sees the form and no wallets, whatever this
plugin sends.

### Three things in the browser console belong to Dodo

Recorded because somebody will meet them and wonder whose they are:

- The `allow` attribute is malformed — `"payment keyboard-map *"` where the
  syntax is `"payment *; keyboard-map *"` — hence a `Unrecognized origin:
  'keyboard-map'` warning.
- The SDK posts messages to its own origin instead of the parent window, so
  `checkout.breakdown` events do not arrive.
- Their fraud SDK asks for accelerometer and bluetooth, which the frame does not
  grant.

None of the three stops a purchase, and none originates here.

### The form is as short as Dodo lets it be

Every field before the pay button is a place to give up, so two are removed at
the source:

| | |
|---|---|
| `minimal_address: true` | Country, plus a postcode only where a tax authority needs one. Street, city and state are skipped. |
| `allow_phone_number_collection: false` | The phone field is gone. Dodo shows it by default and nothing here reads it. |

What is left is name, email and country. The address cannot go entirely: it is
there for VAT, not for a courier. **A shop selling physical goods would have to
turn `minimal_address` off** — there is nothing to ship here.

The "buy as a business" checkbox stays. A WordPress audience contains people who
need a VAT invoice, and it is one collapsed line.

### Two customers see two different checkouts

Measured in a browser, not read out of a document. The SDK injects **two**
elements into the container, in this order:

```
[ express wallet element ]   <- collapses to 0px when no wallet is available
[ checkout iframe        ]
```

So what a customer meets depends on their device:

| | What opens |
|---|---|
| **Has Apple Pay or Google Pay** | The wallet button, at the very top, above every field. Two taps, nothing typed. |
| **Has neither** | Contact step — name, email, country, ZIP — then **Continue to Payment**, then Card, Klarna, SEPA. |

An earlier version of this file said the wallet button sits *behind* the form.
That was wrong, and the way it was wrong is worth keeping: the wallet element was
measured at 0px in a headless browser with no wallet configured, and that was
read as "it is not there" rather than "this browser cannot offer one". The DOM
order settles it — the wallet element precedes the checkout iframe.

What remains true is narrower: **a customer with no wallet pays a step** that
checkouts leading with a combined method-and-fields screen do not charge. That
step is Dodo's, `minimal_address` and the phone flag already make it as short as
it goes, and no setting removes it.

### Dodo's checkout is left exactly as Dodo ships it

Operator decision, and it reversed a day of work: **nothing is built beside
their surface.** An order summary above the frame, a fold that hid the form
behind the express wallet, a description pulled from the product -- all removed.

The reason is not that it looked bad, though it did until it was fixed. It is
that every one of those pieces was a seam against somebody else's UI: matched to
their left inset, their spacing, their loading order, none of which is a
contract. Their next release moves one of them and the seam opens on a page
where money changes hands, silently, on a site nobody is watching that day.

What stays is the window and the plumbing:

| Kept | Why it is not "building beside" |
|---|---|
| The `<dialog>` | A container. It holds their frame, it does not imitate it. |
| Width, padding, scroll correction | Fixes for our own bugs, not additions to theirs. |
| `minimal_address`, no phone, discount code, saved cards | API flags. Dodo renders the result. |

Branding belongs on Dodo's **Design page**, which styles the checkout, the
customer portal and the storefront together, test and live apart. That is one
place instead of three, and it survives their updates because it is theirs.

### What is NOT done here, and where it belongs instead
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
- **No `@latest` from a CDN.** The checkout SDK is pinned to a major version.
  `@latest` lets a third party change what executes on a checkout page with no
  deploy here, and makes a subresource integrity hash impossible because the
  file behind the URL is allowed to change.
- **No build step.** No bundler, no `node_modules`, no compiled assets.

## Testing

```bash
php tests/run.php
```

No PHPUnit, no Composer, no WordPress bootstrap, for one reason: a guard
nobody can run is a guard nobody runs. The handful of WordPress functions the client touches are stubbed,
which is enough to exercise the things worth exercising: the failure vocabulary,
the allow-list, and what does and does not reach the outbound request.
