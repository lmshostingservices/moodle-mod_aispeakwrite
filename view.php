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
 * Activity home: the learner dashboard, or the teacher overview.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_aispeakwrite\output\views;
use mod_aispeakwrite\output\teacher;

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('aispeakwrite', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('aispeakwrite', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aispeakwrite:view', $context);

$PAGE->set_url('/mod/aispeakwrite/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->add_body_class('asw-page');

$event = \mod_aispeakwrite\event\course_module_viewed::create(['objectid' => $instance->id, 'context' => $context]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('aispeakwrite', $instance);
$event->trigger();
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$learner = has_capability('mod/aispeakwrite:practise', $context);
$teacher = has_any_capability(['mod/aispeakwrite:managetasks', 'mod/aispeakwrite:viewreports'], $context);

echo $OUTPUT->header();
if ($learner) {
    echo $OUTPUT->render_from_template('mod_aispeakwrite/dashboard', views::dashboard($instance, $cm, (int)$USER->id));
} else if ($teacher) {
    echo $OUTPUT->render_from_template('mod_aispeakwrite/teacherhome', teacher::home($instance, $cm, $context));
} else {
    echo $OUTPUT->notification(get_string('nopractise', 'mod_aispeakwrite'), 'info');
}
echo $OUTPUT->footer();
