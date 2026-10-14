// k6 step test for the collection endpoint, POST /api/receive.
//
// Sends synthetic page views at a series of fixed request rates, one stage
// after another, and records latency, failures and dropped requests for each
// stage. Run it only against a disposable installation: every request stores
// a row. See docs/LOAD-TESTING.md.
//
//   k6 run -e BASE_URL=https://analytics.test -e WEBSITE_TOKEN=... \
//          -e ORIGIN=https://loadtest.example tests/Load/receive.js
//
// Settings (all optional except WEBSITE_TOKEN):
//   BASE_URL       Installation address (default http://127.0.0.1).
//   WEBSITE_TOKEN  Public token of a website registered for the test.
//   ORIGIN         Origin header; must pass the website's domain rules.
//   MODE           anonymous (default), enhanced (consent granted) or mixed.
//   RATES          Comma-separated requests per second, one stage each.
//   STAGE_SECONDS  Length of each stage (default 30).
//   GAP_SECONDS    Pause between stages so a backlog can drain (default 10).
//   WARMUP_SECONDS Unreported warm-up at 20 requests per second before the
//                  first stage, so every PHP worker has served a request and
//                  opened its database connection (default 10; 0 skips it).
//   VISITORS       Simulated client addresses (default 20000, at most 131072).
//   SPOOF_CLIENTS  1 (default) sends X-Forwarded-For from the benchmarking
//                  range 198.18.0.0/15, so per-address rate limiting behaves as
//                  with real visitors. Requires TRUSTED_PROXIES to include the
//                  proxy in front of PHP; never enable that on a public server
//                  for a test. 0 sends every request from the load generator.
//   MAX_VUS        Upper bound on concurrent virtual users (default 1000).
//   SUMMARY_FILE   Where to write the JSON summary (default k6-summary.json).

import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const BASE_URL = (__ENV.BASE_URL || 'http://127.0.0.1').replace(/\/+$/, '');
const TOKEN = __ENV.WEBSITE_TOKEN || '';
const ORIGIN = __ENV.ORIGIN || 'https://loadtest.example';
const MODE = __ENV.MODE || 'anonymous';
const RATES = (__ENV.RATES || '50,100,125,150,175,200,225,250,300,350')
  .split(',')
  .map((value) => Number(value.trim()))
  .filter((value) => Number.isFinite(value) && value > 0);
const STAGE_SECONDS = Number(__ENV.STAGE_SECONDS || 30);
const GAP_SECONDS = Number(__ENV.GAP_SECONDS || 10);
const WARMUP_SECONDS = Number(__ENV.WARMUP_SECONDS ?? 10);
const OFFSET_SECONDS = WARMUP_SECONDS > 0 ? WARMUP_SECONDS + GAP_SECONDS : 0;
const VISITORS = Math.min(Number(__ENV.VISITORS || 20000), 131072);
const SPOOF_CLIENTS = (__ENV.SPOOF_CLIENTS || '1') === '1';
const MAX_VUS = Number(__ENV.MAX_VUS || 1000);
const SUMMARY_FILE = __ENV.SUMMARY_FILE || 'k6-summary.json';

if (TOKEN === '') {
  throw new Error('Set WEBSITE_TOKEN to the public token of a test website.');
}
if (!['anonymous', 'enhanced', 'mixed'].includes(MODE)) {
  throw new Error('MODE must be anonymous, enhanced or mixed.');
}
if (RATES.length === 0) {
  throw new Error('RATES must list at least one request rate.');
}

const PAGES = [
  '/', '/pricing', '/about', '/contact', '/blog/', '/blog/getting-started',
  '/blog/release-notes', '/docs/install', '/docs/configuration',
  '/products/widget', '/products/gadget', '/checkout', '/checkout/complete',
];
const CHANNELS = ['direct', 'search', 'search', 'social', 'referral', 'email', 'internal'];
const DEVICES = [['desktop', 'large', 1440], ['mobile', 'small', 390], ['tablet', 'medium', 820]];
const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

// Response classes, counted across the whole run to explain failures.
const STATUS_CLASSES = ['202', '400', '403', '429', '4xx', '500', '502', '503', '504', '5xx', 'none', 'other'];
const statusCounters = Object.fromEntries(STATUS_CLASSES.map((name) => [name, new Counter(`status_${name}`)]));

function stageName(index, rate) {
  return `stage_${String(index).padStart(2, '0')}_${rate}rps`;
}

const scenarios = {};
const thresholds = {};
if (WARMUP_SECONDS > 0) {
  scenarios.warmup = {
    executor: 'constant-arrival-rate',
    exec: 'send',
    rate: 20,
    timeUnit: '1s',
    duration: `${WARMUP_SECONDS}s`,
    preAllocatedVUs: 20,
    maxVUs: Math.min(100, MAX_VUS),
    startTime: '0s',
    gracefulStop: `${GAP_SECONDS}s`,
  };
}
RATES.forEach((rate, index) => {
  const name = stageName(index, rate);
  scenarios[name] = {
    executor: 'constant-arrival-rate',
    exec: 'send',
    rate,
    timeUnit: '1s',
    duration: `${STAGE_SECONDS}s`,
    // k6 reuses VUs across stages that do not overlap, so this is cheap.
    preAllocatedVUs: Math.min(Math.max(rate, 20), MAX_VUS),
    maxVUs: MAX_VUS,
    startTime: `${OFFSET_SECONDS + index * (STAGE_SECONDS + GAP_SECONDS)}s`,
    gracefulStop: `${GAP_SECONDS}s`,
  };
  // Always-true thresholds make k6 keep per-stage results in the summary.
  thresholds[`http_req_duration{scenario:${name}}`] = ['max>=0'];
  thresholds[`http_req_failed{scenario:${name}}`] = ['rate>=0'];
  thresholds[`http_reqs{scenario:${name}}`] = ['count>=0'];
  thresholds[`checks{scenario:${name}}`] = ['rate>=0'];
  thresholds[`dropped_iterations{scenario:${name}}`] = ['count>=0'];
});

export const options = {
  scenarios,
  thresholds,
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  discardResponseBodies: false,
  noConnectionReuse: false,
  userAgent: USER_AGENT,
};

function pick(list) {
  return list[Math.floor(Math.random() * list.length)];
}

function clientAddress(visitor) {
  return `198.${18 + (visitor >> 16)}.${(visitor >> 8) & 255}.${visitor & 255}`;
}

function statusClass(status) {
  const exact = String(status);
  if (STATUS_CLASSES.includes(exact)) {
    return exact;
  }
  if (status === 0) {
    return 'none';
  }
  if (status >= 400 && status < 500) {
    return '4xx';
  }
  if (status >= 500) {
    return '5xx';
  }
  return 'other';
}

export function send() {
  const visitor = Math.floor(Math.random() * VISITORS);
  const [deviceClass, viewportBucket, screenWidth] = pick(DEVICES);
  const granted = MODE === 'enhanced' || (MODE === 'mixed' && Math.random() < 0.3);

  const event = {
    websiteToken: TOKEN,
    eventName: 'view',
    pagePath: pick(PAGES),
    referrerChannel: pick(CHANNELS),
    deviceClass,
    viewportBucket,
    consentState: granted ? 'granted' : 'denied',
  };
  if (granted) {
    event.visitorId = `loadtest-visitor-${visitor}`;
    event.sessionId = `loadtest-session-${visitor}-${Math.floor(Date.now() / 1800000)}`;
    event.screenWidth = screenWidth;
  }

  const headers = { 'Content-Type': 'application/json', Origin: ORIGIN };
  if (SPOOF_CLIENTS) {
    headers['X-Forwarded-For'] = clientAddress(visitor);
  }

  const response = http.post(`${BASE_URL}/api/receive`, JSON.stringify(event), {
    headers,
    timeout: '10s',
  });
  statusCounters[statusClass(response.status)].add(1);

  // 202 alone is not enough: ignored events also answer 202.
  const expected = granted ? '"accepted"' : '"recorded"';
  check(response, {
    stored: (r) => r.status === 202 && typeof r.body === 'string' && r.body.includes(expected),
  });
}

export function handleSummary(data) {
  const summary = {
    ...data,
    aggregate_load_test: {
      ended_at_ms: Date.now(),
      mode: MODE,
      rates: RATES,
      stage_seconds: STAGE_SECONDS,
      gap_seconds: GAP_SECONDS,
      offset_seconds: OFFSET_SECONDS,
      visitors: VISITORS,
      spoof_clients: SPOOF_CLIENTS,
      max_vus: MAX_VUS,
      stages: RATES.map((rate, index) => ({ name: stageName(index, rate), rate, index })),
    },
  };

  return {
    [SUMMARY_FILE]: JSON.stringify(summary, null, 2),
    stdout: `k6 finished: ${RATES.length} stages in ${MODE} mode; summary written to ${SUMMARY_FILE}\n`,
  };
}
