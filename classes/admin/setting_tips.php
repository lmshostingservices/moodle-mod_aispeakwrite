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

namespace mod_aispeakwrite\admin;

use mod_aispeakwrite\local\tips;

/**
 * The school tips box: plain text, length-checked.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_tips extends \admin_setting_configtextarea {
    /**
     * Rejects text over the limit.
     *
     * @param string $data
     * @return mixed true or an error string
     */
    public function validate($data) {
        if (\core_text::strlen((string)$data) > tips::MAXCHARS) {
            return get_string('toolong', 'mod_aispeakwrite', tips::MAXCHARS);
        }
        return parent::validate($data);
    }
}
