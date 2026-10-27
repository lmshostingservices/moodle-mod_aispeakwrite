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

use mod_aispeakwrite\local\credentials;
use mod_aispeakwrite\local\marker;
use mod_aispeakwrite\local\remote;
use mod_aispeakwrite\local\submissions;

/**
 * Tests the paid marking flow: one credit per delivered marking, never charged twice.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aispeakwrite\local\marker
 * @covers     \mod_aispeakwrite\local\operation
 * @covers     \mod_aispeakwrite\local\remote
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\local\marker::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\local\operation::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\local\remote::class)]
final class charging_test extends \advanced_testcase {
    /** @var array Requests the fake LMS Labs received. */
    private array $calls = [];

    /** @var array Answers the fake LMS Labs gives, in order. */
    private array $answers = [];

    /** @var string A learner answer long enough to be marked. */
    private const TEXT = "Lieber Tim,\nvielen Dank für den Einladung. Natürlich ich kommen gern zu deiner Party am Samstag. " .
        "Herzlichen Glückwunsch zu dein Geburtstag. Ich bringe einen Kuchen mit. Bis bald, Justus";

    protected function tearDown(): void {
        remote::$transport = null;
        credentials::$central = null;
        parent::tearDown();
    }

    /**
     * Sets up an activity, a task and a learner, and installs the fake LMS Labs.
     *
     * @param array $settings activity settings
     * @return array [instance, task, user, context]
     */
    private function setup_activity(array $settings = []): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/aispeakwrite/lib.php');
        $this->resetAfterTest();
        credentials::$central = fn() => null;
        set_config('lmslabssiteid', 'site-1', 'mod_aispeakwrite');
        set_config('lmslabsapikey', 'key-secret', 'mod_aispeakwrite');
        set_config('schooltips', 'Satzanfang: Nicht mit ich beginnen. | Am Samstag komme ich.', 'mod_aispeakwrite');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Justus',
            'email' => 'justus@example.com']);
        $module = $this->getDataGenerator()->create_module('aispeakwrite', ['course' => $course->id] + $settings);
        $instance = $DB->get_record('aispeakwrite', ['id' => $module->id]);
        $task = $this->getDataGenerator()->get_plugin_generator('mod_aispeakwrite')->create_task(
            ['aispeakwriteid' => $instance->id]
        );
        $context = \context_module::instance($module->cmid);
        remote::$transport = function ($url, $headers, $body) {
            $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
            $answer = array_shift($this->answers);
            if ($answer instanceof \Throwable) {
                throw $answer;
            }
            return $answer;
        };
        return [$instance, $task, $user, $context];
    }

    /**
     * A delivered marking.
     *
     * @param int $credits
     * @return array
     */
    private function delivered(int $credits = 1): array {
        return [200, json_encode(['requestId' => 'r1', 'creditsCharged' => $credits, 'result' => [
            'summary' => 'Good.',
            'criteria' => ['task' => ['atg' => 9, 'telc' => 9], 'communication' => ['atg' => 9, 'telc' => 15],
                'form' => ['atg' => 3, 'telc' => 9]],
            'points' => [['id' => 'p3', 'status' => 'covered']],
            'marks' => [['type' => 'error', 'text' => 'den Einladung', 'correction' => 'die Einladung',
                'ruleId' => 'grammar:article']],
            'ruleHits' => [['ruleId' => 'tip:satzanfang', 'ok' => false]],
        ]])];
    }

    /**
     * A draft with the test answer.
     *
     * @param \stdClass $instance
     * @param \stdClass $task
     * @param \stdClass $user
     * @return \stdClass
     */
    private function draft(\stdClass $instance, \stdClass $task, \stdClass $user): \stdClass {
        $sub = submissions::open_draft($instance, $task, (int)$user->id);
        submissions::save($sub, self::TEXT, '');
        return submissions::get((int)$sub->id, (int)$instance->id);
    }

    /**
     * One delivered marking: stored, charged once, grade and memory updated, and no personal details sent.
     */
    public function test_delivered(): void {
        global $DB;
        [$instance, $task, $user, $context] = $this->setup_activity();
        $this->answers = [$this->delivered()];
        $sink = $this->redirectEvents();
        $marked = marker::mark($instance, $task, $this->draft($instance, $task, $user), $context);

        $this->assertSame('marked', $marked->status);
        $this->assertSame(21, (int)$marked->score);
        $this->assertSame(33, (int)$marked->examscore);
        $this->assertSame('think', $marked->fbmode);
        $this->assertCount(1, $this->calls);
        $this->assertSame(remote::HOST . remote::MARK, $this->calls[0]['url']);
        $this->assertContains('X-API-Key: key-secret', $this->calls[0]['headers']);
        $body = json_decode($this->calls[0]['body'], true);
        $this->assertSame('tip:satzanfang', $body['tips'][0]['id']);
        $this->assertSame(self::TEXT, $body['answer']['text']);
        $this->assertStringNotContainsString('justus@example.com', $this->calls[0]['body']);
        $this->assertStringNotContainsString('key-secret', $this->calls[0]['body']);

        $op = $DB->get_record('aispeakwrite_op', ['itemid' => $marked->id]);
        $this->assertSame('complete', $op->state);
        $this->assertSame(1, (int)$op->credits);
        $this->assertSame('', (string)$op->body);

        $memory = $DB->get_records_menu('aispeakwrite_memory', ['userid' => $user->id], '', 'patternkey, recent');
        $this->assertSame('0', $memory['tip:satzanfang']);
        $this->assertSame('0', $memory['grammar:article']);
        $this->assertSame('1', $memory['content:points']);

        $grades = aispeakwrite_get_user_grades($instance, (int)$user->id);
        $this->assertEqualsWithDelta(46.67, (float)$grades[$user->id]->rawgrade, 0.01);
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_aispeakwrite\event\submission_marked);
        $this->assertCount(1, $events);
    }

    /**
     * The same text submitted again gets the stored result and is not sent or charged again.
     */
    public function test_identical_text_not_charged(): void {
        global $DB;
        [$instance, $task, $user, $context] = $this->setup_activity(['correctionmode' => 'think']);
        $this->answers = [$this->delivered()];
        $first = marker::mark($instance, $task, $this->draft($instance, $task, $user), $context);
        $again = marker::mark($instance, $task, $this->draft($instance, $task, $user), $context);
        $this->assertSame((int)$first->id, (int)$again->id);
        $this->assertCount(1, $this->calls);
        $this->assertSame(1, $DB->count_records('aispeakwrite_op'));
    }

    /**
     * A refusal charges nothing, returns the answer to a draft, and the next try is a new request.
     */
    public function test_refused_not_charged(): void {
        global $DB;
        [$instance, $task, $user, $context] = $this->setup_activity();
        $this->answers = [[402, json_encode(['error' => ['code' => 'INSUFFICIENT_CREDITS']])], $this->delivered()];
        $sub = $this->draft($instance, $task, $user);
        try {
            marker::mark($instance, $task, $sub, $context);
            $this->fail('Expected a refusal');
        } catch (\moodle_exception $e) {
            $this->assertSame('remoterefused_insufficient_credits', $e->errorcode);
        }
        $this->assertSame('draft', $DB->get_field('aispeakwrite_sub', 'status', ['id' => $sub->id]));
        $op = $DB->get_record('aispeakwrite_op', ['itemid' => $sub->id]);
        $this->assertSame('failed', $op->state);
        $this->assertSame(0, (int)$op->credits);

        $marked = marker::mark($instance, $task, submissions::get((int)$sub->id, (int)$instance->id), $context);
        $this->assertSame('marked', $marked->status);
        $keys = array_map(fn($c) => array_values(preg_grep('/^Idempotency-Key:/', $c['headers']))[0], $this->calls);
        $this->assertNotEquals($keys[0], $keys[1]);
    }

    /**
     * No definite answer keeps the request pending; trying again sends the same key and the same body.
     */
    public function test_uncertain_retries_same_key(): void {
        global $DB;
        [$instance, $task, $user, $context] = $this->setup_activity();
        $this->answers = [new \moodle_exception('remoteuncertain', 'mod_aispeakwrite'), [500, '{}'], $this->delivered()];
        $sub = $this->draft($instance, $task, $user);
        foreach ([1, 2] as $try) {
            try {
                marker::mark($instance, $task, submissions::get((int)$sub->id, (int)$instance->id), $context);
                $this->fail('Expected no definite answer');
            } catch (\moodle_exception $e) {
                $this->assertSame('remoteuncertain', $e->errorcode);
            }
            $this->assertSame('pending', $DB->get_field('aispeakwrite_sub', 'status', ['id' => $sub->id]));
        }
        $marked = marker::mark($instance, $task, submissions::get((int)$sub->id, (int)$instance->id), $context);
        $this->assertSame('marked', $marked->status);
        $this->assertCount(3, $this->calls);
        $keys = array_map(fn($c) => array_values(preg_grep('/^Idempotency-Key:/', $c['headers']))[0], $this->calls);
        $this->assertCount(1, array_unique($keys));
        $this->assertCount(1, array_unique(array_column($this->calls, 'body')));
        $this->assertSame(1, $DB->count_records('aispeakwrite_op'));
    }

    /**
     * The daily limit stops a marking before anything is sent.
     */
    public function test_daily_limit(): void {
        [$instance, $task, $user, $context] = $this->setup_activity(['dailylimit' => 1, 'correctionmode' => 'think']);
        $this->answers = [$this->delivered()];
        marker::mark($instance, $task, $this->draft($instance, $task, $user), $context);
        $sub = $this->draft($instance, $task, $user);
        submissions::save($sub, self::TEXT . ' Noch ein Satz.', '');
        $this->expectExceptionMessage(get_string('dailylimitreached', 'mod_aispeakwrite'));
        try {
            marker::mark($instance, $task, submissions::get((int)$sub->id, (int)$instance->id), $context);
        } finally {
            $this->assertCount(1, $this->calls);
        }
    }

    /**
     * A text that is too short is never sent.
     */
    public function test_too_short_not_sent(): void {
        [$instance, $task, $user, $context] = $this->setup_activity();
        $sub = submissions::open_draft($instance, $task, (int)$user->id);
        submissions::save($sub, 'Lieber Tim, ja.', '');
        try {
            marker::mark($instance, $task, submissions::get((int)$sub->id, (int)$instance->id), $context);
            $this->fail('Expected too short');
        } catch (\moodle_exception $e) {
            $this->assertSame('tooshort', $e->errorcode);
        }
        $this->assertCount(0, $this->calls);
    }

    /**
     * A delivered but unusable result is recorded as charged and the answer can be submitted again.
     */
    public function test_unusable_result(): void {
        global $DB;
        [$instance, $task, $user, $context] = $this->setup_activity();
        $this->answers = [[200, json_encode(['creditsCharged' => 1, 'result' => ['summary' => 'no criteria']])]];
        $sub = $this->draft($instance, $task, $user);
        try {
            marker::mark($instance, $task, $sub, $context);
            $this->fail('Expected unusable');
        } catch (\moodle_exception $e) {
            $this->assertSame('unusableresult', $e->errorcode);
        }
        $this->assertSame('failed', $DB->get_field('aispeakwrite_sub', 'status', ['id' => $sub->id]));
        $this->assertSame('complete', $DB->get_field('aispeakwrite_op', 'state', ['itemid' => $sub->id]));
    }

    /**
     * Without credentials nothing is sent.
     */
    public function test_no_credentials(): void {
        [$instance, $task, $user, $context] = $this->setup_activity();
        set_config('lmslabsapikey', '', 'mod_aispeakwrite');
        try {
            marker::mark($instance, $task, $this->draft($instance, $task, $user), $context);
            $this->fail('Expected missing credentials');
        } catch (\moodle_exception $e) {
            $this->assertSame('lmslabscredentialsmissing', $e->errorcode);
        }
        $this->assertCount(0, $this->calls);
    }

    /**
     * Two-step mode: the rewrite is marked in Explain mode and sends the previous text.
     */
    public function test_rewrite_explain(): void {
        [$instance, $task, $user, $context] = $this->setup_activity();
        $this->answers = [$this->delivered(), $this->delivered()];
        marker::mark($instance, $task, $this->draft($instance, $task, $user), $context);
        $second = submissions::open_draft($instance, $task, (int)$user->id);
        $this->assertSame(2, (int)$second->attempt);
        $this->assertSame('explain', $second->fbmode);
        $this->assertSame(self::TEXT, $second->content);
        submissions::save($second, self::TEXT . ' Soll ich etwas mitbringen?', '');
        marker::mark($instance, $task, submissions::get((int)$second->id, (int)$instance->id), $context);
        $body = json_decode($this->calls[1]['body'], true);
        $this->assertSame('explain', $body['mode']);
        $this->assertSame(self::TEXT, $body['answer']['previousText']);
        $this->assertSame(2, $body['draft']);
    }
}
