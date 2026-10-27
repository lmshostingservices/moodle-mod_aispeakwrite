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

use mod_aispeakwrite\local\tips;

/**
 * Tests for the tips parser.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aispeakwrite\local\tips
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\local\tips::class)]
final class tips_test extends \advanced_testcase {
    /**
     * Lines become named tips with stable ids, examples and the level they came from.
     */
    public function test_parse(): void {
        $this->resetAfterTest();
        $parsed = tips::parse("Satzanfang: Nicht mit ich beginnen. | Am Samstag komme ich.\n\n# comment\nOhne Namen", 'school');
        $this->assertCount(2, $parsed);
        $this->assertSame('tip:satzanfang', $parsed[0]['id']);
        $this->assertSame('Satzanfang', $parsed[0]['name']);
        $this->assertSame('Nicht mit ich beginnen.', $parsed[0]['tip']);
        $this->assertSame('Am Samstag komme ich.', $parsed[0]['example']);
        $this->assertSame('school', $parsed[0]['source']);
        $this->assertSame('Ohne Namen', $parsed[1]['tip']);
        $this->assertStringStartsWith('tip:', $parsed[1]['id']);
    }

    /**
     * Umlauts make readable ids.
     */
    public function test_slug(): void {
        $this->assertSame('verb-am-ende-nach-weil', tips::slug('Verb am Ende nach weil'));
        $this->assertSame('zweiteilige-konnektoren', tips::slug('Zweiteilige Konnektoren'));
        $this->assertSame('groesse-ueben', tips::slug('Größe üben'));
    }

    /**
     * The task tip wins over the activity tip, which wins over the school tip, when names match.
     */
    public function test_merged_precedence(): void {
        $this->resetAfterTest();
        set_config('schooltips', "Satzanfang: school version\nKonnektoren: school only", 'mod_aispeakwrite');
        $instance = (object)['activitytips' => "Satzanfang: activity version\nAdverb: activity only"];
        $task = (object)['tasktips' => 'Satzanfang: task version'];
        $merged = tips::merged($instance, $task);
        $byid = array_column($merged['tips'], null, 'id');
        $this->assertSame('task version', $byid['tip:satzanfang']['tip']);
        $this->assertSame('task', $byid['tip:satzanfang']['source']);
        $this->assertArrayHasKey('tip:konnektoren', $byid);
        $this->assertArrayHasKey('tip:adverb', $byid);
        $this->assertCount(3, $merged['tips']);
        $this->assertSame(64, strlen($merged['version']));

        // Changing a tip changes the version, so logs show which wording was used.
        $task->tasktips = 'Satzanfang: changed';
        $this->assertNotSame($merged['version'], tips::merged($instance, $task)['version']);
    }

    /**
     * Content points keep their order and minimum counts.
     */
    public function test_points(): void {
        $points = tips::points("Ob Sie kommen\nMachen Sie Vorschläge | 2\n\n");
        $this->assertCount(2, $points);
        $this->assertSame('p2', $points[1]['id']);
        $this->assertSame(2, $points[1]['mincount']);
        $this->assertSame(1, $points[0]['mincount']);
    }

    /**
     * Phrase groups.
     */
    public function test_phrases(): void {
        $groups = tips::phrases("Schluss: Liebe Grüße | Bis bald!\nAußerdem | Deshalb");
        $this->assertSame('Schluss', $groups[0]['name']);
        $this->assertSame(['Liebe Grüße', 'Bis bald!'], $groups[0]['items']);
        $this->assertSame('', $groups[1]['name']);
        $this->assertSame(['Außerdem', 'Deshalb'], $groups[1]['items']);
    }

    /**
     * Rule labels resolve for tips and fixed patterns only.
     */
    public function test_label(): void {
        $this->resetAfterTest();
        $tips = tips::parse('Satzanfang: x', 'school');
        $this->assertSame('Satzanfang', tips::label('tip:satzanfang', $tips));
        $this->assertSame(get_string('pattern_casedative', 'mod_aispeakwrite'), tips::label('grammar:casedative', $tips));
        $this->assertSame('', tips::label('grammar:invented', $tips));
        $this->assertSame('', tips::label('tip:unknown', $tips));
    }
}
