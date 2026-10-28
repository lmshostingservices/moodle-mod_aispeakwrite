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

use mod_aispeakwrite\local\memory;
use mod_aispeakwrite\local\tips;

/**
 * Tests each learner's record of tips and patterns.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aispeakwrite\local\memory
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\local\memory::class)]
final class memory_test extends \advanced_testcase {
    /**
     * Only the last five results are kept, and repeated misses become history sent with the next marking.
     */
    public function test_recent_and_history(): void {
        $this->resetAfterTest();
        foreach ([true, false, false, true, false, false] as $ok) {
            memory::update(7, 3, 'tip:satzanfang', 'Satzanfang', $ok);
        }
        memory::update(7, 3, 'grammar:plural', 'Plural', true);
        $rows = memory::for_user(7, 3);
        $this->assertSame('tip:satzanfang', $rows[0]['key']);
        $this->assertSame('00100', $rows[0]['recent']);
        $this->assertSame(1, $rows[0]['ok']);
        $history = memory::history(7, 3);
        $this->assertCount(1, $history);
        $this->assertSame(['id' => 'tip:satzanfang', 'name' => 'Satzanfang', 'recent' => '1/5'], $history[0]);
        $this->assertSame([], memory::history(7, 4));
    }

    /**
     * Error marks count as misses, good marks as hits, and unknown rules are ignored.
     */
    public function test_record(): void {
        $this->resetAfterTest();
        $tips = tips::parse('Satzanfang: x', 'school');
        memory::record(
            7,
            3,
            [
                'marks' => [
                    ['type' => 'error', 'ruleid' => 'grammar:casedative'],
                    ['type' => 'good', 'ruleid' => 'tip:satzanfang'],
                    ['type' => 'error', 'ruleid' => ''],
                ],
                'rulehits' => [['ruleid' => 'grammar:invented', 'ok' => true]],
                'points' => [['status' => 'short']],
            ],
            $tips
        );
        $rows = array_column(memory::for_user(7, 3), 'recent', 'key');
        $this->assertSame(
            ['grammar:casedative' => '0', 'content:points' => '0', 'tip:satzanfang' => '1'],
            array_intersect_key($rows, ['grammar:casedative' => 1, 'content:points' => 1, 'tip:satzanfang' => 1])
        );
        $this->assertArrayNotHasKey('grammar:invented', $rows);
    }
}
