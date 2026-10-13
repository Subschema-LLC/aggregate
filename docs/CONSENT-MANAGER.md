# Consent manager lite

Consent manager lite is the small built-in consent banner (CMP) that loads next to
the [tag manager](TAG-MANAGER.md). It asks visitors about enhanced analytics,
every consent category used by the website's enabled tags, and any categories
and [other tags](#categories-and-other-tags) you add. It remembers the choice in
the browser, passes it to the tracker and tag manager, and starts the scripts
your website marks once their category is allowed. It makes no network requests
of its own. Each registered website has its own settings and its own
hosted script:

```text
https://analytics.example.com/cmp-lite/sites/<site-id>/consent.js
```

It is one of three choices in **Setup**. The [standalone banner](../micro-consent-dropins/README.md)
is a separate, larger drop-in with its own settings, and you can also connect
[your own consent manager](TAG-MANAGER.md#install-and-connect-consent). Install
only one banner per page.

- [Configure it in the dashboard](#configure-it-in-the-dashboard)
- [YAML reference](#yaml-reference)
- [Categories and other tags](#categories-and-other-tags)
- [Wording](#wording)
- [Colors](#colors)
- [Layout](#layout)
- [Buttons](#buttons)
- [Consent behavior](#consent-behavior)
- [Regions and the close button](#regions-and-the-close-button)
- [Behavior and limits](#behavior-and-limits)

## Configure it in the dashboard

Open **Collection → Consent manager lite** and choose a website. The page has
seven parts, and a preview that updates as you edit:

- **Banner**: whether the built-in banner is enabled, the name shown in its
  title, and an optional link to your privacy notice.
- **Categories and other tags**: categories to ask about even when no tag uses
  them, and the scripts your website loads itself; see
  [Categories and other tags](#categories-and-other-tags).
- **Wording**: the title, description, additional paragraphs, category list
  heading and labels, button labels, privacy-link text, and the announcements
  screen readers hear after a choice.
- **Colors**: seven colors, checked for readable contrast as you edit and again
  when you save.
- **Layout and buttons**: a card in the corner or a bar across the bottom of the
  window, which buttons appear, in what order, and where the button that reopens
  the banner sits.
- **Consent behavior**: the consent type, what the close button does, the
  categories allowed by default in implicit mode, and Global Privacy Control;
  see [Consent behavior](#consent-behavior).
- **Regional consent rules**: optional regions, each with its own consent
  behavior and wording; see [Regions and the close button](#regions-and-the-close-button).

Fields start with their current wording. Text that matches the default is not
saved, so later improvements to the default wording still reach your banner;
clear a field to restore its default. If a save is rejected, nothing is written
and the page shows your entries once more with the error.

The page edits the `consent_manager` mapping in
`config/tag-manager/sites/<site-id>.yaml`, the same file the tag manager uses,
with the same validation as YAML edits. Saving the tag manager no longer changes
these settings, and saving this page leaves tags, the standalone banner and
unrelated YAML alone. Find site IDs with `php bin/console app:tag-manager:sites`.

The hosted script changes within five minutes for returning visitors, who may
reuse it that long ([browser caching](JS-BUILD.md#browser-caching)), and at once
for new ones. A copy you downloaded from
**Setup** to host yourself is a snapshot: download it again after saving.

## YAML reference

Every key is optional. Omitted keys use their defaults; the dashboard writes only
the keys you change.

```yaml
consent_manager:
  enabled: true
  name: Example shop
  privacy_policy_url: 'https://www.example.com/privacy'
  layout: bar
  categories: [functional]
  external_tags:
    - {id: meta-pixel, name: Meta Pixel, category: marketing}
    - {id: chat, name: Support chat, category: functional}
  text:
    title: 'Your privacy choices at {name}'
    description: 'We use optional cookies only with your permission.'
    details:
      - 'Anonymous, aggregate measurement continues if you reject. You can change your choice at any time.'
    categories:
      analytics: Statistics
      marketing: Advertising
    reject: Reject all
    accept: Accept all
    save: Save my choices
  theme:
    background: '#FFFFFF'
    text: '#1B1F24'
    accent: '#0B5CAD'
    button_background: '#0B5CAD'
    button_text: '#FFFFFF'
    button_border: '#0B5CAD'
  buttons:
    show: [reject, accept, save]
    reopen: bottom-right
```

| Key | Default and rules |
| --- | --- |
| `enabled` | `true`. A YAML boolean. Disabling it grants no consent; connect your own CMP instead. |
| `name` | The registered website's name. 1–120 UTF-8 bytes, no control characters. |
| `privacy_policy_url` | None. Empty or an absolute HTTPS URL without credentials. Adds a link to the banner. |
| `categories` | None. Up to 10 category names asked about even when no enabled tag uses them; see [Categories and other tags](#categories-and-other-tags). |
| `external_tags` | None. Up to 30 tags the website loads itself, each with an `id`, a `name` and a `category`; see [Categories and other tags](#categories-and-other-tags). |
| `layout` | `card`. `card` or `bar`; see [Layout](#layout). |
| `precheck_categories` | `[]`. A list of category names (e.g. `[analytics]`) whose checkboxes start selected for new visitors. In opt-in jurisdictions like the EU/UK under GDPR, keep optional categories unselected. |
| `text` | Default wording; see [Wording](#wording). |
| `theme` | Default colors; see [Colors](#colors). |
| `buttons` | `show: [reject, accept, save]`, `reopen: bottom-left`; see [Buttons](#buttons). |
| `consent_type` | `explicit_eu`. `explicit_eu`, `explicit_us` or `implicit_us`; see [Consent behavior](#consent-behavior). |
| `close_button` | Follows the consent type. `reject`, `dismiss` or `hidden`. |
| `default_granted` | `[analytics]`. Categories allowed before a choice, in `implicit_us` only. |
| `respect_gpc` | `true`. A YAML boolean. When true, Global Privacy Control withholds `default_granted`. |
| `regions` | None. Up to 10 regions; see [Regions and the close button](#regions-and-the-close-button). |
| `default_region` | The region marked `default: true`, otherwise the first. The region shown to visitors who have not picked one. |

Within `text`, `theme` and `buttons`, each key you supply replaces its default.
An invalid value fails closed like the rest of the site's script settings: the
dashboard reports it, and the website's scripts are not served until it is
corrected. There are no environment-variable overrides; the
[environment precedence](TAG-MANAGER.md#yaml-configuration) of site files applies.

## Categories and other tags

The banner asks about `analytics` (enhanced analytics), then the categories in
`categories`, those of your `external_tags`, and those your enabled
[tag manager](TAG-MANAGER.md) tags use. Category names have 1–32 lowercase
letters, digits, underscores or hyphens, beginning with a letter; `none` and
`gpc` are reserved. Label each under [Wording](#wording) (`text.categories`).

`external_tags` lists scripts your website loads itself rather than through the
tag manager: an advertising pixel in your theme, a chat widget, a script a
plugin adds, or a tag you manage in Google Tag Manager. Each has an `id` (1–64
letters, digits, underscores or hyphens, beginning with a letter), the `name`
visitors see, and the `category` that must be allowed before it runs. The
banner names each tag under its category in the category list, using the
`category_tags` wording, and a category it does not already ask about is added.
The list appears with the category checkboxes, so it is not shown when the
banner has no **Save** button.

To hold a script back until its category is allowed, change its `type` to
`text/plain` and add the tag's ID:

```html
<script type="text/plain" data-consent-tag="meta-pixel" src="https://connect.facebook.net/en_US/fbevents.js"></script>
<script type="text/plain" data-consent-tag="meta-pixel" nonce="YOUR_NONCE">fbq('init', '123');</script>
```

The browser does not run a `text/plain` script. Once the visitor allows the
category, or an `implicit_us` default grant allows it, the banner replaces each
waiting script with a real one:

- in page order, keeping its attributes and its nonce, so a strict Content
  Security Policy still applies;
- a script with `src` and no `async` attribute finishes loading (or fails)
  before the next one starts, so a loader runs before the code that uses it;
- once per page: each script runs at most once;
- only for IDs listed in `external_tags`; a script with any other ID never runs.

Withdrawal stops scripts that have not run yet. It cannot stop one that already
ran: that keeps running until the visitor reloads the page, and its cookies stay
until the provider or your site removes them. The banner holds back only
`script` elements you mark. Embedded frames such as videos and maps, and scripts
other code adds later, are not covered.

### Google Tag Manager

If the page already has a `window.dataLayer` array, as Google Tag Manager
creates, the banner pushes the visitor's choices to it when the page loads and
whenever they change:

```javascript
{event: 'aggregate_consent_update', aggregate_consent: {analytics: true, functional: false, marketing: false}}
```

In GTM, fire a tag on a Custom Event trigger for `aggregate_consent_update` with
a condition on a Data Layer Variable such as `aggregate_consent.marketing`, and
list the tag in `external_tags` so visitors see it. An unchanged state is not
pushed again. No `dataLayer` is created when the page has none, so load GTM's
snippet before the banner. This is an event, not Google Consent Mode; see
[Behavior and limits](#behavior-and-limits).

## Wording

All text is plain text: HTML is shown as typed, never interpreted. Labels allow
up to 120 UTF-8 bytes, paragraphs up to 1,000, with no line breaks or control
characters.

| Key | Default |
| --- | --- |
| `title` | `{name}: privacy choices` (`{name}` is replaced with `name`) |
| `description` | Choose which optional categories to allow. Enhanced analytics uses browser identifiers and additional event details. Tags in each selected category may load third-party scripts. |
| `details` | Two paragraphs on anonymous measurement after rejection, withdrawal, and reloading. A list of up to four paragraphs; `[]` shows none. |
| `categories_legend` | Optional categories |
| `categories` | `analytics: Enhanced analytics`; other categories show their name, capitalized. Maps category names to labels. |
| `category_tags` | Includes: {tags} (the line under a category naming its [other tags](#categories-and-other-tags); must contain `{tags}`) |
| `reject` | Reject all optional categories |
| `accept` | Accept all optional categories |
| `save` | Save selected choices |
| `reopen` | Privacy choices |
| `dismiss` | Close this notice (the close button’s label when it closes without a choice) |
| `dismiss_reject` | Reject all optional categories and close (its label when it rejects) |
| `privacy_link` | Read this website’s privacy notice |
| `status_applied` | Your privacy choices have been applied. |
| `status_not_saved` | This choice could not be saved; choose again on your next visit. |
| `status_other_tab` | Your privacy choices were updated in another tab. |

The three `status_*` messages are announced to screen readers, not shown. The
close button shows only ×; `dismiss` and `dismiss_reject` are its accessible
name and tooltip, so say what closing does. The
default paragraphs describe what Aggregate actually does: anonymous measurement
can continue after rejection, and withdrawal stops future enhanced detail but
does not erase history or stop scripts already running. If you replace them,
keep your wording accurate for your website and its tags.

## Colors

Colors are `#RRGGBB` or `#RGB` hex values; quote them in YAML. They are applied
as CSS custom properties, so no other CSS can be supplied.

| Key | Default | Used for |
| --- | --- | --- |
| `background` | `#FFFFFF` | Banner background |
| `text` | `#202124` | Headings and paragraphs |
| `accent` | `#2459B8` | Links, focus outlines and checkboxes |
| `border` | `#202124` | Banner and category-list borders |
| `button_background` | `#FFFFFF` | Every action button and the reopen button |
| `button_text` | `#202124` | Button labels |
| `button_border` | `#202124` | Button borders |

Saving checks contrast with the WCAG 2 formula: `text` and `accent` against
`background`, and `button_text` against `button_background`, need at least
4.5:1. Buttons also need a visible edge: `button_background` or `button_border`
must reach 3:1 against `background`. The error names the colors and the ratio.

All action buttons share one set of colors on purpose, so no choice is styled to
look preferred. Regulators in several regions treat a highlighted "Accept" next to
a muted "Reject" as steering visitors toward consent.

## Layout

`layout` is `card` (the default), a card in the bottom-right corner, or `bar`, a
full-width bar across the bottom of the window. On wide screens the bar keeps
its text and categories on the left and its buttons on the right, with the
content no wider than 72rem; on phones the buttons sit below the text. Both
use the same wording, colors and buttons, and the reopen button appears once
the banner is closed.

## Buttons

`buttons.show` lists the buttons on the banner, in display order, from `reject`,
`accept` and `save`:

- `reject` is required, so refusing always takes one click, as accepting does.
- At least one of `accept` and `save` is required, so visitors can also allow
  categories.
- Without `save`, the banner shows no category checkboxes and the choice is all
  or nothing.

`buttons.reopen` places the button that reopens the banner after a choice:
`bottom-left` (default), `bottom-right`, or `hidden`. Withdrawing consent must stay
as easy as giving it, so if you hide the button, link to the banner from every
page, for example in your footer:

```html
<a href="#privacy-choices" id="privacy-choices-link">Privacy choices</a>
<script nonce="YOUR_NONCE">
  document.getElementById('privacy-choices-link').addEventListener('click', function (event) {
    event.preventDefault();
    if (window.AggregateConsent) window.AggregateConsent.open();
  });
</script>
```

## Consent behavior

The banner's consent signal decides whether the tracker records enhanced or
anonymous analytics: enhanced while analytics is allowed, anonymous otherwise.
Four settings shape that signal. Set them with your legal team for the visitors
this banner serves; Aggregate's defaults are the conservative choice. Each is on
the dashboard's **Consent behavior** panel and in YAML:

```yaml
consent_manager:
  consent_type: implicit_us
  close_button: dismiss
  default_granted: [analytics]
  respect_gpc: true
```

| `consent_type` | Before a choice | Close button (×) by default |
| --- | --- | --- |
| `explicit_eu` (default) | Every optional category is denied: anonymous analytics. | Rejects every optional category and saves that choice, like the reject button. |
| `explicit_us` | Every optional category is denied: anonymous analytics. | Closes without a choice; categories stay denied. |
| `implicit_us` | The `default_granted` categories are allowed until the visitor opts out: enhanced analytics while `analytics` is among them. | Closes without a choice; the default grant stays. |

- **`close_button`** replaces the default action: `reject` (reject every optional
  category and save that), `dismiss` (close without a choice) or `hidden` (no
  close button). Closing without a choice keeps the banner closed for the rest of
  the visitor's browser-tab session; a new tab session shows it again.
- **`default_granted`** lists the categories allowed before a choice in
  `implicit_us`, for example `[analytics, marketing]` where opt-out rules apply
  to both, or `[]` for a notice that allows nothing. Explicit types ignore it:
  they always start every optional category denied. Categories the website does
  not use are ignored.
- **`respect_gpc`**: while true, a browser that sends Global Privacy Control gets
  no default grants, even in `implicit_us`. The visitor can still accept.
  Set it to false only if your legal team says the signal does not apply.

A saved choice always replaces these defaults. The privacy guide compares this
with third-party consent managers in
[Which consent signal sets the mode](PRIVACY-COMPLIANCE.md#which-consent-signal-sets-the-mode).
Enhanced analytics uses browser identifiers, so use `implicit_us` only for
visitors where you have decided that notice and opt-out are lawful for this
website.

## Regions and the close button

Regions let one banner carry different wording and consent behavior. The
banner does not detect where a visitor is: with two or more regions it shows a
region selector, starting on `default_region`, and remembers the visitor's pick
in the browser. The region selector is the visitor's own statement, not a
location check. Each region has an `id` (1–32 lowercase letters, digits,
underscores or hyphens) and a `name`. Its optional `consent_type`,
`close_button`, `default_granted`, `text` and `buttons` replace the banner's for
that region; a region without one uses the banner's. `default_granted` is
accepted only in `implicit_us` regions.

```yaml
consent_manager:
  default_region: eu_eea
  regions:
    - id: eu_eea
      name: European Union & EEA
      consent_type: explicit_eu
    - id: us
      name: United States
      consent_type: implicit_us
      default_granted: [analytics, marketing]
      text:
        title: 'Your privacy choices at {name}'
        dismiss: Close this notice
```

Every banner has a close button (×) in its top-right corner unless
`close_button` is `hidden`; the [table above](#consent-behavior) lists what it
does by default. Its accessible name is `dismiss_reject` when it rejects and
`dismiss` when it closes without a choice.

A banner without `consent_type`, and a region without one, use the banner's
type, which defaults to `explicit_eu`. The values `explicit` and `implicit` from
earlier releases are still accepted and mean `explicit_eu` and `implicit_us`;
saving the dashboard page writes the new names.

Closing without a choice is kept in the tab's session storage under the consent
key with `:dismissed` appended. It holds only `1`, identifies no one and is
never sent anywhere; nothing is stored when the close button saves a rejection
instead. The Escape key hides the banner for the current page in every type.

## Behavior and limits

Every optional category starts denied, except the `default_granted` categories
in `implicit_us`. Choices are stored in the browser's local
storage for that origin, tracker namespace and website, and apply across tabs. The
banner sends nothing to the server; the tracker and tag manager receive the choice
through `AggregateConsent`, documented with the
[tag manager's consent integration](TAG-MANAGER.md#install-and-connect-consent).

This is a lightweight category chooser, not a consent-records system. It does not
detect a visitor's region, implement Google Consent Mode or IAB TCF, keep
provider-level consent, or hold back scripts that are neither loaded through the
tag manager nor [marked for it](#categories-and-other-tags). For a Global Privacy Control advertising opt-out, a request form, or regional examples, see the
[standalone banner](../micro-consent-dropins/README.md) and the
[regional consent examples](CONSENT-REGIONS.md). These are engineering notes, not
legal advice.
