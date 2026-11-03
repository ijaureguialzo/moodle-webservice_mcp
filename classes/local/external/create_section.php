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
 * Web service MCP: create course section external API.
 *
 * Creates (or renames) a course section (unit/topic) with a name and
 * summary. Useful for building complete courses section by section.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_section extends external_api {

    /**
     * Returns description of method parameters.
     *
     * The order of the keys matters: it must match the order of the
     * arguments of the create_section() method below.
     *
     * @return external_function_parameters
     */
    public static function create_section_parameters() {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED),
                'number' => new external_value(PARAM_INT, 'Section number (0 = first section). If not provided, the next free number is used.', VALUE_OPTIONAL, null, NULL_ALLOWED),
                'name' => new external_value(PARAM_TEXT, 'Section name', VALUE_OPTIONAL, ''),
                'summary' => new external_value(PARAM_RAW, 'Section summary / intro', VALUE_OPTIONAL, ''),
                'visible' => new external_value(PARAM_BOOL, 'Whether the section is visible', VALUE_OPTIONAL, true),
            )
        );
    }

    /**
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function create_section_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'sectionid' => new external_value(PARAM_INT, 'Section record id (course_sections.id)'),
                'sectionnumber' => new external_value(PARAM_INT, 'Section number'),
                'warnings' => new external_warnings(),
            )
        );
    }

    /**
     * Create (or update) a course section with a name and summary.
     *
     * @param int $courseid Course id.
     * @param int|null $number Section number; null = next free number.
     * @param string $name Section name.
     * @param string $summary Section summary (HTML).
     * @param bool $visible Whether the section is visible.
     * @return array Array with status, sectionid, sectionnumber and warnings.
     * @throws moodle_exception
     */
    public static function create_section(
        $courseid,
        $number = null,
        $name = '',
        $summary = '',
        $visible = true
    ) {
        global $CFG, $DB;

        $params = self::validate_parameters(
            self::create_section_parameters(),
            compact('courseid', 'number', 'name', 'summary', 'visible')
        );

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        \require_capability('moodle/course:manageactivities', $context);

        if ($params['number'] === null) {
            $max = $DB->get_field_select('course_sections', 'course = :c', ['c' => $params['courseid']], 'MAX(section)');
            $params['number'] = ($max === false) ? 0 : ((int) $max + 1);
        }
        $number = (int) $params['number'];

        // Create the section through the course format API when missing
        // (this keeps section ordering/sequence consistent with the format).
        require_once($CFG->dirroot . '/course/lib.php');
        $modinfo = \get_fast_modinfo($course);
        if (!$modinfo->get_section_info($number)) {
            \core_courseformat\formatactions::section($course)->create_if_missing([$number]);
            $modinfo = \get_fast_modinfo($params['courseid'], 0, true);
        }

        $section = $DB->get_record('course_sections', ['course' => $params['courseid'], 'section' => $number], '*', MUST_EXIST);

        $section->name = $params['name'];
        $section->summary = $params['summary'];
        $section->summaryformat = FORMAT_HTML;
        $section->visible = (int) !empty($params['visible']);
        $DB->update_record('course_sections', $section);

        // Refresh the course module info cache and notify observers.
        \get_fast_modinfo($params['courseid'], 0, true);
        $event = \core\event\course_section_updated::create([
            'objectid' => $section->id,
            'context' => $context,
            'other' => ['sectionnum' => $number],
        ]);
        $event->trigger();

        return array(
            'status' => true,
            'sectionid' => (int) $section->id,
            'sectionnumber' => $number,
            'warnings' => array(),
        );
    }
}
