# Moodle MCP Web Service Plugin

A Moodle web service plugin that implements the **Model Context Protocol (MCP)** for seamless integration with AI assistants and external systems. This plugin exposes Moodle's external functions as MCP tools using JSON-RPC 2.0, making them discoverable and callable by MCP-compatible clients.

## Features

- **MCP Protocol Implementation**: Full support for Model Context Protocol using JSON-RPC 2.0
- **Dynamic Tool Discovery**: Automatically exposes Moodle external functions as MCP tools
- **JSON Schema Generation**: Converts Moodle parameter descriptions to JSON Schema for better tool documentation
- **Token-based Authentication**: Secure access using Moodle's external service tokens
- **Service-aware**: Only exposes functions available to the authenticated service
- **Well-tested**: Comprehensive PHPUnit test coverage
- **Easy Integration**: Built-in client class for quick integration with other systems

## What is MCP?

The **Model Context Protocol (MCP)** is an open protocol that standardizes how applications provide context to AI assistants and Large Language Models (LLMs). It allows AI assistants to:

- Discover available tools and their capabilities
- Invoke tools with proper parameters
- Receive structured responses

This plugin bridges Moodle's web services with the MCP protocol, enabling AI assistants to interact with your Moodle instance in a standardized way.

## Requirements

- **Moodle**: 4.5 or higher
- **PHP**: 8.1 or higher
- **Moodle Web Services**: Must be enabled

## Installation

### Method 1: Via Moodle Plugin Directory (Recommended)

1. Visit **Site administration → Plugins → Install plugins**
2. Search for "MCP Web Service"
3. Click **Install** and follow the on-screen instructions

### Method 2: Manual Installation

1. Download the plugin or clone from repository:
   ```bash
   cd /path/to/moodle/webservice
   git clone https://github.com/onbirdev/moodle-webservice_mcp.git mcp
   ```

2. Visit **Site administration → Notifications** to complete the installation

3. The plugin will be installed as `webservice_mcp`

## Configuration

### 1. Enable Web Services

1. Go to **Site administration → Advanced features**
2. Enable **Enable web services**
3. Save changes

### 2. Enable MCP Protocol

1. Go to **Site administration → Plugins → Web services → Manage protocols**
2. Enable **Model Context Protocol (MCP)**

### 3. Create an External Service

1. Go to **Site administration → Server → Web services → External services**
2. Click **Add** to create a new service
3. Configure the service:
   - **Name**: e.g., "MCP Service"
   - **Short name**: e.g., "mcp_service"
   - **Enabled**: Yes
   - **Authorized users only**: Yes (recommended)
4. Add the external functions your service should expose

### 4. Create a Token

1. Go to **Site administration → Server → Web services → Manage tokens**
2. Click **Add** to create a new token
3. Select:
   - **User**: The user this token will authenticate as
   - **Service**: The service you created above
4. Save and copy the generated token

### 5. Assign Capability

Ensure users have the `webservice/mcp:use` capability to access the MCP web service.

## Resource capabilities (what you can create / edit / delete)

The table below lists each resource type and what an MCP client can do with
it. Legend: ✅ fully supported · ⚠️ partially supported · ❌ no web service
for it (not possible through MCP).

Two things make resources work:

1. **The two plugin functions are registered automatically** for the
   `moodle_mcp` service when the plugin is upgraded (`webservice_mcp_create_section`
   and `webservice_mcp_create_course_module`) — no UI action needed, they
   appear in `tools/list` on their own.
2. **Every core function in the last column must be added by an admin** to
   your service (Site administration → Server → Web services → External
   services → your service → *Add function(s)*). The `mdl_external_services_functions`
   link table is what gates both tool visibility and execution. The full
   per-feature breakdown lives in [`specs/005-webservice-activation.md`](specs/005-webservice-activation.md).

| Resource | Create | Edit | Delete | Core web-service functions to enable |
|---|---|---|---|---|
| **Course** | ✅ | ✅ | ✅ | `core_course_create_courses`, `core_course_update_courses`, `core_course_delete_courses` |
| **Category** | ✅ | ✅ | ✅ | `core_course_create_categories`, `core_course_update_categories`, `core_course_delete_categories` |
| **Section** | ✅ plugin `webservice_mcp_create_section` (also renames) | ⚠️ rename via the same plugin function | ❌ no web service | none (plugin auto-registered) |
| **Any activity (generic delete)** | ✅ plugin `webservice_mcp_create_course_module` (any `modname`) | — | ✅ `core_course_delete_modules` (list of cm ids) | `core_course_delete_modules` |
| **Page** | ✅ plugin | ❌ no core write service | ✅ `core_course_delete_modules` | `core_course_delete_modules` |
| **URL / file Resource / Folder / Label** | ✅ plugin | ❌ no core write services | ✅ `core_course_delete_modules` | `core_course_delete_modules` |
| **Forum** | ✅ plugin | ⚠️ discussions: `mod_forum_add_discussion`, `mod_forum_add_discussion_post`, `mod_forum_update_discussion_post`, `mod_forum_delete_post` · state: `mod_forum_set_lock_state`, `mod_forum_set_pin_state` | ✅ | the six above (+ `mod_forum_get_forums_by_courses`, `mod_forum_get_forum_discussions` to read back) |
| **Wiki** | ✅ plugin (use `extrafields`: `defaultformat=html`, `forceformat=1`) | ✅ pages: `mod_wiki_new_page`, `mod_wiki_edit_page` | ⚠️ instance via `core_course_delete_modules`; no page-delete service | `mod_wiki_new_page`, `mod_wiki_edit_page`, `mod_wiki_get_subwiki_pages`, `mod_wiki_view_wiki` |
| **Glossary** | ✅ plugin | ✅ entries: `mod_glossary_add_entry`, `mod_glossary_update_entry`, `mod_glossary_delete_entry` | ✅ instance; entries via `mod_glossary_delete_entry` | the three above (+ `mod_glossary_get_entries_by_search`, `mod_glossary_get_glossaries_by_courses` to read back) |
| **Book** | ✅ plugin | ❌ core exposes read-only functions (`mod_book_get_books_by_courses`, `mod_book_view_book`) | ✅ | `core_course_delete_modules` (use a wiki or page instead if you need editable content) |
| **Quiz** | ✅ plugin (skeleton; questions are not creatable via web services) | ⚠️ `mod_quiz_save_attempt`, `mod_quiz_save_overrides`, `mod_quiz_update_grade_items`, `mod_quiz_add_random_questions`, `mod_quiz_update_slots` | ✅ | the five above (+ `mod_quiz_get_quizzes_by_courses` to read back) |
| **Assignment** | ✅ plugin | ✅ `mod_assign_save_submission`, `mod_assign_remove_submission`, `mod_assign_save_grade`, `mod_assign_lock_submissions`, `mod_assign_reveal_identities` | ✅ | the five above (+ `mod_assign_get_submissions` to read back) |
| **Choice** | ✅ plugin | ✅ `mod_choice_submit_choice_response`, `mod_choice_delete_choice_responses` | ✅ | the two above |
| **Database (`data`)** | ✅ plugin | ✅ `mod_data_add_entry`, `mod_data_update_entry`, `mod_data_delete_entry` | ✅ | the three above |
| **Workshop** | ✅ plugin | ✅ `mod_workshop_add_submission`, `mod_workshop_update_submission`, `mod_workshop_delete_submission`, `mod_workshop_update_assessment` | ✅ | the four above |

**Notes**

- The token's user must hold the capability required by each function in the
  right context (typically a *teacher* or *editing teacher* role on the course
  for create/edit/delete; `moodle/course:manageactivities` for the plugin
  functions).
- Deleting any activity: get the cm ids with `core_course_get_contents`, then
  call `core_course_delete_modules` with `cmids`.
- Enrolment/role management (not a resource, but needed to make a user a
  teacher): `enrol_manual_enrol_users`, `enrol_manual_unenrol_users`,
  `core_role_assign_roles`, `core_role_unassign_roles`.

## Usage

#### Endpoint URL

The MCP server endpoint can be accessed in two ways.

**1. Using query parameter (wstoken):**
```
https://your-moodle-site.com/webservice/mcp/server.php?wstoken=YOUR_TOKEN
```

**2. Using Authorization header (Bearer token):**
```
https://your-moodle-site.com/webservice/mcp/server.php
```

Add the token to the request header:
```
Authorization: Bearer YOUR_TOKEN
```

Replace:
- `your-moodle-site.com` with your Moodle domain
- `YOUR_TOKEN` with the token you generated


#### Monitoring

Monitor MCP web service usage through:
- Standard Moodle logs at **Site administration → Reports → Logs**


### Client Examples

#### 1. Initialize Session

**Request:**
```json
{
   "jsonrpc": "2.0",
   "method": "initialize",
   "params": {},
   "id": 1
}
```

**Response:**
```json
{
   "jsonrpc": "2.0",
   "result": {
      "protocolVersion": "1.0",
      "serverInfo": {
         "name": "Moodle MCP Server",
         "version": "0.1.0"
      },
      "capabilities": {
         "tools": {}
      }
   },
   "id": 1
}
```

#### 2. List Available Tools

**Request:**
```json
{
   "jsonrpc": "2.0",
   "method": "tools/list",
   "params": {},
   "id": 2
}
```

**Response:**
```json
{
   "jsonrpc": "2.0",
   "result": {
      "tools": [
         {
            "name": "core_user_get_users",
            "description": "Search for users matching the criteria",
            "inputSchema": {
               "type": "object",
               "properties": {
                  "criteria": {
                     "type": "array",
                     "items": {
                        "type": "object",
                        "properties": {
                           "key": {
                              "type": "string"
                           },
                           "value": {
                              "type": "string"
                           }
                        }
                     }
                  }
               }
            },
            "outputSchema": {
               "type": "object",
               "properties": {
                  "result": {
                     "type": "object"
                  }
               }
            }
         }
      ]
   },
   "id": 2
}
```

#### 3. Call a Tool

**Request:**
```json
{
   "jsonrpc": "2.0",
   "method": "tools/call",
   "params": {
      "name": "core_user_get_users",
      "arguments": {
         "criteria": [
            {
               "key": "email",
               "value": "student@example.com"
            }
         ]
      }
   },
   "id": 3
}
```

**Response:**
```json
{
   "jsonrpc": "2.0",
   "result": {
      "content": [
         {
            "type": "text",
            "text": "{\"result\":{\"users\":[{\"id\":2,\"username\":\"student\",\"firstname\":\"Student\",\"lastname\":\"User\",\"email\":\"student@example.com\"}]}}"
         }
      ],
      "structuredContent": {
         "result": {
            "users": [
               {
                  "id": 2,
                  "username": "student",
                  "firstname": "Student",
                  "lastname": "User",
                  "email": "student@example.com"
               }
            ]
         }
      }
   },
   "id": 3
}
```

#### Using cURL

```bash
curl -X POST "https://your-moodle-site.com/webservice/mcp/server.php?wstoken=YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/list",
    "params": {},
    "id": 1
  }'
```

```bash
curl -X POST "https://your-moodle-site.com/webservice/mcp/server.php" \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/list",
    "params": {},
    "id": 1
  }'
```


## API Reference

### Supported MCP Methods

| Method       | Description | Parameters |
|--------|-------------|------------|
| `initialize` | Initialize MCP session | None |
| `notifications/initialized` | Notify MCP session initialization | None |
| `ping`       | Ping MCP session | None |
| `tools/list` | List available tools | None |
| `tools/call` | Invoke a specific tool | `name` (string), `arguments` (object) |


### Error Responses

Errors follow JSON-RPC 2.0 format:

```json
{
    "jsonrpc": "2.0",
    "error": {
        "code": -32600,
        "message": "Invalid Request",
        "data": "Missing method"
    },
    "id": null
}
```

**Common Error Codes:**
- `-32700`: Parse error (invalid JSON)
- `-32600`: Invalid request (missing required fields)
- `-32601`: Method not found
- `-32602`: Invalid params
- `-32603`: Internal error

## Architecture

### Components

```
webservice_mcp/
├── classes/
│   ├── local/
│   │   ├── server.php          # MCP server implementation
│   │   ├── request.php         # Request parser and validator
│   │   └── tool_provider.php   # Tool discovery and schema generation
│   └── privacy/
│       └── provider.php        # Privacy API implementation
├── db/
│   └── access.php              # Capability definitions
├── lang/
│   └── en/
│       └── webservice_mcp.php  # Language strings
├── tests/
│   ├── server_test.php         # Server tests
│   ├── client_test.php         # Client tests
│   ├── request_test.php        # Request parser tests
│   └── tool_provider_test.php  # Tool provider tests
├── lib.php                     # Client class
├── locallib.php                # Local library functions
├── server.php                  # Server endpoint
└── version.php                 # Plugin metadata
```

### Data Flow

1. **Client Request** → JSON-RPC 2.0 POST to `server.php`
2. **Authentication** → Token validation via Moodle's web service API
3. **Request Parsing** → `request` class validates JSON-RPC format
4. **Method Routing** → `server` class routes to appropriate handler
5. **Tool Discovery** → `tool_provider` queries available external functions
6. **Schema Generation** → Converts Moodle descriptions to JSON Schema
7. **Tool Execution** → Calls Moodle's external function API
8. **Response** → JSON-RPC 2.0 formatted response

### Key Classes

- **`server`**: Main server implementation extending `webservice_base_server`
- **`request`**: Parses and validates JSON-RPC 2.0 requests
- **`tool_provider`**: Discovers available tools and generates JSON Schemas
- **`webservice_mcp_client`**: Client for making MCP requests

## Testing

### Running Tests

```bash
# Run all plugin tests
vendor/bin/phpunit --testsuite webservice_mcp_testsuite
```

### Test Coverage

The plugin includes comprehensive tests for:
- ✅ JSON-RPC 2.0 request parsing and validation
- ✅ MCP protocol methods (initialize, notifications/initialized, ping, tools/list, tools/call)
- ✅ Tool discovery and schema generation
- ✅ Client class functionality
- ✅ Error handling and edge cases

## Troubleshooting

### Common Issues

#### 1. "Web services are not enabled"
**Solution**: Enable web services in **Site administration → Advanced features**

#### 2. "Invalid token"
**Solution**: 
- Verify the token is correct
- Check the token hasn't expired
- Ensure the service is enabled
- Confirm the user has appropriate capabilities

#### 3. "Method not found"
**Solution**: Check that the method name is correct:
- `initialize`
- `notifications/initialized`
- `ping`
- `tools/list`
- `tools/call`

#### 4. "Missing tool name"
**Solution**: When using `tools/call`, ensure you provide the `name` parameter:
```json
{
    "method": "tools/call",
    "params": {
        "name": "core_user_get_users",
        "arguments": {}
    }
}
```

#### 5. Empty tools list
**Solution**:
- Check that your service has functions added
- Verify the token is associated with the correct service
- Ensure functions aren't deprecated

### Logging

MCP requests are logged in Moodle's standard web service logs:
- **Site administration → Reports → Logs**
- Filter by "Web service" component


## 💖 Support the development of this plugin

Keep it updated and free for everyone!

[☕ Buy Me a Coffee (Ko-fi)](https://ko-fi.com/onbirdev) | [💸 Support via PayPal](https://www.paypal.me/onbirdev)
