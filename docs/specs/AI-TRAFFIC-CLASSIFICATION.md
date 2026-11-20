# AI traffic classification: a hybrid edge and browser pipeline

_Spec drafted by Claude Code in Scott's session on 20 November 2026 from his
outline. Phases 0 and 1 are in the pull request that adds this file; every
later phase is a proposal for review. Nothing here is a claim of legal
compliance: the privacy design is meant to keep classification within
Aggregate's existing invariants, and each operator still makes their own
assessment._

## Why

AI training crawlers, search indexers and live retrieval fetchers increasingly
request pages, and agentic browsers increasingly run the tracker on someone's
behalf. Aggregate already labels bots (`device_class = 'bot'`, see
[bot traffic](../PRIVACY-COMPLIANCE.md#bot-traffic)), but one label cannot tell
a site owner how AI systems use their content, and two kinds of traffic slip
past it:

- **Most crawlers and fetchers run no JavaScript** (GPTBot, ClaudeBot,
  OAI-SearchBot, PerplexityBot, ChatGPT-User and the like), so the browser
  tracker never fires for them. Only the web server, proxy or CDN sees them.
- **Agentic browsers do run JavaScript** and often send an ordinary Chrome
  User-Agent, so a User-Agent list misses them.

The goal is to separate traffic into buckets people can toggle in reports,
instead of silently counting bots as visitors or silently dropping them, and
to do it without fingerprints, visitor identifiers or stored addresses.

## Traffic classes

| Code | Label | What it covers |
| --- | --- | --- |
| `human` | Human | Nothing marked the request as automated. Always `inferred`: nothing proves a visitor is a person. |
| `ai_agent` | AI agent (interactive) | An automated browser acting for someone. From a published agent list (Google's user-triggered agents), or inferred from weighted signals such as the browser reporting automation. Test suites driving a browser land here too. |
| `ai_fetcher` | AI live fetcher | A page fetched because someone asked an assistant a question (ChatGPT-User, Claude-User, Perplexity-User, Google's user-triggered fetchers). |
| `crawler` | Training or search crawler | GPTBot, ClaudeBot, OAI-SearchBot, Claude-SearchBot, PerplexityBot, Googlebot, Bingbot, Applebot and similar. |
| `automation` | Other automation | Any other bot: uptime monitors, SEO tools, ad review bots, and an operator's address seen with an unrecognized User-Agent. |

Each classification carries a **confidence**:

- `verified`: the address is in the operator's own published range list;
- `declared`: the User-Agent says what it is, which anyone can copy;
- `inferred`: weighted signals, and every `human` result.

The existing `device_class = 'bot'` label stays as it is. A future
`traffic_class` refines it; it does not replace it, so existing reports that
filter `device_class <> 'bot'` keep their meaning.

## Privacy design

- **Classification happens in memory during the request.** The address and
  User-Agent are read, matched and discarded with the request, exactly as the
  rate limiter and the optional geography lookup use them today. Only the
  resulting class code (and, if adopted, the confidence code) would be stored.
- **No address is stored or hashed into events.** A correction to the outline
  this spec started from: Aggregate does not store a daily salted IP hash on
  events. Anonymous rows keep no address-derived value at all; the rate limiter
  uses a short-lived rotating HMAC bucket that never reaches an event, a queue
  or a log. Classification adds lookups beside those, before the address is
  dropped, and adds no hash.
- **Range lists are local.** Published lists are downloaded by the
  installation (`app:traffic:ranges --refresh`) and matched locally. No visitor
  address is sent to a lookup service.
- **The strict collection profile classifies nothing**, as it reads no
  User-Agent today.
- **Behavioural signals stay in the browser** (phase 4): the tracker would send
  one coarse code, never pointer coordinates, timings or a score history.
  Reading more device properties raises the ePrivacy Article 5(3) question the
  [strict profile](../PRIVACY-COMPLIANCE.md#strict-collection-profile)
  documents, so phase 4 needs its own consent and disclosure review.
- **A class code is not a fingerprint**: five values, shared by everyone in a
  bucket. It is a new dimension, though, so adding it to the `bi_anonymous_*`
  views needs the disclosure review in phase 5.

## Signals and their order

`App\Entities\TrafficClass\Classifier` applies these in order:

1. **Published bot range** (verified). The class comes from the list; a list
   covering several bots, such as Anthropic's single list, takes the class from
   the User-Agent when it names that operator's bot, and `automation`
   otherwise.
2. **Documented User-Agent token** (declared), from
   `App\Entities\TrafficClass\KnownAgents`.
3. **The existing bot list** (`BotDetector` and the operator's
   `bot_user_agents`) (declared, `automation`).
4. **Weighted signals** (inferred): reported automation (`navigator.webdriver`)
   weighs 2, a cloud provider's network weighs 1, and 2 or more is
   `ai_agent`. A cloud network alone stays `human`, because VPNs and corporate
   proxies share those networks. Phase 4's browser signals join this score.
5. Otherwise `human` (inferred).

A token that claims an operator whose list is loaded, from an address outside
that list, may be spoofed. Phase 1 keeps it `declared`; phase 2 can decide to
downgrade it to `automation`.

## Range sources

The defaults live in `App\Integrations\TrafficRanges\PublishedSources`, as
each operator documented them on 20 November 2026. All use the
`{"prefixes": [{"ipv4Prefix": …}, {"ipv6Prefix": …}]}` layout except AWS.

| Source id | Operator | Class | URL |
| --- | --- | --- | --- |
| `openai-gptbot` | OpenAI | crawler | `https://openai.com/gptbot.json` |
| `openai-searchbot` | OpenAI | crawler | `https://openai.com/searchbot.json` |
| `openai-chatgpt-user` | OpenAI | ai_fetcher | `https://openai.com/chatgpt-user.json` |
| `openai-adsbot` | OpenAI | automation | `https://openai.com/adsbot.json` |
| `anthropic-bots` | Anthropic | by User-Agent | `https://claude.com/crawling/bots.json` |
| `perplexity-bot` | Perplexity | crawler | `https://www.perplexity.com/perplexitybot.json` |
| `perplexity-user` | Perplexity | ai_fetcher | `https://www.perplexity.com/perplexity-user.json` |
| `google-common-crawlers` | Google | crawler | `…/static/crawling/ipranges/common-crawlers.json` |
| `google-special-crawlers` | Google | automation | `…/static/crawling/ipranges/special-crawlers.json` |
| `google-user-fetchers` | Google | ai_fetcher | `…/static/crawling/ipranges/user-triggered-fetchers-google.json` |
| `google-user-agents` | Google | ai_agent | `…/static/crawling/ipranges/user-triggered-agents.json` |
| `bing-bingbot` | Microsoft | crawler | `https://www.bing.com/toolbox/bingbot.json` |
| `apple-applebot` | Apple | crawler | `https://search.developer.apple.com/applebot.json` |
| `aws` | AWS | cloud network | `https://ip-ranges.amazonaws.com/ip-ranges.json` |
| `google-cloud` | Google Cloud | cloud network | `https://www.gstatic.com/ipranges/cloud.json` |

Google's prefix is `https://developers.google.com`. Notes:

- Google's `user-triggered-fetchers.json` (addresses resolving to
  `gae.googleusercontent.com`) is left out: it covers fetchers that Google Cloud
  customers run, not Google's own.
- Google's user-triggered fetchers include non-AI tools; `ai_fetcher` reads as
  "fetched on someone's request".
- Microsoft Azure publishes its ranges only as a weekly file whose address
  changes, and Hetzner, DigitalOcean and Oracle are not in the defaults yet;
  see the ASN decision below.
- A refresh that fails, returns an empty list or a list with an invalid entry
  keeps the previously saved list, so a vendor outage or format change never
  empties classification.

### Licensing

No range data is committed or shipped in a release ZIP. Each installation
downloads the lists from their publishers, as it would read them in a
browser, and keeps them under `var/traffic-ranges/` (runtime data, ignored by
Git and preserved by updates like the rest of `var/`). That avoids
redistributing third-party data under the AGPL. The publishers' own terms
still apply to automated download; that review is an open decision.

For cloud networks beyond the providers' own lists, the options are:

| Option | Licence | Fit |
| --- | --- | --- |
| Providers' published lists (AWS, Google Cloud, Oracle, DigitalOcean) | Each provider's terms | Default in phase 1 for AWS and Google Cloud. No ASN view; Azure and Hetzner missing. |
| DB-IP ASN Lite (MMDB) | CC BY 4.0 | Works with the existing local MMDB reader; needs attribution in the dashboard and docs. Operator downloads it, as with geography. |
| MaxMind GeoLite2 ASN (MMDB) | GeoLite2 EULA, account required | Same reader; not redistributable, operator supplies it. |
| iptoasn.com | Check current terms | TSV, would need its own reader. |

Recommendation: providers' lists now; an optional operator-supplied ASN MMDB
(DB-IP Lite or GeoLite2 ASN) in phase 2, read like the geography database and
never shipped.

## Phases

### Phase 0: groundwork (this pull request)

- Roadmap entries and this spec.
- Range source list with verified URLs, and the licensing approach above.

### Phase 1: classifier core and range lists (this pull request)

Built in the FIDES zones, with no change to collection:

- `src/Entities/TrafficClass/`: `TrafficClass`, `Confidence`, `KnownAgents`,
  `IpRanges` (IPv4 and IPv6 CIDR matching by masked prefix), `Signals`,
  `Classifier`, `Classification`, `RangeSource`, and the contracts
  `PublishedRanges`, `RangeStore`, `RangeDownloads` and `BotUserAgents`.
- `src/Features/TrafficClassification/`: `ClassifyRequest`, `RefreshRanges`,
  `ShowRanges`.
- `src/Integrations/TrafficRanges/`: the source list, the two list layouts,
  HTTPS download (20 s timeout, 16 MB cap), file store, and an adapter over the
  existing bot list.
- Commands: `app:traffic:ranges [--refresh] [--source=] [--json]` and
  `app:traffic:classify "<User-Agent>" [--ip=] [--automation] [--json]`. The
  second shows how a request would be classified and stores nothing.

### Phase 2: classify and store at `/api/receive`

Why it is not in phase 1: a new `events` column touches `Event`,
`EventIngestion`, `AnonymousEventRecorder`, `TrackEventMessage` and its
handler, `EventRowWriter`, the data transfer and forensic catalogs and the
install SQL. Those files are outside the FIDES zones and held by the ratchet
(`Event.php` and `EventIngestion.php` may not grow), and the change fails the
FIDES spread test. Phase 2 therefore starts with a port of the ingestion and
event-writing area, or rides on the scaling roadmap's *direct insert* work,
which already moves the event row rules into one function.

- Nullable `traffic_class` (and, if wanted, `traffic_confidence`) on `events`,
  with a portable migration on every engine; `NULL` for strict collection and
  rows written before the change.
- Classification at step 4 of ingestion, beside the existing bot check, before
  the address is dropped. `device_class = 'bot'` is still set for every
  non-human class, and `bot_traffic: drop` still drops them.
- Range lists compiled into a PHP array file so OPcache keeps them in memory;
  phase 1's file store reads JSON per instance, which is fine for commands but
  not for collection.
- Range refresh as a job of the [scheduled task](../SCHEDULED-TASKS.md), daily,
  recorded in `processing_tasks`.
- Archives: decide whether hourly archives keep the class or fold it away.
- Optional ASN MMDB as a cloud-network signal.

### Phase 3: log and edge ingestion

An authenticated endpoint for structured access logs from Caddy and Nginx
(JSON log formats), Cloudflare Logpush or a Worker, and Vector or Fluent Bit.
It builds on the roadmap's open *built-in server-side collection* item (an
ingestion key per caller, per-caller rate limits).

- Records only **non-human** classes from logs. Human page views already come
  from the tracker, so counting them again from logs would double them.
- Same path sanitization, kill switches, path exclusions, website domain rules
  and anonymous-mode rules as `/api/receive`; addresses and User-Agents are
  read and dropped in memory.
- Batches of lines per request, with a size cap; the shipper's own timestamp
  is reduced to the UTC hour, as the server does for collection today.
- Reference configuration for each shipper in the docs, not in the app.

### Phase 4: browser signals for agentic browsers

In the tracker (standard profile only, and subject to the consent review
above): `navigator.webdriver` (already read), an inconsistent `window.chrome`
object, a software WebGL renderer (SwiftShader, llvmpipe), `navigator.languages`
disagreeing with the `Accept-Language` header, and interaction entropy (pointer
jitter, scroll deceleration, focus and blur, zero-latency clicks after load,
deterministic page transitions). The tracker reduces these to one coarse
code sent with events; the raw measurements never leave the page. The server
treats the code as untrusted and only lets it add weight, never remove a
server-side classification.

### Phase 5: reporting and answer engine optimization

- `traffic_class` in the reporting views, after disclosure review: suppression
  and pooling must hold per class, and the five classes must not let a user
  subtract one view from another.
- An `ai_assistant` referrer channel for clicks from chatgpt.com,
  perplexity.ai, claude.ai, gemini.google.com and copilot.microsoft.com (a new
  code in an existing BI dimension, so a glossary and contract change).
- A page-level view of AI fetches and crawls beside human visits from AI
  assistants, for answer engine optimization. Charts stay in BI tools and the
  MCP server, never in the app.

## Open decisions for Scott

1. **Class names.** The five codes above follow the outline; `ai_agent` also
   catches non-AI automated browsers such as test suites. Keep the names, or
   rename to `agent` and `live_fetcher`?
2. **Store confidence too?** One more coarse column, or the class alone.
3. **Phase 2 order.** Port the ingestion area under FIDES first, or fold the
   column into the scaling *direct insert* change.
4. **ASN data.** Providers' lists only, or also an operator-supplied DB-IP
   Lite (CC BY 4.0) or GeoLite2 ASN database.
5. **Publishers' terms.** Confirm automated daily download of each list is
   acceptable under each publisher's terms.
6. **Spoofed tokens.** Downgrade a User-Agent claiming an operator whose list is
   loaded, from an address outside it, to `automation`?
