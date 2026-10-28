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

use mod_aispeakwrite\external\save_draft;
use mod_aispeakwrite\external\submit;
use mod_aispeakwrite\local\credentials;
use mod_aispeakwrite\local\remote;
use mod_aispeakwrite\local\submissions;

/**
 * Web service tests.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aispeakwrite\external\save_draft
 * @covers     \mod_aispeakwrite\external\submit
 * @covers     \mod_aispeakwrite\external\base
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\external\save_draft::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\external\submit::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\external\base::class)]
final class external_test extends \advanced_testcase {
    protected function tearDown(): void {
        remote::$transport = null;
        credentials::$central = null;
        parent::tearDown();
    }

    /**
     * A learner saves their own draft; another learner can't touch it.
     */
    public function test_save_draft_own_only(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $u1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $u2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('aispeakwrite', ['course' => $course->id]);
        $instance = $DB->get_record('aispeakwrite', ['id' => $module->id]);
        $task = $this->getDataGenerator()->get_plugin_generator('mod_aispeakwrite')->create_task(
            ['aispeakwriteid' => $module->id]
        );
        $sub = submissions::open_draft($instance, $task, (int)$u1->id);

        $this->setUser($u1);
        // As the browser sends it: a typed "<" arrives with a space after it and is kept.
        $result = save_draft::execute($module->cmid, $sub->id, "Lieber Tim,\ndanke, 3< 4", 'Plan');
        $result = \core_external\external_api::clean_returnvalue(save_draft::execute_returns(), $result);
        $this->assertSame(5, $result['words']);
        $this->assertSame("Lieber Tim,\ndanke, 3< 4", $DB->get_field('aispeakwrite_sub', 'content', ['id' => $sub->id]));

        // HTML is not accepted as plain text.
        try {
            save_draft::execute($module->cmid, $sub->id, '<b>danke</b>', '');
            $this->fail('HTML was accepted');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame("Lieber Tim,\ndanke, 3< 4", $DB->get_field('aispeakwrite_sub', 'content', ['id' => $sub->id]));
        }

        $this->setUser($u2);
        $this->expectException(\dml_missing_record_exception::class);
        save_draft::execute($module->cmid, $sub->id, 'Hacked', '');
    }

    /**
     * Submitting marks the answer and returns the feedback page.
     */
    public function test_submit(): void {
        global $DB;
        $this->resetAfterTest();
        credentials::$central = fn() => null;
        set_config('lmslabssiteid', 's', 'mod_aispeakwrite');
        set_config('lmslabsapikey', 'k', 'mod_aispeakwrite');
        remote::$transport = fn() => [200, json_encode(
            ['creditsCharged' => 1, 'result' => [
                'criteria' => ['task' => ['atg' => 15], 'communication' => ['atg' => 9], 'form' => ['atg' => 9]],
            ]]
        )];
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('aispeakwrite', ['course' => $course->id]);
        $instance = $DB->get_record('aispeakwrite', ['id' => $module->id]);
        $task = $this->getDataGenerator()->get_plugin_generator('mod_aispeakwrite')->create_task(
            ['aispeakwriteid' => $module->id]
        );
        $sub = submissions::open_draft($instance, $task, (int)$user->id);
        $this->setUser($user);
        $text = str_repeat('Ich komme gern zu deiner Party am Samstag. ', 4);
        $result = submit::execute($module->cmid, $sub->id, $text, '');
        $result = \core_external\external_api::clean_returnvalue(submit::execute_returns(), $result);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('/mod/aispeakwrite/feedback.php', $result['url']);
        $this->assertSame('marked', $DB->get_field('aispeakwrite_sub', 'status', ['id' => $sub->id]));

        // A too-short answer comes back as a message, not an exception.
        $second = submissions::open_draft($instance, $task, (int)$user->id);
        $result = submit::execute($module->cmid, $second->id, 'Zu kurz.', '');
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['pending']);
        $this->assertNotEmpty($result['message']);
    }
}
