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

use mod_aispeakwrite\local\manager;
use mod_aispeakwrite\local\marker;
use mod_aispeakwrite\local\remote;
use mod_aispeakwrite\local\tips;

/**
 * Tests for 1.0.6: points per criterion, tips per level, longer tips, marking instructions and refusal messages.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aispeakwrite\local\manager
 * @covers     \mod_aispeakwrite\local\marker
 * @covers     \mod_aispeakwrite\local\tips
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\local\manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\local\marker::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\local\tips::class)]
final class clientfeedback_test extends \advanced_testcase {
    /**
     * A result with one criterion on each band.
     *
     * @return array
     */
    private function bands_result(): array {
        return [
            'summary' => 'ok',
            'criteria' => [
                'task' => ['atg' => 15, 'telc' => 15],
                'communication' => ['atg' => 9, 'telc' => 15],
                'form' => ['atg' => 3, 'telc' => 3],
            ],
        ];
    }

    /**
     * Without settings each criterion is worth 15, as before.
     */
    public function test_default_points(): void {
        $this->resetAfterTest();
        $this->assertSame(['task' => 15, 'communication' => 15, 'form' => 15], manager::criterion_max((object)[]));
        $this->assertSame(45, manager::total_points((object)[]));
        $fb = marker::normalise($this->bands_result(), 'Hallo Tim', (object)['points' => ''], []);
        $this->assertSame(27, $fb['score']);
        $this->assertSame(45, $fb['maxscore']);
        $this->assertSame(15, $fb['criteria']['task']['max']);
    }

    /**
     * The bands are turned into the activity's own points, and the totals follow.
     */
    public function test_custom_points(): void {
        $this->resetAfterTest();
        $instance = (object)['maxtask' => 20, 'maxcommunication' => 10, 'maxform' => 30];
        $max = manager::criterion_max($instance);
        $this->assertSame(60, manager::total_points($instance));
        $this->assertSame([0, 4, 12, 20], manager::bands_for(20));
        $fb = marker::normalise($this->bands_result(), 'Hallo Tim', (object)['points' => ''], [], null, $max);
        $this->assertSame(20, $fb['criteria']['task']['strict']);
        $this->assertSame(6, $fb['criteria']['communication']['strict']);
        $this->assertSame(10, $fb['criteria']['communication']['exam']);
        $this->assertSame(6, $fb['criteria']['form']['strict']);
        $this->assertSame(30, $fb['criteria']['form']['max']);
        $this->assertSame(32, $fb['score']);
        $this->assertSame(60, $fb['maxscore']);
    }

    /**
     * Out-of-range points fall back to 15 when an activity is saved.
     */
    public function test_points_cleaned_on_save(): void {
        $this->resetAfterTest();
        $data = manager::prepare_instance((object)['maxtask' => 0, 'maxcommunication' => 500, 'maxform' => 12]);
        $this->assertSame(15, $data->maxtask);
        $this->assertSame(15, $data->maxcommunication);
        $this->assertSame(12, $data->maxform);
    }

    /**
     * The activity's points reach the gradebook as a share of the maximum grade.
     */
    public function test_gradebook_uses_points(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/aispeakwrite/lib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_aispeakwrite');
        $instance = $gen->create_instance(['course' => $course->id, 'grade' => 60, 'maxtask' => 20, 'maxcommunication' => 10,
            'maxform' => 30]);
        $instance = $DB->get_record('aispeakwrite', ['id' => $instance->id]);
        $this->assertSame(60, manager::total_points($instance));
        $task = $gen->create_task(['aispeakwriteid' => $instance->id]);
        $DB->insert_record('aispeakwrite_sub', (object)['aispeakwriteid' => $instance->id, 'taskid' => $task->id,
            'userid' => $user->id, 'kind' => 'writing', 'attempt' => 1, 'fbmode' => 'think', 'status' => 'marked',
            'content' => 'x', 'contenthash' => '', 'tipsversion' => '', 'score' => 32, 'examscore' => 36, 'maxscore' => 60,
            'timecreated' => time(),
            'timemodified' => time(), 'timemarked' => time()]);
        $grades = aispeakwrite_get_user_grades($instance, $user->id);
        $this->assertEquals(32, $grades[$user->id]->rawgrade);
    }

    /**
     * Tips for the activity's level are merged between the activity's and the school's.
     */
    public function test_level_tips(): void {
        $this->resetAfterTest();
        set_config('schooltips', "Satzanfang: school\nSchluss: school", 'mod_aispeakwrite');
        set_config('leveltipsb1', "Satzanfang: B1 version\nBetreff: B1 only", 'mod_aispeakwrite');
        set_config('leveltipsa2', 'Kurz: A2 only', 'mod_aispeakwrite');

        $b1 = tips::merged((object)['examlevel' => 'B1', 'activitytips' => 'Schluss: activity']);
        $byid = array_column($b1['tips'], null, 'id');
        $this->assertSame('B1 version', $byid['tip:satzanfang']['tip']);
        $this->assertSame('level', $byid['tip:satzanfang']['source']);
        $this->assertSame('activity', $byid['tip:schluss']['source']);
        $this->assertArrayHasKey('tip:betreff', $byid);
        $this->assertArrayNotHasKey('tip:kurz', $byid);

        $a2 = tips::merged((object)['examlevel' => 'A2']);
        $this->assertContains('tip:kurz', array_column($a2['tips'], 'id'));
        $this->assertNotSame($a2['version'], $b1['version']);
        $this->assertSame('', tips::level_tips('Z9'));
        $this->assertSame('Kurz: A2 only', tips::level_tips('telc A2'));
    }

    /**
     * Tips boxes take up to 10,000 characters.
     */
    public function test_tips_limit(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->assertSame(10000, tips::MAXCHARS);
        $setting = new \mod_aispeakwrite\admin\setting_tips('mod_aispeakwrite/schooltips', 'x', '', '', PARAM_TEXT, 70, 8);
        $this->assertTrue($setting->validate(str_repeat('a', 9000)));
        $this->assertIsString($setting->validate(str_repeat('a', 10001)));
        $data = manager::prepare_instance((object)['activitytips' => str_repeat('b', 12000)]);
        $this->assertSame(10000, \core_text::strlen($data->activitytips));
    }

    /**
     * The request holds only fields LMS Labs accepts: the marking instructions travel as tips, the activity's own
     * points stay in Moodle, and long instructions are split into parts LMS Labs accepts.
     */
    public function test_body_fits_lms_labs_contract(): void {
        $this->resetAfterTest();
        $instance = (object)['id' => 1, 'examlevel' => 'B1', 'targetlang' => 'de-DE', 'feedbacklang' => 'en',
            'showgood' => 1, 'sendhistory' => 0, 'maxtask' => 20, 'maxcommunication' => 15, 'maxform' => 10];
        $task = (object)['kind' => 'writing', 'tasktype' => 'email', 'title' => 'T', 'situation' => '', 'sourcetext' => '',
            'points' => 'Machen Sie Vorschläge | 2', 'formality' => 'du', 'targetwords' => 150,
            'markinginstructions' => 'Point 1 needs at least two suggestions.'];
        $sub = (object)['kind' => 'writing', 'fbmode' => 'think', 'attempt' => 1, 'content' => 'Hallo'];
        $school = ['id' => 'tip:betreff', 'name' => 'Betreff', 'tip' => 'Anrede und Schluss.', 'example' => '',
            'source' => 'school'];
        $body = marker::body($instance, $task, $sub, ['tips' => [$school]]);
        $this->assertSame(
            ['title', 'situation', 'sourceText', 'points', 'formality', 'targetWords'],
            array_keys($body['task'])
        );
        $this->assertSame(['criteria', 'bands', 'examScore'], array_keys($body['scoring']));
        $this->assertSame([0, 3, 9, 15], $body['scoring']['bands']);
        $this->assertSame(2, $body['task']['points'][0]['minCount']);
        $this->assertCount(2, $body['tips']);
        $this->assertSame('marking:1', $body['tips'][0]['id']);
        $this->assertSame('Point 1 needs at least two suggestions.', $body['tips'][0]['tip']);
        $this->assertSame('task', $body['tips'][0]['level']);
        $this->assertSame(['id', 'name', 'tip', 'example', 'level'], array_keys($body['tips'][0]));
        $this->assertSame('tip:betreff', $body['tips'][1]['id']);

        // 10,000 characters of instructions: three parts, none over 4,000 characters, nothing lost.
        $lines = [];
        for ($i = 1; $i <= 200; $i++) {
            $lines[] = str_pad('Rule ' . $i . ': ', 49, 'x');
        }
        $long = implode("\n", $lines);
        $tips = marker::marking_tips($long);
        $this->assertCount(3, $tips);
        foreach ($tips as $t) {
            $this->assertLessThanOrEqual(marker::TIPCHARS, \core_text::strlen($t['tip']));
            $this->assertLessThanOrEqual(300, \core_text::strlen($t['name']));
        }
        $this->assertSame($long, implode("\n", array_column($tips, 'tip')));
        $this->assertSame([], marker::marking_tips('  '));

        // Feedback that cites the instructions is dropped, so learners never see them.
        $fb = marker::normalise(['summary' => 'S', 'criteria' => [], 'marks' => [
            ['type' => 'error', 'text' => 'Hallo', 'correction' => 'Hallo!', 'ruleId' => 'marking:1'],
        ]], 'Hallo', $task, [$school], 'de-DE');
        $this->assertSame('', $fb['marks'][0]['ruleid'] ?? '');
    }

    /**
     * Each refusal has its own message, so a teacher knows what to fix.
     */
    public function test_refusal_messages_distinct(): void {
        $this->resetAfterTest();
        $keys = [];
        foreach ([[402, 'INSUFFICIENT_CREDITS'], [403, 'NO_ENTITLEMENT'], [401, 'INVALID_CREDENTIALS']] as [$status, $code]) {
            try {
                remote::fail($status, json_encode(['error' => ['code' => $code]]));
            } catch (\moodle_exception $e) {
                $keys[] = $e->errorcode;
            }
        }
        $this->assertSame(
            ['remoterefused_insufficient_credits', 'remoterefused_no_entitlement', 'remoterefused_invalid_credentials'],
            $keys
        );
        $texts = array_map(fn($k) => get_string($k, 'mod_aispeakwrite'), $keys);
        $this->assertCount(3, array_unique($texts));
    }

    /**
     * A whole-sentence mark is underlined only on the words its correction changes (client feedback, 1.0.10).
     */
    public function test_marks_are_narrowed_to_the_changed_words(): void {
        $p = \mod_aispeakwrite\output\views::narrow(
            'es tun mir leid, dass du haben Probleme mit deine Nachbarn.',
            'es tut mir leid, dass du Probleme mit deinen Nachbarn hast.'
        );
        $text = 'es tun mir leid, dass du haben Probleme mit deine Nachbarn.';
        $spans = array_map(fn($x) => [\core_text::substr($text, $x[0], $x[1] - $x[0]), $x[2]], $p);
        $this->assertSame([['tun', 'tut'], ['haben', ''], ['deine Nachbarn', 'deinen Nachbarn hast']], $spans);
        $this->assertSame(
            [[3, 7, 'meiner'], [34, 39, 'laute']],
            \mod_aispeakwrite\output\views::narrow(
                'In mein alten Wohnung gab es auch latue Kinder',
                'In meiner alten Wohnung gab es auch laute Kinder'
            )
        );
        // Short marks and full rewrites stay whole.
        $this->assertSame([], \mod_aispeakwrite\output\views::narrow('den Einladung', 'die Einladung'));
        $this->assertSame([], \mod_aispeakwrite\output\views::narrow(
            'Ich gehen gestern in Schule mit mein Freund',
            'Gestern bin ich mit meinem Freund zur Schule gegangen'
        ));

        // On the page: one note, three underlined pieces, the number on the last piece only.
        $marks = [['id' => 'm1', 'n' => 1, 'start' => 0, 'end' => \core_text::strlen($text), 'type' => 'error',
            'severity' => 'major', 'correction' => 'es tut mir leid, dass du Probleme mit deinen Nachbarn hast.']];
        $paras = \mod_aispeakwrite\output\views::paragraphs($text, $marks, true);
        $marked = array_values(array_filter($paras[0]['segments'], fn($s) => !empty($s['mark'])));
        $this->assertSame(['tun', 'haben', 'deine Nachbarn'], array_column($marked, 'text'));
        $this->assertSame([false, false, true], array_column($marked, 'error'));
        $this->assertSame(['', '', ''], array_column($marked, 'correction'), 'Make me think hides corrections');
        $joined = implode('', array_column($paras[0]['segments'], 'text'));
        $this->assertSame($text, $joined, 'No text is lost or repeated');
    }
}
