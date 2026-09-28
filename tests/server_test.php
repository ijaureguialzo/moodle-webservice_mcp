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

namespace webservice_mcp;

use advanced_testcase;
use moodle_exception;
use Exception;
use ReflectionClass;
use webservice_mcp\local\request;
use webservice_mcp\local\server;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/lib.php');

/**
 * Tests for MCP server class.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\server
 */
final class server_test extends advanced_testcase {
    /**
     * Test server instantiation.
     */
    public function test_server_instantiation(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $this->assertInstanceOf(server::class, $server);
    }

    /**
     * Test is_tool_call method with tools/call request.
     */
    public function test_is_tool_call_true(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        // Use reflection to access private method.
        $reflection = new ReflectionClass($server);
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);

        // Create a mock request.
        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 1,
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        $method = $reflection->getMethod('is_tool_call');
        $method->setAccessible(true);

        $result = $method->invoke($server);

        $this->assertTrue($result);
    }

    /**
     * Test is_tool_call method with non-tools/call request.
     */
    public function test_is_tool_call_false(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        // Use reflection to access private method.
        $reflection = new ReflectionClass($server);
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);

        // Create a mock request.
        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'id' => 1,
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        $method = $reflection->getMethod('is_tool_call');
        $method->setAccessible(true);

        $result = $method->invoke($server);

        $this->assertFalse($result);
    }

    /**
     * Test extract_tool_call with valid request.
     */
    public function test_extract_tool_call_valid(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        // Use reflection to set up the request.
        $reflection = new ReflectionClass($server);
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);

        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 1,
            'params' => [
                'name' => 'test_function',
                'arguments' => ['param1' => 'value1'],
            ],
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        // Call extract_tool_call.
        $server->extract_tool_call();

        // Verify functionname was set.
        $functionnameprop = $reflection->getProperty('functionname');
        $functionnameprop->setAccessible(true);
        $functionname = $functionnameprop->getValue($server);

        $this->assertEquals('test_function', $functionname);

        // Verify parameters were set.
        $parametersprop = $reflection->getProperty('parameters');
        $parametersprop->setAccessible(true);
        $parameters = $parametersprop->getValue($server);

        $this->assertEquals(['param1' => 'value1'], $parameters);
    }

    /**
     * Test extract_tool_call with missing tool name.
     */
    public function test_extract_tool_call_missing_name(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        // Use reflection to set up the request.
        $reflection = new ReflectionClass($server);
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);

        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 1,
            'params' => [],
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        // Expect exception.
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('err_missing_tool_name', 'webservice_mcp'));

        $server->extract_tool_call();
    }

    /**
     * Test extract_token from URL parameter.
     */
    public function test_extract_token_from_url(): void {
        $this->resetAfterTest(true);

        $_GET['wstoken'] = 'test_token_123';

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('extract_token');
        $method->setAccessible(true);

        $token = $method->invoke($server);

        $this->assertEquals('test_token_123', $token);

        unset($_GET['wstoken']);
    }

    /**
     * Test extract_token from POST parameter.
     */
    public function test_extract_token_from_post(): void {
        $this->resetAfterTest(true);

        $_POST['wstoken'] = 'test_token_456';

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('extract_token');
        $method->setAccessible(true);

        $token = $method->invoke($server);

        $this->assertEquals('test_token_456', $token);

        unset($_POST['wstoken']);
    }

    /**
     * Test generate_error method.
     */
    public function test_generate_error(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('generate_error');
        $method->setAccessible(true);

        $exception = new Exception('Test error message');
        $error = $method->invoke($server, $exception);

        $this->assertIsArray($error);
        $this->assertArrayHasKey('jsonrpc', $error);
        $this->assertArrayHasKey('error', $error);
        $this->assertArrayHasKey('id', $error);

        $this->assertEquals('2.0', $error['jsonrpc']);
        $this->assertArrayHasKey('code', $error['error']);
        $this->assertArrayHasKey('message', $error['error']);
    }

    /**
     * Test safe_json_encode with valid data.
     */
    public function test_safe_json_encode_valid(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('safe_json_encode');
        $method->setAccessible(true);

        $data = ['key' => 'value', 'number' => 123];
        $json = $method->invoke($server, $data);

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertEquals($data, $decoded);
    }

    /**
     * Test safe_json_encode with unicode characters.
     */
    public function test_safe_json_encode_unicode(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('safe_json_encode');
        $method->setAccessible(true);

        $data = ['text' => 'Hello 世界 🌍'];
        $json = $method->invoke($server, $data);

        $this->assertIsString($json);
        $this->assertStringContainsString('Hello', $json);
        $decoded = json_decode($json, true);
        $this->assertEquals($data, $decoded);
    }

    /**
     * Test server constants are defined.
     */
    public function test_server_constants(): void {
        $this->resetAfterTest(true);

        $reflection = new ReflectionClass(server::class);

        $this->assertTrue($reflection->hasConstant('PROTOCOL_VERSION'));
        $this->assertTrue($reflection->hasConstant('SERVER_NAME'));
        $this->assertTrue($reflection->hasConstant('SERVER_VERSION'));

        $protocolversion = $reflection->getConstant('PROTOCOL_VERSION');
        $servername = $reflection->getConstant('SERVER_NAME');
        $serverversion = $reflection->getConstant('SERVER_VERSION');

        $this->assertIsString($protocolversion);
        $this->assertIsString($servername);
        $this->assertIsString($serverversion);
        $this->assertEquals('2025-03-26', $protocolversion);
        $this->assertEquals('Moodle MCP Server', $servername);
        $this->assertEquals('1.0.0', $serverversion);
    }

    /**
     * Test send_error maps JSON-RPC error codes to correct HTTP status codes.
     */
    public function test_send_error_http_status_mapping(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('send_error');
        $method->setAccessible(true);

        // Capture output and HTTP status code via output buffering.
        ob_start();

        // Test -32600 (Invalid Request) → 400.
        $mockException = new class extends Exception {
            public function getCode(): int { return -32600; }
        };
        try {
            $method->invoke($server, $mockException);
        } catch (Exception $e) {
            // Expected when http_response_code is called in test env.
        }
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals(-32600, $decoded['error']['code']);

        // Test -32601 (Method not found) → 404.
        ob_start();
        $mockException404 = new class extends Exception {
            public function getCode(): int { return -32601; }
        };
        try {
            $method->invoke($server, $mockException404);
        } catch (Exception $e) {
            // Expected.
        }
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals(-32601, $decoded['error']['code']);

        // Test -32603 (Internal error) → 500.
        ob_start();
        $mockException500 = new class extends Exception {
            public function getCode(): int { return -32603; }
        };
        try {
            $method->invoke($server, $mockException500);
        } catch (Exception $e) {
            // Expected.
        }
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals(-32603, $decoded['error']['code']);
    }

    /**
     * Test send_error with null exception produces valid JSON-RPC error.
     */
    public function test_send_error_null_exception(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('send_error');
        $method->setAccessible(true);

        ob_start();
        try {
            $method->invoke($server, null);
        } catch (Exception $e) {
            // Expected.
        }
        $output = ob_get_clean();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('jsonrpc', $decoded);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertArrayHasKey('code', $decoded['error']);
        $this->assertEquals(-32603, $decoded['error']['code']);
        $this->assertArrayHasKey('message', $decoded['error']);
    }

    /**
     * Test handle_mcp_method returns 404 for unknown methods.
     */
    public function test_handle_mcp_method_unknown_method(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('handle_mcp_method');
        $method->setAccessible(true);

        // Set up a valid request with unknown method.
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);
        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'nonexistent/method',
            'id' => 42,
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        // Set HTTP method to POST.
        $httpmethodprop = $reflection->getProperty('httpmethod');
        $httpmethodprop->setAccessible(true);
        $httpmethodprop->setValue($server, 'POST');

        ob_start();
        try {
            $method->invoke($server);
        } catch (Exception $e) {
            // Expected.
        }
        $output = ob_get_clean();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals(-32601, $decoded['error']['code']);
        $this->assertEquals('Method not found', $decoded['error']['message']);
        $this->assertEquals(42, $decoded['id']);
    }

    /**
     * Test handle_mcp_method with null mcprequest returns error.
     */
    public function test_handle_mcp_method_null_request(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('handle_mcp_method');
        $method->setAccessible(true);

        // Set HTTP method to POST without setting mcprequest.
        $httpmethodprop = $reflection->getProperty('httpmethod');
        $httpmethodprop->setAccessible(true);
        $httpmethodprop->setValue($server, 'POST');

        // mcprequest is null by default.
        ob_start();
        try {
            $method->invoke($server);
        } catch (Exception $e) {
            // Expected.
        }
        $output = ob_get_clean();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals(-32600, $decoded['error']['code']);
        $this->assertEquals('Invalid Request', $decoded['error']['message']);
    }

    /**
     * Test safe_json_encode fallback when encoding fails.
     */
    public function test_safe_json_encode_fallback(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('safe_json_encode');
        $method->setAccessible(true);

        // Use an stdClass with recursive references that json_encode cannot handle.
        $recursive = new stdClass();
        $recursive->self = $recursive;

        // json_encode will fail, triggering the fallback path.
        $result = $method->invoke($server, $recursive);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('jsonrpc', $decoded);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertArrayHasKey('code', $decoded['error']);
        $this->assertEquals(-32603, $decoded['error']['code']);
        $this->assertStringContainsString('Internal JSON encoding error', $decoded['error']['message']);
    }

    /**
     * Test send_response produces valid JSON-RPC response with tools/call format.
     */
    public function test_send_response_valid_format(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);

        // Set up mcprequest.
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);
        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 99,
            'params' => [
                'name' => 'test_func',
                'arguments' => [],
            ],
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        // Set function name and returns.
        $functionnameprop = $reflection->getProperty('functionname');
        $functionnameprop->setAccessible(true);
        $functionnameprop->setValue($server, 'test_func');

        $returnsprop = $reflection->getProperty('returns');
        $returnsprop->setAccessible(true);
        $returnsprop->setValue($server, ['status' => 'ok']);

        // Set function with returns_desc.
        $functionprop = $reflection->getProperty('function');
        $functionprop->setAccessible(true);
        $mockFunction = new stdClass();
        $mockFunction->returns_desc = null; // Skip schema coercion.
        $functionprop->setValue($server, $mockFunction);

        ob_start();
        try {
            $method = $reflection->getMethod('send_response');
            $method->setAccessible(true);
            $method->invoke($server);
        } catch (Exception $e) {
            // Expected.
        }
        $output = ob_get_clean();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('jsonrpc', $decoded);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertArrayHasKey('id', $decoded);
        $this->assertEquals(99, $decoded['id']);
        $this->assertArrayHasKey('result', $decoded);
        $this->assertArrayHasKey('content', $decoded['result']);
        $this->assertIsArray($decoded['result']['content']);
        $this->assertArrayHasKey('type', $decoded['result']['content'][0]);
        $this->assertEquals('text', $decoded['result']['content'][0]['type']);
        $this->assertArrayHasKey('structuredContent', $decoded['result']);
    }

    /**
     * Test send_response with null mcprequest uses fallback values.
     */
    public function test_send_response_null_mcprequest(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);

        // Ensure mcprequest is null.
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);
        $mcprequestprop->setValue($server, null);

        // Set function name and returns.
        $functionnameprop = $reflection->getProperty('functionname');
        $functionnameprop->setAccessible(true);
        $functionnameprop->setValue($server, 'test_func');

        $returnsprop = $reflection->getProperty('returns');
        $returnsprop->setAccessible(true);
        $returnsprop->setValue($server, ['status' => 'ok']);

        // Set function with returns_desc.
        $functionprop = $reflection->getProperty('function');
        $functionprop->setAccessible(true);
        $mockFunction = new stdClass();
        $mockFunction->returns_desc = null;
        $functionprop->setValue($server, $mockFunction);

        ob_start();
        try {
            $method = $reflection->getMethod('send_response');
            $method->setAccessible(true);
            $method->invoke($server);
        } catch (Exception $e) {
            // Expected.
        }
        $output = ob_get_clean();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertArrayHasKey('result', $decoded);
        $this->assertArrayHasKey('content', $decoded['result']);
        $this->assertArrayHasKey('structuredContent', $decoded['result']);
    }

    /**
     * Test initialize response contains correct MCP fields.
     */
    public function test_send_initialize_response_format(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('send_initialize_response');
        $method->setAccessible(true);

        // Set up mcprequest.
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);
        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'id' => 'init-1',
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        ob_start();
        try {
            $method->invoke($server);
        } catch (Exception $e) {
            // Expected.
        }
        $output = ob_get_clean();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertEquals('init-1', $decoded['id']);
        $this->assertArrayHasKey('result', $decoded);
        $this->assertArrayHasKey('protocolVersion', $decoded['result']);
        $this->assertEquals('2025-03-26', $decoded['result']['protocolVersion']);
        $this->assertArrayHasKey('serverInfo', $decoded['result']);
        $this->assertEquals('Moodle MCP Server', $decoded['result']['serverInfo']['name']);
        $this->assertEquals('1.0.0', $decoded['result']['serverInfo']['version']);
        $this->assertArrayHasKey('capabilities', $decoded['result']);
        $this->assertArrayHasKey('tools', $decoded['result']['capabilities']);
    }

    /**
     * Test tools/list response contains valid JSON-RPC format.
     */
    public function test_send_tools_list_response_format(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('send_tools_list_response');
        $method->setAccessible(true);

        // Set up mcprequest with valid request.
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);
        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 3,
            'params' => [],
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        ob_start();
        try {
            $method->invoke($server);
        } catch (Exception $e) {
            // Expected.
        }
        $output = ob_get_clean();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertEquals(3, $decoded['id']);
        $this->assertArrayHasKey('result', $decoded);
        $this->assertArrayHasKey('tools', $decoded['result']);
        $this->assertIsArray($decoded['result']['tools']);
    }

    /**
     * Test ping response produces valid JSON-RPC with empty result.
     */
    public function test_send_ping_response_format(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('send_ping_response');
        $method->setAccessible(true);

        // Set up mcprequest.
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);
        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 'ping-1',
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        ob_start();
        try {
            $method->invoke($server);
        } catch (Exception $e) {
            // Expected.
        }
        $output = ob_get_clean();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertEquals('ping-1', $decoded['id']);
        $this->assertArrayHasKey('result', $decoded);
    }

    /**
     * Test generate_error with null mcprequest returns safe defaults.
     */
    public function test_generate_error_null_mcprequest(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('generate_error');
        $method->setAccessible(true);

        // mcprequest is null by default.
        $error = $method->invoke($server, null);

        $this->assertIsArray($error);
        $this->assertEquals('2.0', $error['jsonrpc']);
        $this->assertEquals(-32603, $error['error']['code']);
        $this->assertEquals('Internal error', $error['error']['message']);
        $this->assertNull($error['id']);
    }

    /**
     * Test generate_error with exception returns proper error structure.
     */
    public function test_generate_error_with_exception(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('generate_error');
        $method->setAccessible(true);

        // Set up mcprequest.
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);
        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 5,
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        $exception = new Exception('Something went wrong', 42);
        $exception->errorcode = 'test_error_code';
        $error = $method->invoke($server, $exception);

        $this->assertIsArray($error);
        $this->assertEquals('2.0', $error['jsonrpc']);
        $this->assertEquals(5, $error['id']);
        $this->assertEquals(-32603, $error['error']['code']);
        $this->assertEquals('Something went wrong', $error['error']['message']);
        $this->assertArrayHasKey('data', $error['error']);
        $this->assertEquals('Something went wrong', $error['error']['data']['message']);
        $this->assertEquals(42, $error['error']['data']['code']);
        $this->assertEquals('test_error_code', $error['error']['data']['errorcode']);
    }

    /**
     * Test handle_mcp_method with null mcprequest returns error with proper id.
     */
    public function test_handle_mcp_method_null_request_proper_id(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('handle_mcp_method');
        $method->setAccessible(true);

        // Set HTTP method to POST without mcprequest.
        $httpmethodprop = $reflection->getProperty('httpmethod');
        $httpmethodprop->setAccessible(true);
        $httpmethodprop->setValue($server, 'POST');

        ob_start();
        try {
            $method->invoke($server);
        } catch (Exception $e) {
            // Expected.
        }
        $output = ob_get_clean();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('id', $decoded);
        $this->assertNull($decoded['id']);
    }

    /**
     * Test send_tools_list_response with null mcprequest uses fallback values.
     */
    public function test_send_tools_list_response_null_mcprequest(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('send_tools_list_response');
        $method->setAccessible(true);

        // mcprequest is null by default.
        ob_start();
        try {
            $method->invoke($server);
        } catch (Exception $e) {
            // Expected.
        }
        $output = ob_get_clean();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertArrayHasKey('result', $decoded);
        $this->assertArrayHasKey('tools', $decoded['result']);
        $this->assertIsArray($decoded['result']['tools']);
    }
}
