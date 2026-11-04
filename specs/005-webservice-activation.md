# 005 · Web-Service Activation (which functions to enable for each feature)

Status: **normative (operations)**.

The MCP endpoint only exposes and executes functions that are **assigned to
the token's external service** (the `mdl_external_services_functions` link
table — `001` §R3.2). Core Moodle functions are *not* added to any service
automatically; the two plugin functions are registered by the plugin itself
(`db/services.php`, `services => ['moodle_mcp']`) but the **service must
exist** and be **enabled**, and core functions must be **added by an admin
through the service UI**.

This document is organized so that a human (or agent) can add feature blocks
as needed: **§A** general prerequisites (do once), **§B** one block per
feature/module (enable per need, copy the template from `specs/README.md`),
**§C** current verified state, **§D** operational notes.

---

## A · General prerequisites (one-time, admin)

> All paths: **Site administration** unless noted.

| # | Step | Where | Notes |
|---|---|---|---|
| A1 | **Enable web services** | *Advanced features* → *Enable web services* = Yes | Nothing works without it; the endpoint returns 403 |
| A2 | **Install the plugin** | *Plugins → Install plugins from filesystem* (or plugin directory / git clone into `webservice/mcp`) | Installing registers the `mcp` **protocol** automatically (it is a `webservice`-type plugin, `001` §R1.1) |
| A3 | **Enable the MCP protocol** | *Plugins → Web services → Manage protocols* → enable *Model Context Protocol (MCP)* | Stored in `mdl_config.webserviceprotocols` |
| A4 | **Create the external service** | *Server → Web services → External services → Add service* | Name: `Moodle MCP` · Short name: **`moodle_mcp`** · Enabled: Yes · *Authorized users only*: No (or add users explicitly). The short name must match the plugin's `db/services.php` `services => ['moodle_mcp']` for the two plugin functions to bind to it on upgrade |
| A5 | **Add functions to the service** | *External services → `Moodle MCP`* → *Add function(s)* | Pick the functions listed in the §B blocks you need. **This is the step that makes features appear** (link table) |
| A6 | **Create a token** | *Server → Web services → Manage tokens → Add token* | User + service. The token authenticates as that user; capability checks use the user's roles (`001` §R3.2) |
| A7 | **Verify** | `tools/list` (below) | Count of tools = functions in the service; try one call |

```bash
curl -sk https://moodle.test/webservice/mcp/server.php \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <TOKEN>' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' |
  python3 -c 'import json,sys; d=json.load(sys.stdin); print(len(d["result"]["tools"]), "tools")'
```

**How the gates work** (so you can debug "why is this tool missing/calling
failing"):

1. **Token** must belong to an *enabled* service (`mdl_external_services.enabled=1`).
2. **Function** must be in that service's function list (link table). Missing
   from `tools/list` ⇒ not added in A5 (or the function is *deprecated*, which
   `tools/list` skips — `003` §R1.3).
3. **User** (the token's) must hold the function's capability in the target
   context (e.g. a *teacher* or *editing teacher* role on the course for
   `moodle/course:manageactivities`), and the service's `requiredcapability`
   if set.
4. If the service is *authorized users only*, the user must be in the
   service's user list within `validuntil`/IP rules.

---

## B · Feature blocks (enable what you need)

Each block: purpose → functions to add → capability → example call → pitfalls.
Blocks B1–B8 below reflect the **current** setup; B9 shows the
copy-this template for new blocks (`specs/README.md`).

### B1 · Course structure — sections & activities (the plugin's own tools)

**Purpose**: build courses: sections with names/summaries, and any
activity/resource inside them (pages, forums, urls, wikis, …).

| Function | Used for | Capability |
|---|---|---|
| `webservice_mcp_create_section` | create/rename sections | `moodle/course:manageactivities` |
| `webservice_mcp_create_course_module` | create any module (page/url/forum/wiki/…) with `extrafields` | `moodle/course:manageactivities` |

Both are plugin functions: registered by `db/services.php` for `moodle_mcp`
on upgrade — **no UI action needed** beyond A4 existing; they appear in
`tools/list` automatically. See `004` for the full parameter specs.

```json
{"jsonrpc":"2.0","id":1,"method":"tools/call",
 "params":{"name":"webservice_mcp_create_course_module",
   "arguments":{"courseid":13,"modname":"page","name":"U1 · Teoría",
                "section":1,"intro":"<p>…</p>","content":"<h2>…</h2>",
                "contentformat":1,"visible":true}}}
```

**Pitfalls**: `section` accepts a section *record id* or a *number*; middle
optional params may be omitted safely (`002` §R8); wiki pages must use
`extrafields: defaultformat=html` (P4).

### B2 · Courses & categories (CRUD + discovery)

**Purpose**: create/update/delete courses and categories; read structure.

| Function | Used for |
|---|---|
| `core_course_create_courses` / `core_course_update_courses` / `core_course_delete_courses` | course CRUD |
| `core_course_get_courses` / `core_course_get_courses_by_field` / `core_course_search_courses` | find courses |
| `core_course_get_categories` / `core_course_create_categories` / `core_course_update_categories` / `core_course_delete_categories` | category tree |
| `core_course_get_contents` | **the workhorse**: full section/module tree of a course (returns a *list* of sections; each has `modules[]` with `name`, `modname`, `instance`, `id`) |
| `core_course_get_course_module` / `core_course_get_course_module_by_instance` | cm lookups |
| `core_course_delete_modules` | delete activities (cleanup) |
| `core_courseformat_get_section_content_items` / `core_courseformat_new_module` | course-format aware reads/alt. creation |

Capability: `moodle/course:view` (reads) / `moodle/course:created` /
`moodle/course:delete` / `moodle/course:category` as applicable.

```json
{"jsonrpc":"2.0","id":1,"method":"tools/call",
 "params":{"name":"core_course_get_contents","arguments":{"courseid":13}}}
```

**Pitfalls**: `core_course_get_contents` returns a **list** (not a dict with a
`sections` key) — handle both shapes defensively in scripts.

### B3 · People: users, roles & enrolments (make admin a professor, etc.)

**Purpose**: enroll users with roles (e.g. `admin` as *editing teacher* in a
course), read rosters, manage roles.

| Function | Used for | Capability |
|---|---|---|
| `core_user_get_users` / `core_user_get_users_by_field` | find users | `moodle/user:view` |
| `core_user_create_users` / `core_user_update_users` | manage users | `moodle/user:create/update` |
| `enrol_manual_enrol_users` | **enrol with role**: `{enrolments:[{courseid, userid, roleid}]}` | `moodle/enrol:enrol` |
| `enrol_manual_unenrol_users` | unenrol | `moodle/enrol:enrol` |
| `core_enrol_get_enrolled_users` | roster of a course (verifies the above) | `moodle/enrol:enrol` |
| `core_enrol_get_users_courses` | courses of a user | `moodle/user:view` |
| `core_enrol_get_potential_users` | candidates for enrolment | `moodle/enrol:enrol` |
| `core_role_assign_roles` / `core_role_unassign_roles` | role assignments (context-scoped) | `moodle/role:assign` |

Reference ids (verify per instance): role `editingteacher = 3`,
`teacher = 4`, `student = 5`. Context ids are a global auto-increment: read
them dynamically (`SELECT id FROM mdl_context WHERE contextlevel = 40 AND
instanceid = <courseid>` — level 40 = course) instead of assuming any formula.

```json
{"jsonrpc":"2.0","id":1,"method":"tools/call",
 "params":{"name":"enrol_manual_enrol_users",
   "arguments":{"enrolments":[{"courseid":13,"userid":2,"roleid":3,
                                "timestart":0,"timeend":0}]}}}
```

### B4 · Forums (discussions)

**Purpose**: seed/manage discussion forums (Q&A per unit).

| Function | Used for |
|---|---|
| `mod_forum_add_discussion` | new discussion `{forumid, subject, message, messageformat}` |
| `mod_forum_add_discussion_post` | reply `{discussionid, parentid, message, messageformat}` |
| `mod_forum_get_forums_by_courses` | list forums of courses |
| `mod_forum_get_forum_discussions` / `mod_forum_get_discussion_posts` | read |
| `mod_forum_update_discussion_post` / `mod_forum_delete_post` | edit/remove |
| `mod_forum_view_forum` | render |

Capability: `mod/forum:adddiscussion` / `mod/forum:discussiondelete` (the
*teacher* role covers the first two; check the rest for your use case).
A full read/write forum set (23 functions) is already in the current service
(§C). **Note**: the *forum instance* itself is created via B1
(`webservice_mcp_create_course_module` with `modname: "forum"` — `intro` is
NOT NULL).

### B5 · Wiki — book-like manuals (10 functions)

**Purpose**: multi-page editable "books" (consultation manuals per unit).

| Function | Used for |
|---|---|
| `mod_wiki_new_page` | create page `{title, content, contentformat?, wikiid or subwikiid}` |
| `mod_wiki_edit_page` | edit page `{pageid, content, section?}` (no title param; format comes from the wiki) |
| `mod_wiki_get_subwiki_pages` | list pages `{wikiid, groupid=-1, userid=0, options{includecontent}}` |
| `mod_wiki_get_subwikis` | list subwikis `{wikiid}` (returns `{subwikis:[…]}`) |
| `mod_wiki_view_wiki` / `mod_wiki_view_page` | render |
| `mod_wiki_get_page_contents` / `mod_wiki_get_page_for_editing` / `mod_wiki_get_subwiki_files` | content/file access |
| `mod_wiki_get_wikis_by_courses` | list wikis of courses |

Capability: `mod/wiki:editpage` / `mod/wiki:createpage` (covered by teacher).

**Recipe (verified)**: create the instance with B1 +
`extrafields: [{defaultformat:"html"},{forceformat:"1"},{firstpagetitle:"…"}]`,
then `mod_wiki_new_page` per page with `contentformat: "html"`.

**Pitfalls (P4)**: wiki page parsers exist only for **`creole`, `nwiki`,
`html`** (`mod/wiki/parser/markups/`). `editor_tiny` (or any site editor)
fails with `Parser type editortiny not found` on create **and** on every
subsequent read of affected pages. `new_page` with an explicit
`subwikiid: -1` fails (`!empty(-1)` → looks up a non-existent subwiki):
pass `wikiid` instead and let core create the main subwiki. A wiki created
via the instance form has **no first page** until the first
`new_page`/`edit_page`.

### B6 · Glossary (optional block — example of a *not-yet-enabled* family)

**Purpose**: terminology glossaries. *Not enabled in the current service*;
enable on demand.

| Function | Used for |
|---|---|
| `mod_glossary_add_entry` | **write entry** `{glossaryid, entryid?, content, definitiontext, definitionformat}` |
| `mod_glossary_update_entry` / `mod_glossary_delete_entries` | edit/remove |
| `mod_glossary_get_all_entries` / `mod_glossary_get_entries_by_search` | read |
| `mod_glossary_get_glossaries_by_courses` | discover instances |

Capability: `mod/glossary:entryadd/update/delete`. The **glossary instance**
is created via B1 (`modname: "glossary"`). Verify exact names in the service
function picker before documenting a block as verified.

### B7 · Book — *not writable in core* (informational)

Core exposes only **read** functions for the book module
(`mod_book_get_books_by_courses`, `mod_book_view_book`, …); there is **no**
chapter-creation web service. For "book" content, use **B5 wiki** (multi-page
editable) or a single long `page` (B1). Do not promise book content creation
through MCP.

### B8 · Site & session info

| Function | Used for |
|---|---|
| `core_webservice_get_site_info` | site name, URL, versions, user, themes — good health/identity probe |
| `core_user_get_user_preferences` / `core_user_set_user_preferences` | per-user preferences if needed |

No special capability (the token's own user).

### B9 · Template for new feature blocks

Copy this, fill it in, enable the functions in A5, verify, then mark it
verified with the function count and one real example (template also in
`specs/README.md`):

```markdown
### B<n> · <Feature> (<modname>)
**Purpose**: …
**Enable in the `moodle_mcp` service**:
| Function | Used for | Capability |
|---|---|---|
**Plugin code required**: none | …
**Example tools/call**: …
**Pitfalls / notes**: …
```

---

## C · Current verified state (local instance, service `moodle_mcp` id 2)

100 functions enabled, grouped (this is what `tools/list` returns, minus
any deprecated functions it skips):

| Group | Count | Functions |
|---|---|---|
| **Plugin** | 2 | `webservice_mcp_create_section`, `webservice_mcp_create_course_module` |
| **Courses** | 38 | `core_course_*` (31: create/update/delete courses, categories, search, get_contents, get_course_module[_by_instance], delete_modules, duplicate, import, favourites, view_*, updates) + `core_courseformat_*` (7: new_module, update_course, get_state, get_section_content_items, get_overview_information, file_handlers, log_view_*) |
| **Enrolment** | 3 | `core_enrol_get_enrolled_users`, `core_enrol_get_users_courses`, `core_enrol_get_potential_users` |
| **Manual enrol** | 2 | `enrol_manual_enrol_users`, `enrol_manual_unenrol_users` |
| **Roles** | 2 | `core_role_assign_roles`, `core_role_unassign_roles` |
| **Users** | 21 | `core_user_*` (get_users[_by_field], search_identity, create/update/delete_users, preferences, private files, devices, picture, view_*, agree_site_policy, add_user_private_files) |
| **Forum** | 21 | `mod_forum_*` (add_discussion[_post], update/delete post, get_forums_by_courses, get_discussion[_posts][_by_userid], forum access info, mark read, prepare draft area, subscription/tracking/lock/pin/favourite setters, view_forum[_discussion], can_add_discussion) |
| **Wiki** | 10 | `mod_wiki_*` (new_page, edit_page, view_wiki, view_page, get_subwikis, get_subwiki_pages, get_page_contents, get_page_for_editing, get_subwiki_files, get_wikis_by_courses) |
| **Site** | 1 | `core_webservice_get_site_info` |

The current service row: name `Moodle MCP`, shortname `moodle_mcp`,
enabled=1, **restrictedusers=0** (any user's token works; flip to 1 to lock
to named users via *Authorized users*).

---

## D · Operational notes (activation gotchas)

- **D1 — The `services` column is not a gate.** `mdl_external_functions`
  stores the plugin-declared shortname (`moodle_mcp`), but *call* gating and
  *tool listing* both use the link table `mdl_external_services_functions`
  (`001` §R3.2). "The function is registered in db/services.php but missing"
  ⇒ it was never added to the service in A5 (or a later upgrade pruned it —
  see D3).
- **D2 — opcache** (P6): after adding functions or changing plugin code, the
  *tool list* comes from DB (fresh) but the *execution* may run stale PHP in
  long-lived workers → restart apache/php-fpm + `admin/cli/purge_caches.php`.
- **D3 — upgrades can prune service links.** `db/services.php` processing on
  upgrade re-syncs the plugin's own functions; links for *core* functions
  that an admin added manually survive, but a service deleted from
  `db/services.php` conventions can be rebuilt — if a function disappears
  after an upgrade, re-add it in A5.
- **D4 — debugging inverts symptoms** (P5): verify features in the same
  debug mode you will run in production (`debug=0`); use `debug=31`
  + `debuginfo` to diagnose, then reset.
- **D5 — capability errors are by design** (`001` §R3.2): "you are not
  allowed to access" on a write tool usually means the token's user lacks
  the capability in that context (enrol them via B3 first).
