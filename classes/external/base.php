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

namespace mod_aispeakwrite\external;

use core_external\external_api;
use mod_aispeakwrite\local\manager;
use mod_aispeakwrite\local\submissions;

/**
 * Shared loading and permission checks for the web services.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base extends external_api {
    /**
     * Loads the activity and the learner's own submission, and checks the learner may practise.
     *
     * @param int $cmid
     * @param int $subid
     * @return array [cm, context, instance, task, sub]
     */
    protected static function load(int $cmid, int $subid): array {
        global $DB, $USER;
        $cm = get_coursemodule_from_id('aispeakwrite', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aispeakwrite:practise', $context);
        $instance = $DB->get_record('aispeakwrite', ['id' => $cm->instance], '*', MUST_EXIST);
        $sub = submissions::get($subid, (int)$instance->id, (int)$USER->id);
        $task = manager::task((int)$instance->id, (int)$sub->taskid);
        $visible = manager::tasks_for_user($instance, $cm, (int)$USER->id);
        if (!isset($visible[$task->id])) {
            throw new \moodle_exception('tasknotavailable', 'mod_aispeakwrite');
        }
        return [$cm, $context, $instance, $task, $sub];
    }

    /**
     * Turns an exception into a learner-facing message for the browser.
     *
     * @param \moodle_exception $e
     * @return array ['ok' => false, 'pending' => bool, 'message' => string]
     */
    protected static function failure(\moodle_exception $e): array {
        return [
            'ok' => false,
            'pending' => in_array($e->errorcode, ['remoteuncertain', 'remotepending'], true),
            'message' => $e->getMessage(),
            'url' => '',
        ];
    }
}
