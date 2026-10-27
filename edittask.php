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
 * Teacher: add or edit a writing prompt or speaking topic.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_aispeakwrite\form\task_form;
use mod_aispeakwrite\local\manager;

$id = required_param('id', PARAM_INT);
$taskid = optional_param('task', 0, PARAM_INT);
$cm = get_coursemodule_from_id('aispeakwrite', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('aispeakwrite', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aispeakwrite:managetasks', $context);

$task = $taskid ? manager::task((int)$instance->id, $taskid) : null;
$kind = $task ? $task->kind : optional_param('kind', 'writing', PARAM_ALPHA);
$kind = $kind === 'speaking' ? 'speaking' : 'writing';

$PAGE->set_url('/mod/aispeakwrite/edittask.php', ['id' => $cm->id, 'task' => $taskid, 'kind' => $kind]);
$PAGE->set_title(format_string($instance->name) . ': ' . get_string($task ? 'edittask' : 'addtask', 'mod_aispeakwrite'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->add_body_class('asw-page');
$back = new moodle_url('/mod/aispeakwrite/manage.php', ['id' => $cm->id]);

$groups = [];
foreach (groups_get_all_groups($course->id, 0, $cm->groupingid) as $g) {
    $groups[$g->id] = format_string($g->name);
}
$learners = [];
$namefields = array_map(fn($f) => 'u.' . $f, \core_user\fields::get_name_fields());
foreach (
    get_enrolled_users(
        $context,
        'mod/aispeakwrite:practise',
        0,
        'u.id, ' . implode(', ', $namefields),
        'u.lastname, u.firstname'
    ) as $u
) {
    $learners[$u->id] = fullname($u);
}

$form = new task_form(null, ['kind' => $kind, 'groups' => $groups, 'learners' => $learners]);
if ($task) {
    $values = clone $task;
    $values->task = $task->id;
    $values->id = $cm->id;
    $values->userids = $DB->get_fieldset_select('aispeakwrite_taskuser', 'userid', 'taskid = ?', [$task->id]);
    $form->set_data($values);
} else {
    $form->set_data(['id' => $cm->id, 'task' => 0, 'kind' => $kind]);
}

if ($form->is_cancelled()) {
    redirect($back);
}
if ($data = $form->get_data()) {
    $types = $kind === 'speaking' ? manager::SPEAKING_TYPES : manager::WRITING_TYPES;
    $record = (object)[
        'aispeakwriteid' => $instance->id,
        'kind' => $kind,
        'tasktype' => in_array($data->tasktype, $types, true) ? $data->tasktype : $types[0],
        'title' => trim($data->title),
        'topic' => trim((string)$data->topic),
        'situation' => trim((string)$data->situation),
        'sourcetext' => trim((string)$data->sourcetext),
        'points' => trim((string)$data->points),
        'formality' => in_array($data->formality ?? 'any', ['du', 'sie', 'any'], true) ? ($data->formality ?? 'any') : 'any',
        'targetwords' => max(0, min(1000, (int)($data->targetwords ?? 0))),
        'timelimit' => max(1, min(120, (int)$data->timelimit)),
        'tasktips' => trim((string)$data->tasktips),
        'guide' => trim((string)$data->guide),
        'phrases' => trim((string)($data->phrases ?? '')),
        'partnerbrief' => trim((string)($data->partnerbrief ?? '')),
        'audience' => in_array($data->audience, ['all', 'group', 'selected'], true) ? $data->audience : 'all',
        'groupid' => (int)($data->groupid ?? 0),
        'duedate' => (int)($data->duedate ?? 0),
        'visible' => empty($data->visible) ? 0 : 1,
        'timemodified' => time(),
    ];
    if ($task) {
        $record->id = $task->id;
        $DB->update_record('aispeakwrite_task', $record);
    } else {
        $record->sortorder = manager::next_sortorder((int)$instance->id);
        $record->timecreated = $record->timemodified;
        $record->id = $DB->insert_record('aispeakwrite_task', $record);
    }
    $userids = array_map('intval', array_intersect((array)($data->userids ?? []), array_keys($learners)));
    $where = 'taskid = :taskid';
    $params = ['taskid' => $record->id];
    if ($userids) {
        [$notin, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u', false);
        $where .= " AND userid $notin";
        $params += $inparams;
    }
    $DB->delete_records_select('aispeakwrite_taskuser', $where, $params);
    manager::assign($record, $userids, $record->duedate, (int)$USER->id);
    redirect($back, get_string('tasksaved', 'mod_aispeakwrite'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($task ? 'edittask' : 'addtask', 'mod_aispeakwrite'));
$form->display();
echo $OUTPUT->footer();
