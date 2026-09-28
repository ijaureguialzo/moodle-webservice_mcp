<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace webservice_mcp\local;

use core\exception\invalid_parameter_exception;
use core\exception\invalid_response_exception;
use core\exception\moodle_exception;
use core_external\external_api;
use core_external\external_description;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;
use Exception;
use stdClass;
use webservice_base_server;

/**
 * MCP (Model Context Protocol) web service server implementation.
 *
 * This server handles JSON-RPC 2.0 requests following the MCP specification.
 * It supports MCP-specific methods like initializem notifications/initialized, ping, tools/list, and tools/call,
 * as well as direct function invocation.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class server extends webservice_base_server {
    /** @var string Protocol version supported by this server. */
    private const PROTOCOL_VERSION = '2025-03-26';

    /** @var string Server name. */
    private const SERVER_NAME = 'Moodle MCP Server';

    /** @var string Server version. */
    private const SERVER_VERSION = '1.0.0';

    /** @var string HTTP request method. */
    protected string $httpmethod;

    /** @var request|null Parsed MCP request object. */
    protected ?request $mcprequest = null;

    /**
     * Constructor.
     *
     * @param int $authmethod Authentication method (e.g., WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN).
     */
    public function __construct(int $authmethod) {
        parent::__construct($authmethod);
        $this->wsname = 'mcp';
    }

    /**
     * Main server execution method.
     *
     * Handles the complete request lifecycle: parsing, authentication,
     * execution, and response generation.
     *
     * For MCP-specific methods (initialize, ping, tools/list, tools/call
     * with no function name), handles the MCP protocol directly.
     * For standard Moodle external function calls, delegates to the
     * parent webservice_base_server::run() which handles load_function_info()
     * and execute().
     *
     * @return void
     */
    public function run(): void {
        $this->set_headers();

        // Allocate sufficient memory for complex operations.
        raise_memory_limit(MEMORY_EXTRA);

        // Set extended timeout for long-running operations.
        external_api::set_timeout();

        // Configure exception handler.
        set_exception_handler([$this, 'exception_handler']);

        // Parse incoming request.
        $this->parse_request();

        // Handle MCP-specific methods that don't require standard flow.
        if (empty($this->functionname)) {
            // Authenticate for MCP methods.
            $this->authenticate_user();

            $this->handle_mcp_method();

            $this->session_cleanup();

            die;
        }

        // For standard function calls (tools/call with a Moodle external
        // function), we must call load_function_info() and execute() —
        // parent::run() does these as part of its own parse_request() /
        // authenticate_user() / execute() sequence.  We have already done
        // parse_request() and authenticate_user(), so call the remaining
        // steps directly:
        $this->load_function_info();
        $this->execute();
        $this->send_response();
        $this->session_cleanup();

        die;
    }

    /**
     * Parse and validate incoming request, set token, method, parameters.
     *
     * @return void
     */
    protected function parse_request(): void {
        parent::set_web_service_call_settings();

        $this->token = $this->extract_token();
        $this->httpmethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($this->httpmethod === 'POST' && !request::is_raw_input_empty()) {
            $this->mcprequest = request::from_raw_input();

            // Handle MCP tool invocation.
            if ($this->is_tool_call()) {
                $this->extract_tool_call();
            }
        }
    }

    /**
     * Determine whether the incoming MCP request represents a tools/call invocation.
     *
     * @return bool True if the request is a tools/call request, false otherwise.
     */
    private function is_tool_call(): bool {
        return !empty($this->mcprequest->method) && $this->mcprequest->method === 'tools/call';
    }

    /**
     * Extract the function name and parameters from an MCP tools/call request.
     *
     * This method populates:
     *   - $this->functionname
     *   - $this->parameters
     *
     * @return void
     * @throws moodle_exception If the tool name is missing.
     */
    public function extract_tool_call(): void {
        if (empty($this->mcprequest->params) || empty($this->mcprequest->params['name'])) {
            throw new moodle_exception('err_missing_tool_name', 'webservice_mcp');
        }

        // Extract function and arguments.
        $this->functionname = $this->mcprequest->params['name'];
        $this->parameters = $this->mcprequest->params['arguments'] ?? [];
    }

    /**
     * Extract Bearer token from Authorization header or fallback to wstoken GET param.
     *
     * @return string|null
     */
    protected function extract_token(): ?string {
        // Try robust header extraction (case-insensitive).
        $auth = null;

        if (function_exists('getallheaders')) {
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            $auth = $headers['authorization'] ?? null;
        }

        // Fallback to $_SERVER keys (common in CGI/FPM).
        if ($auth === null) {
            $keys = [
                'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'Authorization',
            ];
            foreach ($keys as $key) {
                if (!empty($_SERVER[$key])) {
                    $auth = $_SERVER[$key];
                    break;
                }
            }
        }

        if (!empty($auth) && preg_match('/Bearer\s+(\S+)/i', $auth, $matches)) {
            return $matches[1];
        }

        // Fallback to GET parameter (validated).
        return optional_param('wstoken', null, PARAM_ALPHANUMEXT);
    }

    /**
     * Handle MCP-specific endpoints: CORS preflight, server info (GET),
     * initialize, notifications/initialized, ping and tools/list (POST).
     *
     * Exits after sending a response.
     *
     * @return void
     */
    protected function handle_mcp_method(): void {
        // Handle OPTIONS for CORS preflight.
        if ($this->httpmethod === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        // Handle GET for basic server info (not JSON-RPC wrapped).
        if ($this->httpmethod === 'GET') {
            $this->send_server_info();
            exit;
        }

        if (!($this->mcprequest instanceof request) || !isset($this->mcprequest->method)) {
            // Bad request.
            http_response_code(400);
            echo $this->safe_json_encode([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32600, 'message' => 'Invalid Request'],
                'id' => $this->mcprequest?->id ?? null,
            ]);
            exit;
        }

        switch ($this->mcprequest->method) {
            case 'initialize':
                $this->send_initialize_response();
                break;

            case 'notifications/initialized':
                $this->handle_initialized_notification();
                break;

            case 'ping':
                $this->send_ping_response();
                break;

            case 'tools/list':
                $this->send_tools_list_response();
                break;

            default:
                // If method unexpectedly reached here, return method not found.
                http_response_code(404);
                $payload = [
                    'jsonrpc' => '2.0',
                    'error' => ['code' => -32601, 'message' => 'Method not found'],
                    'id' => $this->mcprequest->id ?? null,
                ];
                echo $this->safe_json_encode($payload);
        }
    }

    /**
     * Output server info for GET requests.
     *
     * @return void
     */
    protected function send_server_info(): void {
        $response = [
            'name' => self::SERVER_NAME,
            'version' => self::SERVER_VERSION,
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => true],
            ],
        ];

        echo $this->safe_json_encode($response);
    }

    /**
     * Send MCP initialize JSON-RPC response.
     *
     * @return void
     */
    protected function send_initialize_response(): void {
        $result = [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => true],
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'instructions' => 'Moodle MCP server initialized successfully',
        ];

        $payload = [
            'jsonrpc' => $this->mcprequest?->jsonrpc ?? '2.0',
            'id' => $this->mcprequest?->id ?? null,
            'result' => $result,
        ];

        echo $this->safe_json_encode($payload);
    }

    /**
     * Handle MCP notifications/initialized client notification.
     *
     * According to MCP specification, notifications do not expect a JSON-RPC response body.
     *
     * @return void
     */
    protected function handle_initialized_notification(): void {
        http_response_code(204);
    }

    /**
     * Sends a JSON-RPC ping response.
     *
     * @return void
     */
    protected function send_ping_response(): void {
        echo $this->safe_json_encode([
            'jsonrpc' => '2.0',
            'result' => new stdClass(),
            'id' => $this->mcprequest?->id ?? null,
        ]);
    }

    /**
     * Send tools list as MCP JSON-RPC response.
     *
     * @return void
     */
    protected function send_tools_list_response(): void {
        $tools = tool_provider::get_tools($this->token);

        $payload = [
            'jsonrpc' => $this->mcprequest?->jsonrpc ?? '2.0',
            'id' => $this->mcprequest?->id ?? null,
            'result' => [
                'tools' => $tools,
            ],
        ];

        echo $this->safe_json_encode($payload);
    }

    /**
     * Send a successful response for standard function calls.
     *
     * Applies schema-aware type coercion so that every field in the response
     * matches the PHP type declared by Moodle's external API schema, without
     * calling clean_returnvalue() (which throws for valid-but-mistyped data
     * such as null in optional boolean fields or integers in PARAM_RAW slots).
     *
     * @return void
     */
    protected function send_response(): void {
        // Apply schema-aware coercion: walk schema + data together and emit
        // the correct PHP type for each leaf field.  This handles cases such as:
        //   • PARAM_RAW returning int/bool → converted to string
        //   • PARAM_BOOL optional returning null → false
        //   • PARAM_INT returning string → (int)
        // without the side-effect of converting every integer field to a string
        // the way the old generic coerce_types() fallback did.
        if ($this->function->returns_desc !== null) {
            $validatedvalues = $this->schema_aware_coerce(
                $this->function->returns_desc,
                $this->returns
            );
        } else {
            $validatedvalues = $this->returns;
        }

        // Wrap result for tools/call format.
        $validatedvalues = [
            'result' => $validatedvalues,
        ];

        $content = [
            'type' => 'text',
            'text' => $this->safe_json_encode($validatedvalues),
        ];

        $result = [
            'content' => [$content],
            'structuredContent' => $validatedvalues,
        ];

        $payload = [
            'jsonrpc' => $this->mcprequest?->jsonrpc ?? '2.0',
            'id' => $this->mcprequest?->id ?? null,
            'result' => $result,
        ];

        echo $this->safe_json_encode($payload);
    }

    /**
     * Schema-aware type coercion.
     *
     * Walks the Moodle external API schema descriptor and the raw response data
     * together, applying the minimum necessary type conversion at every leaf so
     * that the returned value always matches its declared PHP type.
     *
     * Rules applied per PARAM_* type:
     *   PARAM_BOOL         → (bool) — null becomes false for optional fields
     *   PARAM_INT          → (int)
     *   PARAM_FLOAT        → (float)
     *   PARAM_RAW and rest → (string) — ints/bools Moodle sometimes returns here
     *                                    are cast to string
     *
     * This avoids the old double-failure pattern:
     *   1. clean_returnvalue() throws because of a type mismatch
     *   2. coerce_types() blindly converts every scalar (including valid integers)
     *      to a string, breaking fields that were correct to begin with.
     *
     * @param external_description|null $desc  Schema descriptor for this node.
     * @param mixed                     $data  Raw value from Moodle.
     * @return mixed  Coerced value.
     */
    protected function schema_aware_coerce(?external_description $desc, mixed $data): mixed {
        // No schema → pass data through unchanged.
        if ($desc === null) {
            return $data;
        }

        // --- external_multiple_structure (JSON array) ---
        if ($desc instanceof external_multiple_structure) {
            if ($data === null) {
                return [];
            }
            $result = [];
            $items = is_array($data) ? $data : (array) $data;
            foreach ($items as $item) {
                $result[] = $this->schema_aware_coerce($desc->content, $item);
            }
            return $result;
        }

        // --- external_single_structure (JSON object) ---
        if ($desc instanceof external_single_structure) {
            if ($data === null) {
                return [];
            }
            $map = is_array($data) ? $data : (array) $data;
            $result = [];
            foreach ($desc->keys as $key => $subdesc) {
                // Use declared default when the key is completely absent.
                $value = array_key_exists($key, $map) ? $map[$key] : $subdesc->default ?? null;
                $result[$key] = $this->schema_aware_coerce($subdesc, $value);
            }
            return $result;
        }

        // --- external_warnings (special Moodle type, always an array of objects) ---
        if ($desc instanceof external_warnings) {
            if ($data === null) {
                return [];
            }
            $items = is_array($data) ? $data : (array) $data;
            $result = [];
            foreach ($items as $w) {
                $result[] = is_array($w) ? $w : (array) $w;
            }
            return $result;
        }

        // --- external_value (leaf scalar) ---
        if ($desc instanceof external_value) {
            // Null handling for optional fields.
            if ($data === null) {
                if ($desc->required === VALUE_OPTIONAL || $desc->required === VALUE_DEFAULT) {
                    // Return a safe zero-value for the declared type.
                    return match ($desc->type) {
                        PARAM_BOOL  => false,
                        PARAM_INT   => 0,
                        PARAM_FLOAT => 0.0,
                        default     => '',
                    };
                }
                // Required field that is null — return a safe default anyway to
                // avoid a PHP error; the data is already wrong at the Moodle level.
                return match ($desc->type) {
                    PARAM_BOOL  => false,
                    PARAM_INT   => 0,
                    PARAM_FLOAT => 0.0,
                    default     => '',
                };
            }

            // Cast to the declared type.
            return match ($desc->type) {
                PARAM_BOOL  => (bool) $data,
                PARAM_INT   => (int) $data,
                PARAM_FLOAT => (float) $data,
                // PARAM_RAW, PARAM_TEXT, PARAM_ALPHA, PARAM_ALPHANUMEXT, etc.
                // Moodle sometimes returns integers for these fields (e.g.
                // courseformatoptions[].value, attachment). Cast to string so
                // consumers always get what the schema advertises.
                default     => (string) $data,
            };
        }

        // Unknown descriptor type — return data as-is.
        return $data;
    }

    /**
     * Sends an error response, optionally logging exception details for debugging.
     *
     * @param Exception|null $ex The exception to log and include in the error response, or null if no exception is provided.
     * @return void
     */
    protected function send_error($ex = null): void {
        if ($ex !== null && debugging('', DEBUG_MINIMAL)) {
            $this->log_exception_for_debug($ex);
        }

        $error = $this->generate_error($ex);
        $errorcode = $error['error']['code'] ?? -32603;

        // Map JSON-RPC error codes to appropriate HTTP status codes.
        match ($errorcode) {
            -32600 => http_response_code(400),
            -32601 => http_response_code(404),
            -32602 => http_response_code(400),
            -32603 => http_response_code(500),
            default => http_response_code(500),
        };

        echo $this->safe_json_encode($error);
    }

    /**
     * Generates a standardized error response for handling exceptions in the JSON-RPC protocol.
     *
     * @param Exception|moodle_exception|null $ex The exception to process. If null, a default internal error is returned.
     * @return array The formatted error response containing error code, message, and additional data.
     */
    protected function generate_error($ex): array {
        $jsonrpc = $this->mcprequest?->jsonrpc ?? '2.0';
        $requestid = $this->mcprequest?->id;

        if ($ex === null) {
            return [
                'jsonrpc' => $jsonrpc,
                'error' => ['code' => -32603, 'message' => 'Internal error'],
                'id' => $requestid,
            ];
        }

        $errordata = [
            'exception' => get_class($ex),
            'message' => $ex->getMessage(),
            'code' => $ex->getCode(),
        ];

        if (isset($ex->errorcode)) {
            $errordata['errorcode'] = $ex->errorcode;
        }

        if (debugging() && isset($ex->debuginfo)) {
            $errordata['debuginfo'] = $ex->debuginfo;
        }

        return [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32603,
                'message' => $ex->getMessage(),
                'data' => $errordata,
            ],
            'id' => $requestid,
        ];
    }

    /**
     * Set JSON/CORS and caching headers.
     *
     * @return void
     */
    protected function set_headers(): void {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, must-revalidate, max-age=0');
        header('Expires: ' . gmdate('D, d M Y H:i:s', 0) . ' GMT');
        header('Pragma: no-cache');

        // CORS - allow any origin by default (adjust for production use).
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }

    /**
     * Safely encode data to JSON and handle errors.
     *
     * @param mixed $data
     * @return string JSON encoded string
     */
    protected function safe_json_encode(mixed $data): string {
        // Use JSON_THROW_ON_ERROR if available.
        if (defined('JSON_THROW_ON_ERROR')) {
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            // Avoid leaking internal structures; return minimal error JSON-RPC.
            $fallback = [
                'jsonrpc' => $this->mcprequest?->jsonrpc ?? '2.0',
                'error' => ['code' => -32603, 'message' => 'Internal JSON encoding error'],
                'id' => $this->mcprequest?->id ?? null,
            ];
            return json_encode($fallback);
        }

        return $encoded;
    }

    /**
     * Log rich exception information when debugging is enabled.
     *
     * @param Exception $ex
     * @return void
     */
    protected function log_exception_for_debug(Exception $ex): void {
        $info = get_exception_info($ex);
        $message = 'MCP exception handler: ' . $info->message .
            ' Debug: ' . ($info->debuginfo ?? '') . "\n" .
            format_backtrace($info->backtrace ?? [], true);
        debugging($message);
    }

    /**
     * Clean response.
     *
     * @param external_description $description description of the return values
     * @param mixed $response the actual response
     * @return mixed response with added defaults for optional items, invalid_response_exception thrown if any problem found
     *
     * @see external_api::clean_returnvalue()
     * @author 2010 Jerome Mouneyrac
     */
    public static function clean_response(external_description $description, $response) {
        if ($response === null && $description->allownull == NULL_ALLOWED) {
            return null;
        }
        if ($description instanceof external_value) {
            if (is_array($response) || is_object($response)) {
                throw new invalid_response_exception('Scalar type expected, array or object received.');
            }

            if ($description->type == PARAM_BOOL) {
                // Special case for PARAM_BOOL - we want true/false instead of the usual 1/0 - we can not be too strict here.
                if (is_bool($response) || $response === 0 || $response === 1 || $response === '0' || $response === '1') {
                    return (bool) $response;
                }
            }
            $responsetype = gettype($response);
            $debuginfo = "Invalid external api response: the value is \"{$response}\" of PHP type \"{$responsetype}\", ";
            $debuginfo .= "the server was expecting \"{$description->type}\" type";
            try {
                return validate_param($response, $description->type, $description->allownull, $debuginfo);
            } catch (invalid_parameter_exception $e) {
                // Proper exception name, to be recursively catched to build the path to the faulty attribute.
                throw new invalid_response_exception($e->debuginfo);
            }
        } else if ($description instanceof external_single_structure) {
            if (!is_array($response) && !is_object($response)) {
                throw new invalid_response_exception(
                // phpcs:ignore moodle.PHP.ForbiddenFunctions.Found
                    "Only arrays/objects accepted. The bad value is: '" . print_r($response, true) . "'"
                );
            }

            // Cast objects into arrays.
            if (is_object($response)) {
                $response = (array) $response;
            }

            $result = [];
            foreach ($description->keys as $key => $subdesc) {
                if (!array_key_exists($key, $response)) {
                    if ($subdesc->required == VALUE_REQUIRED) {
                        throw new invalid_response_exception(
                            "Error in response - Missing following required key in a single structure: {$key}"
                        );
                    }
                    if ($subdesc instanceof external_value) {
                        if ($subdesc->required == VALUE_DEFAULT) {
                            try {
                                $result[$key] = self::clean_response($subdesc, $subdesc->default);
                            } catch (invalid_response_exception $e) {
                                // Build the path to the faulty attribute.
                                throw new invalid_response_exception("{$key} => " . $e->getMessage() . ': ' . $e->debuginfo);
                            }
                        }
                    }
                } else {
                    try {
                        $result[$key] = self::clean_response($subdesc, $response[$key]);
                    } catch (invalid_response_exception $e) {
                        // Build the path to the faulty attribute.
                        throw new invalid_response_exception("{$key} => " . $e->getMessage() . ': ' . $e->debuginfo);
                    }
                }
                unset($response[$key]);
            }

            return $result;
        } else if ($description instanceof external_multiple_structure) {
            if (!is_array($response)) {
                throw new invalid_response_exception(
                // phpcs:ignore moodle.PHP.ForbiddenFunctions.Found
                    "Only arrays accepted. The bad value is: '" . print_r($response, true) . "'"
                );
            }
            $result = [];
            foreach ($response as $param) {
                $result[] = self::clean_response($description->content, $param);
            }
            return $result;
        } else {
            throw new invalid_response_exception('Invalid external api response description');
        }
    }
}
