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
use mod_aispeakwrite\local\integrity;
use mod_aispeakwrite\local\marker;
use mod_aispeakwrite\local\remote;
use mod_aispeakwrite\local\submissions;

/**
 * Tests writing integrity: paste and tab counts, the rewrite check, the teacher panel and the event for plagiarism
 * plugins.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aispeakwrite\local\integrity
 * @covers     \mod_aispeakwrite\event\answer_submitted
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\local\integrity::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\event\answer_submitted::class)]
final class integrity_test extends \advanced_testcase {
    /** @var string First draft: 30 words. */
    private const FIRST = "Lieber Tim,\nvielen Dank für den Einladung. Natürlich ich kommen gern zu deiner Party am Samstag. " .
        "Herzlichen Glückwunsch zu dein Geburtstag. Ich bringe einen Kuchen mit, und meine Schwester kommt auch. " .
        "Bis bald, Justus";

    protected function tearDown(): void {
        remote::$transport = null;
        credentials::$central = null;
        parent::tearDown();
    }

    /**
     * An activity, a writing task and a learner, with a fake LMS Labs that marks "den Einladung".
     *
     * @param array $settings
     * @return array [instance, task, user, context, cm]
     */
    private function setup_activity(array $settings = []): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/aispeakwrite/lib.php');
        $this->resetAfterTest();
        credentials::$central = fn() => null;
        set_config('lmslabssiteid', 'site-1', 'mod_aispeakwrite');
        set_config('lmslabsapikey', 'key', 'mod_aispeakwrite');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('aispeakwrite', ['course' => $course->id] + $settings);
        $instance = $DB->get_record('aispeakwrite', ['id' => $module->id]);
        $task = $this->getDataGenerator()->get_plugin_generator('mod_aispeakwrite')->create_task(
            ['aispeakwriteid' => $instance->id]
        );
        remote::$transport = fn() => [200, json_encode(
            ['requestId' => 'r', 'creditsCharged' => 1, 'result' => [
                'criteria' => ['task' => ['atg' => 9], 'communication' => ['atg' => 9], 'form' => ['atg' => 3]],
                'marks' => [
                    ['type' => 'error', 'text' => 'den Einladung', 'correction' => 'die Einladung'],
                    ['type' => 'error', 'text' => 'ich kommen', 'correction' => 'komme ich'],
                ],
            ]]
        )];
        $cm = get_coursemodule_from_instance('aispeakwrite', $instance->id);
        return [$instance, $task, $user, \context_module::instance($cm->id), $cm];
    }

    /**
     * Marks a draft with the given text and returns it.
     *
     * @param \stdClass $instance
     * @param \stdClass $task
     * @param \stdClass $user
     * @param \context_module $context
     * @param string $text
     * @return \stdClass
     */
    private function mark_text(
        \stdClass $instance,
        \stdClass $task,
        \stdClass $user,
        \context_module $context,
        string $text
    ): \stdClass {
        $sub = submissions::open_draft($instance, $task, (int)$user->id);
        submissions::save($sub, $text, '');
        return marker::mark($instance, $task, submissions::get((int)$sub->id, (int)$instance->id), $context);
    }

    /**
     * The comparison ignores the marked spots, counts other words changed and leaves short texts alone.
     */
    public function test_changed_percent(): void {
        $this->resetAfterTest();
        $before = 'Ich gehe morgen mit meinem Bruder in das Kino und danach essen wir zusammen eine große Pizza.';
        $marks = [['start' => 11, 'end' => 17]]; // The word "morgen".
        $this->assertSame(0, integrity::changed_percent($before, $before, $marks));
        // Only the marked word changed.
        $this->assertSame(0, integrity::changed_percent(
            $before,
            'Ich gehe heute mit meinem Bruder in das Kino und danach essen wir zusammen eine große Pizza.',
            $marks
        ));
        // Words added at the end are new writing, not changes.
        $this->assertSame(0, integrity::changed_percent($before, $before . ' Es wird bestimmt lustig.', $marks));
        // Everything rewritten: only the allowance next to the one mark is not counted.
        $this->assertGreaterThan(75, integrity::changed_percent(
            $before,
            'Am Wochenende besuchen wir unsere Großeltern auf dem Land, weil sie Geburtstag haben.',
            $marks
        ));
        // Half of the unmarked words replaced (8 of 16).
        $pct = integrity::changed_percent(
            $before,
            'Ich gehe morgen mit meinem Bruder in das Theater, später trinken alle gemeinsam einen kleinen Kaffee.',
            $marks
        );
        $this->assertGreaterThanOrEqual(40, $pct);
        $this->assertLessThanOrEqual(60, $pct);
        // Too short outside the marks to judge.
        $this->assertNull(integrity::changed_percent('Ich gehe morgen.', 'Wir fahren weg.', []));
        // Case and punctuation do not count as changes.
        $this->assertSame(0, integrity::changed_percent($before, str_replace('Ich', 'ich', $before) . '!!', $marks));
    }

    /**
     * Character offsets of marks work with multibyte text.
     */
    public function test_changed_percent_multibyte(): void {
        $this->resetAfterTest();
        $before = 'Über die Brücke gehen wir für Grüße zur Straße, und später Öffnen wir die Tür für die Gäste schön.';
        $start = \core_text::strpos($before, 'Grüße');
        $marks = [['start' => $start, 'end' => $start + 5]];
        $after = str_replace('Grüße', 'Grüßen', $before);
        $this->assertSame(0, integrity::changed_percent($before, $after, $marks));
        $this->assertGreaterThan(0, integrity::changed_percent($before, str_replace('Straße', 'Stadt', $before), $marks));
    }

    /**
     * Counts follow the activity's paste setting; tab switches are always kept; counts add up and are clamped.
     */
    public function test_add_counts_by_mode(): void {
        global $DB;
        foreach (['allow' => [0, 0, 0], 'log' => [3, 120, 0], 'block' => [0, 0, 2]] as $mode => $expect) {
            [$instance, $task, $user] = $this->setup_activity(['pastemode' => $mode]);
            $this->assertSame($mode, $instance->pastemode);
            $sub = submissions::open_draft($instance, $task, (int)$user->id);
            integrity::add_counts($instance, $sub, 2, 100, 1, 1);
            integrity::add_counts($instance, $sub, 1, 20, 1, 4);
            $row = $DB->get_record('aispeakwrite_sub', ['id' => $sub->id]);
            $this->assertSame($expect, [(int)$row->pastecount, (int)$row->pastechars, (int)$row->pasteblocked], $mode);
            $this->assertSame(5, (int)$row->tabswitches, $mode);
        }
        // Negative and huge numbers from a broken client.
        [$instance, $task, $user] = $this->setup_activity();
        $sub = submissions::open_draft($instance, $task, (int)$user->id);
        integrity::add_counts($instance, $sub, -5, -100, 0, 99999999);
        $row = $DB->get_record('aispeakwrite_sub', ['id' => $sub->id]);
        $this->assertSame(0, (int)$row->pastecount);
        $this->assertSame(integrity::MAXSTEP, (int)$row->tabswitches);
    }

    /**
     * A marked draft's counts no longer change.
     */
    public function test_counts_frozen_after_marking(): void {
        global $DB;
        [$instance, $task, $user, $context] = $this->setup_activity();
        $marked = $this->mark_text($instance, $task, $user, $context, self::FIRST);
        integrity::add_counts($instance, $marked, 5, 500, 0, 5);
        $row = $DB->get_record('aispeakwrite_sub', ['id' => $marked->id]);
        $this->assertSame(0, (int)$row->pastecount);
        $this->assertSame(0, (int)$row->tabswitches);
    }

    /**
     * The rewrite check flags a draft 2 that replaced the text and leaves an honest correction alone.
     */
    public function test_rewrite_check(): void {
        global $DB;
        [$instance, $task, $user, $context] = $this->setup_activity(['rewritelimit' => 5, 'dailylimit' => 0]);
        $first = $this->mark_text($instance, $task, $user, $context, self::FIRST);
        $this->assertNull($first->rewritechanged);
        $this->assertSame(0, (int)$first->rewriteflag);

        // Draft 2 fixes only the marked spots.
        $fixed = str_replace(['den Einladung', 'ich kommen'], ['die Einladung', 'komme ich'], self::FIRST);
        $second = $this->mark_text($instance, $task, $user, $context, $fixed);
        $this->assertSame(2, (int)$second->attempt);
        $row = $DB->get_record('aispeakwrite_sub', ['id' => $second->id]);
        $this->assertSame(0, (int)$row->rewritechanged);
        $this->assertSame(0, (int)$row->rewriteflag);

        // Draft 3 is a different text.
        $other = "Hallo Tim,\nleider kann ich am Samstag nicht kommen, weil meine Familie verreist. Wir fahren nach " .
            "Hamburg zu meiner Tante und bleiben dort eine ganze Woche. Ich wünsche dir trotzdem eine tolle Feier. " .
            "Viele Grüße, Justus";
        $third = $this->mark_text($instance, $task, $user, $context, $other);
        $row = $DB->get_record('aispeakwrite_sub', ['id' => $third->id]);
        $this->assertSame(3, (int)$row->attempt);
        $this->assertGreaterThan(40, (int)$row->rewritechanged);
        $this->assertSame(1, (int)$row->rewriteflag);
        $this->assertTrue(integrity::flagged($row));
    }

    /**
     * The threshold is a site setting.
     */
    public function test_threshold_setting(): void {
        $this->resetAfterTest();
        $this->assertSame(40, integrity::threshold());
        set_config('rewritethreshold', 75, 'mod_aispeakwrite');
        $this->assertSame(75, integrity::threshold());
        set_config('rewritethreshold', 500, 'mod_aispeakwrite');
        $this->assertSame(100, integrity::threshold());
        set_config('rewritethreshold', 'x', 'mod_aispeakwrite');
        $this->assertSame(40, integrity::threshold());
    }

    /**
     * Marking a written answer fires answer_submitted for plagiarism plugins, with the draft and the learner.
     */
    public function test_answer_submitted_event(): void {
        [$instance, $task, $user, $context] = $this->setup_activity();
        $sink = $this->redirectEvents();
        $marked = $this->mark_text($instance, $task, $user, $context, self::FIRST);
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_aispeakwrite\event\answer_submitted
        ));
        $this->assertCount(1, $events);
        $this->assertSame((int)$marked->id, (int)$events[0]->objectid);
        $this->assertSame((int)$user->id, (int)$events[0]->relateduserid);
        $this->assertSame($context->id, $events[0]->contextid);
        $this->assertSame(1, $events[0]->other['attempt']);
        $this->assertStringContainsString('feedback.php', $events[0]->get_url()->out(false));
    }

    /**
     * The web services pass the browser's counts on, for the learner's own draft only.
     */
    public function test_externals_store_counts(): void {
        global $DB;
        [$instance, $task, $user, , $cm] = $this->setup_activity(['dailylimit' => 0]);
        $sub = submissions::open_draft($instance, $task, (int)$user->id);
        $this->setUser($user);
        save_draft::execute($cm->id, $sub->id, 'Lieber Tim,', '', 1, 42, 0, 2);
        $row = $DB->get_record('aispeakwrite_sub', ['id' => $sub->id]);
        $this->assertSame([1, 42, 2], [(int)$row->pastecount, (int)$row->pastechars, (int)$row->tabswitches]);

        $result = submit::execute($cm->id, $sub->id, self::FIRST, '', 1, 8, 0, 1);
        $this->assertTrue($result['ok']);
        $row = $DB->get_record('aispeakwrite_sub', ['id' => $sub->id]);
        $this->assertSame('marked', $row->status);
        $this->assertSame([2, 50, 3], [(int)$row->pastecount, (int)$row->pastechars, (int)$row->tabswitches]);
    }

    /**
     * The teacher panel describes the draft, and plagiarism links stay empty when plagiarism is off.
     */
    public function test_panel(): void {
        global $CFG, $DB;
        [$instance, $task, $user, $context, $cm] = $this->setup_activity(['pastemode' => 'block']);
        $marked = $this->mark_text($instance, $task, $user, $context, self::FIRST);
        $DB->set_field('aispeakwrite_sub', 'pasteblocked', 3, ['id' => $marked->id]);
        $sub = $DB->get_record('aispeakwrite_sub', ['id' => $marked->id]);
        $CFG->enableplagiarism = 0;
        $panel = integrity::panel($instance, $cm, $sub);
        $this->assertTrue($panel['flagged']);
        $this->assertFalse($panel['hasplagiarism']);
        $texts = array_column($panel['rows'], 'text');
        $this->assertContains(get_string('integrity_pasteblocked', 'mod_aispeakwrite', 3), $texts);
        $this->assertContains(get_string('integrity_rewritefirst', 'mod_aispeakwrite'), $texts);
        $this->assertSame('', integrity::plagiarism_links($cm, $sub));
        // Speaking answers have no panel.
        $sub->kind = 'speaking';
        $this->assertNull(integrity::panel($instance, $cm, $sub));
    }

    /**
     * The activity supports the plagiarism API, and the paste setting defaults to logging.
     */
    public function test_supports_and_default(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/aispeakwrite/lib.php');
        $this->resetAfterTest();
        $this->assertTrue(aispeakwrite_supports(FEATURE_PLAGIARISM));
        $this->assertSame('log', integrity::default_paste_mode());
        set_config('defaultpastemode', 'block', 'mod_aispeakwrite');
        $this->assertSame('block', integrity::default_paste_mode());
        $this->assertSame('log', integrity::paste_mode((object)['pastemode' => 'nonsense']));
    }
}
