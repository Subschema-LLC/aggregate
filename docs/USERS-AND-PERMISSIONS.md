# Users and permissions

[README](../README.md) · [Configuration](CONFIGURATION.md) · [Audit trail](AUDIT-TRAIL.md)

Every dashboard account is an **administrator** or a **standard user**.
Administrators can do everything: every page, every website and group, and
managing users and permission groups. Standard users can use only the websites
and pages an administrator grants them, directly or through
[permission groups](#permission-groups). Everyone manages their own
[profile](#your-profile-and-access).

- [Account types](#account-types)
- [What can be granted](#what-can-be-granted)
- [Permission groups](#permission-groups)
- [Managing users](#managing-users)
- [Your profile and access](#your-profile-and-access)
- [Command line and YAML](#command-line-and-yaml)
- [How access is enforced](#how-access-is-enforced)
- [Upgrading from earlier versions](#upgrading-from-earlier-versions)

## Account types

| | Administrator | Standard user |
| --- | --- | --- |
| Pages | Every page | Only granted pages, at view or edit |
| Websites | Every website and group | Only granted websites and groups |
| Users and permission groups | Create, change and delete | No access |
| Data transfer | Export, import and copy | No access |
| API tokens and AI assistants | Create and revoke [API tokens](REPORTING-API.md#tokens); approve and revoke [AI assistant connections](MCP.md#sign-in) | No access |
| Email | Choose the [email service](EMAIL.md), its credential and the sender; send a test message | No access |
| Own profile | Name, email, password | Name, email, password |

Only administrators create, change or delete users. An installation always keeps
at least one administrator, and administrators cannot remove their own
administrator access or delete their own account from the dashboard, so nobody
locks themselves out by accident.

## What can be granted

A grant has two parts: **websites** and **pages**.

**Websites** decide what website pages show and change:

- **Every website and group**, including ones added later. This also covers the
  settings shared by all websites, such as the deployment collection controls,
  the deployment data model and the tag manager's shared configuration.
- **Selected website groups**: every website in each group, including websites
  added to the group later, plus that group's own settings.
- **Selected websites**, by registration token.

**Pages** are granted at **view** (open the page) or **edit** (also save its
changes). A page left out is no access, and its menu entry disappears. Run
`php bin/console app:permission-groups pages` for the list with ids.

| Kind | Pages | What the user sees |
| --- | --- | --- |
| Website pages | Setup, Websites, Tag manager lite, Consent manager lite, Collection controls, Data model | Only their websites and groups. A user without every website starts on their first group or website, and cannot open the deployment scope. |
| System-wide pages | Website groups, Organization traffic, Reporting views, BI glossary, BI disclosure, Page groups, Sync, General settings, Branding, Data lifecycle, Scheduled tasks, Audit trail, Updates, Feature flags | Settings for every website, whatever websites they were granted. The Users page marks these as system-wide. |
| Reference | Event examples | View only; it changes nothing. |

A few actions on website pages also need **every website**, because they read or
change something all websites share: adding a website, rebuilding the browser
scripts on Setup, the tracking failure status and retries on Collection
controls, and observed properties, downloading, and publishing columns on the
Data model page.

Grant system-wide pages with care; some amount to more access than they name:

- Edit on **Website groups** moves websites between groups, so a user granted a
  group could move other websites into it. Treat it as every website.
- Edit on **Updates** installs application code, which is as much as an
  administrator can do.
- Edit on **General settings** changes the application host and documentation
  link that every page uses.
- View on **Audit trail** shows every user's actions.

**Data transfer** is not offered at all: it can import accounts, administrators
included, and copies every website's events.

## Permission groups

A permission group (role) is a named set of websites and pages. Assign it to
standard users, and changing the group changes their access on their next page.
A user can belong to several groups and also have grants of their own. They get
the **highest** level any of them gives: grants only add access.

Manage groups on **Administration → Users → Permission groups**. Each group has
a name, an optional description, its websites and its pages. Deleting a group
removes it from every user who had it.

Examples:

- **Client editors**: websites in the Brand A group; Websites view, Tag manager
  lite and Consent manager lite edit.
- **Analysts**: every website; Data model and Reporting views view, BI glossary
  edit.
- **Read-only client**: one website; Setup and Websites view.

## Managing users

On **Administration → Users**:

- **Add user**: username, password, optional display name and email, account
  type and permission groups. Choose a standard user's websites and pages after
  creating them.
- **Open a user** to change their username, name, email, account type,
  permission groups and direct grants, to see **what they can use** and where
  each grant comes from, to reset their password, or to delete them.

Changes take effect on the user's next page. Making someone an administrator or
a standard user, resetting their password or changing their username signs them
out of their sessions.

## Your profile and access

Every user opens their profile from their name in the menu. There they can:

- change their **display name**, shown in the menu instead of their username;
- change their **email**, so administrators can reach them;
- change their **password**, after entering the current one. Their other
  sessions sign out; the current one stays signed in. For 15 minutes after
  signing in with an emailed code, the current password is not needed.

## Two-step sign-in

When an administrator requires [two-step sign-in](EMAIL.md#two-step-sign-in)
(`mfa_required`), every user enters a code emailed to the address on their
account after their password. Users without an email address cannot sign in
until an administrator adds one; the Email page lists them, and nobody can
remove their own address while it is required. Turning it on signs out every
other session on its next page.

## Forgotten passwords and sign-in codes

When an administrator turns on [sign-in codes](EMAIL.md#sign-in-codes) and
email can be sent, the sign-in page offers **Forgot your password? Email me a
sign-in code**. A user with an email address gets a six-digit code that signs
them in once; they can then set a new password on their profile. Sign-in codes
are not offered while two-step sign-in is required. Without them,
an administrator resets a password on the Users page, or with
`app:user:reset-password` on the server.

**Your access** lists, read-only, the websites and pages they can use, the level
of each page, and where each grant comes from: assigned directly or from a
permission group. Only administrators change access or usernames.

On a page a user can only view, a notice says so and the page's save buttons
and fields are turned off.

## Command line and YAML

Every dashboard action has a command, so headless installations can manage
access too. Commands run as the operator and are not limited by permissions.

```bash
# Show what a user can use and where it comes from
php bin/console app:user:access alex

# Grant directly: add a website group and pages
php bin/console app:user:access alex --website-group=brand-a --page=websites:view --page=tag_manager:edit

# Start over: every website, data model view only
php bin/console app:user:access alex --clear --all-websites --page=data_model:view

# Permission groups
php bin/console app:permission-groups pages
php bin/console app:permission-groups create --name="Client editors" --website-group=brand-a --page=websites:view --page=tag_manager:edit
php bin/console app:permission-groups update --id=client-editors --page=consent_manager:edit
php bin/console app:user:access alex --group=client-editors
php bin/console app:permission-groups delete --id=client-editors

# Account type and removal
php bin/console app:user:access alex --admin
php bin/console app:user:access alex --standard
php bin/console app:user:delete alex
```

`app:user:create` and `app:user:reset-password` work as before; a new account
without `--role=ROLE_ADMIN` is a standard user with no access until granted.

Permission groups live in the untracked `config/permission-groups.yaml`, which
updates never replace. The dashboard and the command write it; you can also edit
it directly. Copy [config/permission-groups.yaml.example](../config/permission-groups.yaml.example)
for the shape:

```yaml
permission_groups:
  client-editors:
    name: Client editors
    description: Edit tags and the consent banner for the Brand A websites.
    websites:
      groups: [brand-a]      # website group ids from config/website-scopes.yaml
      sites: []              # website tokens from config/websites.yaml
      # all: true            # every website and group instead
    pages:
      websites: view
      tag_manager: edit
      consent_manager: edit
```

An unknown page, level or setting makes the file invalid. An invalid file grants
nothing until it is corrected, the Users page shows the error, and the dashboard
refuses to save over it.

A user's own grants and assigned groups are stored on their account in the
`users` table (`access`), with their display name and email. They are user
records, not settings, so they stay in the database with the account.
[Data transfer](DATA-TRANSFER.md) copies accounts without these: a standard
user arrives on the target with no access, and an administrator grants it
again there. Copy `config/permission-groups.yaml` yourself if the target should
have the same groups.

## How access is enforced

- **Every dashboard route is listed** in `App\Security\Access\DashboardPages`
  with the page and level it needs. A check runs before any controller, so a
  page cannot be reached by typing its address or posting its form. A dashboard
  route missing from the list is administrator-only, and a test fails until it
  is listed.
- **View and edit are separate.** Reading a page needs view; any request that
  changes something needs edit. A view-only user who submits a form is sent back
  with a message, and the attempt is recorded in the audit trail as denied.
- **Website pages check the website** in every request, not only the choices
  they show: a website, group or scope outside the user's grants is not found.
- **The menu, quick search and page tabs** show only what the user can open.
  That is presentation; the checks above are the enforcement.
- **Commands are unrestricted**, because they run as the operator.

The audit trail's **Users** area records creating, changing and deleting users
and permission groups, profile changes and passwords, with the account type and
grants in the details. See [Audit trail](AUDIT-TRAIL.md).

## Upgrading from earlier versions

The update adds `display_name`, `email` and `access` columns to `users`.
Administrators stay administrators. Earlier non-administrator ("viewer")
accounts keep what they could see before: the Websites page, for every website,
read-only. Grant them more on the Users page.

If you customized `config/navigation.local.yaml`, links there that still name
`role: ROLE_ADMIN` stay hidden from standard users. Remove that line from the
links you want granted pages to show; page permissions decide the rest.
