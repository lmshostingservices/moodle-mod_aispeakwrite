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

namespace mod_aispeakwrite\task;

/**
 * Ends paid requests that never got a definite answer, so no answer text is kept longer than needed.
 *
 * A request still pending after seven days is marked expired, its stored body is emptied, and the learner's answer
 * becomes a draft again so it can be submitted afresh.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends \core\task\scheduled_task {
    /** @var int Age after which a pending request is given up. */
    public const MAXAGE = 7 * DAYSECS;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskcleanup', 'mod_aispeakwrite');
    }

    /**
     * Runs the clean-up.
     */
    public function execute() {
        global $DB;
        $old = $DB->get_records_select(
            'aispeakwrite_op',
            "state = 'pending' AND timemodified < :cutoff",
            ['cutoff' => time() - self::MAXAGE],
            '',
            'id, kind, itemid'
        );
        foreach ($old as $op) {
            $DB->update_record(
                'aispeakwrite_op',
                (object)['id' => $op->id, 'state' => 'expired', 'body' => '', 'timemodified' => time()]
            );
            if ($op->kind === 'mark') {
                $DB->set_field_select(
                    'aispeakwrite_sub',
                    'status',
                    'draft',
                    "id = :id AND status = 'pending'",
                    ['id' => $op->itemid]
                );
            }
        }
        mtrace(count($old) . ' stale LMS Labs requests ended.');
    }
}
