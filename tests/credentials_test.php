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

/**
 * Tests the LMS Labs credential lookup.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aispeakwrite\local\credentials
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\local\credentials::class)]
final class credentials_test extends \advanced_testcase {
    protected function tearDown(): void {
        credentials::$central = null;
        parent::tearDown();
    }

    /**
     * Central Config wins when complete.
     */
    public function test_central_first(): void {
        $this->resetAfterTest();
        set_config('lmslabssiteid', 'local-site', 'mod_aispeakwrite');
        set_config('lmslabsapikey', 'local-key', 'mod_aispeakwrite');
        credentials::$central = fn() => ['siteid' => 'central-site', 'apikey' => 'central-key'];
        $this->assertSame(
            ['siteid' => 'central-site', 'apikey' => 'central-key', 'source' => 'central'],
            credentials::resolve()
        );
    }

    /**
     * An incomplete central pair is never mixed with the local one.
     */
    public function test_never_mixed(): void {
        $this->resetAfterTest();
        set_config('lmslabssiteid', 'local-site', 'mod_aispeakwrite');
        set_config('lmslabsapikey', 'local-key', 'mod_aispeakwrite');
        credentials::$central = fn() => ['siteid' => 'central-site', 'apikey' => ''];
        $this->assertSame(['siteid' => 'local-site', 'apikey' => 'local-key', 'source' => 'local'], credentials::resolve());
        $this->assertSame('credentials_local', credentials::status());

        set_config('lmslabsapikey', '', 'mod_aispeakwrite');
        $this->assertNull(credentials::find());
        $this->assertSame('credentials_centralincomplete', credentials::status());
        $this->expectException(\moodle_exception::class);
        credentials::resolve();
    }

    /**
     * Without Central Config the local pair is used.
     */
    public function test_local_only(): void {
        $this->resetAfterTest();
        credentials::$central = fn() => null;
        $this->assertSame('credentials_none', credentials::status());
        set_config('lmslabssiteid', 's', 'mod_aispeakwrite');
        set_config('lmslabsapikey', 'k', 'mod_aispeakwrite');
        $this->assertSame('local', credentials::find()['source']);
    }
}
