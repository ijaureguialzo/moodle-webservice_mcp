# 006 · Quality & Delivery

Status: **normative (process)**.

## 1 · Coding standards

- **Moodle coding style** is the baseline (PHPCS `moodle` standard — the CI
  runs `moodle-plugin-ci phpcs --max-warnings 1` and `phpdoc
  --max-warnings 0`; both are **blocking**).
- PHP ≥ 8.1 features welcome; every plugin class file keeps the standard GPL
  header, `declare(strict_types=1)`, a class-level docblock (`@package`,
  `@license`), and docblocks on every public/protected method (parameters and
  return types documented).
- `use` statements: import the exact classes you reference; qualify global
  functions/classes from the namespace root with a leading backslash where
  needed (e.g. `\create_module()`, `\context_course::instance()`).
- New PHP files: 4-space indent, no trailing whitespace, newline at EOF.
- **Never** patch core Moodle files from this repository; never vendor
  third-party PHP inside the plugin without a documented, license-clean
  rationale in the spec.
- Strings shown to users go in `lang/en/webservice_mcp.php` (and any
  translated languages the project adopts) — never hard-code user-facing
  messages in code.
- IDE folders (`.idea/`) stay out of the repo (`.gitignore`).

## 2 · Testing

Test suite: `tests/*_test.php`, run with
`moodle-plugin-ci phpunit --fail-on-warning` (or
`vendor/bin/phpunit --testsuite webservice_mcp_testsuite` inside a Moodle
checkout).

| File | Covers |
|---|---|
| `server_test.php` | instantiation, tool-call extraction, token extraction, error generation, `safe_json_encode`, `send_response` shape, initialize format, HTTP status mapping |
| `request_test.php` | JSON-RPC 2.0 validation (valid/invalid shapes, ids, params) |
| `tool_provider_test.php` | schema generation (all value kinds, structures, arrays, nesting, null) + `get_tools` service scoping |
| `client_test.php` | `webservice_mcp_client` request building |

Rules:

- T1. **Every behavioural change ships with a test** that would fail without
  the change. For protocol/dispatch changes, include the **partial-arguments
  regression test** (`tools/call` with a middle optional parameter omitted
  and a later one present — the P1 trap; assert the function received the
  correct per-slot values, e.g. via a spy external function or the returned
  `structuredContent`).
- T2. Use the existing base classes: `advanced_testcase` for protocol
  logic; `externallib_advanced_testcase` for anything touching external
  functions (it sets up the external-function test environment).
- T3. Tests must not require network access; the `webservice_mcp_test_client`
  (`locallib.php`) exists for the webservice-test-harness style of tests.
- T4. CI matrix (`.github/workflows/ci.yml`): PHP 8.1/8.2 ×
  `MOODLE_405_STABLE` × pgsql/mariadb, with phplint, phpmd (non-blocking),
  phpcs, phpdoc, validate, savepoints, mustache, grunt, phpunit, behat.
  A change that cannot pass the 4.5 CI must be documented in the spec as a
  version-gated behaviour (see P8) — do not weaken CI to make a change pass.

## 3 · Versioning

- `version.php`: `$plugin->version = YYYYMMDDNN` — `NN` increments per
  change-day (1, 2, …); keep `$plugin->requires` at the minimum Moodle
  version the code still supports (currently `2024100700` = 4.5);
  `$plugin->maturity = MATURITY_BETA`; `$plugin->release` follows semver
  (`feat` → minor bump, `fix`/`docs`-affecting-behaviour → patch bump).
- `CHANGELOG.md`: one `## Version X.Y.Z (YYYYMMDDNN)` section per release,
  bullets in Conventional-Commit style (`Feat:` / `Fix:` / `Improved:`).
- Docs-only changes (specs, README, AGENTS.md) do **not** bump the version.
- `release.yml` auto-creates a GitHub tag + release when `version.php`
  changes on `main` — so version bumps belong in their own or the feature's
  commit on `main`, never squashed silently.

## 4 · Commit & delivery rules (MANDATORY)

> Restated from `AGENTS.md` §5 with the details. A feature is **not done**
> until committed this way.

1. **Conventional Commits**: `<type>[optional scope]: <subject>` with types
   `feat | fix | docs | refactor | test | perf | build | ci | chore | revert`.
   - Subject: imperative, ≤ 72 chars, no trailing period.
   - Body: *why* (root cause for fixes; behaviour delta for feats). Use
     bullet lists for multi-part changes.
   - One logical change per commit. A feature = up to a few commits
     (spec / code / tests / version may be separate, same message family).
2. **LLM co-author trailer, always, for AI-assisted work.** Trailer format
   (trailing blank line then, exactly):
   ```
   Co-authored-by: <LLM identity> <noreply@<vendor-domain>>
   ```
   - Use the **actual** identity of the model performing the work; if the
     agent does not know it, ask the user before committing.
   - Current working example (this environment):
     `Co-authored-by: Qwen3.8-27B-4bit <noreply@mlx-community>`
   - The human remains the committer (git author); the trailer adds the LLM
     as a co-author. Do not put the LLM in the `author` slot, do not use
     `Signed-off-by` for this purpose, and do not omit the trailer "because
     the change was small" — size is irrelevant.
3. **What a "finished feature" commit set contains**:
   spec delta (`specs/…`), code, tests, `version.php` + `CHANGELOG.md`
   (if behaviour changed), all committed before declaring the feature done
   and before starting the next feature.
4. **Prohibited in commits**: tokens/credentials, local absolute paths,
   `.idea/`, generated artifacts, core-Moodle patches, "temporary debug"
   code (if you instrumented to diagnose, remove it before committing —
   see §6).

## 5 · Definition of done (feature)

- [ ] Spec written/updated (`specs/`) and consistent with the implementation.
- [ ] Implementation + tests satisfy the spec's acceptance criteria;
      `phpunit` green, `phpcs`/`phpdoc` green.
- [ ] Integration smoke test through the live MCP endpoint (real token),
      including a **partial-arguments** call and one read-back of the
      created state.
- [ ] `version.php` + `CHANGELOG.md` updated (behaviour changes only).
- [ ] Debugging reset (`debug=0`), caches purged, no scratch objects left in
      the test course.
- [ ] Conventional Commits with the LLM co-author trailer; `main` clean.

## 6 · Environment & operations notes

- **E1 — Local integration instance** (this project's development loop):
  Moodle 5.x in Docker (`moodle-docker` project; `moodle.test` via traefik
  TLS; mariadb container). The plugin source is **bind-mounted** into the
  container's docroot, so local edits are visible in-container immediately.
- **E2 — opcache** (P6): source changes need a PHP-worker refresh to take
  effect: `apache2ctl graceful` (or container restart) **plus**
  `php admin/cli/purge_caches.php` (run as the web user). "The file is new
  in the container but the old code runs" is the canonical symptom.
- **E3 — debugging**: developer debug is off by default. To diagnose
  validation errors, set `mdl_config` `debug=31`, `debugdisplay=31` + purge;
  read `error.data.debuginfo` in MCP responses; **always reset to 0** and
  purge afterwards (P5: symptoms invert between modes).
- **E4 — no direct DB writes for features.** State changes go through MCP /
  the external functions; direct SQL is for *diagnosis and verification
  only*. (Admin-level activations like adding service functions are UI
  actions, documented in `005`.)
- **E5 — CI vs local version skew** (P8): CI proves Moodle 4.5; the local
  instance is 5.x. Core internals can differ (parser lists, DML classes,
  constants). A behaviour that works in one but not the other must be
  version-gated in code (`$CFG->version` checks) and noted in the spec.
- **E6 — security posture**: the CORS header is `*` (convenience for local
  agents); deployments that expose the endpoint publicly must tighten it and
  consider service `restrictedusers=1`. The endpoint already requires a valid
  permanent token for every method, including `tools/list`.
