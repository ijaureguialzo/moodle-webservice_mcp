# 001 · Architecture

Status: **normative** — describes the as-is architecture the specs in this
directory are written against.

## R1 · Plugin surface

- R1.1 The plugin is a `webservice`-type component named `webservice_mcp`,
  installed at `moodle/webservice/mcp/`. Because plugins of type `webservice`
  are listed by `admin/webservice/protocols.php`
  (`core_component::get_plugin_list('webservice')`), **installing the plugin
  is what registers the `mcp` protocol** in Site admin → Plugins → Web
  services → Manage protocols. No core changes, no separate protocol plugin.
- R1.2 The plugin ships exactly these runtime pieces:
  | Piece | File | Responsibility |
  |---|---|---|
  | Entry point | `server.php` | Defines `NO_DEBUG_DISPLAY` + `WS_SERVER`, requires `../../config.php`, gates on `webservice_protocol_is_enabled('mcp')` (403 otherwise), instantiates `webservice_mcp\local\server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN)` |
  | Server | `classes/local/server.php` | JSON-RPC/MCP dispatch, position-safe execution, responses/headers/errors |
  | Request | `classes/local/request.php` | Immutable validated JSON-RPC 2.0 request |
  | Tool provider | `classes/local/tool_provider.php` | `tools/list` content: service functions → MCP tools + JSON Schema |
  | External functions | `classes/local/external/*` | Custom write functions (see `004`) |
  | Privacy | `classes/privacy/provider.php` | `null_provider` — the plugin stores no personal data |
  | Capability | `db/access.php` | `webservice/mcp:use` (read, `CONTEXT_COURSE`, no archetypes) |
  | Service registration | `db/services.php` | Declares the 2 custom functions with `services => ['moodle_mcp']` |
  | Clients | `lib.php` (`webservice_mcp_client`), `locallib.php` (`webservice_mcp_test_client`) | PHP clients for tests/integration |
- R1.3 The plugin persists **no data of its own**. Its only DB interaction is
  reading Moodle's web-service tables (see §R5) and, through the external
  functions, performing normal module writes.

## R2 · Request lifecycle

For `POST /webservice/mcp/server.php` with a JSON body:

1. `server.php` gates on the `mcp` protocol being enabled, else `403 Forbidden`.
2. `server::run()` → `set_headers()` (JSON, no-cache, CORS) → memory/timeout
   bumps → custom exception handler → `parse_request()`.
3. `parse_request()`: base `set_web_service_call_settings()`; extracts the
   token (`Authorization: Bearer …` first, then `?wstoken=`); if the body is
   non-empty, builds a `request` from `php://input`.
4. Branch:
   - **MCP methods** (`initialize`, `notifications/initialized`, `ping`,
     `tools/list`) — i.e. the request did not set `$this->functionname`:
     `authenticate_user()` → `handle_mcp_method()` → `session_cleanup()` → `die`.
   - **`tools/call`**: `extract_tool_call()` copies `params.name` →
     `$this->functionname` and `params.arguments` → `$this->parameters`, then
     `parent::run()` executes the standard web-service pipeline:
     `authenticate_user()` → `load_function_info()` (service + capability
     checks, `003` §R3) → `execute()` (`002` §R8) → `send_response()`.
5. `GET` → server info JSON; `OPTIONS` → CORS 200 (both after auth).

## R3 · Authentication & authorization (normative invariants)

- R3.1 Authentication is **permanent-token only**
  (`WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN`): the token must exist in
  `mdl_external_tokens`, belong to an **enabled** service, the token's user
  must exist, and (if the service is user-restricted) the user must be
  authorized in `mdl_external_services_users` within `validuntil`/IP rules.
  This is enforced by the core `webservice_base_server`, unchanged.
- R3.2 A function is callable only if **all** hold (core `load_function_info()`):
  1. the function exists and is not a "raw" one,
  2. the function name is present in `mdl_external_services_functions` for at
     least one enabled service the token's user may use (**the link table is
     the gate — the `mdl_external_functions.services` column is not consulted**),
  3. the user holds the capability(s) stored in `mdl_external_functions.capabilities`
     (e.g. `moodle/course:manageactivities`) in the target context,
  4. the service's `requiredcapability` (if any) is held.
- R3.3 `webservice/mcp:use` (db/access.php) is declared for compatibility
  with external policy tooling; the **effective** gates for this endpoint are
  R3.1 + R3.2. Do not rely on `webservice/mcp:use` alone for security.

## R4 · Data flow for a tool call

```
JSON-RPC request ──▶ request (immutable)
        │                 │ params.name / params.arguments
        ▼                 ▼
  authenticate_user ──▶ load_function_info (service link table + capabilities)
        │
        ▼
  server::execute()
    ├─ external_function::validate_parameters(desc, rawParams)
    ├─ build_positional_args(class, method, desc, validated)   ← 002 §R8
    ├─ get_plugins_with_function('override_webservice_execution')
    └─ call_user_func_array([class, method], args)
        │
        ▼
  send_response()
    ├─ clean_response(returns_desc, returns)   (throws invalid_response_exception)
    └─ { result: { content: [{type:"text", text: json}],
                   structuredContent: { result: <cleaned> } } }
```

## R5 · Database surface used

| Table | Read | Write | Used by |
|---|---|---|---|
| `mdl_external_tokens` | ✔ | – | token → service/user resolution |
| `mdl_external_services` | ✔ | – | service gating (enabled, restrictions) |
| `mdl_external_services_functions` | ✔ | – | **function availability** (tool list + call gating) |
| `mdl_external_services_users` | ✔ | – | user-restricted services |
| `mdl_external_functions` | ✔ (core) | – | name → class/method/capabilities |
| module tables (`course_sections`, `course_modules`, `mod_*`, `role_assignments`, …) | ✔ | ✔ | via the external functions (`004`) |

The plugin **never** writes web-service tables itself; activation of core
functions into the `moodle_mcp` service is an **admin action** (see `005`).

## R6 · Protocol & versioning invariants

- R6.1 JSON-RPC 2.0: exactly one of `result`/`error` per response; `id`
  echoed (null for notifications); errors use code `-32603` with a
  machine-readable `data` object (`002` §R7).
- R6.2 MCP `protocolVersion` is `2025-03-26`; `serverInfo` name is
  `Moodle MCP Server`; capabilities advertise `tools.listChanged: true`.
- R6.3 Plugin versioning: `version.php` `$plugin->version = YYYYMMDDNN`
  (NN increments per change-day), `$plugin->release` semver,
  `MATURITY_BETA`; `CHANGELOG.md` mirrors notable changes (`006` §3).

## R7 · Environment invariants

- R7.1 Target PHP ≥ 8.1; new/changed classes use `declare(strict_types=1)`.
- R7.2 Supported Moodle ≥ 4.5 (`$plugin->requires = 2024100700`). CI proves
  4.5 (MOODLE_405_STABLE) on pgsql+mariadb, PHP 8.1/8.2. Integration tests
  additionally target the local 5.x instance (`006` §5–6).
