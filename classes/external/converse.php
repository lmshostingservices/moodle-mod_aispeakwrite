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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_aispeakwrite\local\marker;

/**
 * The AI partner's next turn in a speaking conversation.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class converse extends base {
    /** @var int Partner turns allowed in one conversation. */
    public const MAXTURNS = 20;

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
                'turns' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'role' => new external_value(PARAM_ALPHA, 'learner or partner'),
                            'text' => new external_value(PARAM_TEXT, 'What was said'),
                        ]
                    ),
                    'Conversation so far (empty: the partner opens it)',
                    VALUE_DEFAULT,
                    []
                ),
            ]
        );
    }

    /**
     * Asks LMS Labs for the partner's reply.
     *
     * @param int $cmid
     * @param int $subid
     * @param array $turns
     * @return array
     */
    public static function execute(int $cmid, int $subid, array $turns = []): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'subid' => $subid, 'turns' => $turns]
        );
        [, , $instance, $task, $sub] = self::load($params['cmid'], $params['subid']);
        if ($task->kind !== 'speaking') {
            throw new \moodle_exception('wrongkind', 'mod_aispeakwrite');
        }
        if (!get_config('mod_aispeakwrite', 'partner') || $sub->status !== 'draft') {
            return ['ok' => false, 'reply' => '', 'done' => true, 'message' => get_string('partneroff', 'mod_aispeakwrite')];
        }
        $partnerturns = count(array_filter($params['turns'], fn($t) => $t['role'] === 'partner'));
        if ($partnerturns >= self::MAXTURNS) {
            return ['ok' => true, 'reply' => '', 'done' => true, 'message' => ''];
        }
        try {
            $answer = marker::converse($instance, $task, $sub, $params['turns']);
        } catch (\moodle_exception $e) {
            return ['ok' => false, 'reply' => '', 'done' => false, 'message' => $e->getMessage()];
        }
        return ['ok' => true, 'reply' => $answer['reply'], 'done' => $answer['done'], 'message' => ''];
    }

    /**
     * Result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'ok' => new external_value(PARAM_BOOL, 'A reply came back'),
                'reply' => new external_value(PARAM_TEXT, 'What the partner says'),
                'done' => new external_value(PARAM_BOOL, 'The partner thinks the conversation is complete'),
                'message' => new external_value(PARAM_TEXT, 'Message for the learner'),
            ]
        );
    }
}
