# Template organization

Group Twig templates by the feature they serve. Template paths are independent
of URL paths: administration routes can remain under `/dashboard` while their
templates live in the relevant feature folder.

| Folder | Purpose |
| --- | --- |
| `websites/` | Website registration, domain rules, and tracker setup guidance |
| `settings/` | Application, branding, collection, and privacy settings |
| `users/` | User administration |
| `data_model/` | Custom property definitions, discovery, and reporting-view configuration |
| `event_examples/` | Saved-model and ecommerce request examples |
| `data_lifecycle/` | Retention, archives, and database maintenance |
| `feature_flags/` | Optional feature controls |
| `updates/` | Release discovery and package verification |
| `internal_traffic/` | Organization-traffic administration and public marker script |
| `home/`, `install/`, `security/` | Public landing page, installation, and authentication |
| `branding/`, `navigation/`, `shared/` | Reusable branding, navigation, and cross-feature presentation |

Use `index.html.twig` for a feature's main page and descriptive names for other
pages. Prefix partials with `_` and keep them beside their owning feature;
place a partial in `shared/` when multiple features use it. For example,
`websites/_domain_fields.html.twig` belongs to website management, while
`shared/_settings_styles.html.twig` supports several administration pages.

[base.html.twig](base.html.twig) remains the shared layout. Reuse its branding
and navigation helpers when adding pages. When moving a template, update
controller render paths, Twig includes/extends, and test references together.
The public script at `internal_traffic/marker.js.twig` also has explicit
references in the JavaScript build tooling.
