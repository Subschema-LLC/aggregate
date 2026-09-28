# Regional consent examples

**Engineering examples, not legal advice.** These configurations do not determine
which laws apply or certify a deployment. Review your audience, purposes,
providers, sensitive contexts, children, retention and transfers with appropriate
advisers. An operator chooses the applicable guidance; the scripts do not infer
legal jurisdiction from a visitor's IP address, language or device.

The examples use the [independent MicroConsent option](../micro-consent-dropins/README.md),
separate from the built-in consent manager. They all take a conservative opt-in
approach: no tracker script before an affirmative analytics choice. This is a
product configuration choice, not a claim that every listed country requires
consent for every form of measurement. Use the global example where several
jurisdictions apply or the appropriate regional treatment is uncertain.

## Choose and install an example

| Example | Review emphasis |
| --- | --- |
| [Global / unknown](../micro-consent-dropins/examples/global.yaml) | Conservative fallback; assess every applicable jurisdiction |
| [EU / EEA](../micro-consent-dropins/examples/eu-eea.yaml) | Valid consent and national ePrivacy differences; no presumed analytics exemption |
| [United Kingdom](../micro-consent-dropins/examples/uk.yaml) | Current PECR exceptions have conditions; raw retained events are a material limit |
| [Canada, including Quebec](../micro-consent-dropins/examples/canada.yaml) | Meaningful consent, sensitivity/expectations, and Quebec activation requirements |
| [United States](../micro-consent-dropins/examples/us.yaml) | State and purpose differences, GPC, sale/sharing/targeted-advertising opt-outs |

Each file is a complete **example** per-site mapping with:

- `consent_manager.enabled: false` to avoid installing the built-in banner too;
- separate `standalone_consent` settings, optional categories denied until chosen;
- a tag manager enabled with one tracker script requiring `consent: analytics`;
- GPC handling enabled and Formspree submissions disabled by default.

Run `php bin/console app:tag-manager:sites` to find the intended website's public
instance ID and configuration path. Merge the example deliberately into
`config/tag-manager/sites/<site-id>.yaml`, preserving existing tags, other keys,
and other environments. Replace the example privacy URL, application host,
URL-encoded endpoint and public website token. The site's environment-specific
file or mapping takes precedence as described in [Tag manager](TAG-MANAGER.md#yaml-configuration).
Do not copy one site's token or settings over another.

For the server-configured standalone bundle, load:

```html
<script src="https://analytics.example.com/standalone-cmp/sites/SITE_ID/consent.js?min=1" defer referrerpolicy="no-referrer"></script>
<script src="https://analytics.example.com/tms-lite/sites/SITE_ID/lib.js?min=1" defer referrerpolicy="no-referrer"></script>
```

The first response includes the independent UI, CSS and optional Aggregate bridge
configured for that website. Remove the built-in CMP URL and any direct tracker
installation, including duplicate GTM tags. Use the generated **Setup** snippet
when the application runs under a URL prefix. The independent files can also be
hosted without the application using the [static installation guide](../micro-consent-dropins/README.md#files-and-static-installation).

The templates do not create a separate collection policy per region or website.
Collection controls and property models still use the deployment's active
aggregate YAML. Retain these defaults when assessing a conservative setup:

```yaml
# In the deployment's aggregate.yaml, not the per-site tag-manager file:
anonymous_geo_enabled: false
page_sequence_enabled: false
```

Keep all six UTM properties `consent_required: true`. The server independently
enforces configured collection permissions. Review existing goal permissions and
exclude sensitive routes using `anonymous_excluded_paths`; the global
`anonymous_tracking_enabled: false` switch stops both privacy modes. Do not
introduce IP geolocation just to choose a consent example.

## What denial and withdrawal actually do

`consent: analytics` prevents the tracker tag's **first load** before a choice.
The container itself can load, and independent scripts or tags marked `none`
remain outside that gate. Check all installation paths and providers.

Once the tracker has loaded, withdrawing analytics calls `setConsent(false)`:
SDK identifiers are removed, enhanced detail stops, and coarse anonymous events
may continue. The SDK has no visitor-facing all-collection stop method. Reload
the page after withdrawal when using this script gate to stop all measurement;
the denied tracker tag then stays unloaded. Existing third-party scripts may also
need provider-specific stop/cleanup APIs, and in-flight requests or stored history
are not erased by reload. Do not promise that a banner alone stops every request.

A setup that intentionally allows reviewed coarse measurement before a choice
must disclose that behavior and any applicable objection mechanism. Changing a
tracker tag to `consent: none` is a deliberate change to these examples. Merely
calling `setConsent(false)` does not implement an objection to all measurement.

The optional page-depth counter may use tab session storage or a URL transport
parameter. Neither method establishes a legal exemption; the URL method can also
appear in initial requests and earlier scripts. Leave it off until its use has
been reviewed. Local IP geography is off by default and must never use an
external visitor-IP lookup service.

## Regional review notes

### EU and EEA

Where consent is required, use a specific, informed, freely given affirmative
choice. Optional grants must not come from preselected boxes, silence, continued
browsing, or scrolling. Make refusal and withdrawal straightforward and keep
preferences accessible after the banner closes.

The [EDPB consent guidelines](https://www.edpb.europa.eu/documents/guideline/guidelines-052020-on-consent-under-regulation-2016679_en)
explain those requirements. [CNIL's analytics guidance](https://www.cnil.fr/en/sheet-ndeg16-use-analytics-your-websites-and-applications)
explains the general consent rule and conditions for a narrow audience-measurement
exception, including purpose limitation, separation from other datasets, notice
and objection; it explicitly warns that national interpretations vary.

These examples do not certify any analytics exception. Self-hosting, identifier
removal, bounded page depth and the application's `anonymous` mode name are not
proof of legal anonymity or an exemption. Keep the consent-first gate unless the
operator has established another appropriate arrangement for the actual use.

### United Kingdom

The ICO finalized its updated [storage and access guidance](https://ico.org.uk/for-organisations/direct-marketing-and-privacy-and-electronic-communications/guidance-on-the-use-of-storage-and-access-technologies/)
on **29 April 2026** following the Data (Use and Access) Act changes. It includes
a [statistical-purposes exception](https://ico.org.uk/for-organisations/direct-marketing-and-privacy-and-electronic-communications/guidance-on-the-use-of-storage-and-access-technologies/what-are-the-exceptions/#statistical),
so a blanket statement that every UK analytics use always needs consent would
be inaccurate.

The exception is conditional: the sole purpose must be statistical information
to improve the service; resulting information must be aggregate and nonpersonal;
individual personal data cannot be retained beyond the aggregation need. It is
not for identifying or profiling people or for advertising. A third party must
only assist the service-improvement purpose under the applicable restrictions.
Clear information and a simple, free way to object are required; an objection
must stop the relevant storage/access.

Aggregate retains individual anonymous-mode event rows and cannot establish that
its outputs are legally anonymous. The UK example therefore still gates the whole
tracker before consent. It does not claim to meet that exception automatically.
A separately assessed exception workflow needs actual control over coarse
measurement and retention, not just an enhanced-consent toggle.

### Canada and Quebec

The [Office of the Privacy Commissioner of Canada's meaningful-consent guidance](https://www.priv.gc.ca/en/privacy-topics/privacy-for-businesses/appropriate-handling-of-personal-information/collecting-personal-information-and-consent/consent/gl_omc_201805/)
calls for understandable information about what is collected, recipients,
purposes and significant consequences. Express consent is generally appropriate
for sensitive information, unexpected processing, or meaningful residual risk of
significant harm. Implied consent depends on narrowly assessed circumstances;
it is not a universal permission for analytics. Withdrawal must be respected.

The [Quebec CAI's Law 25 guidance](https://www.cai.gouv.qc.ca/protection-renseignements-personnels/sujets-et-domaines-dinteret/principaux-changements-loi-25)
states that technology functions identifying, locating or profiling a person
require prior information and a way for the person to activate them; those
functions cannot be active by default. Review the actual behavior rather than
assuming a coarse location is outside the rule.

The Canada example uses affirmative opt-in and leaves optional geography/page
depth off through the recommended deployment defaults. Notices should identify
the actual data and purposes; collecting less data does not remove the need to
assess the appropriate consent and legal treatment.

### United States

There is no single US cookie rule represented by this example. Applicability
and obligations depend on the state, entity, audience and processing purpose;
sale, sharing, targeted advertising, sensitive information and children's data
can trigger different requirements. The example preserves explicit enhanced
consent as the application's own invariant.

The [California Privacy Protection Agency FAQ](https://cppa.ca.gov/faq.html) and
[California Attorney General FAQ](https://oag.ca.gov/privacy/ccpa) explain that
covered businesses must honor qualifying opt-out preference signals such as
Global Privacy Control for sale/sharing. The [Connecticut Attorney General's guide](https://portal.ct.gov/ag/sections/privacy/the-connecticut-data-privacy-act)
describes universal opt-out signals for sale/targeted advertising from January 1,
2025, alongside sensitive-data and other obligations. Those are examples, not
an exhaustive state-law matrix.

With `respect_gpc: true`, the standalone UI treats an active signal as
`marketing: false` and `doNotSell: true`. This never grants analytics, and the
absence of GPC is not affirmative permission. Configure every relevant provider
to honor the applicable choice. The banner cannot decide whether an operator's
specific use is a sale/share or enforce behavior in an unconnected library.

### Other jurisdictions

Use the global conservative example while reviewing the applicable rules;
it is not an assertion that all jurisdictions have the same consent standard.
For example, Brazil's [ANPD cookie guide](https://www.gov.br/anpd/pt-br/centrais-de-conteudo/materiais-educativos-e-publicacoes/guia-orientativo-cookies-e-protecao-de-dados-pessoais.pdf)
discusses consent and other legal bases, and recommends visible rejection of
nonessential cookies and easy withdrawal. Australia's [OAIC tracking-pixel guidance](https://www.oaic.gov.au/privacy/privacy-guidance-for-organisations-and-government-agencies/organisations/tracking-pixels-and-privacy-obligations)
covers reasonable necessity, minimization, notices, sensitive-information consent
and overseas disclosure safeguards. The latter addresses tracking pixels; it does
not certify this first-party analytics implementation.

## Requests, records and operational limits

The default 180-day consent lifetime is a configurable review interval, not a
statutory regional duration. Update the revision after relevant purpose or notice
changes. Stored category choices are not an authenticated server-side consent
receipt or proof that every legal requirement was met.

Optional Formspree requests are separate from browser choices. Enabling an
endpoint discloses the visitor's submitted email, request type and message to
Formspree; ordinary network metadata also reaches that recipient. Review and
explain its processing and retention. Submitting a form does not itself fulfill
a rights request, verify identity, or delete historical data. The operator must
handle those duties through its own process.

Before deployment, test requests and browser storage before a choice, after
acceptance, after withdrawal/reload, with GPC, and with storage unavailable.
Review all independent tags and server-side exclusions. Verify the configured
privacy notice and request-delivery process. See the [privacy guide](PRIVACY-COMPLIANCE.md)
and [standalone integration guide](../micro-consent-dropins/README.md).

Source review date: **28 September 2026**. Regulator guidance and law can change;
review the linked primary sources and local requirements when deploying. A
retrieved page is guidance for its stated scope, not a legal assessment of your site.
