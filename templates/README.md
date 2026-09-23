# UI templates and assets

UI pages use Twig, Symfony UX Twig Components, Stimulus, and AssetMapper. Route
controllers render their feature template, such as
[`tag_manager/index.html.twig`](tag_manager/index.html.twig). Each page extends
[`base.html.twig`](base.html.twig), sets its title, and owns its page markup.
Extract reusable controls and useful pieces such as rows, cards, forms,
navigation, dialogs, and the walkthrough into Twig Components. Keep collection
and configuration behavior in shared PHP services.

## Page and component paths

Use matching feature and page names for page markup and assets. Reusable
components keep Symfony's PascalCase Twig names; their asset paths use
snake_case. All paths below are relative to the repository root.

| Purpose | Path pattern |
| --- | --- |
| Page markup | `templates/<feature>/<page>.html.twig` |
| Page JavaScript | `assets/controllers/pages/<feature>/<page>_controller.js` |
| Page CSS | `assets/styles/pages/<feature>/<page>.css` |
| Reusable component markup | `templates/components/<Feature>/<Component>.html.twig` |
| Component JavaScript | `assets/controllers/components/<feature>/<component>_controller.js` |
| Component CSS | `assets/styles/components/<feature>/<component>.css` |

For example, `templates/setup/index.html.twig` uses
`assets/controllers/pages/setup/index_controller.js` and
`assets/styles/pages/setup/index.css`. Its `Setup:Walkthrough` component has its
own stylesheet at `assets/styles/components/setup/walkthrough.css`, while the
page controller manages the tour. The data model and websites pages follow the
same `index` convention. The static data-structure page lives at
`templates/home/data_structure.html.twig` with CSS in
`assets/styles/pages/home/data_structure.css`.

Create a controller or stylesheet only when it has behavior or styles to own.
Components can use their containing page's styles: `TagManager:TagRow` has a
controller in `assets/controllers/components/tag_manager/tag_row_controller.js`
and uses `assets/styles/pages/tag_manager/index.css`. Static templates need no
empty controllers or PHP classes. Keep page markup directly in the route
template instead of adding a component that only forwards the entire page.

## Twig and Stimulus conventions

For reusable pieces, use anonymous components with `{% props %}` declarations.
Pass data explicitly:

```twig
{{ component('Ui:CopyButton', {
    target: 'installation-code',
    status: 'copy-status',
    label: 'Copy installation',
    success: 'Copied.'
}) }}
```

The source ID identifies code or an input; the status ID identifies a live
feedback region. The component provides
`data-controller="components--ui--copy-button"`,
its values, and the click action. Use `attributes.defaults()` on reusable
component roots to accept classes and additional data attributes. Nullable props
need explicit defaults such as `{% props configuration_error = null %}`.
Framework globals and helpers (`app`, `app_branding`, `path`, `csrf_token`) remain
available normally. Route templates use ordinary HTML attributes; the component
attribute bag and `{% props %}` declarations belong only in component templates.
Keep secrets out of rendered props and data attributes.

Symfony discovers anonymous templates in `templates/components/`. A colon maps
to a directory: `TagManager:TagRow` resolves to
`components/TagManager/TagRow.html.twig`. If a component later needs PHP behavior,
use `#[AsTwigComponent]` under `src/Twig/Components/`; the standard recipe and
existing PSR-4 service discovery handle registration and dependency injection.

StimulusBundle discovers controllers in `assets/controllers/`. Nested directories
become `--` and underscores become `-`:

| Controller path below `assets/controllers/` | Stimulus identifier |
| --- | --- |
| `pages/setup/index_controller.js` | `pages--setup--index` |
| `pages/data_model/index_controller.js` | `pages--data-model--index` |
| `pages/websites/index_controller.js` | `pages--websites--index` |
| `components/ui/copy_button_controller.js` | `components--ui--copy-button` |
| `components/tag_manager/tag_row_controller.js` | `components--tag-manager--tag-row` |

Bind the identifier with `data-controller`, declare
`data-...-target` and `data-...-value` attributes, and use `data-action` for events.
Avoid document-wide initialization scripts and inline event handlers. Initialize
state in `connect()` and clean up temporary UI state in `disconnect()` so controls
also work after DOM replacement. Keep all targets inside their controller's
element: the data-model page root contains its form and row templates, and each
tag row has its own controller. Use unique HTML IDs for labels and copy targets.

Shared components include `Ui:CopyButton`, `Ui:Confirm`, `Ui:Validation`, and
`Ui:Notification`. Confirm and Validation render forms with a `content` block:

```twig
{% component 'Ui:Validation' with {action: path('app_settings_save'), method: 'post'} %}
    {% block content %}
        {# Include the form's normal CSRF field and controls here. #}
    {% endblock %}
{% endcomponent %}
```

Validation reveals closed ancestor details when a control is invalid. Confirm
asks before submitting a destructive form. Copy buttons retain manual selection
and live feedback when clipboard permission is unavailable.

## Asset loading

`base.html.twig` calls `importmap('app')`. `assets/app.js` starts the standard
Stimulus loader and imports `assets/styles/app.css`. That stylesheet imports
page, component, and shared stylesheets; add a matching CSS import when creating one.
AssetMapper resolves and fingerprints those imports in development and compiled
releases. The page loads CSS even when JavaScript is disabled. There is no custom
compiler or controller registration map. Turbo Drive is disabled in
`assets/controllers.json`; forms and links use normal full-page navigation.

Base layout rules live in `assets/styles/base.css`, shared settings rules in
`assets/styles/shared/settings.css`, and navigation rules in
`assets/styles/components/layout/navigation.css`. The public
`/branding/theme.css` response renders only validated branding variables from
`branding/theme.css.twig`, so branding changes do not require recompilation.
The setup walkthrough computes only callout and arrow coordinates in JavaScript;
its presentation rules live in its component stylesheet.

## Standalone browser artifacts

The tracker, tag loader, CMP, and experimental drop-ins retain their standalone
scripts and build process. Organization-marker pages and downloads retain
`internal_traffic/public.html.twig`, `marker.html.twig`, and `marker.js.twig`;
the admin page embeds that same marker artifact. Hosted marker pages link
`assets/styles/internal-traffic.css`, while downloads bundle that source to work
without the application. These artifacts must not depend on UI components,
Stimulus, or an import map.

## Check changes

Run the affected controller/route and browser tests, then:

```bash
php bin/console debug:twig-component
php bin/console lint:twig templates
php bin/console lint:container
php bin/console asset-map:compile
```

Check the headless container and production assets when changing shared wiring.
Inspect keyboard use, narrow layouts, clipboard fallback, and controller
reconnection for interactive changes. Isolated controller tests use the actual
UX renderer through `tests/Support/TwigComponents.php`; route tests exercise the
normal bundle registration and AssetMapper responses.
