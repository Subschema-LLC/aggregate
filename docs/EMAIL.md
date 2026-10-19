# Transactional email

[README](../README.md) · [Configuration](CONFIGURATION.md) · [Audit trail](AUDIT-TRAIL.md)

Aggregate sends a small number of messages to its own users, such as
[sign-in codes](#sign-in-codes), through an email service you choose. It never
emails website visitors or analytics data. Email is off until an administrator
turns it on.

- [Choose a service](#choose-a-service)
- [Set it up on the Email page](#set-it-up-on-the-email-page)
- [Set it up in YAML](#set-it-up-in-yaml)
- [Send a test message](#send-a-test-message)
- [Sign-in codes](#sign-in-codes)
- [What is recorded](#what-is-recorded)
- [What a message carries](#what-a-message-carries)
- [Another service, or failover](#another-service-or-failover)
- [Add a provider](#add-a-provider)
- [Troubleshooting](#troubleshooting)

## Choose a service

| Service | `email_provider` | Credential (`config/secrets/email.yaml` key) | Its own settings |
| --- | --- | --- | --- |
| Postmark | `postmark` | Server API token (`postmark_server_token`) | `email_postmark_message_stream` (default `outbound`) |
| SendGrid | `sendgrid` | API key with Mail Send (`sendgrid_api_key`) | `email_sendgrid_region`: `global` (default) or `eu` |
| Mailgun | `mailgun` | Sending API key or private API key (`mailgun_api_key`) | `email_mailgun_domain` (required), `email_mailgun_region`: `us` (default) or `eu` |
| Any SMTP server | `smtp` | Password, optional (`smtp_password`) | `email_smtp_host`, `email_smtp_port` (587), `email_smtp_username`, `email_smtp_encryption` |
| Another service | `dsn` | A mailer DSN (`dsn`) | None; see [another service](#another-service-or-failover) |

Postmark, SendGrid and Mailgun are reached over their HTTPS APIs, which work on
hosts that block outgoing SMTP ports. Each uses Symfony Mailer's own bridge for
that service, shipped with Aggregate.

`email_smtp_encryption` is `starttls` (the default: the server must offer
STARTTLS, or nothing is sent), `tls` (TLS from the start, usually port 465) or
`none`, which is meant only for a relay on the same server. Use the SMTP choice
for your organization's mail server or for a service's SMTP relay.

Before sending, verify the from address or its domain with the service; each
refuses mail from an unverified sender.

## Set it up on the Email page

**Administration → Email** at `/dashboard/email` is for administrators only,
because a credential sends mail as your organization and messages carry
sign-in codes.

1. Choose the **service** and enter the **from address**. The from name
   defaults to the [brand name](CONFIGURATION.md#application-settings); a
   reply-to address is optional.
2. Enter the service's settings and its credential, then **Save email settings**.
   A saved credential is never shown again: leave the field empty to keep it,
   enter a new one to replace it, or tick **Remove** to delete it.
3. **Send a test email** to your own address (add one to your profile first).
4. Set **Email** to **On** and save.

The page shows whether email can be sent and, if not, what is missing, and the
last attempt and last success for each kind of message.

Credentials are stored in `config/secrets/email.yaml`, readable only by the
application's user. Updates keep the file and release packages never contain
it. Settings are written to `config/aggregate.yaml`, which therefore never holds
a key.

## Set it up in YAML

Everything the page does works without the dashboard. In
`config/aggregate.yaml` (all keys are in
[config/aggregate.yaml.example](../config/aggregate.yaml.example)):

```yaml
email_enabled: true
email_provider: postmark            # postmark, sendgrid, mailgun, smtp or dsn
email_from_address: no-reply@example.com
email_from_name: ''                 # empty: the brand name
email_reply_to: ''
email_postmark_message_stream: outbound
```

Then put the credential in `config/secrets/email.yaml`, readable only by the
application's user (`chmod 600`):

```yaml
postmark_server_token: 'your-server-token'
```

Every setting has an uppercase environment variable of the same name, such as
`EMAIL_PROVIDER` or `EMAIL_SMTP_HOST`, which takes precedence over YAML and
locks the field on the page. Each credential can also come from the
environment, as `EMAIL_` plus its key in capitals: `EMAIL_POSTMARK_SERVER_TOKEN`,
`EMAIL_SENDGRID_API_KEY`, `EMAIL_MAILGUN_API_KEY`, `EMAIL_SMTP_PASSWORD` or
`EMAIL_DSN`. An environment variable wins over the file, and the page cannot
change it.

`app:email:status` shows the service, the sender, which credentials are set and
where (never their values), what stops a send, and the last sends; with
`--json` it prints the same for monitoring, and it exits with a failure while
nothing can be sent.

## Send a test message

```bash
php bin/console app:email:test you@example.com
php bin/console app:email:test admin          # a username with an email address
```

A test is sent even while `email_enabled` is false, so the settings can be
checked before email is turned on; every other message waits until it is on.
The command prints the service's message ID, which finds the message in the
service's activity log. A failure prints the service's reason.

## Sign-in codes

With `sign_in_codes_enabled: true` (or **Sign-in codes: On** on the Email page,
or `SIGN_IN_CODES_ENABLED`), the sign-in page offers **Forgot your password?
Email me a sign-in code**. The user enters their username or email address
and gets a six-digit code at the address on their account, which signs them in
instead of their password. The option appears only while email can be sent,
and only users with an email address can use it; everyone keeps their password.

- **A code lasts 10 minutes, allows 5 tries and works once,** in the browser
  that asked for it. Asking again replaces it. An address that several users
  share matches none of them; those users sign in by username.
- **It doubles as a password reset.** For 15 minutes after signing in with a
  code, the profile lets the user set a new password without the current one.
- **The page never says whether an account exists.** It answers the same for
  any username or address, and the email is sent after the response, so its
  timing does not tell either.
- **Guessing is bounded.** Each code allows 5 tries; wrong codes also count
  against the sign-in throttling that protects passwords (5 failures a minute
  for an account from one address); and after 10 wrong codes in 24 hours from any browser,
  code sign-in pauses for that account, while its password still works. An
  account gets at most 3 codes in 15 minutes, and one address may ask 10 times
  in 15 minutes. The limits are in `config/packages/framework.yaml`.
- **Nothing is stored.** The code exists only in the email and, as an HMAC keyed
  with `APP_SECRET`, in the server-side session of the browser that asked:
  no table, no YAML. Servers behind a load balancer must share sessions,
  as signing in already requires.

Anyone who can read a user's email can sign in as that user, as with any
emailed password reset. Keep the option off where that is not acceptable, for
example when users' mailboxes are shared.

Each request for a code is recorded under **Sign-ins** in the audit trail (when
that area is on), naming the account only when it exists; each sign-in with a
code says so; each code sent is an `email_send` task with subject
`sign_in_code`, started by `request` for that account. The code itself is never
recorded.

## What is recorded

Each send is one row in `processing_tasks` and one entry in `audit_trail`
(see [task and audit records](DATABASE.md#processing-tasks-and-audit-trail)):

- **task type** `email_send`, **subject** the kind of message (`test`,
  `sign_in_code`), how it was started (`dashboard`, `command`, or `request` for
  a code asked for on the sign-in page) and by whom;
- **external ID** the service's message ID;
- **details** the service used, or the reason a send failed.

Neither records the recipient's address, the subject line, the content or a
code it carries. A failure reason is redacted before it is stored, logged or
shown: email addresses and the credential are removed from the service's
answer. The audit trail lists sends under
[Background job runs](AUDIT-TRAIL.md#what-is-recorded); saving the Email page
is recorded under **Integrations** with the names of the settings and
credentials that changed, never their values, and a test from the page or the
command line under **Manual runs**. Both tables are purged after their
[retention periods](CONFIGURATION.md#task-and-audit-records).

The application log records a failed send's redacted reason, its kind and its
task ID.

## What a message carries

Every message has a plain-text and an HTML part, from
`templates/email/<kind>.txt.twig` and `.html.twig`, with the brand name and no
images, remote resources or tracking pixels. It also carries:

- `Auto-Submitted: auto-generated`, so out-of-office responders stay quiet;
- the kind of message as a tag: a Postmark tag, a SendGrid category or a
  Mailgun tag, so the service's statistics separate them;
- the task ID as metadata (Postmark metadata, SendGrid custom arguments, Mailgun
  variables, or an `X-Metadata-aggregate_task` header over SMTP), which ties
  the service's events to the task.

Mailgun messages turn tracking off (`o:tracking=no`). Postmark tracks opens and
links only when the server or stream is set to; SendGrid's click and open
tracking are account settings. Turn them off for the key Aggregate uses: open
tracking adds a pixel, and click tracking rewrites links.

Messages go straight to the service, not through the Messenger queue, so a
sign-in code never waits in a queue table and the task records the service's
real answer.

## Another service, or failover

`email_provider: dsn` takes any transport Symfony Mailer can build from a
[mailer DSN](https://symfony.com/doc/current/mailer.html#using-a-3rd-party-transport),
kept whole as the credential, since it contains the key:

```yaml
# config/secrets/email.yaml
dsn: 'failover(postmark+api://TOKEN@default sendgrid+api://KEY@default)'
```

`failover(…)` tries the next service when one is unreachable; `roundrobin(…)`
spreads messages across them. A service other than the built-in three also
needs its Symfony Mailer bridge package installed with Composer (for example
`symfony/amazon-mailer` for Amazon SES), and an application update removes
packages the release does not ship, so such a setup is for installations that
manage their own dependencies. A DSN naming a missing bridge is refused with
the package to install.

## Add a provider

Contributors adding a first-class provider (one with its own settings on the
page) add:

1. its Symfony Mailer bridge to `composer.json`;
2. a class in `src/Service/Email/Provider/` that implements `EmailProvider`,
   usually by extending `AbstractEmailProvider`: its code, label, settings
   (named `email_<code>_*`), their validation, its credential and its DSN.
   `#[AsTaggedItem(priority: …)]` places it in the menu;
3. optionally `templates/email_settings/_provider_<code>.html.twig` for its
   settings fields;
4. its keys in `config/aggregate.yaml.example` and this guide, and a test
   beside `tests/Service/Email/EmailProvidersTest.php`.

Settings, credentials, sending, redaction and recording are shared, so nothing
else changes.

## Troubleshooting

- **"No sender address is set" or "No email service is chosen":** complete the
  first box of the Email page, or the matching YAML keys.
- **"The Postmark server API token is not set":** save the credential, or set
  its environment variable. `app:email:status` shows where each one comes from.
- **The service refuses the sender:** verify the from address or its domain
  with the service.
- **"TLS required but neither TLS or STARTTLS are in use":** the SMTP server does
  not offer STARTTLS. Use its TLS port with `tls`, or `none` only for a relay on
  the same server.
- **Postmark: "not allowed to send to this message stream":** set
  `email_postmark_message_stream` to a transactional stream of the server whose
  token you use.
- **A send is slow or times out:** the server must reach the service's API over
  HTTPS (port 443) or the SMTP server's port; a firewall or a proxy setting may
  block it.
