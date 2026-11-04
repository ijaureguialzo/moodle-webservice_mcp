# AGENTS.md — Entry Point for AI Agents

This file is the **single central access point** for any AI agent (or human)
working in this repository. Read this document first, then consult the specs
in [`specs/`](specs/README.md) for the area you are about to touch.

---

## 1. What this project is

`moodle-webservice_mcp` is a **Moodle plugin** (type `webservice`, component
`webservice_mcp`) that implements the **Model Context Protocol (MCP)** over
**JSON-RPC 2.0**. It turns a running Moodle instance into an MCP server:

- A single HTTP endpoint: `POST /webservice/mcp/server.php`
- Authentication with standard Moodle **external service tokens** (Bearer header or `?wstoken=`)
- **Dynamic tool discovery**: every external function assigned to the token's
  service is exposed as an MCP tool (with JSON Schema input/output)
- **Tool invocation**: `tools/call` dispatches to the external function, with
  position-safe argument passing (see `specs/002-mcp-protocol-server.md`)

The plugin additionally contributes **two custom write external functions**
that core Moodle does not provide (create course section / create course
module), which makes it possible to build whole courses through MCP.

| | |
|---|---|
| Upstream | `github.com/onbirdev/moodle-webservice_mcp` (remote `onbirdev`) |
| Working fork | `github.com/ijaureguialzo/moodle-webservice_mcp` (remote `origin`) |
| Component | `webservice_mcp` (lives in `moodle/webservice/mcp/` once installed) |
| PHP | 8.1+ (CI matrix: 8.1, 8.2) |
| Moodle | 4.5+ required (`$plugin->requires = 2024100700`); CI tests against `MOODLE_405_STABLE`; local integration instance is 5.x |

---

## 2. Repository layout

```
├── AGENTS.md                  ← you are here
├── specs/                     ← SDD specification documents (source of truth)
├── server.php                 ← HTTP entry point (protocol gate, instantiates server)
├── version.php                ← plugin metadata (version/requires/release)
├── classes/
│   ├── local/
│   │   ├── server.php         ← MCP server: JSON-RPC dispatch, execute(), responses
│   │   ├── request.php        ← immutable, validated JSON-RPC 2.0 request object
│   │   ├── tool_provider.php  ← tools/list: external functions → MCP tools + JSON Schema
│   │   └── external/
│   │       ├── create_course_module.php  ← custom write external function
│   │       └── create_section.php        ← custom write external function
│   └── privacy/
│       └── provider.php       ← null_provider (the plugin stores no personal data)
├── db/
│   ├── access.php             ← capability webservice/mcp:use
│   └── services.php           ← registers the 2 custom functions for the `moodle_mcp` service
├── lang/en/webservice_mcp.php ← error strings + plugin name
├── lib.php                    ← webservice_mcp_client (PHP client for tests/integration)
├── locallib.php               ← webservice_mcp_test_client (webservice_test_client_interface)
├── tests/                     ← PHPUnit: server, request, tool_provider, client
└── .github/workflows/         ← CI (moodle-plugin-ci ^4) + release automation
```

---

## 3. Build & test

The plugin does **not** build anything on its own; it is validated inside a
Moodle checkout via `moodle-plugin-ci` (same as GitHub CI, `.github/workflows/ci.yml`):

```bash
# From a directory that will contain the moodle + plugin checkouts:
composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ci ^4
cd ci/bin
./moodle-plugin-ci install --plugin ../moodle-webservice_mcp --db-host=127.0.0.1
./moodle-plugin-ci phplint                      # PHP lint
./moodle-plugin-ci phpcs --max-warnings 1       # Moodle coding style (blocking)
./moodle-plugin-ci phpdoc --max-warnings 0      # Docblocks (blocking)
./moodle-plugin-ci validate                     # plugin file structure
./moodle-plugin-ci savepoints                   # version/savepoint consistency
./moodle-plugin-ci phpunit --fail-on-warning    # unit tests
```

Lightweight local checks (no Moodle needed):

```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;
```

**Integration testing** (recommended after any change to dispatch/external
functions): point a real HTTP client at a running Moodle with the plugin
installed:

```bash
curl -sk https://moodle.test/webservice/mcp/server.php \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <TOKEN>' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

> ⚠️ **opcache caveat**: long-lived PHP workers (apache/php-fpm) can keep
> serving stale plugin code after a source change. After modifying the plugin
> in place (e.g. bind-mounted into a Docker Moodle), do a graceful apache
> restart (or container restart) **and** `php admin/cli/purge_caches.php`.
> See `specs/006-quality-and-delivery.md` §6.

---

## 4. SDD workflow (Spec-Driven Development)

This repository is developed **spec-first**. The loop, in order:

1. **Read** the spec(s) in `specs/` covering the area you will change.
   `specs/README.md` explains the numbering and how to add new documents.
2. **If the intended work is not covered by any spec, write or extend the
   spec first** (new numbered document, or a new block in the applicable
   document). The spec must state: purpose, normative requirements
   (`R<n>` items), interfaces, acceptance criteria, and — for anything that
   depends on web-service activation — the functions to enable.
3. **Implement** the code so it satisfies the spec requirements.
   Where a requirement and existing behaviour disagree, the **spec wins** —
   update the code, and only change the spec when the spec itself is wrong
   (say why in the commit message).
4. **Verify**: unit tests (`moodle-plugin-ci phpunit`), coding style
   (`phpcs`/`phpdoc`), and an integration smoke test through the live MCP
   endpoint for protocol-level changes.
5. **Deliver**: commits per `§5` below (Conventional Commits + LLM co-author
   trailer), plus `version.php`/`CHANGELOG.md` updates when behaviour changes.

Specs are the contract between agents, humans and future sessions: after your
work, a fresh agent that only read `AGENTS.md` + `specs/` must be able to
understand and extend what you built without archaeology.

---

## 5. Commit & delivery rules (MANDATORY)

These rules are part of every acceptance criterion. A feature is not
"finished" until committed this way.

1. **Conventional Commits** only. Types used in this project:
   `feat`, `fix`, `docs`, `refactor`, `test`, `perf`, `build`, `ci`, `chore`, `revert`.
   - Subject: imperative mood, ≤ 72 chars, no trailing period.
   - Body: explain *why* (especially for `fix`: root cause), one idea per commit.
   - One logical change per commit; a feature that touches spec + code + tests
     may be 2-3 commits (e.g. `docs: ...`, `feat: ...`, `test: ...`).
2. **LLM co-author trailer: always.** Every commit authored with AI assistance
   must end with a trailer naming the LLM that did the work:
   ```
   Co-authored-by: <LLM identity> <noreply@<vendor-domain>>
   ```
   Current working example (keep the identity of the model actually in use):
   ```
   Co-authored-by: Qwen3.8-27B-4bit <noreply@mlx-community>
   ```
   The human author stays the committer; the trailer is the second author line.
   Never invent a different attribution; if you do not know your model name,
   ask before committing.
3. **Versioning** (code/behaviour changes only; docs-only changes do not bump):
   - `version.php`: `$plugin->version = YYYYMMDDNN` — date + an incrementing
     NN per day (see `git log` history for the pattern); update
     `$plugin->release` (semver: `feat` → minor, `fix` → patch).
   - `CHANGELOG.md`: add an entry under a new `## Version X.Y.Z (YYYYMMDDNN)`
     heading, one bullet per notable commit.
   - The `release.yml` workflow auto-tags on `version.php` changes in `main`.
4. **Never commit**: IDE files (`.idea/` is git-ignored), tokens, credentials,
   absolute local paths, or core-Moodle patches.

---

## 6. Hard constraints (never break)

- **Do not patch core Moodle files.** All behaviour lives in the plugin.
  (Core quirks discovered during integration are documented in
  `specs/005-webservice-activation.md` §D and worked around in plugin code.)
- **External function methods must declare individual parameters** matching the
  `external_function_parameters` keys in name *and* order —
  `external_api::call_external_function()` uses `call_user_func_array()`.
  See `specs/004-external-functions.md` §R1.
- **`tools/call` dispatch must stay position-safe**: `server::execute()`
  back-fills omitted optional parameters via `build_positional_args()`.
  Do not regress to `array_values($validated)` (see pitfall P1).
- **Feature availability is driven by the web-service link table**
  (`mdl_external_services_functions`). Any new externally callable feature
  needs a block in `specs/005-webservice-activation.md`.
- The plugin remains **privacy-neutral** (`null_provider`); new code must not
  start persisting user data.
- Responses must remain JSON-RPC 2.0 valid: exactly one of `result` / `error`,
  `id` echoed, `content[]` + `structuredContent` on successful tool calls.

---

## 7. Known pitfalls (learned the hard way)

These are real, verified traps. Specs encode the rules; this is the lore.

- **P1 — positional argument shift.** The base `webservice_base_server::execute()`
  re-indexes validated params with `array_values()` before
  `call_user_func_array()`. MCP clients send *partial* JSON (optional params
  omitted), so a later value slides into the omitted slot (e.g. an `extrafields`
  array landing in `$contentformat` → `invalidparameter: Scalar type expected,
  array or object received`). Fixed by `server::execute()` +
  `build_positional_args()` (commit `22a1582`). Core never exposed it because
  the mobile app always sends complete JSON.
- **P2 — `database_column_info` has no `getName()`**: use the magic property
  `$column->name` (commit `a9b61e6`).
- **P3 — DML records don't expose `->tablename`** anymore. Derive table names
  from the module name (`wiki` → `mdl_wiki`; the table is the *modname*, not
  `mod_<modname>` — the prefix is for directories) (commits `5d2d6f2`, `2d17bd6`).
- **P4 — wiki page formats are limited.** `mod/wiki` parsers exist only for
  `creole`, `nwiki` and `html` (`mod/wiki/parser/markups/*.php`). Forcing
  `editor_tiny` fails with `Parser type editortiny not found` on *every*
  page operation. Use `defaultformat='html'` + `forceformat=1` for HTML
  wiki content.
- **P5 — developer debugging inverts failure modes.** With `debug=31`, PHP
  warnings become Whoops exceptions (a "working" create can die); with
  `debug=0` the same warnings are swallowed and can silently corrupt data
  (e.g. module created with wrong format). If a flow works in one mode and
  not the other, suspect a core warning, not your logic.
- **P6 — opcache in long-lived workers** (see §3): a source change that "is
  already deployed" may not be the code actually running. Restart + purge.
- **P7 — the `services` column of `mdl_external_functions` is not what gates
  calls.** `load_function_info()` and `tool_provider` both use the
  `mdl_external_services_functions` link table. Functions must be added to the
  service through the UI (or that table) — see `specs/005` §A.
- **P8 — CI Moodle (4.5) ≠ local Moodle (5.x)**: core internals
  (parser lists, DML classes, constants) can differ; integration tests must
  run against the real local instance, not only CI.

---

## 8. Spec index

| Spec | Scope |
|---|---|
| [`specs/README.md`](specs/README.md) | How the spec system works; how to add specs and feature blocks |
| [`specs/001-architecture.md`](specs/001-architecture.md) | Components, request lifecycle, data flow, invariants, DB tables |
| [`specs/002-mcp-protocol-server.md`](specs/002-mcp-protocol-server.md) | JSON-RPC 2.0/MCP protocol behaviour: methods, auth, headers, responses, errors, position-safe dispatch |
| [`specs/003-tool-discovery.md`](specs/003-tool-discovery.md) | `tools/list`: service scoping, deprecation filtering, JSON Schema generation rules |
| [`specs/004-external-functions.md`](specs/004-external-functions.md) | The two custom write functions (parameters/returns/behaviour) + checklist for adding new ones |
| [`specs/005-webservice-activation.md`](specs/005-webservice-activation.md) | **Which web-service functions must be enabled for each feature** — general prerequisites + per-module blocks + current full list |
| [`specs/006-quality-and-delivery.md`](specs/006-quality-and-delivery.md) | Coding standards, testing, versioning, Conventional Commits + co-author trailer, release, environment quirks |

---

## 9. Quick reference: the endpoint

```
POST /webservice/mcp/server.php
Content-Type: application/json
Authorization: Bearer <token>        (or ?wstoken=<token>)
```

| Method | Purpose |
|---|---|
| `initialize` | negotiate `protocolVersion` `2025-03-26`, return server info/capabilities |
| `notifications/initialized` | client ack → HTTP 204, no body |
| `ping` | liveness → `result: {}` |
| `tools/list` | tools available to the token's service |
| `tools/call` `{name, arguments}` | invoke an external function |

GET → server info (plain JSON, not JSON-RPC). OPTIONS → CORS 200.
