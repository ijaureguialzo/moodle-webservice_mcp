<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be distributed under the
// terms of the GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/gpl.php>.

/**
 * MCP web service plugin: functions definitions for external services.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = array(

    'webservice_mcp_create_course_module' => array(
        'classname'     => 'webservice_mcp\local\external\create_course_module',
        'methodname'    => 'create_course_module',
        'description'   => 'Create a course module (activity/resource such as page, forum, quiz, url, resource) inside a course section.',
        'type'          => 'write',
        'capabilities'  => 'moodle/course:manageactivities',
        'services'      => array('moodle_mcp'),
    ),

);
