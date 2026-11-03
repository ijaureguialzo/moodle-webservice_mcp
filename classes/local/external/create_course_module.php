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

declare(strict_types=1);

namespace webservice_mcp\local\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_warnings;
use core_external\external_single_structure;
use stdClass;

/**
 * Web service MCP: create course module external API.
 *
 * Allows programmatic creation of course modules (activities/resources
 * such as page, forum, quiz, url, resource) inside a course section.
 *
 * NOTE: Moodle's external_api::call_external_function() invokes the method
 * via call_user_func_array() with the (positionally ordered) validated
 * parameters, so each declared parameter must be an individual method
 * argument (not a single array).
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_course_module extends external_api {

    /**
     * Returns description of method parameters.
     *
     * The order of the keys matters: it must match the order of the
     * arguments of the create_course_module() method below.
     *
     * @return external_function_parameters
     */
    public static function create_course_module_parameters() {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED),
                'modname' => new external_value(PARAM_TEXT, 'Activity module type (e.g. page, forum, quiz, url, resource)', VALUE_REQUIRED),
                'name' => new external_value(PARAM_TEXT, 'Activity name', VALUE_REQUIRED),
                'section' => new external_value(PARAM_INT, 'Course section id (as in course_modules.section / course_sections.id). 0 = first section. If the value matches a section id it is resolved to that section; otherwise it is treated as a section number.', VALUE_OPTIONAL, 0),
                'intro' => new external_value(PARAM_RAW, 'Activity intro / description', VALUE_OPTIONAL, ''),
                'content' => new external_value(PARAM_RAW, 'Activity content (text/HTML for page)', VALUE_OPTIONAL, ''),
                'contentformat' => new external_value(PARAM_INT, 'Content format (FORMAT_HTML=1, FORMAT_TEXT=0)', VALUE_OPTIONAL, FORMAT_HTML),
                'visible' => new external_value(PARAM_BOOL, 'Whether the activity is visible', VALUE_OPTIONAL, true),
            )
        );
    }

    /**
     * Resolve the section argument to a section *number* (what create_module needs).
     *
     * If the given value matches an existing section record id in the course,
     * its section number is returned. Otherwise the value is used as the number
     * as-is (create_module will create the section if missing).
     *
     * @param int $courseid Course id.
     * @param int $section  Section id or number as provided by the caller.
     * @return int Section number.
     */
    protected static function resolve_section(int $courseid, int $section): int {
        global $DB;

        $existing = $DB->get_record('course_sections', ['id' => $section, 'course' => $courseid]);
        if (!empty($existing)) {
            return (int) $existing->section;
        }

        return $section;
    }

    /**
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function create_course_module_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'coursemoduleid' => new external_value(PARAM_INT, 'Created course module (cm) id'),
                'instanceid' => new external_value(PARAM_INT, 'Created module instance id'),
                'cmurl' => new external_value(PARAM_URL, 'URL of the created activity', VALUE_OPTIONAL),
                'warnings' => new external_warnings(),
            )
        );
    }

    /**
     * Create a course module (activity/resource) in a course section.
     *
     * @param int $courseid Course id.
     * @param string $modname Module type (page, forum, quiz, url, resource...).
     * @param string $name Activity name.
     * @param int $section Course section id.
     * @param string $intro Activity intro / description.
     * @param string $content Activity content (page/URL body).
     * @param int $contentformat Content format (FORMAT_HTML=1, FORMAT_TEXT=0).
     * @param bool $visible Whether the activity is visible.
     * @return array Array with status, coursemoduleid, instanceid, cmurl and warnings.
     * @throws moodle_exception
     */
    public static function create_course_module(
        $courseid,
        $modname,
        $name,
        $section = 0,
        $intro = '',
        $content = '',
        $contentformat = FORMAT_HTML,
        $visible = true
    ) {
        global $CFG, $DB;

        $params = self::validate_parameters(
            self::create_course_module_parameters(),
            compact('courseid', 'modname', 'name', 'section', 'intro', 'content', 'contentformat', 'visible')
        );

        $modname = $params['modname'];

        // Make sure the module plugin exists and is enabled.
        $module = $DB->get_record('modules', ['name' => $modname]);
        if (!$module || empty($module->visible)) {
            throw new \moodle_exception('pluginnotexist', 'core', '', $modname);
        }

        // Request and permission validation.
        $coursecontext = \context_course::instance($params['courseid']);
        self::validate_context($coursecontext);
        \require_capability('moodle/course:manageactivities', $coursecontext);

        // Load the module library so that <modname>_add_instance() is available.
        require_once($CFG->libdir . '/resourcelib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        \include_modulelib($modname);

        $moduleinfo = new stdClass();
        $moduleinfo->modulename = $modname;
        $moduleinfo->course = $params['courseid'];
        $moduleinfo->section = self::resolve_section($params['courseid'], (int) $params['section']);
        $moduleinfo->visible = (int) !empty($params['visible']);
        $moduleinfo->name = $params['name'];

        if ($params['intro'] !== '') {
            $moduleinfo->intro = $params['intro'];
            $moduleinfo->introeditor = array('text' => $params['intro'], 'format' => FORMAT_HTML);
        }

        // Only some modules (page/url/folder/resource) have a "content" column.
        if ($params['content'] !== '') {
            $moduleinfo->content = $params['content'];
            if (property_exists($moduleinfo, 'contentformat')) {
                $moduleinfo->contentformat = $params['contentformat'];
            } else {
                $moduleinfo->contentformat = $params['contentformat'];
            }
            $moduleinfo->display = RESOURCELIB_DISPLAY_AUTO;
        }

        $moduleinfo = \create_module($moduleinfo);

        $cm = $DB->get_record('course_modules', ['id' => $moduleinfo->coursemodule]);
        $cmurl = null;
        if (!empty($cm->id)) {
            $cmurl = (string) new \moodle_url('/mod/' . $modname . '/view.php', ['id' => $cm->id]);
        }

        return array(
            'status' => true,
            'coursemoduleid' => (int) $moduleinfo->coursemodule,
            'instanceid' => (int) $moduleinfo->instance,
            'cmurl' => $cmurl,
            'warnings' => array(),
        );
    }
}
