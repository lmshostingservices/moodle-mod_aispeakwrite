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
        $module = $this->getDataGenerator()->create_module(
            'aispeakwrite',
            ['course' => $course->id, 'activitytips' => 'Satzanfang: x', 'correctionmode' => 'explain', 'pastemode' => 'block']
        );
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
        $this->assertSame('block', $copy->pastemode);
        $titles = $DB->get_fieldset_select(
            'aispeakwrite_task',
            'title',
            'aispeakwriteid = ? ORDER BY sortorder',
            [$copy->id]
        );
        $this->assertSame(['One', 'Two'], $titles);
        $this->assertSame(
            'speak3',
            $DB->get_field(
                'aispeakwrite_task',
                'tasktype',
                ['aispeakwriteid' => $copy->id, 'title' => 'Two']
            )
        );
    }

    /**
     * A backup with user data keeps each draft's writing integrity figures.
     */
    public function test_backup_restore_integrity(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('aispeakwrite', ['course' => $course->id, 'pastemode' => 'allow']);
        $task = $this->getDataGenerator()->get_plugin_generator('mod_aispeakwrite')->create_task(
            ['aispeakwriteid' => $module->id]
        );
        $instance = $DB->get_record('aispeakwrite', ['id' => $module->id]);
        $sub = \mod_aispeakwrite\local\submissions::open_draft($instance, $task, (int)$user->id);
        $DB->update_record('aispeakwrite_sub', (object)['id' => $sub->id, 'status' => 'marked', 'attempt' => 2,
            'pastecount' => 4, 'pastechars' => 321, 'pasteblocked' => 1, 'tabswitches' => 7, 'rewritechanged' => 55,
            'rewriteflag' => 1]);

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();
        $dir = 'asw-integrity-' . time();
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($dir));

        $newcourseid = \restore_dbops::create_new_course('Restored', 'R1', $course->category);
        $rc = new \restore_controller(
            $dir,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $copy = $DB->get_record('aispeakwrite', ['course' => $newcourseid]);
        $this->assertSame('allow', $copy->pastemode);
        $restored = $DB->get_record('aispeakwrite_sub', ['aispeakwriteid' => $copy->id]);
        $this->assertSame(
            [4, 321, 1, 7, 55, 1],
            [(int)$restored->pastecount, (int)$restored->pastechars, (int)$restored->pasteblocked,
                (int)$restored->tabswitches, (int)$restored->rewritechanged, (int)$restored->rewriteflag]
        );
    }
}
