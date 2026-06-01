# AdminKit tests

A **dependency-free** test suite — no PHPUnit, no Composer, no wp-cli (this
project ships none of them). It's a single PHP script you run directly:

```bash
# Pure-logic tests only (no database needed — this is what CI runs):
php tests/run.php

# Full suite, including the database-backed seam tests:
WP_LOAD=/path/to/your/wp-load.php php tests/run.php
```

On a LocalWP site you usually also need the MySQL socket, e.g.:

```bash
WP_LOAD="/Users/you/Local Sites/adminkit/app/public/wp-load.php" \
  php -d mysqli.default_socket="/Users/you/Library/Application Support/Local/run/<id>/mysql/mysqld.sock" \
  tests/run.php
```

The runner **auto-detects WordPress**: if `WP_LOAD` is set (or a `wp-load.php`
is found by walking up from the plugin), the DB-backed tests run; otherwise they
skip cleanly and only the pure tests run. It exits non-zero if anything fails,
so it doubles as a CI gate.

## What's covered

**Pure (run everywhere, incl. CI)**
- Dashboard **default card order** — Statistics is the first main card; "At a
  glance" sits in the side column. Guards the `default_order()` contract.
- Stats **preset ranges** — Year-to-date spans Jan 1 → today.

**DB-backed (need a live WordPress)** — the critical data seams:
- **Settings sanitize** — `AdminKit_Settings_Page::sanitize()` keeps only known
  schema keys and drops unknown ones (the import/save safety net).
- **Menu store** — `save_config()` → `get_config()` round-trips a layout.
- **Stats store** — `record()` aggregates into `summary_range()` (pageviews +
  top pages); `mark_active()` feeds `active_visitors()` / `recent_activity()`.

## Adding a test

Open `run.php`. Use `ak_group('name')` to start a section, then `ak_ok($cond,
$msg)` / `ak_eq($expected, $actual, $msg)`. Put anything that needs the database
inside the `if ( $has_wp ) { … }` block; put pure-logic checks above it so they
run in CI too. Keep tests idempotent — restore any rows/options you write (the
existing store tests read a baseline first and add to it rather than asserting
absolute totals).
