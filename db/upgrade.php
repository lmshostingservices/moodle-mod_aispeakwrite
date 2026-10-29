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

/**
 * Upgrade steps for mod_aispeakwrite.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs the upgrade steps.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_aispeakwrite_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102900) {
        // Writing integrity: the paste setting on the activity, and per-draft counts.
        $table = new xmldb_table('aispeakwrite');
        $field = new xmldb_field('pastemode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'log', 'completionmarked');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('aispeakwrite_sub');
        $fields = [
            new xmldb_field('pastecount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemarked'),
            new xmldb_field('pastechars', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'pastecount'),
            new xmldb_field('pasteblocked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'pastechars'),
            new xmldb_field('tabswitches', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'pasteblocked'),
            new xmldb_field('rewritechanged', XMLDB_TYPE_INTEGER, '3', null, null, null, null, 'tabswitches'),
            new xmldb_field('rewriteflag', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'rewritechanged'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_mod_savepoint(true, 2026102900, 'aispeakwrite');
    }

    return true;
}
