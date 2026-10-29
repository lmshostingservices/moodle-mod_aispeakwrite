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

namespace mod_aispeakwrite\event;

/**
 * A learner's written answer was submitted and marked: the hook for plagiarism plugins such as Essay Guard.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_submitted extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'aispeakwrite_sub';
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventanswersubmitted', 'mod_aispeakwrite');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        return "The written answer with id '{$this->objectid}' of the user with id '{$this->relateduserid}' was submitted " .
            "in the activity with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * Link to the feedback.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/aispeakwrite/feedback.php', ['id' => $this->contextinstanceid, 'sub' => $this->objectid]);
    }

    /**
     * Validation.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('relateduserid must be set.');
        }
    }

    /**
     * Object id mapping for restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'aispeakwrite_sub', 'restore' => 'aispeakwrite_sub'];
    }

    /**
     * Other mapping for restore.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [
            'taskid' => ['db' => 'aispeakwrite_task', 'restore' => 'aispeakwrite_task'],
            'attempt' => \core\event\base::NOT_MAPPED,
        ];
    }
}
