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
 * Restore structure for mod_aispeakwrite.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore structure for mod_aispeakwrite.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_aispeakwrite_activity_structure_step extends restore_activity_structure_step {
    /**
     * Paths to restore.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');
        $paths = [
            new restore_path_element('aispeakwrite', '/activity/aispeakwrite'),
            new restore_path_element('aispeakwrite_task', '/activity/aispeakwrite/tasks/task'),
        ];
        if ($userinfo) {
            $paths[] = new restore_path_element('aispeakwrite_taskuser', '/activity/aispeakwrite/tasks/task/taskusers/taskuser');
            $paths[] = new restore_path_element('aispeakwrite_sub', '/activity/aispeakwrite/subs/sub');
            $paths[] = new restore_path_element('aispeakwrite_memory', '/activity/aispeakwrite/memories/memory');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * The activity.
     *
     * @param array $data
     */
    protected function process_aispeakwrite($data) {
        global $DB;
        $data = (object)$data;
        $data->course = $this->get_courseid();
        $data->examdate = $this->apply_date_offset($data->examdate);
        $newid = $DB->insert_record('aispeakwrite', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * A task.
     *
     * @param array $data
     */
    protected function process_aispeakwrite_task($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->aispeakwriteid = $this->get_new_parentid('aispeakwrite');
        $data->groupid = $data->groupid ? (int)$this->get_mappingid('group', $data->groupid) : 0;
        if ($data->audience === 'group' && !$data->groupid) {
            $data->audience = 'all';
        }
        $data->duedate = $this->apply_date_offset($data->duedate);
        $newid = $DB->insert_record('aispeakwrite_task', $data);
        $this->set_mapping('aispeakwrite_task', $oldid, $newid);
    }

    /**
     * An assignment.
     *
     * @param array $data
     */
    protected function process_aispeakwrite_taskuser($data) {
        global $DB;
        $data = (object)$data;
        $data->taskid = $this->get_new_parentid('aispeakwrite_task');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->assignedby = (int)$this->get_mappingid('user', $data->assignedby);
        $data->duedate = $this->apply_date_offset($data->duedate);
        if (
            $data->userid && !$DB->record_exists('aispeakwrite_taskuser', ['taskid' => $data->taskid,
                'userid' => $data->userid])
        ) {
            $DB->insert_record('aispeakwrite_taskuser', $data);
        }
    }

    /**
     * An answer.
     *
     * @param array $data
     */
    protected function process_aispeakwrite_sub($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->aispeakwriteid = $this->get_new_parentid('aispeakwrite');
        $data->taskid = $this->get_mappingid('aispeakwrite_task', $data->taskid);
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->taskid || !$data->userid) {
            return;
        }
        if ($data->status === 'pending') {
            // The paid request is not restored, so an answer that was being marked becomes a draft again.
            $data->status = 'draft';
        }
        $newid = $DB->insert_record('aispeakwrite_sub', $data);
        $this->set_mapping('aispeakwrite_sub', $oldid, $newid);
    }

    /**
     * A focus-area record.
     *
     * @param array $data
     */
    protected function process_aispeakwrite_memory($data) {
        global $DB;
        $data = (object)$data;
        $data->aispeakwriteid = $this->get_new_parentid('aispeakwrite');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (
            $data->userid && !$DB->record_exists('aispeakwrite_memory', ['aispeakwriteid' => $data->aispeakwriteid,
                'userid' => $data->userid, 'patternkey' => $data->patternkey])
        ) {
            $DB->insert_record('aispeakwrite_memory', $data);
        }
    }

    /**
     * Files after restore.
     */
    protected function after_execute() {
        $this->add_related_files('mod_aispeakwrite', 'intro', null);
    }
}
