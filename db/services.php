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
 * Web services for mod_aispeakwrite.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_aispeakwrite_save_draft' => [
        'classname' => \mod_aispeakwrite\external\save_draft::class,
        'description' => 'Saves a writing draft.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aispeakwrite:practise',
    ],
    'mod_aispeakwrite_submit' => [
        'classname' => \mod_aispeakwrite\external\submit::class,
        'description' => 'Marks a written answer through LMS Labs.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aispeakwrite:practise',
    ],
    'mod_aispeakwrite_submit_speaking' => [
        'classname' => \mod_aispeakwrite\external\submit_speaking::class,
        'description' => 'Stores a speaking transcript and marks it through LMS Labs.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aispeakwrite:practise',
    ],
    'mod_aispeakwrite_converse' => [
        'classname' => \mod_aispeakwrite\external\converse::class,
        'description' => 'The AI partner\'s next turn in a speaking conversation.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aispeakwrite:practise',
    ],
];
