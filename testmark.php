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
 * Teacher: mark a sample text with the current tips before learners see the task (1 credit, nothing saved).
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_aispeakwrite\local\manager;
use mod_aispeakwrite\local\marker;
use mod_aispeakwrite\local\operation;
use mod_aispeakwrite\local\remote;
use mod_aispeakwrite\local\tips;
use mod_aispeakwrite\output\views;

$id = required_param('id', PARAM_INT);
$taskid = required_param('task', PARAM_INT);
$cm = get_coursemodule_from_id('aispeakwrite', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('aispeakwrite', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aispeakwrite:managetasks', $context);
$task = manager::task((int)$instance->id, $taskid);

$url = new moodle_url('/mod/aispeakwrite/testmark.php', ['id' => $cm->id, 'task' => $task->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('testsample', 'mod_aispeakwrite'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->add_body_class('asw-page');

$text = optional_param('sample', '', PARAM_TEXT);
$mode = optional_param('mode', 'think', PARAM_ALPHA) === 'explain' ? 'explain' : 'think';
$result = null;
$error = '';
if ($text !== '' && confirm_sesskey()) {
    $sub = (object)['id' => 0, 'taskid' => $task->id, 'userid' => $USER->id, 'attempt' => 1, 'kind' => $task->kind,
        'fbmode' => $mode, 'metrics' => '', 'notes' => '', 'status' => 'marked', 'score' => 0, 'examscore' => 0,
        'maxscore' => 45, 'content' => $task->kind === 'speaking'
            ? json_encode(['turns' => [['role' => 'learner', 'text' => $text]]], JSON_UNESCAPED_UNICODE) : $text];
    $merged = tips::merged($instance, $task);
    try {
        if (manager::words(marker::answer_text($sub)) < manager::min_words($task)) {
            throw new moodle_exception('tooshort', 'mod_aispeakwrite', '', manager::min_words($task));
        }
        $body = marker::body($instance, $task, $sub, $merged);
        $body['test'] = true;
        $op = operation::claim((int)$instance->id, (int)$USER->id, 'test', random_int(1, 2000000000), $body);
        [$status, $response, , $headers] = remote::request(remote::MARK, operation::body($op), $op->idemkey);
        if ($status !== 200) {
            operation::not_delivered($op, $status, $response, $headers);
        }
        $data = json_decode($response, true);
        operation::complete($op, is_numeric($data['creditsCharged'] ?? null) ? (int)$data['creditsCharged'] : 1);
        $fb = is_array($data['result'] ?? null) ? marker::normalise(
            $data['result'],
            marker::answer_text($sub),
            $task,
            $merged['tips']
        ) : null;
        if ($fb === null) {
            throw new moodle_exception('unusableresult', 'mod_aispeakwrite', '', '-');
        }
        $sub->feedback = json_encode($fb);
        $sub->score = $fb['score'];
        $sub->examscore = $fb['examscore'];
        $result = views::feedback($instance, $cm, $task, $sub, true);
        $result['canrewrite'] = false;
        $result['subid'] = 0;
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_aispeakwrite/testmark', [
    'title' => format_string($task->title),
    'actionurl' => $url->out(false),
    'sesskey' => sesskey(),
    'sample' => $text,
    'think' => $mode === 'think',
    'error' => $error,
    'backurl' => (new moodle_url('/mod/aispeakwrite/manage.php', ['id' => $cm->id]))->out(false),
]);
if ($result) {
    $PAGE->requires->js_call_amd('mod_aispeakwrite/feedback', 'init', ['#asw-feedback-0']);
    echo $OUTPUT->render_from_template('mod_aispeakwrite/feedback', $result);
}
echo $OUTPUT->footer();
