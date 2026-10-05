# Consent manager lite

Consent manager lite is the small built-in consent banner (CMP) that loads next to
the [tag manager](TAG-MANAGER.md). It asks visitors about enhanced analytics and
every consent category used by the website's enabled tags, remembers the choice
in the browser, and passes it to the tracker and tag manager. It makes no network
requests of its own. Each registered website has its own settings and its own
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
- [Wording](#wording)
- [Colors](#colors)
- [Buttons](#buttons)
- [Behavior and limits](#behavior-and-limits)

## Configure it in the dashboard

Open **Collection → Consent manager lite** and choose a website. The page has
four parts, and a preview that updates as you edit:

- **Banner**: whether the built-in banner is enabled, the name shown in its
  title, and an optional link to your privacy notice.
- **Wording**: the title, description, additional paragraphs, category list
  heading and labels, button labels, privacy-link text, and the announcements
  screen readers hear after a choice.
- **Colors**: seven colors, checked for readable contrast as you edit and again
  when you save.
- **Buttons**: which buttons appear, in what order, and where the button that
  reopens the banner sits.

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
| `text` | Default wording; see [Wording](#wording). |
| `theme` | Default colors; see [Colors](#colors). |
| `buttons` | `show: [reject, accept, save]`, `reopen: bottom-left`; see [Buttons](#buttons). |

Within `text`, `theme` and `buttons`, each key you supply replaces its default.
An invalid value fails closed like the rest of the site's script settings: the
dashboard reports it, and the website's scripts are not served until it is
corrected. There are no environment-variable overrides; the
[environment precedence](TAG-MANAGER.md#yaml-configuration) of site files applies.

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
| `reject` | Reject all optional categories |
| `accept` | Accept all optional categories |
| `save` | Save selected choices |
| `reopen` | Privacy choices |
| `privacy_link` | Read this website’s privacy notice |
| `status_applied` | Your privacy choices have been applied. |
| `status_not_saved` | This choice could not be saved; choose again on your next visit. |
| `status_other_tab` | Your privacy choices were updated in another tab. |

The three `status_*` messages are announced to screen readers, not shown. The
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

## Behavior and limits

Every optional category starts denied. Choices are stored in the browser's local
storage for that origin, tracker namespace and website, and apply across tabs. The
banner sends nothing to the server; the tracker and tag manager receive the choice
through `AggregateConsent`, documented with the
[tag manager's consent integration](TAG-MANAGER.md#install-and-connect-consent).

This is a lightweight category chooser, not a consent-records system. It does not
detect a visitor's region, implement Google Consent Mode or IAB TCF, keep
provider-level consent, or block scripts that were not loaded through the tag
manager. For Global Privacy Control, a request form, or regional examples, see the
[standalone banner](../micro-consent-dropins/README.md) and the
[regional consent examples](CONSENT-REGIONS.md). These are engineering notes, not
legal advice.
