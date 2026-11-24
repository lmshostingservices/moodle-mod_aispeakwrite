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
 * Cancels an answer that is stuck being marked, so it can be submitted again.
 *
 * The learner can cancel their own answer; a teacher who manages tasks can clear any learner's. Either way only after
 * the marking has had no result for a few minutes. The text is kept and nothing is charged for the cancelled request.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_aispeakwrite\local\operation;

$id = required_param('id', PARAM_INT);
$subid = required_param('sub', PARAM_INT);
$cm = get_coursemodule_from_id('aispeakwrite', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('aispeakwrite', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
require_sesskey();
$context = context_module::instance($cm->id);
$sub = $DB->get_record('aispeakwrite_sub', ['id' => $subid, 'aispeakwriteid' => $instance->id], '*', MUST_EXIST);

$own = (int)$sub->userid === (int)$USER->id;
if ($own) {
    require_capability('mod/aispeakwrite:practise', $context);
    $back = new moodle_url(
        '/mod/aispeakwrite/' . ($sub->kind === 'speaking' ? 'speak.php' : 'write.php'),
        ['id' => $cm->id, 'task' => $sub->taskid]
    );
} else {
    require_capability('mod/aispeakwrite:managetasks', $context);
    $back = new moodle_url('/mod/aispeakwrite/analytics.php', ['id' => $cm->id]);
}
$PAGE->set_url(new moodle_url('/mod/aispeakwrite/unstick.php', ['id' => $cm->id, 'sub' => $sub->id]));

if ($sub->status !== 'pending') {
    redirect($back);
}
if (!operation::cancellable($sub)) {
    redirect($back, get_string('cancelnotyet', 'mod_aispeakwrite'), null, \core\output\notification::NOTIFY_INFO);
}
operation::release_submission((int)$sub->id);
redirect(
    $back,
    get_string($own ? 'cancelled' : 'stuck_cleared', 'mod_aispeakwrite'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
