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

namespace mod_aispeakwrite;

/**
 * Backup and restore (duplicate) tests.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_aispeakwrite_activity_structure_step
 * @covers     \restore_aispeakwrite_activity_structure_step
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_aispeakwrite_activity_structure_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_aispeakwrite_activity_structure_step::class)]
final class backup_test extends \advanced_testcase {
    /**
     * Duplicating an activity copies its settings and tasks, but not learners' answers.
     */
    public function test_duplicate(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('aispeakwrite', ['course' => $course->id,
            'activitytips' => 'Satzanfang: x', 'correctionmode' => 'explain']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_aispeakwrite');
        $gen->create_task(['aispeakwriteid' => $module->id, 'title' => 'One']);
        $gen->create_task(['aispeakwriteid' => $module->id, 'title' => 'Two', 'kind' => 'speaking']);

        $cm = get_coursemodule_from_id('aispeakwrite', $module->cmid);
        if (
            class_exists(\core_courseformat\local\cmactions::class)
                && method_exists(\core_courseformat\local\cmactions::class, 'duplicate')
        ) {
            // Moodle 5.2 and later.
            $newcm = (new \core_courseformat\local\cmactions($course))->duplicate((int)$cm->id);
        } else {
            $newcm = duplicate_module($course, $cm);
        }
        $copy = $DB->get_record('aispeakwrite', ['id' => $newcm->instance]);
        $this->assertSame('Satzanfang: x', $copy->activitytips);
        $this->assertSame('explain', $copy->correctionmode);
        $titles = $DB->get_fieldset_select(
            'aispeakwrite_task',
            'title',
            'aispeakwriteid = ? ORDER BY sortorder',
            [$copy->id]
        );
        $this->assertSame(['One', 'Two'], $titles);
        $this->assertSame('speak3', $DB->get_field('aispeakwrite_task', 'tasktype', ['aispeakwriteid' => $copy->id,
            'title' => 'Two']));
    }
}
