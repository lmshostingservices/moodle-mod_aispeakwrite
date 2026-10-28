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
 * Teacher: the activity's writing prompts and speaking topics.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_aispeakwrite\local\manager;
use mod_aispeakwrite\output\teacher;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$taskid = optional_param('task', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$cm = get_coursemodule_from_id('aispeakwrite', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('aispeakwrite', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aispeakwrite:managetasks', $context);

$url = new moodle_url('/mod/aispeakwrite/manage.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($instance->name) . ': ' . get_string('tasks', 'mod_aispeakwrite'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->add_body_class('asw-page');

if ($action !== '' && $taskid) {
    require_sesskey();
    $task = manager::task((int)$instance->id, $taskid);
    switch ($action) {
        case 'hide':
        case 'show':
            $DB->set_field('aispeakwrite_task', 'visible', $action === 'show' ? 1 : 0, ['id' => $task->id]);
            redirect($url);
            break;
        case 'up':
        case 'down':
            $tasks = array_values(manager::tasks((int)$instance->id));
            $ids = array_map(fn($t) => (int)$t->id, $tasks);
            $pos = array_search((int)$task->id, $ids, true);
            $swap = $action === 'up' ? $pos - 1 : $pos + 1;
            if ($pos !== false && isset($ids[$swap])) {
                [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                foreach ($ids as $order => $tid) {
                    $DB->set_field('aispeakwrite_task', 'sortorder', $order + 1, ['id' => $tid]);
                }
            }
            redirect($url);
            break;
        case 'delete':
            if (!$confirm) {
                echo $OUTPUT->header();
                $yes = new moodle_url(
                    $url,
                    ['task' => $task->id, 'action' => 'delete', 'confirm' => 1, 'sesskey' => sesskey()]
                );
                echo $OUTPUT->confirm(
                    get_string('deletetaskconfirm', 'mod_aispeakwrite', format_string($task->title)),
                    $yes,
                    $url
                );
                echo $OUTPUT->footer();
                exit;
            }
            manager::delete_task($task);
            aispeakwrite_update_grades($instance);
            redirect($url, get_string('taskdeleted', 'mod_aispeakwrite'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_aispeakwrite/manage', teacher::manage($instance, $cm));
echo $OUTPUT->footer();
