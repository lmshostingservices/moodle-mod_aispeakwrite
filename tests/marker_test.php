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

use mod_aispeakwrite\local\marker;
use mod_aispeakwrite\local\tips;

/**
 * Tests that what LMS Labs returns is checked before it is stored.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aispeakwrite\local\marker
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\local\marker::class)]
final class marker_test extends \advanced_testcase {
    /** @var string The answer used in these tests. */
    private const TEXT = "Lieber Tim,\nvielen Dank für den Einladung und natürlich ich kommen gern.";

    /**
     * A task with four points.
     *
     * @return \stdClass
     */
    private function task(): \stdClass {
        return (object)['points' => "Ob Sie kommen\nGlückwünsche\nAngebot | 2\nErfahrungen"];
    }

    /**
     * A well-formed result.
     *
     * @return array
     */
    private function sample_result(): array {
        return [
            'summary' => '<b>Good</b> start.',
            'criteria' => [
                'task' => ['atg' => 9, 'telc' => 9, 'reason' => 'Point 3 missing'],
                'communication' => ['atg' => 8, 'telc' => 2, 'reason' => ''],
                'form' => ['atg' => 4, 'telc' => 15, 'reason' => ''],
            ],
            'points' => [
                ['id' => 'p3', 'status' => 'missing', 'quote' => '', 'comment' => 'Offer something', 'phrases' => ['Soll ich?']],
                ['id' => 'p9', 'status' => 'covered'],
                ['id' => 'p1', 'status' => 'nonsense'],
            ],
            'marks' => [
                ['type' => 'error', 'text' => 'den Einladung', 'start' => 0, 'end' => 3, 'correction' => 'die Einladung',
                    'ruleId' => 'grammar:article', 'hint' => 'Gender?', 'examples' => ['die Zeitung', 'a', 'b', 'c']],
                ['type' => 'error', 'text' => 'ich kommen', 'correction' => 'komme ich', 'ruleId' => 'tip:invented'],
                ['type' => 'good', 'text' => 'Lieber Tim,', 'ruleId' => 'tip:anrede'],
                ['type' => 'error', 'text' => 'not in the answer'],
                ['type' => 'error', 'text' => 'Einladung'],
                ['type' => 'shout', 'text' => 'Tim'],
            ],
            'ruleHits' => [['ruleId' => 'tip:anrede', 'ok' => true], ['ruleId' => 'tip:made-up', 'ok' => false]],
            'focus' => ['grammar:article', 'grammar:nope'],
        ];
    }

    /**
     * Scores are snapped to the bands, the exam score is never below the strict score, and the totals add up.
     */
    public function test_scores_banded(): void {
        $this->resetAfterTest();
        $fb = marker::normalise($this->sample_result(), self::TEXT, $this->task(), tips::parse('Anrede: x', 'school'));
        $this->assertSame(9, $fb['criteria']['task']['strict']);
        $this->assertSame(9, $fb['criteria']['communication']['strict']);
        $this->assertSame(9, $fb['criteria']['communication']['exam']);
        $this->assertSame(3, $fb['criteria']['form']['strict']);
        $this->assertSame(15, $fb['criteria']['form']['exam']);
        $this->assertSame(21, $fb['score']);
        $this->assertSame(33, $fb['examscore']);
        $this->assertSame(45, $fb['maxscore']);
        $this->assertSame('Good start.', $fb['summary']);
    }

    /**
     * Only known points are kept, with their own text, and an unknown status becomes "covered".
     */
    public function test_points_whitelisted(): void {
        $this->resetAfterTest();
        $fb = marker::normalise($this->sample_result(), self::TEXT, $this->task(), []);
        $this->assertSame(['p1', 'p3'], array_column($fb['points'], 'id'));
        $this->assertSame('covered', $fb['points'][0]['status']);
        $this->assertSame('missing', $fb['points'][1]['status']);
        $this->assertSame('Angebot', $fb['points'][1]['text']);
    }

    /**
     * Marks must match the learner's own text; wrong offsets are corrected, invented rules are dropped.
     */
    public function test_marks_checked(): void {
        $this->resetAfterTest();
        $fb = marker::normalise($this->sample_result(), self::TEXT, $this->task(), tips::parse('Anrede: x', 'school'));
        $marks = $fb['marks'];
        $this->assertCount(3, $marks);
        $this->assertSame('Lieber Tim,', $marks[0]['text']);
        $this->assertSame('good', $marks[0]['type']);
        $this->assertSame('tip:anrede', $marks[0]['ruleid']);
        $this->assertSame(0, $marks[0]['n']);
        $this->assertSame('den Einladung', $marks[1]['text']);
        $this->assertSame(\core_text::strpos(self::TEXT, 'den Einladung'), $marks[1]['start']);
        $this->assertSame('grammar:article', $marks[1]['ruleid']);
        $this->assertSame(1, $marks[1]['n']);
        $this->assertCount(3, $marks[1]['examples']);
        $this->assertSame('', $marks[2]['ruleid']);
        $this->assertSame(2, $marks[2]['n']);
        $this->assertSame(['tip:anrede'], array_column($fb['rulehits'], 'ruleid'));
        $this->assertSame(['grammar:article'], $fb['focus']);
    }

    /**
     * A result without all three criteria is not usable.
     */
    public function test_missing_criteria(): void {
        $this->resetAfterTest();
        $r = $this->sample_result();
        unset($r['criteria']['form']);
        $this->assertNull(marker::normalise($r, self::TEXT, $this->task(), []));
    }

    /**
     * Locating a mark: offsets are character based and taken ranges are skipped.
     */
    public function test_locate(): void {
        $text = 'Größe und Größe';
        $this->assertSame([0, 5], marker::locate($text, 'Größe', 0, 5, []));
        $this->assertSame([10, 15], marker::locate($text, 'Größe', 0, 5, [[0, 5]]));
        $this->assertSame([null, null], marker::locate($text, 'fehlt', null, null, []));
        $this->assertSame([null, null], marker::locate($text, '', null, null, []));
    }

    /**
     * Speaking answers are marked on the learner's turns only.
     */
    public function test_answer_text_speaking(): void {
        $sub = (object)['kind' => 'speaking', 'content' => json_encode(['turns' => [
            ['role' => 'partner', 'text' => 'Hallo!'],
            ['role' => 'learner', 'text' => 'Hallo Lena.'],
            ['role' => 'robot', 'text' => 'ignored'],
            ['role' => 'learner', 'text' => 'Wir feiern am Freitag.'],
        ]])];
        $this->assertSame("Hallo Lena.\nWir feiern am Freitag.", marker::answer_text($sub));
        $this->assertCount(3, marker::turns($sub));
    }

    /**
     * Saying it wrong and then right, while speaking, is a self-correction even when the AI calls it an error.
     */
    public function test_speaking_self_correction(): void {
        $text = "Ich habe schon mit meine … mit meiner Abteilung gesprochen.\n" .
            "Die Küche ist gemütlicher, weil wir müssen früh anfangen.\n" .
            "Ich bringe der, äh, den Kuchen mit.\n" .
            "Wir treffen uns am Freitag in die Küche, nein, in der Küche.";
        $task = (object)['kind' => 'speaking', 'points' => "Wann\nWo"];
        $r = [
            'criteria' => ['task' => ['atg' => 15], 'communication' => ['atg' => 15], 'form' => ['atg' => 9]],
            'marks' => [
                ['type' => 'error', 'text' => 'mit meine … mit meiner Abteilung', 'correction' => 'mit meiner Abteilung'],
                ['type' => 'error', 'text' => 'weil wir müssen früh anfangen', 'correction' => 'weil wir früh anfangen müssen'],
                ['type' => 'error', 'text' => 'Ich bringe der', 'correction' => 'den Kuchen'],
                ['type' => 'error', 'text' => 'in die Küche', 'correction' => 'in der Küche'],
            ],
        ];
        $fb = marker::normalise($r, $text, $task, []);
        $types = array_column($fb['marks'], 'type', 'text');
        $this->assertSame('selfcorrection', $types['mit meine … mit meiner Abteilung']);
        $this->assertSame('error', $types['weil wir müssen früh anfangen']);
        $this->assertSame('selfcorrection', $types['Ich bringe der']);
        $this->assertSame('selfcorrection', $types['in die Küche']);
        // Only the real mistake is numbered.
        $this->assertSame([0, 1, 0, 0], array_column($fb['marks'], 'n'));

        // Writing is never reclassified: a typed text can be edited before sending.
        $task->kind = 'writing';
        $fb = marker::normalise($r, $text, $task, []);
        $this->assertSame(['error'], array_values(array_unique(array_column($fb['marks'], 'type'))));
    }
}
