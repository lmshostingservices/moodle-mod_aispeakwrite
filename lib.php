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
 * Moodle callbacks for mod_aispeakwrite. Logic lives in classes/.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_aispeakwrite\local\manager;
use mod_aispeakwrite\local\submissions;

/**
 * Declares the features this module supports.
 *
 * @param string $feature FEATURE_xx constant
 * @return mixed
 */
function aispeakwrite_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_OTHER;
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_PLAGIARISM:
            return true;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Adds a new instance.
 *
 * @param stdClass $data
 * @param mod_aispeakwrite_mod_form|null $mform
 * @return int new instance id
 */
function aispeakwrite_add_instance($data, $mform = null) {
    global $DB;
    $data = manager::prepare_instance($data);
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->id = $DB->insert_record('aispeakwrite', $data);
    aispeakwrite_grade_item_update($data);
    return $data->id;
}

/**
 * Updates an instance.
 *
 * @param stdClass $data
 * @param mod_aispeakwrite_mod_form|null $mform
 * @return bool
 */
function aispeakwrite_update_instance($data, $mform = null) {
    global $DB;
    $data->id = $data->instance;
    $data = manager::prepare_instance($data);
    $data->timemodified = time();
    $DB->update_record('aispeakwrite', $data);
    aispeakwrite_grade_item_update($data);
    aispeakwrite_update_grades($data);
    return true;
}

/**
 * Deletes an instance and everything that belongs to it.
 *
 * @param int $id
 * @return bool
 */
function aispeakwrite_delete_instance($id) {
    global $DB;
    $instance = $DB->get_record('aispeakwrite', ['id' => $id]);
    if (!$instance) {
        return false;
    }
    $taskids = $DB->get_fieldset_select('aispeakwrite_task', 'id', 'aispeakwriteid = ?', [$id]);
    if ($taskids) {
        [$in, $params] = $DB->get_in_or_equal($taskids);
        $DB->delete_records_select('aispeakwrite_taskuser', "taskid $in", $params);
    }
    foreach (['aispeakwrite_sub', 'aispeakwrite_memory', 'aispeakwrite_op', 'aispeakwrite_task'] as $table) {
        $DB->delete_records($table, ['aispeakwriteid' => $id]);
    }
    aispeakwrite_grade_item_delete($instance);
    $DB->delete_records('aispeakwrite', ['id' => $id]);
    return true;
}

/**
 * Adds completion rule data to the course module info.
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info|false
 */
function aispeakwrite_get_coursemodule_info($coursemodule) {
    global $DB;
    $instance = $DB->get_record(
        'aispeakwrite',
        ['id' => $coursemodule->instance],
        'id, name, intro, introformat, completionmarked'
    );
    if (!$instance) {
        return false;
    }
    $result = new cached_cm_info();
    $result->name = $instance->name;
    if ($coursemodule->showdescription) {
        $result->content = format_module_intro('aispeakwrite', $instance, $coursemodule->id, false);
    }
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionmarked'] = (int)$instance->completionmarked;
    }
    return $result;
}

/**
 * Describes the active completion rules.
 *
 * @param cm_info|stdClass $cm
 * @return string[]
 */
function mod_aispeakwrite_get_completion_active_rule_descriptions($cm) {
    if (empty($cm->customdata['customcompletionrules']) || $cm->completion != COMPLETION_TRACKING_AUTOMATIC) {
        return [];
    }
    $out = [];
    $n = (int)($cm->customdata['customcompletionrules']['completionmarked'] ?? 0);
    if ($n > 0) {
        $out[] = get_string('completiondetail:marked', 'mod_aispeakwrite', $n);
    }
    return $out;
}

/**
 * Creates or updates the grade item.
 *
 * @param stdClass $instance
 * @param mixed $grades
 * @return int
 */
function aispeakwrite_grade_item_update($instance, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $params = ['itemname' => $instance->name];
    if (isset($instance->cmidnumber)) {
        $params['idnumber'] = $instance->cmidnumber;
    }
    if ($instance->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = $instance->grade;
        $params['grademin'] = 0;
    } else if ($instance->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid'] = -$instance->grade;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }
    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }
    return grade_update('mod/aispeakwrite', $instance->course, 'mod', 'aispeakwrite', $instance->id, 0, $grades, $params);
}

/**
 * Deletes the grade item.
 *
 * @param stdClass $instance
 * @return int
 */
function aispeakwrite_grade_item_delete($instance) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    return grade_update(
        'mod/aispeakwrite',
        $instance->course,
        'mod',
        'aispeakwrite',
        $instance->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * The grades of one or all learners: the average of each learner's best strict score per task.
 *
 * @param stdClass $instance
 * @param int $userid 0 for all
 * @return array userid => grade object
 */
function aispeakwrite_get_user_grades($instance, $userid = 0) {
    global $DB;
    $params = ['aid' => $instance->id];
    $where = '';
    if ($userid) {
        $where = 'AND userid = :userid';
        $params['userid'] = $userid;
    }
    $users = $DB->get_fieldset_sql(
        "SELECT DISTINCT userid FROM {aispeakwrite_sub}
        WHERE aispeakwriteid = :aid AND status = 'marked' $where",
        $params
    );
    $grades = [];
    foreach ($users as $uid) {
        $best = submissions::best_by_task((int)$instance->id, (int)$uid);
        if (!$best) {
            continue;
        }
        $percent = array_sum($best) / count($best);
        $grade = $instance->grade > 0 ? round($percent / 100 * $instance->grade, 2) : null;
        $grades[$uid] = (object)['userid' => $uid, 'rawgrade' => $grade];
    }
    return $grades;
}

/**
 * Pushes grades to the gradebook.
 *
 * @param stdClass $instance
 * @param int $userid
 * @param bool $nullifnone
 */
function aispeakwrite_update_grades($instance, $userid = 0, $nullifnone = true) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    if ($instance->grade == 0) {
        aispeakwrite_grade_item_update($instance);
        return;
    }
    $grades = aispeakwrite_get_user_grades($instance, $userid);
    if ($grades) {
        aispeakwrite_grade_item_update($instance, $grades);
    } else if ($userid && $nullifnone) {
        aispeakwrite_grade_item_update($instance, (object)['userid' => $userid, 'rawgrade' => null]);
    } else {
        aispeakwrite_grade_item_update($instance);
    }
}

/**
 * Adds the teacher pages to the activity's settings navigation.
 *
 * @param settings_navigation $settingsnav
 * @param navigation_node $node
 */
function aispeakwrite_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $node) {
    $cm = $settingsnav->get_page()->cm;
    if (!$cm) {
        return;
    }
    $context = context_module::instance($cm->id);
    if (has_capability('mod/aispeakwrite:managetasks', $context)) {
        $node->add(
            get_string('tasks', 'mod_aispeakwrite'),
            new moodle_url('/mod/aispeakwrite/manage.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'aispeakwrite_tasks'
        );
    }
    if (has_capability('mod/aispeakwrite:viewreports', $context)) {
        $node->add(
            get_string('analytics', 'mod_aispeakwrite'),
            new moodle_url('/mod/aispeakwrite/analytics.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'aispeakwrite_analytics'
        );
    }
}

/**
 * Adds the reset option to the course reset form.
 *
 * @param MoodleQuickForm $mform
 */
function aispeakwrite_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'aispeakwriteheader', get_string('modulenameplural', 'mod_aispeakwrite'));
    $mform->addElement('advcheckbox', 'reset_aispeakwrite', get_string('resetanswers', 'mod_aispeakwrite'));
}

/**
 * Reset form defaults.
 *
 * @param stdClass $course
 * @return array
 */
function aispeakwrite_reset_course_form_defaults($course) {
    return ['reset_aispeakwrite' => 1];
}

/**
 * Deletes learner answers, memory, assignments and request records when a course is reset.
 *
 * @param stdClass $data
 * @return array status
 */
function aispeakwrite_reset_userdata($data) {
    global $DB;
    if (empty($data->reset_aispeakwrite)) {
        return [];
    }
    $instances = $DB->get_records('aispeakwrite', ['course' => $data->courseid]);
    foreach ($instances as $instance) {
        $taskids = $DB->get_fieldset_select('aispeakwrite_task', 'id', 'aispeakwriteid = ?', [$instance->id]);
        if ($taskids) {
            [$in, $params] = $DB->get_in_or_equal($taskids);
            $DB->delete_records_select('aispeakwrite_taskuser', "taskid $in", $params);
        }
        foreach (['aispeakwrite_sub', 'aispeakwrite_memory', 'aispeakwrite_op'] as $table) {
            $DB->delete_records($table, ['aispeakwriteid' => $instance->id]);
        }
        aispeakwrite_grade_item_update($instance, 'reset');
    }
    return [['component' => get_string('modulenameplural', 'mod_aispeakwrite'),
        'item' => get_string('resetanswers', 'mod_aispeakwrite'), 'error' => false]];
}
