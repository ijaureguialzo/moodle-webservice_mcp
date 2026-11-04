# 004 · Custom External Functions

Status: **normative**.

These are the **write** operations core Moodle does not expose as web
services, contributed by this plugin. Both are registered in
`db/services.php` for the `moodle_mcp` service (capability
`moodle/course:manageactivities`, `type: 'write'`) — see `005` §B1 for the
activation side.

Implementation: `classes/local/external/create_section.php`,
`classes/local/external/create_course_module.php`
(namespace `webservice_mcp\local\external`, `declare(strict_types=1)`).

## R0 · External function contract (applies to every function in this plugin)

- R0.1 **Individual method parameters**: the method must declare one argument
  per schema key, **same names, same order** as
  `external_function_parameters`. `external_api::call_external_function()`
  invokes via `call_user_func_array()` with a positional array — a single
  array argument breaks at runtime.
- R0.2 Re-validate inside the body with
  `self::validate_parameters(self::…_parameters(), compact('key1','key2',…))`
  (Moodle convention; protects direct callers).
- R0.3 Authorize with `self::validate_context($context)` +
  `require_capability(…)`, then perform the write, then trigger the relevant
  core events and refresh caches (`get_fast_modinfo(…, 0, true)`).
- R0.4 Return an `external_single_structure` with at least `status` (bool)
  plus the new ids and `external_warnings()`.
- R0.5 Class/method naming: class `webservice_mcp\local\external\<name>`,
  static method `<name>`; function name `webservice_mcp_<name>`.
- R0.6 Every new function: spec block here + activation block in `005` +
  `version.php` bump + `CHANGELOG.md` + tests.

---

## B1 · `webservice_mcp_create_section`

**Purpose**: create (or rename) a course section with name/summary — the
building block for multi-unit courses.

| Parameter | Type | Req | Default | Meaning |
|---|---|---|---|---|
| `courseid` | int | ✔ | – | course |
| `number` | int | – | `null` | section number; `null` = next free number (`MAX(section)+1`) |
| `name` | text | – | `''` | section name |
| `summary` | raw | – | `''` | HTML summary |
| `visible` | bool | – | `true` | visibility |

Returns: `{status, sectionid, sectionnumber, warnings}`.

Behaviour:
1. Capabilities: `moodle/course:manageactivities` on the course context.
2. When the section number is missing, create it through the course-format
   API (`core_courseformat\formatactions::section($course)->create_if_missing([$n])`)
   so ordering/sequence stays format-consistent; refresh `get_fast_modinfo`.
3. Update `course_sections` (name, summary+`FORMAT_HTML`, visible) and
   trigger `core\event\course_section_updated` (`other.sectionnum`).

---

## B2 · `webservice_mcp_create_course_module`

**Purpose**: create any module instance (page, forum, url, resource, wiki, …)
in a course section — core Moodle has **no** generic "create activity"
web service, so this is the workhorse for course building.

| Parameter | Type | Req | Default | Meaning |
|---|---|---|---|---|
| `courseid` | int | ✔ | – | course |
| `modname` | text | ✔ | – | module plugin name (`page`, `forum`, `url`, `resource`, `wiki`, …) |
| `name` | text | ✔ | – | activity name |
| `section` | int | – | `0` | section **record id** or **number** (resolved, see below) |
| `intro` | raw | – | `''` | intro/description (HTML) |
| `content` | raw | – | `''` | body (page content; **url link** for `modname=url`) |
| `contentformat` | int | – | `FORMAT_HTML` | content format |
| `visible` | bool | – | `true` | visibility |
| `extrafields` | multiple{name: alphanumext, value: raw} | – | `[]` | extra **module-table** columns (see `R4`) |

Returns: `{status, coursemoduleid, instanceid, cmurl, warnings}`.

Behaviour:
1. `resolve_section()`: if `section` matches an existing `course_sections.id`
   in the course, map it to the section **number** (what `create_module()`
   needs); otherwise use it as the number.
2. Capability `moodle/course:manageactivities`; include the module library
   (`include_modulelib($modname)`) so `<modname>_add_instance()` exists.
3. Build `$moduleinfo` with core's mandatory fields:
   - `intro`/`introformat` **always** set (modules such as `forum` have
     `NOT NULL` intro); `introeditor` array for `FEATURE_MOD_INTRO` modules
     (`itemid: 0` = no draft files) — `create_module()` requires it.
   - `content` → `externalurl` for `modname = url` (NOT NULL column), else
     `content`/`contentformat`; `display = RESOURCELIB_DISPLAY_AUTO` for
     resource-like modules; `http://` placeholder when a url has no link.
   - `modname = page`: set `printintro`/`printlastmodified` display-option
     defaults (core `page_add_instance()` reads them unconditionally; the
     values are filtered out by `insert_record()` since they are not table
     columns — this keeps the create warning-free under developer debugging).
4. `extrafields` handling (R4) — **before** `create_module()`.
5. `create_module($moduleinfo)` → returns cm; set the instance id, build the
   `cmurl` via `moodle_url('/mod/<modname>/view.php', ['id' => cmid])`,
   refresh `get_fast_modinfo`, trigger `core\event\course_module_created`.

### R4 · `extrafields` whitelist (safety contract)

- R4.1 Only rows of `{name, value}` pairs where `name` matches
  `/^[a-z][a-z0-9_]*$/`, is a **real column of the module instance table**
  (resolved via `$DB->get_columns($modname)` — the table is the modname, e.g.
  `wiki` → `mdl_wiki`; column names read via the magic property
  `$column->name`, P2/P3), and is **not** in the reserved list
  (`id, course, name, intro, introformat, content, contentformat,
  externalurl, visible, section, timemodified, timecreated`) are applied to
  `$moduleinfo`.
- R4.2 Anything else is **silently skipped** (never an error): the caller
  cannot clobber core bookkeeping or inject columns that don't exist.
- R4.3 Typical uses: `wiki` → `defaultformat` (`html`/`creole`/`nwiki` —
  P4: **not** `editor_tiny`), `forceformat`, `firstpagetitle`, `wikimode`;
  other modules as their tables allow.
- R4.4 A missing/unknown module table (DML exception) degrades to "no extra
  fields", never to a failed create.

### Module quirks the body must keep satisfying (verified in integration)

| modname | Quirk |
|---|---|
| `forum` | `intro` NOT NULL; needs `introeditor` |
| `url` | `externalurl` NOT NULL (default `http://`) |
| `page` | `page_add_instance()` reads `printintro`/`printlastmodified` unconditionally |
| `wiki` | page formats limited to `creole`/`nwiki`/`html` (P4); set `defaultformat`/`forceformat` via `extrafields` |
| any | `create_module()` requires `introeditor` for `FEATURE_MOD_INTRO`; section must be a **number** |

---

## C · Checklist: adding a new custom external function

1. **Spec first**: add a `B<n>` block here (parameters/returns/behaviour/
   quirks) and an activation block in `005` §B (which functions to enable).
2. **Code**: new class in `classes/local/external/` following `R0`;
   `declare(strict_types=1)`; full docblocks; Moodle coding style.
3. **Register** the function in `db/services.php`
   (`classname`, `methodname`, `description`, `type: 'write'`,
   `capabilities`, `services: ['moodle_mcp']`).
4. **Version**: bump `version.php` (`YYYYMMDDNN`, increment NN; `release`
   patch/minor) + `CHANGELOG.md` entry.
5. **Tests**: at minimum parameter validation (required/optional/defaults)
   and one success path per function, in `tests/` (PHPUnit,
   `advanced_testcase` / `externallib_advanced_testcase` patterns used by the
   existing suites).
6. **Integration**: run the function through the live MCP endpoint with
   **partial** arguments (middle optional omitted — `002` §R8 regression).
7. **Deliver**: Conventional Commits + LLM co-author trailer (`006` §4).
