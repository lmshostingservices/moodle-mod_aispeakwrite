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
 * The speaking screen.
 *
 * Speech is turned into text by the learner's own browser; only the text is sent to Moodle and marked.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_aispeakwrite\local\manager;
use mod_aispeakwrite\local\submissions;
use mod_aispeakwrite\output\views;

$id = required_param('id', PARAM_INT);
$taskid = required_param('task', PARAM_INT);
$cm = get_coursemodule_from_id('aispeakwrite', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('aispeakwrite', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aispeakwrite:practise', $context);

$tasks = manager::tasks_for_user($instance, $cm, (int)$USER->id);
if (!isset($tasks[$taskid]) || $tasks[$taskid]->kind !== 'speaking') {
    throw new moodle_exception('tasknotavailable', 'mod_aispeakwrite');
}
$task = $tasks[$taskid];

$PAGE->set_url('/mod/aispeakwrite/speak.php', ['id' => $cm->id, 'task' => $task->id]);
$PAGE->set_title(format_string($task->title));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->add_body_class('asw-page');

$sub = submissions::open_draft($instance, $task, (int)$USER->id);
echo $OUTPUT->header();
if (!$sub) {
    $last = submissions::latest_marked((int)$task->id, (int)$USER->id);
    echo $OUTPUT->notification(get_string('norewritesleft', 'mod_aispeakwrite'), 'info');
    if ($last) {
        echo $OUTPUT->single_button(
            new moodle_url('/mod/aispeakwrite/feedback.php', ['id' => $cm->id, 'sub' => $last->id]),
            get_string('seefeedback', 'mod_aispeakwrite'),
            'get'
        );
    }
} else {
    $data = views::speaker($instance, $cm, $task, $sub);
    $PAGE->requires->js_call_amd('mod_aispeakwrite/speaker', 'init', ['#asw-speaker-' . $sub->id]);
    echo $OUTPUT->render_from_template('mod_aispeakwrite/speaker', $data);
}
echo $OUTPUT->footer();
