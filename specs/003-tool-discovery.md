# 003 · Tool Discovery (`tools/list`)

Status: **normative**.

Implementation: `classes/local/tool_provider.php`
(`webservice_mcp\local\tool_provider::get_tools(string $token)`).
Tests: `tests/tool_provider_test.php`.

## R1 · Scope: service membership, not the whole world

- R1.1 `get_tools($token)` resolves the token **with `MUST_EXIST`** in
  `mdl_external_tokens` (unknown token → DML exception → `-32603` envelope,
  `002` §R7).
- R1.2 Tools are the functions listed in `mdl_external_services_functions`
  for the token's `externalserviceid`, in `functionname` order. **This is the
  same link table that gates `tools/call`** (`001` §R3.2), so `tools/list`
  and `tools/call` always agree on what is available.
- R1.3 For each function name: `external_api::external_function_info($name)`;
  **skip** (silently) functions whose info fails to load (missing class) or
  that are `deprecated`. Never let one broken function poison the list.

## R2 · Tool object shape

Each tool is:

```json
{
  "name": "<external function name>",
  "description": "<function description or ''>",
  "inputSchema":  { … JSON Schema of parameters_desc … },
  "outputSchema": { "type": "object", "properties": { "result": { … JSON Schema of returns_desc … } } }
}
```

- R2.1 `name` is the external function name verbatim (e.g.
  `mod_wiki_new_page`, `webservice_mcp_create_section`); it is what
  `tools/call.params.name` expects.
- R2.2 `outputSchema` always wraps the return description in a `result`
  property, mirroring the `structuredContent.result` response shape
  (`002` §R6).

## R3 · JSON Schema generation rules

`generate_schema(external_description)` maps Moodle external descriptions to
JSON Schema:

| Moodle | JSON Schema |
|---|---|
| `external_value(PARAM_INT)` | `{"type":"integer"}` |
| `external_value(PARAM_FLOAT)` | `{"type":"number"}` |
| `external_value(PARAM_BOOL)` | `{"type":"boolean"}` |
| `external_value(other)` | `{"type":"string"}` |
| `external_single_structure` | `{"type":"object","properties":{…}}` (+ `required`) |
| `external_multiple_structure` | `{"type":"array","items":{…}}` |
| `null` description | `{"type":"object","properties":{}}` |

- R3.1 `external_value`: `description` is copied when non-empty; `default` is
  copied only for `VALUE_DEFAULT` entries with a non-null default.
- R3.2 `external_single_structure`: the `required` array lists exactly the
  keys whose sub-description is `VALUE_REQUIRED` (tracked with an internal
  `_required` marker that is stripped before emission — it must never leak
  into the public schema).
- R3.3 Nested structures recurse; array items keep their sub-schema but drop
  the `_required` marker.
- R3.4 Type fidelity matters to LLM clients: do not widen types
  (an `PARAM_INT` must stay `integer`); unknown description kinds degrade to
  `{"type":"object"}`.

## R4 · Performance & safety invariants

- R4.1 `get_tools` performs one `MUST_EXIST` token read + one recordset read +
  per-function `external_function_info`; it must not write, must not trigger
  full course/module queries, and must be safe to call on every client
  session start (MCP clients call `tools/list` after `initialize`).
- R4.2 Output must be JSON-encodable (finite structures, no resources, no
  circular references). Encoding failures are handled by `002` §R3.4.

## Acceptance criteria

- [ ] `tools/list` returns exactly the non-deprecated functions of the token's
      service; adding a function to the service in the UI makes it appear
      (after cache purge) without any plugin change.
- [ ] Schemas satisfy the R3 mapping (covered by `tool_provider_test`).
- [ ] The response contains no `_required` keys and encodes as JSON.
