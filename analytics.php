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
 * Teacher: class analytics and credit usage.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_aispeakwrite\local\manager;
use mod_aispeakwrite\output\teacher;

$id = required_param('id', PARAM_INT);
$issue = optional_param('issue', '', PARAM_TEXT);
$cm = get_coursemodule_from_id('aispeakwrite', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('aispeakwrite', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aispeakwrite:viewreports', $context);
$issue = preg_match('/^(tip|grammar|content):[a-z0-9-]{1,60}$/', $issue) ? $issue : '';

$url = new moodle_url('/mod/aispeakwrite/analytics.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($instance->name) . ': ' . get_string('analytics', 'mod_aispeakwrite'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->add_body_class('asw-page');

// Assign practice to the learners who keep missing a pattern.
if (optional_param('assign', 0, PARAM_BOOL) && confirm_sesskey()) {
    require_capability('mod/aispeakwrite:managetasks', $context);
    $task = manager::task((int)$instance->id, required_param('taskid', PARAM_INT));
    $userids = array_filter(array_map('intval', explode(',', required_param('userids', PARAM_SEQUENCE))));
    $enrolled = array_keys(get_enrolled_users($context, 'mod/aispeakwrite:practise', 0, 'u.id'));
    $userids = array_values(array_intersect($userids, $enrolled));
    $due = optional_param('due', '', PARAM_ALPHANUMEXT);
    $duetime = 0;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $due, $m)) {
        $duetime = make_timestamp((int)$m[1], (int)$m[2], (int)$m[3], 23, 59);
    }
    $n = manager::assign($task, $userids, (int)$duetime, (int)$USER->id);
    redirect(
        new moodle_url($url, ['issue' => $issue]),
        get_string('assignedn', 'mod_aispeakwrite', count($userids)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$groupid = groups_get_activity_group($cm, true) ?: 0;

echo $OUTPUT->header();
groups_print_activity_menu($cm, $url);
echo $OUTPUT->render_from_template(
    'mod_aispeakwrite/analytics',
    teacher::analytics($instance, $cm, $context, (int)$groupid, $issue)
);
echo $OUTPUT->footer();
