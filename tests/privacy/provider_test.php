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

namespace mod_aispeakwrite\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider tests.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aispeakwrite\privacy\provider
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\privacy\provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * An activity with data for two learners.
     *
     * @return array [context, user1, user2, instance]
     */
    private function data(): array {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $u1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $u2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('aispeakwrite', ['course' => $course->id]);
        $task = $this->getDataGenerator()->get_plugin_generator('mod_aispeakwrite')->create_task(
            ['aispeakwriteid' => $module->id]
        );
        foreach ([$u1, $u2] as $u) {
            $DB->insert_record(
                'aispeakwrite_sub',
                (object)['aispeakwriteid' => $module->id, 'taskid' => $task->id,
                'userid' => $u->id, 'attempt' => 1, 'kind' => 'writing', 'status' => 'marked', 'content' => 'Hallo Tim',
                'notes' => '', 'fbmode' => 'think', 'contenthash' => '', 'tipsversion' => '', 'score' => 9, 'maxscore' => 45,
                'timecreated' => time(),
                'timemodified' => time(),
                'timemarked' => time()]
            );
            $DB->insert_record(
                'aispeakwrite_memory',
                (object)['aispeakwriteid' => $module->id, 'userid' => $u->id,
                'patternkey' => 'tip:a',
                'label' => 'A',
                'ptype' => 'tip',
                'recent' => '01',
                'timemodified' => time()]
            );
            $DB->insert_record(
                'aispeakwrite_op',
                (object)['aispeakwriteid' => $module->id, 'userid' => $u->id,
                'kind' => 'mark', 'bodyhash' => 'x', 'idemkey' => 'k' . $u->id, 'state' => 'complete', 'credits' => 1,
                'timecreated' => time(),
                'timemodified' => time()]
            );
            $DB->insert_record(
                'aispeakwrite_taskuser',
                (object)['taskid' => $task->id, 'userid' => $u->id, 'timecreated' => time()]
            );
        }
        return [\context_module::instance($module->cmid), $u1, $u2, $module];
    }

    /**
     * Metadata declares the tables, LMS Labs and the browser.
     */
    public function test_metadata(): void {
        $items = provider::get_metadata(new collection('mod_aispeakwrite'))->get_collection();
        $names = array_map(fn($i) => $i->get_name(), $items);
        foreach (
            ['aispeakwrite_sub', 'aispeakwrite_memory', 'aispeakwrite_op', 'aispeakwrite_taskuser', 'lmslabs',
                'browser'] as $name
        ) {
            $this->assertContains($name, $names);
        }
    }

    /**
     * Contexts, users, export and deletion.
     */
    public function test_export_and_delete(): void {
        global $DB;
        [$context, $u1, $u2, $module] = $this->data();
        $this->assertSame([$context->id], array_map('intval', provider::get_contexts_for_userid($u1->id)->get_contextids()));

        $userlist = new userlist($context, 'mod_aispeakwrite');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$u1->id, $u2->id], $userlist->get_userids());

        $this->export_context_data_for_user($u1->id, $context, 'mod_aispeakwrite');
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'mod_aispeakwrite')]);
        $this->assertSame('Hallo Tim', $data->answers[0]['answer']);
        $this->assertArrayHasKey('pastesfromoutside', $data->answers[0]);
        $this->assertArrayHasKey('timesleftscreen', $data->answers[0]);
        $this->assertArrayHasKey('rewritechangedpercent', $data->answers[0]);
        $this->assertCount(1, $data->focusareas);

        provider::delete_data_for_user(new approved_contextlist($u1, 'mod_aispeakwrite', [$context->id]));
        foreach (['aispeakwrite_sub', 'aispeakwrite_memory', 'aispeakwrite_op', 'aispeakwrite_taskuser'] as $table) {
            $this->assertSame(0, $DB->count_records($table, ['userid' => $u1->id]), $table);
            $this->assertSame(1, $DB->count_records($table, ['userid' => $u2->id]), $table);
        }

        provider::delete_data_for_users(new approved_userlist($context, 'mod_aispeakwrite', [$u2->id]));
        $this->assertSame(0, $DB->count_records('aispeakwrite_sub', ['userid' => $u2->id]));

        [$context2] = $this->data();
        provider::delete_data_for_all_users_in_context($context2);
        $cm = get_coursemodule_from_id('aispeakwrite', $context2->instanceid);
        $this->assertSame(0, $DB->count_records('aispeakwrite_sub', ['aispeakwriteid' => $cm->instance]));
    }
}
