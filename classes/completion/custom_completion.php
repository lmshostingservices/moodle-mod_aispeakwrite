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

declare(strict_types=1);

namespace mod_aispeakwrite\completion;

use core_completion\activity_custom_completion;

/**
 * Custom completion: get a number of tasks marked.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * The state of a custom rule.
     *
     * @param string $rule
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE
     */
    public function get_state(string $rule): int {
        global $DB;
        $this->validate_rule($rule);
        $needed = (int)($this->cm->customdata['customcompletionrules']['completionmarked'] ?? 0);
        $done = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT taskid) FROM {aispeakwrite_sub}
            WHERE aispeakwriteid = :aid AND userid = :userid AND status = 'marked'",
            ['aid' => $this->cm->instance, 'userid' => $this->userid]
        );
        return $done >= $needed ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * The custom rules this module defines.
     *
     * @return string[]
     */
    public static function get_defined_custom_rules(): array {
        return ['completionmarked'];
    }

    /**
     * Descriptions of the active rules.
     *
     * @return string[]
     */
    public function get_custom_rule_descriptions(): array {
        $n = (int)($this->cm->customdata['customcompletionrules']['completionmarked'] ?? 0);
        return ['completionmarked' => get_string('completiondetail:marked', 'mod_aispeakwrite', $n)];
    }

    /**
     * The order rules are shown in.
     *
     * @return string[]
     */
    public function get_sort_order(): array {
        return ['completionview', 'completionmarked', 'completionusegrade', 'completionpassgrade'];
    }
}
