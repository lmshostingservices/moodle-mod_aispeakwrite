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
 * Test data generator for mod_aispeakwrite.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_aispeakwrite_generator extends testing_module_generator {
    /**
     * Creates an activity.
     *
     * @param array|stdClass|null $record
     * @param array|null $options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;
        $defaults = [
            'correctionmode' => 'two', 'showtelc' => 1, 'showgood' => 1, 'sendhistory' => 1, 'dailylimit' => 3,
            'rewritelimit' => 2, 'activitytips' => '', 'examlevel' => 'B1', 'targetlang' => 'de-DE', 'feedbacklang' => 'en',
            'examdate' => 0, 'grade' => 100,
        ];
        foreach ($defaults as $name => $value) {
            if (!isset($record->$name)) {
                $record->$name = $value;
            }
        }
        return parent::create_instance($record, (array)$options);
    }

    /**
     * Creates a task.
     *
     * @param array|stdClass $record must include aispeakwriteid
     * @return stdClass
     */
    public function create_task($record): stdClass {
        global $DB;
        $record = (object)(array)$record;
        $kind = $record->kind ?? 'writing';
        $defaults = [
            'kind' => $kind, 'tasktype' => $kind === 'speaking' ? 'speak3' : 'email', 'title' => 'Geburtstagsfeier',
            'topic' => 'Feste', 'situation' => 'Sie haben von einem Freund folgende E-Mail erhalten:',
            'sourcetext' => "Lieber …,\nam Samstag werde ich 30!", 'formality' => 'du', 'targetwords' => 150,
            'timelimit' => 30, 'tasktips' => '', 'guide' => '', 'phrases' => 'Schluss: Liebe Grüße | Bis bald!',
            'partnerbrief' => '', 'audience' => 'all', 'groupid' => 0, 'duedate' => 0, 'visible' => 1,
            'points' => "Ob Sie zur Feier kommen\nGlückwünsche zum Geburtstag\nAngebot, etwas mitzubringen\n" .
                "Ihre Erfahrungen mit Geburtstagsfeiern",
        ];
        foreach ($defaults as $name => $value) {
            if (!isset($record->$name)) {
                $record->$name = $value;
            }
        }
        $record->sortorder = \mod_aispeakwrite\local\manager::next_sortorder((int)$record->aispeakwriteid);
        $record->timecreated = time();
        $record->timemodified = time();
        $record->id = $DB->insert_record('aispeakwrite_task', $record);
        return $record;
    }
}
