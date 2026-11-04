# `specs/` — Specification System (SDD)

This directory is the **source of truth** for the behaviour of the
`webservice_mcp` plugin. Code must satisfy these documents; where they
disagree, the spec wins (fix the code, or fix the spec with justification —
see `../AGENTS.md` §4).

## Document layout

| File | Kind | Purpose |
|---|---|---|
| `001-architecture.md` | Architecture | Components, lifecycle, invariants, DB surface |
| `002-mcp-protocol-server.md` | Behavioural spec | MCP/JSON-RPC protocol contract of the endpoint |
| `003-tool-discovery.md` | Behavioural spec | `tools/list` semantics and JSON Schema rules |
| `004-external-functions.md` | Behavioural spec | The custom external functions + how to add more |
| `005-webservice-activation.md` | Operations spec | Web-service functions to enable per feature |
| `006-quality-and-delivery.md` | Process spec | Standards, testing, versioning, commits |

Numbering: `NNN-kebab-case-slug.md`, three digits, ascending by dependency
(001 is the foundation, 006 is process). When adding a document, take the
next free number; never renumber existing ones (agents' bookmarks and
cross-references would break).

## Writing conventions

- **Requirements** are numbered `R1, R2, …` per document and are normative
  ("must"/"must not"). Numbering is stable: never renumber, reuse freed
  numbers for new requirements.
- **Interfaces** (method signatures, JSON shapes) are quoted verbatim from
  the code or the protocol spec.
- **Acceptance criteria** are bullet lists written as observable outcomes
  (a test or a curl command must be able to prove each one).
- Cross-references use relative links to other spec files and section names.
- Language: English (the project's language). Keep it terse; no marketing prose.

## Adding a new feature (template)

A "feature" in this project is typically one of:

1. **A new MCP capability** (protocol-level) → new section in `002` or a new
   numbered document + code in `classes/local/`.
2. **A new custom external function** (a new write operation, e.g.
   `webservice_mcp_add_forum_discussion`) → extend `004` with the function
   spec **and** add the enabling functions to `005` (see below).
3. **A new module family usable through MCP** (glossary, quiz, book, …) →
   add a **feature block** to `005-webservice-activation.md` (usually no
   plugin code needed, because the function is a core one).

### Feature block template (for `005-webservice-activation.md` §B)

When a human (or agent) needs a new module/feature, copy this block into
`005` §B, fill it in, enable the functions in the service UI, and verify:

```markdown
### B<n> · <Feature name> (<modname>)

**Purpose**: <one line — what this lets agents do through MCP>

**Enable in the `moodle_mcp` service** (Site admin → Server → Web services
→ External services → `Moodle MCP` → *Add function(s)*):

| Function | Used for | Capability |
|---|---|---|
| `mod_<modname>_<fn>` | <verb + object> | <capability> |

**Plugin code required**: none | `webservice_mcp_<fn>` (see specs/004 §B<n>)

**Example `tools/call`**:
```json
{"jsonrpc":"2.0","id":1,"method":"tools/call",
 "params":{"name":"mod_<modname>_<fn>","arguments":{ ... }}}
```

**Pitfalls / notes**: <verified quirks, formats, NOT NULL columns, …>
```

After enabling, verify with `tools/list` (count goes up) and one real call.
Record the verified state (function count, examples) back into the block.

## Spec → code → commit flow (recap)

```
spec (specs/*.md)  →  implementation (classes/, db/)  →  tests (tests/)
        ↑                                                        ↓
        └──────── version bump (version.php, CHANGELOG.md)  ←──┘
                            ↓
        Conventional Commits + "Co-authored-by: <LLM>" trailer
```

See `../AGENTS.md` §4–5 for the normative version.
