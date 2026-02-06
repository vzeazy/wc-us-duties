# Repository Guidelines

## Project Structure & Module Organization
- `wc-us-duty.php`: plugin bootstrap, hooks, and file loading.
- `includes/`: core PHP classes (`class-wrd-*.php`) for admin UI, duty engine, settings, DB access, and frontend behavior.
- `includes/admin/`: admin table and Duty Manager classes.
- `assets/`: admin JavaScript/CSS used in product edit, bulk edit, and reconciliation flows.
- `migrations/`: SQL schema changes (for example `migrations/001_create_customs_profiles.sql`).
- `scripts/`: one-off CLI/import helpers for Zonos and restock workflows.
- `docs/`, `QUICK_START.md`, `IMPROVEMENTS.md`: operational and feature notes.

## Build, Test, and Development Commands
- `php -l wc-us-duty.php`: lint bootstrap file.
- `find includes assets scripts -type f \\( -name '*.php' -o -name '*.js' \\)`: quick file inventory before review.
- `find includes scripts -name '*.php' -print0 | xargs -0 -n1 php -l`: lint all PHP sources.
- `wp plugin activate wc-us-duty`: activate plugin locally.
- `wp eval 'echo WRD_US_DUTY_VERSION;'`: sanity-check plugin bootstraps in WordPress.

## Coding Style & Naming Conventions
- Follow existing WordPress PHP style: 4-space indentation, braces on same line, early returns.
- Keep class names prefixed with `WRD_` and file names as `class-wrd-*.php`.
- Keep hooks, meta keys, and AJAX actions prefixed with `wrd_` (example: `wrd_search_profiles`).
- JavaScript in `assets/` uses jQuery IIFE modules; keep behavior scoped and admin-specific.

## Testing Guidelines
- No PHPUnit or JS test runner is currently configured; rely on linting plus manual QA.
- Validate changes in WooCommerce admin screens: product edit, Duty Manager, profiles import/export, and reconciliation tables.
- Run checkout smoke tests for both DDP fee mode and DAP notice mode.
- For regressions touching classification, verify HS code and country inheritance from categories.

## Commit & Pull Request Guidelines
- Use concise, imperative commit messages; current history favors Conventional Commit prefixes like `feat:` and `refactor(admin):`.
- Keep commits focused by concern (engine, admin UI, scripts, migration).
- PRs should include:
- What changed and why.
- Manual test steps and expected results.
- Screenshots/GIFs for admin UI updates in `assets/` or `includes/admin/`.
- Any migration or data-impact notes.

## Security & Configuration Tips
- Sanitize and escape all input/output (`sanitize_text_field`, `wp_unslash`, `esc_html`, `esc_url`).
- Guard direct access with `if (!defined('ABSPATH')) { exit; }`.
- Keep nonces and capability checks on AJAX/admin actions before processing writes.
