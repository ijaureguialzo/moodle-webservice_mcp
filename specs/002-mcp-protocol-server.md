# 002 · MCP Protocol Server

Status: **normative** — the protocol contract of `POST /webservice/mcp/server.php`.

Implementation: `classes/local/server.php` (`webservice_mcp\local\server`
extends `webservice_base_server`) + `classes/local/request.php`.
Tests: `tests/server_test.php`, `tests/request_test.php`.

## R1 · Entry point

- R1.1 `server.php` defines `NO_DEBUG_DISPLAY` and `WS_SERVER`, then
  `require '../../config.php'`.
- R1.2 If `webservice_protocol_is_enabled('mcp')` is false → `403 Forbidden`
  + developer debug note, `die` (no HTML, no Moodle bootstrap side effects).
- R1.3 The server is constructed with
  `WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN`; `run()` sets headers, raises the
  memory limit (`MEMORY_EXTRA`), extends the timeout
  (`external_api::set_timeout()`), installs the JSON-RPC exception handler,
  parses the request, then either handles an MCP method or defers to
  `parent::run()` (standard function pipeline).

## R2 · Request object (`request`)

- R2.1 `request::from_raw_input()` reads `php://input`, rejects empty bodies
  (`err_empty_request`), `json_decode`s **as an array** (`true`), rejects
  non-object JSON and malformed JSON (`err_invalid_json`, including the
  `json_last_error_msg()` detail).
- R2.2 Construction validates JSON-RPC 2.0 structure: `jsonrpc` must be the
  string `"2.0"` (`err_invalid_jsonrpc`); `method` must be a non-empty string
  (`err_missing_method`). `id` may be absent (notifications → null); `params`
  may be absent (null).
- R2.3 Instances are immutable value objects; the server keeps the parsed
  request in `$this->mcprequest` and reads `$jsonrpc`, `$id`, `$method`,
  `$params` from it when building responses.
- R2.4 `request::is_raw_input_empty()` treats `''` and `false` as empty.

## R3 · HTTP surface & headers

- R3.1 Every response is `Content-Type: application/json; charset=utf-8` with
  no-cache headers (`Cache-Control: private, must-revalidate, max-age=0`,
  `Expires: epoch`, `Pragma: no-cache`).
- R3.2 CORS: `Access-Control-Allow-Origin: *`, allowed headers
  `Content-Type, Authorization, X-Requested-With`, methods `GET, POST,
  OPTIONS`. (Tighten per deployment; see `006` §6 security note.)
- R3.3 `OPTIONS` → `200` empty (preflight). `GET` (authenticated) → plain
  server-info JSON: `{name, version, protocolVersion, capabilities}`.
- R3.4 JSON encoding always uses `JSON_UNESCAPED_UNICODE |
  JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`; on encode failure a minimal
  `-32603 Internal JSON encoding error` envelope is emitted instead of raw PHP
  output.

## R4 · MCP methods (POST)

| Method | Response |
|---|---|
| `initialize` | `result = { protocolVersion: "2025-03-26", capabilities: { tools: { listChanged: true } }, serverInfo: { name: "Moodle MCP Server", version: <const> }, instructions: "Moodle MCP server initialized successfully" }` |
| `notifications/initialized` | HTTP `204`, **no body** (MCP notifications expect none) |
| `ping` | `result = {}` (empty JSON object) |
| `tools/list` | `result = { tools: [ … ] }` — content per `003` |
| `tools/call` | standard pipeline (`R8`); result shape per `R6` |

- R4.1 Any other method → JSON-RPC error `-32601 Method not found` (with the
  request `id`); a request with a body but no `method` → `400` + `-32600
  Invalid Request`.
- R4.2 MCP methods (`initialize`, `notifications/initialized`, `ping`,
  `tools/list`) **do not** set `$this->functionname`; `run()` routes them to
  `handle_mcp_method()` after `authenticate_user()` and then ends the request
  (`session_cleanup(); die;`). They are reachable only with a valid token.
- R4.3 `initialize`/`ping`/`tools/list` responses must carry the request's
  original `jsonrpc` and `id`.

## R5 · `tools/call` request shape

- R5.1 `params.name` is **required** (string): the external function name,
  e.g. `webservice_mcp_create_section` or `mod_wiki_new_page`. Missing/empty →
  `moodle_exception('err_missing_tool_name', 'webservice_mcp')` → JSON-RPC
  error envelope (`R7`).
- R5.2 `params.arguments` is the raw argument object (default `{}` when
  absent). Values keep their JSON types; scalars stay scalars, objects become
  PHP arrays (assoc decode). **Clients may omit any optional parameter** —
  the dispatch must tolerate partial arguments (`R8`).

## R6 · Success response shape

For a successful `tools/call`:

```json
{
  "jsonrpc": "2.0",
  "id": <request id>,
  "result": {
    "content": [ { "type": "text", "text": "<json of {result: <cleaned>}>" } ],
    "structuredContent": { "result": <cleaned return value> }
  }
}
```

- R6.1 The raw return value is first passed through `clean_response()` with
  the function's `returns_desc` (Moodle's `external_api` semantics, local
  copy with recursive path-building debuginfo). Functions with
  `returns_desc === null` pass the value through untouched.
- R6.2 `text` is the JSON of `{"result": <cleaned>}` (unicode-friendly
  encoding); `structuredContent` duplicates it as a real JSON object for
  clients that prefer structured output.
- R6.3 Validation failure of the *return value*
  (`invalid_response_exception`) is reported as a JSON-RPC error (`R7`),
  never as a PHP-fatal page.

## R7 · Error envelope

All failures return HTTP 200 with:

```json
{
  "jsonrpc": "2.0",
  "error": {
    "code": -32603,
    "message": "<exception message>",
    "data": {
      "exception": "<FQCN>",
      "message": "<message>",
      "code": <int code>,
      "errorcode": "<moodle errorcode, when present>",
      "debuginfo": "<only when debugging() is on>"
    }
  },
  "id": <request id or null>
}
```

- R7.1 Protocol-level errors keep their JSON-RPC codes: `-32700` parse,
  `-32600` invalid request, `-32601` method not found; Moodle/external
  exceptions are mapped to `-32603` **with** the `data` object above (this is
  the MCP-friendly diagnostic channel; `debuginfo` is the field to read when
  debugging validation failures).
- R7.2 With developer debugging on, the full backtrace is additionally logged
  via `debugging()` (`log_exception_for_debug`).
- R7.3 The exception handler is installed per request; uncaught Throwables
  must never produce HTML or raw PHP error output on this endpoint.

## R8 · Position-safe execution (critical invariant)

`server::execute()` (override of `webservice_base_server::execute()`):

1. `$validated = external_function::validate_parameters(parameters_desc, $this->parameters)` —
   core validation of the raw arguments; the result is keyed by schema key and
   **contains only the keys the framework kept** (omitted optional params may
   be absent).
2. `$args = build_positional_args(classname, methodname, parameters_desc, $validated)`
   — reconstructs the **complete** positional list:
   - for each schema key **in declared order**: the validated value if the
     caller supplied it; otherwise the **method's** PHP default (via
     `ReflectionMethod`); failing that, the schema default (`external_value::default`);
     failing that, `null`.
   - then padded/trimmed to the exact method arity.
   - If reflection fails (weird class) → falls back to core behaviour
     (`array_values($validated)`).
3. Runs the `override_webservice_execution` plugin callbacks exactly like core.
4. `call_user_func_array([class, method], $args)`.

- R8.1 **Must not regress to `array_values($validated)`**: re-indexing shifts
  later values into slots of omitted optional parameters (the P1 trap: an
  `extrafields` array landing in `$contentformat` → spurious
  `invalidparameter: "contentformat => Scalar type expected, array or object
  received."`). Any future refactor of dispatch must keep a test that calls a
  function with a *middle optional parameter omitted* and a *later parameter
  present* (see `006` §2).
- R8.2 Method parameter **names and order** must match the schema keys (the
  external API convention; see `004` §R1) — the back-fill relies on schema
  order == signature order.
- R8.3 The back-fill must never fabricate values for `VALUE_REQUIRED` keys
  (validation already failed); it only completes *optional* slots.

## Acceptance criteria (summary)

- [ ] `curl` of `initialize` / `ping` / `tools/list` with a valid token returns
      the shapes above with the request `id` echoed; with no/invalid token the
      standard core token errors are returned as JSON (never HTML).
- [ ] `tools/call` with a complete argument object works for any service function.
- [ ] `tools/call` with **partial** arguments (middle optional omitted) invokes
      the function with correct per-slot values (P1 regression test).
- [ ] Every failure path returns a parseable JSON-RPC error envelope (R7) with
      `data.debuginfo` present under `debug=31`.
- [ ] `GET` and `OPTIONS` behave per R3.2–R3.3.
