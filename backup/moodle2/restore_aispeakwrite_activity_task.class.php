<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Restore task for mod_aispeakwrite.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/aispeakwrite/backup/moodle2/restore_aispeakwrite_stepslib.php');

/**
 * Restore task for mod_aispeakwrite.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_aispeakwrite_activity_task extends restore_activity_task {
    /**
     * No specific settings.
     */
    protected function define_my_settings() {
    }

    /**
     * The structure step.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_aispeakwrite_activity_structure_step('aispeakwrite_structure', 'aispeakwrite.xml'));
    }

    /**
     * Content to decode.
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        return [new restore_decode_content('aispeakwrite', ['intro'], 'aispeakwrite')];
    }

    /**
     * Link decoding rules.
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('ASWVIEWBYID', '/mod/aispeakwrite/view.php?id=$1', 'course_module'),
            new restore_decode_rule('ASWINDEX', '/mod/aispeakwrite/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Log rules.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules() {
        return [new restore_log_rule('aispeakwrite', 'view', 'view.php?id={course_module}', '{aispeakwrite}')];
    }

    /**
     * Course log rules.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules_for_course() {
        return [new restore_log_rule('aispeakwrite', 'view all', 'index.php?id={course}', null)];
    }
}
