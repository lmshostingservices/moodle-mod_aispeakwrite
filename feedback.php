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
 * Feedback on a marked answer.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_aispeakwrite\local\integrity;
use mod_aispeakwrite\local\manager;
use mod_aispeakwrite\local\submissions;
use mod_aispeakwrite\output\views;

$id = required_param('id', PARAM_INT);
$subid = required_param('sub', PARAM_INT);
$cm = get_coursemodule_from_id('aispeakwrite', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('aispeakwrite', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aispeakwrite:view', $context);

$sub = submissions::get($subid, (int)$instance->id);
$own = (int)$sub->userid === (int)$USER->id;
if (!$own) {
    require_capability('mod/aispeakwrite:viewreports', $context);
    $groupmode = groups_get_activity_groupmode($cm);
    if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
        $shared = array_intersect(
            array_keys(groups_get_all_groups($course->id, $USER->id)),
            array_keys(groups_get_all_groups($course->id, $sub->userid))
        );
        if (!$shared) {
            throw new required_capability_exception($context, 'moodle/site:accessallgroups', 'nopermissions', '');
        }
    }
}
if ($sub->status !== 'marked') {
    $page = $sub->kind === 'speaking' ? 'speak.php' : 'write.php';
    redirect(new moodle_url('/mod/aispeakwrite/' . $page, ['id' => $cm->id, 'task' => $sub->taskid]));
}
$task = manager::task((int)$instance->id, (int)$sub->taskid);

$PAGE->set_url('/mod/aispeakwrite/feedback.php', ['id' => $cm->id, 'sub' => $sub->id]);
$PAGE->set_title(format_string($task->title) . ': ' . get_string('feedback', 'mod_aispeakwrite'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->add_body_class('asw-page');
$PAGE->requires->js_call_amd('mod_aispeakwrite/feedback', 'init', ['#asw-feedback-' . $sub->id]);

echo $OUTPUT->header();
if (!$own) {
    $user = core_user::get_user($sub->userid);
    echo $OUTPUT->notification(get_string('viewinganswerof', 'mod_aispeakwrite', fullname($user)), 'info');
}
$data = views::feedback($instance, $cm, $task, $sub, $own);
$data['subid'] = $sub->id;
// Writing integrity is for teachers only: never shown to the learner, never sent to LMS Labs.
if (!$own && has_capability('mod/aispeakwrite:viewreports', $context)) {
    $data['integrity'] = integrity::panel($instance, $cm, $sub);
}
echo $OUTPUT->render_from_template('mod_aispeakwrite/feedback', $data);
echo $OUTPUT->footer();
