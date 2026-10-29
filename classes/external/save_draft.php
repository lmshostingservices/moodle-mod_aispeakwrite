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
use mod_aispeakwrite\local\integrity;
use mod_aispeakwrite\local\manager;
use mod_aispeakwrite\local\submissions;

/**
 * Saves a writing draft (autosave). Free.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_draft extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'subid' => new external_value(PARAM_INT, 'Draft id'),
                // Plain text. The browser adds a space after any "<" that could start a tag, so nothing typed is lost.
                'content' => new external_value(PARAM_TEXT, 'Text as typed'),
                'notes' => new external_value(PARAM_TEXT, 'Draft note', VALUE_DEFAULT, ''),
                'pastes' => new external_value(
                    PARAM_INT,
                    'Pastes from outside the answer box since the last save',
                    VALUE_DEFAULT,
                    0
                ),
                'pastechars' => new external_value(PARAM_INT, 'Characters in those pastes', VALUE_DEFAULT, 0),
                'pasteblocked' => new external_value(PARAM_INT, 'Pastes stopped since the last save', VALUE_DEFAULT, 0),
                'tabswitches' => new external_value(
                    PARAM_INT,
                    'Times the learner left the screen since the last save',
                    VALUE_DEFAULT,
                    0
                ),
            ]
        );
    }

    /**
     * Saves the draft.
     *
     * @param int $cmid
     * @param int $subid
     * @param string $content
     * @param string $notes
     * @param int $pastes
     * @param int $pastechars
     * @param int $pasteblocked
     * @param int $tabswitches
     * @return array
     */
    public static function execute(
        int $cmid,
        int $subid,
        string $content,
        string $notes = '',
        int $pastes = 0,
        int $pastechars = 0,
        int $pasteblocked = 0,
        int $tabswitches = 0
    ): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'subid' => $subid, 'content' => $content, 'notes' => $notes, 'pastes' => $pastes,
                'pastechars' => $pastechars, 'pasteblocked' => $pasteblocked, 'tabswitches' => $tabswitches]
        );
        [, , $instance, , $sub] = self::load($params['cmid'], $params['subid']);
        submissions::save($sub, $params['content'], $params['notes']);
        integrity::add_counts(
            $instance,
            $sub,
            $params['pastes'],
            $params['pastechars'],
            $params['pasteblocked'],
            $params['tabswitches']
        );
        return ['saved' => true, 'words' => manager::words(submissions::plain($params['content']))];
    }

    /**
     * Result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'saved' => new external_value(PARAM_BOOL, 'Saved'),
                'words' => new external_value(PARAM_INT, 'Word count'),
            ]
        );
    }
}
