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
 * Backup structure for mod_aispeakwrite.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backup structure for mod_aispeakwrite.
 *
 * Tasks are always included. Learner answers, focus areas and assignments are included with user data. Records of paid
 * requests are not backed up.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_aispeakwrite_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $root = new backup_nested_element(
            'aispeakwrite',
            ['id'],
            [
                'name', 'intro', 'introformat', 'correctionmode', 'showtelc', 'showgood', 'sendhistory', 'dailylimit',
                'rewritelimit', 'activitytips', 'examlevel', 'targetlang', 'feedbacklang', 'examdate', 'grade',
                'completionmarked', 'pastemode',
                'timecreated',
            'timemodified']
        );
        $tasks = new backup_nested_element('tasks');
        $task = new backup_nested_element(
            'task',
            ['id'],
            [
                'kind', 'tasktype', 'title', 'topic', 'situation', 'sourcetext', 'points', 'formality', 'targetwords',
                'timelimit', 'tasktips', 'guide', 'phrases', 'partnerbrief', 'audience', 'groupid', 'duedate', 'visible',
                'sortorder',
                'timecreated',
            'timemodified']
        );
        $taskusers = new backup_nested_element('taskusers');
        $taskuser = new backup_nested_element('taskuser', ['id'], ['userid', 'duedate', 'assignedby', 'timecreated']);
        $subs = new backup_nested_element('subs');
        $sub = new backup_nested_element(
            'sub',
            ['id'],
            [
                'taskid', 'userid', 'attempt', 'kind', 'status', 'content', 'notes', 'fbmode', 'metrics', 'feedback',
                'contenthash',
                'score',
                'examscore',
                'maxscore',
                'tipsversion',
                'timecreated',
                'timemodified',
                'timemarked', 'pastecount', 'pastechars', 'pasteblocked', 'tabswitches', 'rewritechanged',
            'rewriteflag']
        );
        $memories = new backup_nested_element('memories');
        $memory = new backup_nested_element(
            'memory',
            ['id'],
            [
                'userid',
                'patternkey',
                'label',
                'ptype',
                'hits',
                'misses',
                'recent',
            'timemodified']
        );

        $root->add_child($tasks);
        $tasks->add_child($task);
        $task->add_child($taskusers);
        $taskusers->add_child($taskuser);
        $root->add_child($subs);
        $subs->add_child($sub);
        $root->add_child($memories);
        $memories->add_child($memory);

        $root->set_source_table('aispeakwrite', ['id' => backup::VAR_ACTIVITYID]);
        $task->set_source_table('aispeakwrite_task', ['aispeakwriteid' => backup::VAR_PARENTID], 'sortorder ASC');
        if ($userinfo) {
            $taskuser->set_source_table('aispeakwrite_taskuser', ['taskid' => backup::VAR_PARENTID]);
            $sub->set_source_table('aispeakwrite_sub', ['aispeakwriteid' => backup::VAR_PARENTID], 'id ASC');
            $memory->set_source_table('aispeakwrite_memory', ['aispeakwriteid' => backup::VAR_PARENTID]);
        }

        $task->annotate_ids('group', 'groupid');
        $taskuser->annotate_ids('user', 'userid');
        $taskuser->annotate_ids('user', 'assignedby');
        $sub->annotate_ids('user', 'userid');
        $memory->annotate_ids('user', 'userid');
        $root->annotate_files('mod_aispeakwrite', 'intro', null);

        return $this->prepare_activity_structure($root);
    }
}
