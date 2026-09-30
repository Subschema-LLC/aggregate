# Server-side collection

[Tracker](TRACKING.md) · [Privacy guide](PRIVACY-COMPLIANCE.md) · [Event examples](EVENT-EXAMPLES.md) · [Connect BI tools and AI assistants](BI-CONNECTION.md)

Your website's own server can send events to Aggregate instead of, or alongside,
the browser tracker. Nothing runs on the visitor's device: no script, no cookie
and no browser storage. It is also more reliable where it matters most: an order
your server saved is counted even when the visitor's browser blocks scripts.

Server-side events go to the same collection endpoint as the tracker and follow
the same rules: path sanitization, excluded paths, the collection switch, allowed
goals and properties, the strict profile and anonymous mode. This guide explains
what to send, how to send it without slowing your pages, and gives examples for
PHP, Java, .NET, Node.js, Python, Ruby and Go.

## When to use it

Server-side collection fits best for:

- **Conversions your server completes:** orders, sign-ups, bookings, form
  submissions and downloads. The server knows the action succeeded, so the
  count doesn't depend on a thank-you page loading a script.
- **Sites that can't or won't run a script**, such as sites with a strict content
  security policy, or organizations that want nothing added to their pages.
- **Page views, when your application renders every page itself.** If a page
  cache or CDN answers most requests, your server never sees them; see
  [what to count as a page view](#what-to-count-as-a-page-view).

Keep the browser tracker for viewport sizes, for navigation inside single-page
apps, for pages served from a cache, and for
[enhanced analytics](PRIVACY-COMPLIANCE.md#enhanced-analytics-consent), which
depends on a consent choice made in the browser. Many sites use both: the tracker
for page views and the server for goals.

## Does it avoid the need for consent?

Partly, and it depends on where your visitors are. This is general information,
not legal advice.

**What changes.** Without a script or anything stored on the device, the most
common consent triggers are gone: cookies, browser storage, and scripts that read
information from the device. In the United States, state privacy laws mostly
regulate selling or sharing personal data and targeted advertising; first-party
statistics sent to your own server are generally a matter of notice rather than
opt-in consent.

**What doesn't change.**

- **The EU and UK rules on devices are read broadly.** The European Data
  Protection Board's guidelines say that tracking based on URLs or IP addresses
  that a device sends can fall within Article 5(3) of the ePrivacy Directive
  ([Guidelines 2/2023](https://www.edpb.europa.eu/system/files/2024-10/edpb_guidelines_202302_technical_scope_art_53_eprivacydirective_v2_en_0.pdf)).
  No regulator has said that server-side analytics is consent-free as a rule.
  Some offer audience-measurement exemptions with conditions, such as first-party
  use, statistics only, clear information and a simple way to object: see
  [what strict does not establish](PRIVACY-COMPLIANCE.md#strict-collection-profile).
  Anonymous server-side collection fits those conditions better than a script
  does, but whether it qualifies is your assessment.
- **Data protection law still applies.** A request carries an IP address, a
  User-Agent and a page address, which can be personal data. You need a lawful
  basis (legitimate interests is common for minimal first-party statistics), a
  privacy notice that mentions the measurement, and data minimization.
- **It is not a way to collect more.** Server-side events are anonymous-mode
  events: sanitized path, event name, coarse categories and the UTC hour. Don't
  send identifiers, account IDs, email addresses or order numbers. Aggregate
  strips identifier-like path segments and ignores identifiers without consent,
  but don't rely on that; leave them out.

So server-side collection lets you measure more *reliably*, including visitors
whose browsers block scripts, and, where the law allows measurement without
consent, visitors who would have declined a cookie banner. It doesn't let you
measure in more *detail*.

## What to send

Send one `POST` request to the installation's `/api/receive` endpoint for each
event, with a JSON body:

```http
POST /api/receive HTTP/1.1
Host: analytics.example.com
Content-Type: application/json
Origin: https://www.example.com
User-Agent: (the visitor's User-Agent header)

{"websiteToken": "REPLACE_WITH_PUBLIC_WEBSITE_TOKEN", "eventName": "view", "pagePath": "/pricing", "referrer": "https://www.google.com/"}
```

| Field | Required | Value |
| --- | --- | --- |
| `websiteToken` | Yes | The website's public token from **Websites**. |
| `eventName` | Yes | `view` for a page view, or a fixed, non-identifying name such as `order_completed`. |
| `pagePath` | Yes | The path only. Aggregate drops any query string or fragment and redacts identifier-like segments. |
| `referrer` | No | The visitor's `Referer` header. Aggregate keeps only its channel (`search`, `social`, `email`, `internal`, `referral` or `direct`). |
| `referrerChannel` | No | A channel code instead of `referrer`. Use `unknown` for events that don't come from a visitor's request. |
| `deviceClass` | No | A device code. Without it, Aggregate derives one from the `User-Agent` header. Use `unknown` for events that don't come from a visitor's request. |
| `goalEvent` | No | A goal code from `config/goals.yaml` that allows anonymous use, such as `purchase`. |
| `eventData` | No | Properties the [data model](DATA-MODEL.md) allows without consent. |
| `internalTraffic` | No | `true` when the request carries your [organization traffic](PRIVACY-COMPLIANCE.md#organization-traffic) marker cookie, so staff visits can be filtered in reports. |

| Header | Value |
| --- | --- |
| `Content-Type` | `application/json`. |
| `Origin` | Your site's origin, such as `https://www.example.com`, which must match the website's [domain rules](CONFIGURATION.md#website-domains). Not needed if the website allows all domains. A server can send any `Origin`, so these rules don't authenticate server callers; they stop other sites' pages from submitting events in visitors' browsers. |
| `User-Agent` | The visitor's `User-Agent` header, so Aggregate can derive the device class and recognize crawlers. It is not stored. |
| `X-Forwarded-For` | Optional: the visitor's IP address. See [rate limits and geography](#rate-limits-and-geography). |

Don't send `consentState`, `visitorId` or `sessionId`: server-side events stay in
anonymous mode.

**Responses.** `202` with `{"status": "recorded", "mode": "anonymous"}` means the
event was stored. `202` with `{"status": "ignored"}` means collection is off or
the path is excluded. `400` means the token, path or event name was invalid,
`403` that the `Origin` didn't match the website's domain rules, and `429` that the
rate limit was reached. A `warnings` list containing `goal_not_allowed` means the
event was stored without its goal code.

**Send each event right away.** Aggregate records an anonymous event in the UTC
hour it arrives, so a queue that sends events hours later puts them in the wrong
hour. Don't retry failed events later for the same reason.

## What to count as a page view

- Count `GET` requests that return status `200` with an HTML page.
- Skip `HEAD` requests, assets, API responses, feeds, health checks, redirects,
  errors and "not found" pages.
- Skip prefetches. Browsers fetch some pages in advance, marked with a
  `Sec-Purpose` or `Purpose` header containing `prefetch`, and the visitor may
  never open them. The examples below skip them.
- **Crawlers.** Most crawlers don't run scripts, so the tracker rarely sees them,
  but your server sees every one. Forward the `User-Agent` header so Aggregate
  can mark known crawlers as `bot`, and exclude `device_class = 'bot'` in your
  reports. Crawlers that pretend to be browsers still get through, so expect
  server-side page views to be higher than tracker counts.
- **Caches.** Pages served by a page cache, reverse proxy or CDN never reach your
  application. Count page views where requests are answered, or keep the tracker
  for page views.
- **Don't count twice.** Send page views from either the tracker or the server,
  not both, and send each goal from one place only.

## Sending without slowing pages

- Send after the response has gone to the visitor, or in the background. Each
  example does this in the usual way for its framework.
- Use short timeouts (the examples use about one second to connect and two in
  total) and ignore failures: analytics must never break a page.
- In serverless functions, wait for the request to finish before the function
  returns, or use your platform's way of finishing work after the response; an
  unfinished request may be cut off.

## Rate limits and geography

Aggregate's per-minute rate limit and optional
[coarse geography](CONFIGURATION.md#optional-coarse-geography) use the address of
whoever sends the request. For server-side events that is your web server, so:

- all server-side events share one rate-limit bucket (`rate_limit_per_minute`,
  100 by default), and a site that sends more events than that per minute gets
  `429` responses;
- with geography on, every server-side event is placed in your server's country,
  or in none if the server has a private address.

To fix both, tell Aggregate the visitor's address:

1. On the Aggregate installation, add your web server's exact address to
   `TRUSTED_PROXIES` in `.env.local` (for example `TRUSTED_PROXIES=203.0.113.10`).
   If Aggregate also sits behind its own reverse proxy, list both.
2. In your application, send the visitor's address in `X-Forwarded-For`. Take it
   from your framework's client address, which already applies your own proxy
   settings. Never copy an incoming `X-Forwarded-For` header, which visitors can
   forge.

Aggregate uses the address only for the rate limit and the local geography
lookup and stores neither, exactly as for browser requests, which carry the
address anyway. Trust only exact addresses or narrow ranges, as the
[geography settings](CONFIGURATION.md#optional-coarse-geography) explain.

On shared hosting, other sites can send requests from the same address, and
could then set the header too; raise the limit instead there. For low traffic,
raising `rate_limit_per_minute` is simpler anyway, but it raises the limit for
every client. Leave geography off if you don't forward addresses.

## Examples

Each example reads three settings, here from environment variables:

```bash
AGGREGATE_ENDPOINT=https://analytics.example.com/api/receive
AGGREGATE_WEBSITE_TOKEN=REPLACE_WITH_PUBLIC_WEBSITE_TOKEN
AGGREGATE_SITE_ORIGIN=https://www.example.com
```

Each one has a helper that sends an event and a way to count page views. Pass the
visitor's request for events a page load or form submission causes. From queued
jobs, payment webhooks and scheduled tasks, pass no visitor: the example then
sends `unknown` for the channel and device, because the request belongs to your
server or a payment provider, not the visitor.

### PHP

`aggregate.php` works with any PHP application and needs only the `curl`
extension. Under PHP-FPM, make sure the three settings reach PHP (FPM clears the
environment by default), or replace `getenv()` with your own configuration.

```php
<?php

declare(strict_types=1);

/**
 * Sends one event to Aggregate from the server (needs the curl extension).
 *
 * $visitor: true for an event caused by the current web request, so Aggregate
 * can derive the referrer channel and device class from it; false for webhooks,
 * queues and scheduled jobs.
 */
function aggregate_send(string $eventName, string $pagePath, array $fields = [], bool $visitor = true): void
{
    $payload = [
        'websiteToken' => getenv('AGGREGATE_WEBSITE_TOKEN'),
        'eventName' => $eventName,
        'pagePath' => $pagePath,
    ] + $fields;
    $headers = ['Content-Type: application/json', 'Origin: '.getenv('AGGREGATE_SITE_ORIGIN')];

    if ($visitor) {
        // Reduced to a referrer channel and a device class; neither is stored.
        $payload['referrer'] = $_SERVER['HTTP_REFERER'] ?? '';
        $headers[] = 'User-Agent: '.($_SERVER['HTTP_USER_AGENT'] ?? '');
        // Optional, see "Rate limits and geography":
        // $headers[] = 'X-Forwarded-For: '.$_SERVER['REMOTE_ADDR'];
    } else {
        $payload += ['referrerChannel' => 'unknown', 'deviceClass' => 'unknown'];
    }

    $curl = curl_init((string) getenv('AGGREGATE_ENDPOINT'));
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
        CURLOPT_TIMEOUT_MS => 1500,
    ]);
    curl_exec($curl); // Never let analytics break a page: ignore the result.
}

/** Browsers prefetch pages that the visitor may never open. */
function aggregate_is_prefetch(): bool
{
    $purpose = ($_SERVER['HTTP_SEC_PURPOSE'] ?? '').($_SERVER['HTTP_PURPOSE'] ?? '');

    return str_contains(strtolower($purpose), 'prefetch');
}

/** Counts this request as a page view once the visitor has the page. */
function aggregate_count_page_view(): void
{
    register_shutdown_function(static function (): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || http_response_code() !== 200 || aggregate_is_prefetch()) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request(); // Send the page before contacting Aggregate.
        }
        aggregate_send('view', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    });
}
```

Use it like this:

```php
// At the top of each page, or in a shared bootstrap file:
require __DIR__.'/aggregate.php';
aggregate_count_page_view();

// After an order is saved:
aggregate_send('order_completed', '/checkout/complete', ['goalEvent' => 'purchase']);

// From a payment webhook or a queued job:
aggregate_send('order_paid', '/checkout/complete', ['goalEvent' => 'purchase'], visitor: false);
```

With PHP-FPM, `fastcgi_finish_request()` sends the page to the visitor before
Aggregate is contacted. Other server APIs send it when the script ends, a moment
later.

### Laravel

A helper class, and a terminable middleware that Laravel calls after the response
has been sent:

```php
<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class Aggregate
{
    /** Pass the visitor's request, or null from queued jobs, webhooks and scheduled tasks. */
    public static function send(?Request $visitor, string $eventName, string $pagePath, array $fields = []): void
    {
        $payload = [
            'websiteToken' => config('services.aggregate.website_token'),
            'eventName' => $eventName,
            'pagePath' => $pagePath,
        ] + $fields;
        $headers = ['Origin' => config('services.aggregate.site_origin')];

        if ($visitor) {
            // Reduced to a referrer channel and a device class; neither is stored.
            $payload['referrer'] = (string) $visitor->headers->get('Referer', '');
            $headers['User-Agent'] = (string) $visitor->userAgent();
        } else {
            $payload += ['referrerChannel' => 'unknown', 'deviceClass' => 'unknown'];
        }

        try {
            Http::withHeaders($headers)->connectTimeout(1)->timeout(2)
                ->post(config('services.aggregate.endpoint'), $payload);
        } catch (\Throwable) {
            // Analytics must never break the site.
        }
    }

    public static function isPrefetch(Request $request): bool
    {
        return str_contains(strtolower($request->header('Sec-Purpose', '').$request->header('Purpose', '')), 'prefetch');
    }
}
```

```php
<?php

namespace App\Http\Middleware;

use App\Support\Aggregate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Counts HTML page views after the response has been sent to the visitor. */
class AggregatePageViews
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->isMethod('GET') && $response->getStatusCode() === 200
            && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')
            && ! Aggregate::isPrefetch($request)) {
            Aggregate::send($request, 'view', $request->getPathInfo());
        }
    }
}
```

Add the settings to `config/services.php`, and the middleware to the `web` group
in `bootstrap/app.php` (Laravel 11 or newer):

```php
// config/services.php
'aggregate' => [
    'endpoint' => env('AGGREGATE_ENDPOINT'),
    'website_token' => env('AGGREGATE_WEBSITE_TOKEN'),
    'site_origin' => env('AGGREGATE_SITE_ORIGIN'),
],

// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: \App\Http\Middleware\AggregatePageViews::class);
})
```

For a goal, call the helper where the action completes:

```php
Aggregate::send($request, 'signup_completed', '/signup', ['goalEvent' => 'signup']);
```

### Symfony

An event listener that counts page views on `kernel.terminate`, which runs after
the response has been sent, and sends other events for your controllers and
message handlers. It uses the HttpClient component (`composer require
symfony/http-client`) and reads the three environment variables.

```php
<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Counts HTML page views after the response is sent, and sends other events. */
#[AsEventListener]
final class AggregateEvents
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(env: 'AGGREGATE_ENDPOINT')] private readonly string $endpoint,
        #[Autowire(env: 'AGGREGATE_WEBSITE_TOKEN')] private readonly string $token,
        #[Autowire(env: 'AGGREGATE_SITE_ORIGIN')] private readonly string $origin,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$request->isMethod('GET') || $response->getStatusCode() !== 200
            || !str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')
            || str_contains(strtolower($request->headers->get('Sec-Purpose', '').$request->headers->get('Purpose', '')), 'prefetch')) {
            return;
        }

        $this->send($request, 'view', $request->getPathInfo());
    }

    /** Also call this from a controller or message handler for goals. */
    public function send(?Request $visitor, string $eventName, string $pagePath, array $fields = []): void
    {
        $payload = ['websiteToken' => $this->token, 'eventName' => $eventName, 'pagePath' => $pagePath] + $fields;
        $headers = ['Origin' => $this->origin];
        if ($visitor !== null) {
            $payload['referrer'] = $visitor->headers->get('Referer', '');
            $headers['User-Agent'] = $visitor->headers->get('User-Agent', '');
        } else {
            $payload += ['referrerChannel' => 'unknown', 'deviceClass' => 'unknown'];
        }

        try {
            $this->http->request('POST', $this->endpoint, [
                'json' => $payload,
                'headers' => $headers,
                'timeout' => 1.5,
                'max_duration' => 2,
            ])->getStatusCode();
        } catch (\Throwable) {
            // Analytics must never break the site.
        }
    }
}
```

For a goal, inject the service into a controller:

```php
$aggregate->send($request, 'order_completed', '/checkout/complete', ['goalEvent' => 'purchase']);
```

### WordPress and WooCommerce

Save this as `wp-content/mu-plugins/aggregate-events.php` and fill in the three
constants. It counts front-end page views after the page has been sent, and paid
WooCommerce orders as the `purchase` goal.

```php
<?php
/**
 * Plugin Name: Aggregate server-side events
 * Description: Counts page views and WooCommerce purchases from the server.
 */

defined('ABSPATH') || exit;

const AGGREGATE_ENDPOINT = 'https://analytics.example.com/api/receive';
const AGGREGATE_WEBSITE_TOKEN = 'REPLACE_WITH_PUBLIC_WEBSITE_TOKEN';
const AGGREGATE_SITE_ORIGIN = 'https://www.example.com';

function aggregate_send(string $event_name, string $page_path, array $fields = [], bool $visitor = true): void
{
    $payload = ['websiteToken' => AGGREGATE_WEBSITE_TOKEN, 'eventName' => $event_name, 'pagePath' => $page_path] + $fields;
    $user_agent = '';
    if ($visitor) {
        // Reduced to a referrer channel and a device class; neither is stored.
        $payload['referrer'] = wp_unslash($_SERVER['HTTP_REFERER'] ?? '');
        $user_agent = wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '');
    } else {
        $payload += ['referrerChannel' => 'unknown', 'deviceClass' => 'unknown'];
    }

    // Failures come back as a WP_Error and are deliberately ignored.
    wp_remote_post(AGGREGATE_ENDPOINT, [
        'headers' => ['Content-Type' => 'application/json', 'Origin' => AGGREGATE_SITE_ORIGIN],
        'user-agent' => $user_agent,
        'body' => wp_json_encode($payload),
        'timeout' => 2,
    ]);
}

// Page views: front-end pages only, sent after the visitor has the page.
add_action('template_redirect', static function (): void {
    $purpose = strtolower(($_SERVER['HTTP_SEC_PURPOSE'] ?? '').($_SERVER['HTTP_PURPOSE'] ?? ''));
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || is_feed() || is_preview() || str_contains($purpose, 'prefetch')) {
        return;
    }
    add_action('shutdown', static function (): void {
        if (http_response_code() !== 200) {
            return; // Redirects, errors and "not found" pages.
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        aggregate_send('view', wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    });
});

// WooCommerce: a paid order counts as a purchase. Payment gateways often confirm
// payment in their own request, so this is not the visitor's request.
add_action('woocommerce_payment_complete', static function (): void {
    aggregate_send('order_paid', '/checkout/order-received', ['goalEvent' => 'purchase'], visitor: false);
});
```

WordPress page-cache plugins and hosting caches serve pages without running
PHP, so their page views are never counted. On a cached site, keep the browser
tracker for page views and use this plugin only for goals: remove the
`template_redirect` block.

### Java (Spring Boot)

`AggregateClient` uses only the JDK (Java 17 or newer) and sends asynchronously:

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.time.Duration;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.stream.Collectors;

/** Sends server-side events to Aggregate. Java 17 or newer, no dependencies. */
public final class AggregateClient {
    private final HttpClient http = HttpClient.newBuilder().connectTimeout(Duration.ofSeconds(1)).build();
    private final URI endpoint;
    private final String websiteToken;
    private final String siteOrigin;

    public AggregateClient(String endpoint, String websiteToken, String siteOrigin) {
        this.endpoint = URI.create(endpoint);
        this.websiteToken = websiteToken;
        this.siteOrigin = siteOrigin;
    }

    /** The visitor's request headers; Aggregate keeps only a referrer channel and a device class. */
    public record Visitor(String referer, String userAgent) {}

    /**
     * Sends one event without waiting for it. Pass the visitor for events a page
     * load or form post causes, or null from queues and webhooks. fields adds
     * optional values such as goalEvent.
     */
    public void send(String eventName, String pagePath, Map<String, String> fields, Visitor visitor) {
        Map<String, String> payload = new LinkedHashMap<>();
        payload.put("websiteToken", websiteToken);
        payload.put("eventName", eventName);
        payload.put("pagePath", pagePath);
        payload.putAll(fields);
        HttpRequest.Builder request = HttpRequest.newBuilder(endpoint)
                .timeout(Duration.ofSeconds(2))
                .header("Content-Type", "application/json")
                .header("Origin", siteOrigin);
        if (visitor != null) {
            payload.put("referrer", visitor.referer() == null ? "" : visitor.referer());
            request.header("User-Agent", visitor.userAgent() == null ? "" : visitor.userAgent());
        } else {
            payload.put("referrerChannel", "unknown");
            payload.put("deviceClass", "unknown");
        }
        String json = payload.entrySet().stream()
                .map(e -> quote(e.getKey()) + ":" + quote(e.getValue()))
                .collect(Collectors.joining(",", "{", "}"));

        http.sendAsync(request.POST(HttpRequest.BodyPublishers.ofString(json)).build(),
                        HttpResponse.BodyHandlers.discarding())
                .exceptionally(error -> null); // Analytics must never break the site.
    }

    /** Browsers prefetch pages that the visitor may never open. */
    public static boolean isPrefetch(String secPurpose, String purpose) {
        return (String.valueOf(secPurpose) + purpose).toLowerCase().contains("prefetch");
    }

    private static String quote(String value) {
        StringBuilder out = new StringBuilder("\"");
        for (char c : value.toCharArray()) {
            switch (c) {
                case '"' -> out.append("\\\"");
                case '\\' -> out.append("\\\\");
                default -> out.append(c < 0x20 ? String.format("\\u%04x", (int) c) : String.valueOf(c));
            }
        }
        return out.append('"').toString();
    }
}
```

A Spring Boot 3 filter counts page views, and a bean supplies the settings:

```java
import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.io.IOException;
import java.util.Map;
import org.springframework.stereotype.Component;
import org.springframework.web.filter.OncePerRequestFilter;

/** Counts successful HTML page views (Spring Boot 3). */
@Component
public class AggregatePageViewFilter extends OncePerRequestFilter {
    private final AggregateClient aggregate;

    public AggregatePageViewFilter(AggregateClient aggregate) {
        this.aggregate = aggregate;
    }

    @Override
    protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response, FilterChain chain)
            throws ServletException, IOException {
        chain.doFilter(request, response);
        String contentType = response.getContentType();
        if ("GET".equals(request.getMethod()) && response.getStatus() == 200
                && contentType != null && contentType.startsWith("text/html")
                && !AggregateClient.isPrefetch(request.getHeader("Sec-Purpose"), request.getHeader("Purpose"))) {
            aggregate.send("view", request.getRequestURI(), Map.of(), visitor(request));
        }
    }

    /** Use this in controllers too, for goals. */
    public static AggregateClient.Visitor visitor(HttpServletRequest request) {
        return new AggregateClient.Visitor(request.getHeader("Referer"), request.getHeader("User-Agent"));
    }
}
```

```java
@Bean
AggregateClient aggregateClient(@Value("${aggregate.endpoint}") String endpoint,
        @Value("${aggregate.website-token}") String websiteToken,
        @Value("${aggregate.site-origin}") String siteOrigin) {
    return new AggregateClient(endpoint, websiteToken, siteOrigin);
}
```

For a goal, in a controller:

```java
aggregate.send("order_completed", "/checkout/complete", Map.of("goalEvent", "purchase"),
        AggregatePageViewFilter.visitor(request));
```

Pass `null` as the visitor from scheduled jobs and webhooks.

### .NET (ASP.NET Core)

A typed `HttpClient` and a middleware that sends page views once the response has
completed (.NET 8 or newer):

```csharp
using System.Net.Http.Json;

/// <summary>Sends server-side events to Aggregate. Register with AddHttpClient&lt;AggregateClient&gt;().</summary>
public sealed class AggregateClient(HttpClient http, IConfiguration config)
{
    /// <summary>
    /// Sends one event. Pass the visitor's request for events a page load or form
    /// post causes, or null from queues and webhooks. Failures are ignored.
    /// </summary>
    public async Task SendAsync(string eventName, string pagePath, HttpRequest? visitor,
        IDictionary<string, string>? fields = null)
    {
        var payload = new Dictionary<string, string>(fields ?? new Dictionary<string, string>())
        {
            ["websiteToken"] = config["Aggregate:WebsiteToken"]!,
            ["eventName"] = eventName,
            ["pagePath"] = pagePath,
        };
        using var request = new HttpRequestMessage(HttpMethod.Post, config["Aggregate:Endpoint"]);
        request.Headers.TryAddWithoutValidation("Origin", config["Aggregate:SiteOrigin"]);
        if (visitor is not null)
        {
            // Reduced to a referrer channel and a device class; neither is stored.
            payload["referrer"] = visitor.Headers.Referer.ToString();
            request.Headers.TryAddWithoutValidation("User-Agent", visitor.Headers.UserAgent.ToString());
        }
        else
        {
            payload["referrerChannel"] = "unknown";
            payload["deviceClass"] = "unknown";
        }
        request.Content = JsonContent.Create(payload);

        try
        {
            using var response = await http.SendAsync(request);
        }
        catch (Exception)
        {
            // Analytics must never break the site.
        }
    }

    public static bool IsPrefetch(HttpRequest request) =>
        $"{request.Headers["Sec-Purpose"]}{request.Headers["Purpose"]}".Contains("prefetch", StringComparison.OrdinalIgnoreCase);
}

/// <summary>Counts successful HTML page views once the response has been sent.</summary>
public sealed class AggregatePageViewMiddleware(RequestDelegate next)
{
    public async Task InvokeAsync(HttpContext context, AggregateClient aggregate)
    {
        await next(context);
        var (request, response) = (context.Request, context.Response);
        if (HttpMethods.IsGet(request.Method) && response.StatusCode == 200
            && response.ContentType?.StartsWith("text/html") == true
            && !AggregateClient.IsPrefetch(request))
        {
            var path = request.Path.Value ?? "/";
            response.OnCompleted(() => aggregate.SendAsync("view", path, request));
        }
    }
}
```

Register both in `Program.cs`, and put the settings in `appsettings.json` or
environment variables (`Aggregate__Endpoint`, `Aggregate__WebsiteToken` and
`Aggregate__SiteOrigin`):

```csharp
builder.Services.AddHttpClient<AggregateClient>(client => client.Timeout = TimeSpan.FromSeconds(2));
// ...
app.UseMiddleware<AggregatePageViewMiddleware>();
```

```json
{
  "Aggregate": {
    "Endpoint": "https://analytics.example.com/api/receive",
    "WebsiteToken": "REPLACE_WITH_PUBLIC_WEBSITE_TOKEN",
    "SiteOrigin": "https://www.example.com"
  }
}
```

For a goal, in an endpoint or controller:

```csharp
await aggregate.SendAsync("signup_completed", "/signup", request,
    new Dictionary<string, string> { ["goalEvent"] = "signup" });
```

### Node.js (Express)

`aggregate.mjs` uses the built-in `fetch` (Node.js 18 or newer). `sendEvent`
works with any framework; `pageViews` is Express middleware that sends after the
response has finished.

```javascript
// aggregate.mjs: send server-side events to Aggregate (Node.js 18 or newer).
const { AGGREGATE_ENDPOINT, AGGREGATE_WEBSITE_TOKEN, AGGREGATE_SITE_ORIGIN } = process.env;

/**
 * Sends one event. visitor is { referer, userAgent } from the visitor's request
 * headers, or null from queues and webhooks. Resolves when done; never rejects.
 */
export function sendEvent(visitor, eventName, pagePath, fields = {}) {
  const payload = { websiteToken: AGGREGATE_WEBSITE_TOKEN, eventName, pagePath, ...fields };
  const headers = { 'Content-Type': 'application/json', Origin: AGGREGATE_SITE_ORIGIN };
  if (visitor) {
    // Reduced to a referrer channel and a device class; neither is stored.
    payload.referrer = visitor.referer ?? '';
    headers['User-Agent'] = visitor.userAgent ?? '';
  } else {
    Object.assign(payload, { referrerChannel: 'unknown', deviceClass: 'unknown' });
  }

  return fetch(AGGREGATE_ENDPOINT, {
    method: 'POST',
    headers,
    body: JSON.stringify(payload),
    signal: AbortSignal.timeout(2000),
  }).then(() => {}, () => {}); // Analytics must never break the site.
}

/** The visitor details Aggregate uses, from an Express request. */
export const visitorOf = (req) => ({ referer: req.get('Referer'), userAgent: req.get('User-Agent') });

/** Express middleware: counts HTML page views once the response is sent. */
export function pageViews(req, res, next) {
  res.on('finish', () => {
    const purpose = `${req.get('Sec-Purpose') ?? ''}${req.get('Purpose') ?? ''}`.toLowerCase();
    if (req.method === 'GET' && res.statusCode === 200
        && (res.get('Content-Type') ?? '').startsWith('text/html') && !purpose.includes('prefetch')) {
      sendEvent(visitorOf(req), 'view', req.path);
    }
  });
  next();
}
```

```javascript
import { pageViews, sendEvent, visitorOf } from './aggregate.mjs';

app.use(pageViews);

app.post('/signup', async (req, res) => {
  // After the account is created:
  sendEvent(visitorOf(req), 'signup_completed', '/signup', { goalEvent: 'signup' });
  res.redirect(303, '/welcome');
});
```

In other frameworks, pass `{ referer, userAgent }` from the request headers. In
serverless functions, `await` the promise `sendEvent` returns.

### Python (Django, Flask, FastAPI)

`aggregate.py` uses only the standard library and sends from a small thread pool,
so a slow analytics server never delays a response:

```python
"""Send server-side events to Aggregate. Standard library only."""
import json
import os
import urllib.request
from concurrent.futures import ThreadPoolExecutor

ENDPOINT = os.environ["AGGREGATE_ENDPOINT"]
WEBSITE_TOKEN = os.environ["AGGREGATE_WEBSITE_TOKEN"]
SITE_ORIGIN = os.environ["AGGREGATE_SITE_ORIGIN"]

# Sends happen in the background, so a slow analytics server never delays a page.
_senders = ThreadPoolExecutor(max_workers=4)


def send_event(event_name, page_path, *, referrer=None, user_agent=None, visitor=True, **fields):
    """Send one event. Use visitor=False from queues, webhooks and scheduled jobs."""
    payload = {"websiteToken": WEBSITE_TOKEN, "eventName": event_name, "pagePath": page_path, **fields}
    headers = {"Content-Type": "application/json", "Origin": SITE_ORIGIN}
    if visitor:
        # Reduced to a referrer channel and a device class; neither is stored.
        payload["referrer"] = referrer or ""
        headers["User-Agent"] = user_agent or ""
    else:
        payload.update(referrerChannel="unknown", deviceClass="unknown")
    request = urllib.request.Request(
        ENDPOINT, data=json.dumps(payload).encode(), headers=headers, method="POST"
    )
    _senders.submit(_post, request)


def _post(request):
    try:
        urllib.request.urlopen(request, timeout=2).close()
    except Exception:
        pass  # Analytics must never break the site.
```

Django middleware for page views. Save both files in one of your apps, such as
`yourapp/aggregate.py` and `yourapp/middleware.py`, and add
`"yourapp.middleware.AggregatePageViewMiddleware"` to `MIDDLEWARE` in `settings.py`:

```python
"""Django middleware: counts HTML page views. Add it to MIDDLEWARE in settings.py."""
from .aggregate import send_event


class AggregatePageViewMiddleware:
    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        response = self.get_response(request)
        purpose = (request.headers.get("Sec-Purpose", "") + request.headers.get("Purpose", "")).lower()
        if (
            request.method == "GET"
            and response.status_code == 200
            and response.get("Content-Type", "").startswith("text/html")
            and "prefetch" not in purpose
        ):
            send_event(
                "view",
                request.path,
                referrer=request.headers.get("Referer"),
                user_agent=request.headers.get("User-Agent"),
            )
        return response
```

For a goal, in any framework:

```python
send_event(
    "signup_completed",
    "/signup",
    referrer=request.headers.get("Referer"),
    user_agent=request.headers.get("User-Agent"),
    goalEvent="signup",
)
```

Flask and FastAPI requests have the same `headers`. From a Celery task or another
background job, pass `visitor=False` instead of the two headers.

### Ruby (Rails and Rack)

`aggregate.rb` uses only the standard library and includes Rack middleware for
page views:

```ruby
# Sends server-side events to Aggregate. Ruby standard library only.
require "json"
require "net/http"

module Aggregate
  ENDPOINT = URI(ENV.fetch("AGGREGATE_ENDPOINT"))

  # Sends one event in the background. Pass the visitor's Rack env for events a
  # page load or form post causes, or nil from jobs and webhooks.
  def self.send_event(env, event_name, page_path, **fields)
    payload = { websiteToken: ENV.fetch("AGGREGATE_WEBSITE_TOKEN"), eventName: event_name, pagePath: page_path, **fields }
    headers = { "Content-Type" => "application/json", "Origin" => ENV.fetch("AGGREGATE_SITE_ORIGIN") }
    if env
      # Reduced to a referrer channel and a device class; neither is stored.
      payload[:referrer] = env["HTTP_REFERER"].to_s
      headers["User-Agent"] = env["HTTP_USER_AGENT"].to_s
    else
      payload.merge!(referrerChannel: "unknown", deviceClass: "unknown")
    end

    Thread.new do
      Net::HTTP.start(ENDPOINT.host, ENDPOINT.port, use_ssl: ENDPOINT.scheme == "https",
                      open_timeout: 1, read_timeout: 2) do |http|
        http.post(ENDPOINT.path, payload.to_json, headers)
      end
    rescue StandardError
      nil # Analytics must never break the site.
    end
  end

  # Rack middleware (Rails: config.middleware.use Aggregate::PageViews).
  class PageViews
    def initialize(app)
      @app = app
    end

    def call(env)
      status, headers, body = @app.call(env)
      content_type = (headers["content-type"] || headers["Content-Type"]).to_s
      purpose = "#{env['HTTP_SEC_PURPOSE']}#{env['HTTP_PURPOSE']}".downcase
      if env["REQUEST_METHOD"] == "GET" && status.to_i == 200 &&
         content_type.start_with?("text/html") && !purpose.include?("prefetch")
        Aggregate.send_event(env, "view", env["SCRIPT_NAME"].to_s + env["PATH_INFO"].to_s)
      end
      [status, headers, body]
    end
  end
end
```

In Rails, save it as `lib/aggregate.rb` and add the middleware in
`config/application.rb`:

```ruby
require_relative "../lib/aggregate"

module YourApp
  class Application < Rails::Application
    config.middleware.use Aggregate::PageViews
  end
end
```

For a goal, in a controller:

```ruby
Aggregate.send_event(request.env, "booking_confirmed", "/booking/complete", goalEvent: "booking")
```

From a background job, pass `nil` instead of `request.env`.

### Go

`aggregate.go` uses only the standard library. `PageViews` wraps any
`http.Handler`, and `Send` posts in a goroutine:

```go
// Package aggregate sends server-side events to Aggregate. Standard library only.
package aggregate

import (
	"bytes"
	"encoding/json"
	"net/http"
	"os"
	"strings"
	"time"
)

var client = &http.Client{Timeout: 2 * time.Second}

// Send posts one event in the background. Pass the visitor's request for events
// a page load or form post causes, or nil from queues and webhooks. fields adds
// optional payload fields such as "goalEvent".
func Send(visitor *http.Request, eventName, pagePath string, fields map[string]any) {
	payload := map[string]any{
		"websiteToken": os.Getenv("AGGREGATE_WEBSITE_TOKEN"),
		"eventName":    eventName,
		"pagePath":     pagePath,
	}
	for key, value := range fields {
		payload[key] = value
	}
	userAgent := ""
	if visitor != nil {
		// Reduced to a referrer channel and a device class; neither is stored.
		payload["referrer"] = visitor.Referer()
		userAgent = visitor.UserAgent()
	} else {
		payload["referrerChannel"], payload["deviceClass"] = "unknown", "unknown"
	}
	body, _ := json.Marshal(payload)

	go func() {
		request, err := http.NewRequest(http.MethodPost, os.Getenv("AGGREGATE_ENDPOINT"), bytes.NewReader(body))
		if err != nil {
			return
		}
		request.Header.Set("Content-Type", "application/json")
		request.Header.Set("Origin", os.Getenv("AGGREGATE_SITE_ORIGIN"))
		request.Header.Set("User-Agent", userAgent)
		if response, err := client.Do(request); err == nil {
			response.Body.Close()
		} // Analytics must never break the site: errors are ignored.
	}()
}

// statusRecorder notes the status and content type a handler sends.
type statusRecorder struct {
	http.ResponseWriter
	status      int
	contentType string
	wrote       bool
}

func (r *statusRecorder) WriteHeader(status int) {
	if !r.wrote {
		r.wrote, r.status, r.contentType = true, status, r.Header().Get("Content-Type")
	}
	r.ResponseWriter.WriteHeader(status)
}

func (r *statusRecorder) Write(body []byte) (int, error) {
	if !r.wrote {
		r.WriteHeader(http.StatusOK)
	}
	if r.contentType == "" {
		r.contentType = http.DetectContentType(body) // What net/http sends when unset.
	}
	return r.ResponseWriter.Write(body)
}

// Unwrap lets http.ResponseController reach the original writer.
func (r *statusRecorder) Unwrap() http.ResponseWriter { return r.ResponseWriter }

// PageViews wraps a handler and counts successful HTML page views.
func PageViews(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		recorder := &statusRecorder{ResponseWriter: w, status: http.StatusOK}
		next.ServeHTTP(recorder, r)
		purpose := strings.ToLower(r.Header.Get("Sec-Purpose") + r.Header.Get("Purpose"))
		if r.Method == http.MethodGet && recorder.status == http.StatusOK &&
			strings.HasPrefix(recorder.contentType, "text/html") &&
			!strings.Contains(purpose, "prefetch") {
			Send(r, "view", r.URL.Path, nil)
		}
	})
}
```

```go
mux := http.NewServeMux()
// ... your routes ...
http.ListenAndServe(":8080", aggregate.PageViews(mux))

// After an order is saved:
aggregate.Send(r, "order_completed", "/checkout/complete", map[string]any{"goalEvent": "purchase"})
```

Pass `nil` instead of the request from background work.

## Test with curl

Send one event by hand from your web server to check the token, the `Origin`
rules and the network path:

```bash
curl -i https://analytics.example.com/api/receive \
  -H 'Content-Type: application/json' \
  -H 'Origin: https://www.example.com' \
  -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' \
  -d '{"websiteToken": "REPLACE_WITH_PUBLIC_WEBSITE_TOKEN", "eventName": "view", "pagePath": "/server-side-test"}'
```

A `202` response with `{"status":"recorded","mode":"anonymous"}` means it worked.
The event appears in `bi_anonymous_events_v1` after its UTC hour has ended, and
only if its cell reaches the minimum count, so a single test event is expected to
stay hidden there.

## Limits

- **No sender authentication yet.** Like the browser tracker, server-side
  collection uses the public website token, so anyone who knows it can send
  events. An authenticated key for server callers is a
  [roadmap proposal](../ROADMAP.md#proposals-to-explore).
- **Viewport is always `unknown`**, because only the browser knows it.
- **Anonymous mode only.** These examples never send consented detail; use the
  browser tracker for [enhanced analytics](PRIVACY-COMPLIANCE.md#enhanced-analytics-consent).
- **Page views need care.** Caches, crawlers and prefetches change what your
  server sees; see [what to count as a page view](#what-to-count-as-a-page-view).
