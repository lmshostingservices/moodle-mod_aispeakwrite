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

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_aispeakwrite\local\marker;
use mod_aispeakwrite\local\submissions;

/**
 * Saves and marks a written answer (1 credit when LMS Labs delivers a result).
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'subid' => new external_value(PARAM_INT, 'Draft id'),
            // Raw so that a learner typing "<" is not rejected; the text is stored as plain text (tags stripped) and is
            // only ever output escaped.
            'content' => new external_value(PARAM_RAW, 'Text as typed, stored as plain text'), // pipeline-ignore: PARAM_RAW — preserve typed comparison symbols; submissions::save strips tags and control characters.
            'notes' => new external_value(PARAM_TEXT, 'Draft note, stored as plain text', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Marks the answer.
     *
     * @param int $cmid
     * @param int $subid
     * @param string $content
     * @param string $notes
     * @return array
     */
    public static function execute(int $cmid, int $subid, string $content, string $notes = ''): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'subid' => $subid, 'content' => $content, 'notes' => $notes]
        );
        [$cm, $context, $instance, $task, $sub] = self::load($params['cmid'], $params['subid']);
        if ($task->kind !== 'writing') {
            throw new \moodle_exception('wrongkind', 'mod_aispeakwrite');
        }
        try {
            if ($sub->status !== 'pending') {
                submissions::save($sub, $params['content'], $params['notes']);
                $sub = submissions::get((int)$sub->id, (int)$instance->id, (int)$sub->userid);
            }
            $marked = marker::mark($instance, $task, $sub, $context);
        } catch (\moodle_exception $e) {
            return self::failure($e);
        }
        $url = new \moodle_url('/mod/aispeakwrite/feedback.php', ['id' => $cm->id, 'sub' => $marked->id]);
        return ['ok' => true, 'pending' => false, 'message' => '', 'url' => $url->out(false)];
    }

    /**
     * Result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Marked'),
            'pending' => new external_value(PARAM_BOOL, 'No definite answer yet: try again with the same request'),
            'message' => new external_value(PARAM_TEXT, 'Message for the learner'),
            'url' => new external_value(PARAM_URL, 'Feedback page'),
        ]);
    }
}
