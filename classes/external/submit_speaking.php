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
use mod_aispeakwrite\local\manager;
use mod_aispeakwrite\local\marker;

/**
 * Saves a speaking transcript (recognised by the learner's browser) and marks it (1 credit when delivered).
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_speaking extends base {
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
                    'Conversation turns',
                    VALUE_DEFAULT,
                    []
                ),
                'seconds' => new external_value(PARAM_INT, 'Session length', VALUE_DEFAULT, 0),
                'speakingseconds' => new external_value(PARAM_INT, 'Time the learner was speaking', VALUE_DEFAULT, 0),
                'longpauses' => new external_value(
                    PARAM_INT,
                    'Silences over 3 seconds while it was the learner\'s turn',
                    VALUE_DEFAULT,
                    0
                ),
                'flow' => new external_value(
                    PARAM_SEQUENCE,
                    'Speech activity per bucket (0-9), comma-separated',
                    VALUE_DEFAULT,
                    ''
                ),
            ]
        );
    }

    /**
     * Stores the transcript and marks it.
     *
     * @param int $cmid
     * @param int $subid
     * @param array $turns
     * @param int $seconds
     * @param int $speakingseconds
     * @param int $longpauses
     * @param string $flow
     * @return array
     */
    public static function execute(
        int $cmid,
        int $subid,
        array $turns = [],
        int $seconds = 0,
        int $speakingseconds = 0,
        int $longpauses = 0,
        string $flow = ''
    ): array {
        global $DB;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'subid' => $subid,
            'turns' => $turns, 'seconds' => $seconds, 'speakingseconds' => $speakingseconds, 'longpauses' => $longpauses,
            'flow' => $flow]
        );
        [$cm, $context, $instance, $task, $sub] = self::load($params['cmid'], $params['subid']);
        if ($task->kind !== 'speaking') {
            throw new \moodle_exception('wrongkind', 'mod_aispeakwrite');
        }
        try {
            if ($sub->status !== 'pending') {
                if (!in_array($sub->status, ['draft', 'failed'], true)) {
                    throw new \moodle_exception('alreadymarked', 'mod_aispeakwrite');
                }
                $clean = [];
                $learnerwords = 0;
                foreach (array_slice($params['turns'], 0, 60) as $turn) {
                    $text = marker::clean($turn['text'], 2000);
                    if ($text === '' || !in_array($turn['role'], ['learner', 'partner'], true)) {
                        continue;
                    }
                    $clean[] = ['role' => $turn['role'], 'text' => $text];
                    if ($turn['role'] === 'learner') {
                        $learnerwords += manager::words($text);
                    }
                }
                $seconds = max(0, min(3600, $params['seconds']));
                $speaking = max(0, min($seconds, $params['speakingseconds']));
                $flowvalues = array_slice(
                    array_map(
                        fn($v) => max(0, min(9, (int)$v)),
                        array_filter(explode(',', $params['flow']), 'strlen')
                    ),
                    0,
                    120
                );
                $metrics = [
                    'seconds' => $seconds,
                    'speakingseconds' => $speaking,
                    'longpauses' => max(0, min(999, $params['longpauses'])),
                    'wpm' => $speaking > 0 ? (int)round($learnerwords / ($speaking / 60)) : 0,
                    'flow' => $flowvalues,
                ];
                $DB->update_record(
                    'aispeakwrite_sub',
                    (object)['id' => $sub->id,
                    'content' => json_encode(['turns' => $clean], JSON_UNESCAPED_UNICODE),
                    'metrics' => json_encode($metrics),
                    'timemodified' => time()]
                );
                $sub = $DB->get_record('aispeakwrite_sub', ['id' => $sub->id], '*', MUST_EXIST);
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
        return submit::execute_returns();
    }
}
