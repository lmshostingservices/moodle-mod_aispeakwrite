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

namespace mod_aispeakwrite\local;

/**
 * Each learner's record of which tips and grammar patterns they get right, kept per activity.
 *
 * "recent" holds the last results (newest last, 1 = right, 0 = missed) and is what the dashboard, the class analytics
 * and the learner history sent with each marking are built from.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class memory {
    /** @var int How many recent results are kept. */
    public const KEEP = 5;

    /**
     * Records the outcome of one marked answer.
     *
     * @param int $aid
     * @param int $userid
     * @param array $feedback validated feedback
     * @param array $tips merged tips
     */
    public static function record(int $aid, int $userid, array $feedback, array $tips): void {
        $outcome = [];
        foreach ($feedback['rulehits'] ?? [] as $hit) {
            $outcome[$hit['ruleid']] = ($outcome[$hit['ruleid']] ?? true) && $hit['ok'];
        }
        foreach ($feedback['marks'] ?? [] as $mark) {
            if (empty($mark['ruleid'])) {
                continue;
            }
            if ($mark['type'] === 'error') {
                $outcome[$mark['ruleid']] = false;
            } else if (!isset($outcome[$mark['ruleid']])) {
                $outcome[$mark['ruleid']] = true;
            }
        }
        if (!empty($feedback['points'])) {
            $ok = true;
            foreach ($feedback['points'] as $point) {
                if (in_array($point['status'], ['missing', 'mismatch', 'short'], true)) {
                    $ok = false;
                }
            }
            $outcome['content:points'] = $ok;
        }
        foreach ($outcome as $ruleid => $ok) {
            $label = tips::label($ruleid, $tips);
            if ($label === '') {
                continue;
            }
            self::update($aid, $userid, $ruleid, $label, $ok);
        }
    }

    /**
     * Adds one result for a pattern.
     *
     * @param int $aid
     * @param int $userid
     * @param string $key
     * @param string $label
     * @param bool $ok
     */
    public static function update(int $aid, int $userid, string $key, string $label, bool $ok): void {
        global $DB;
        $row = $DB->get_record('aispeakwrite_memory', ['aispeakwriteid' => $aid, 'userid' => $userid,
            'patternkey' => $key]);
        $type = str_starts_with($key, 'tip:') ? 'tip' : (str_starts_with($key, 'content:') ? 'content' : 'grammar');
        if (!$row) {
            $DB->insert_record('aispeakwrite_memory', (object)['aispeakwriteid' => $aid, 'userid' => $userid,
                'patternkey' => $key, 'label' => \core_text::substr($label, 0, 255), 'ptype' => $type,
                'hits' => $ok ? 1 : 0, 'misses' => $ok ? 0 : 1, 'recent' => $ok ? '1' : '0', 'timemodified' => time()]);
            return;
        }
        $recent = substr($row->recent . ($ok ? '1' : '0'), -self::KEEP);
        $DB->update_record('aispeakwrite_memory', (object)['id' => $row->id, 'label' => \core_text::substr($label, 0, 255),
            'hits' => $row->hits + ($ok ? 1 : 0), 'misses' => $row->misses + ($ok ? 0 : 1), 'recent' => $recent,
            'timemodified' => time()]);
    }

    /**
     * A learner's patterns, weakest first.
     *
     * @param int $aid
     * @param int $userid
     * @return array[] each ['key', 'label', 'type', 'ok', 'total', 'recent']
     */
    public static function for_user(int $aid, int $userid): array {
        global $DB;
        $rows = $DB->get_records('aispeakwrite_memory', ['aispeakwriteid' => $aid, 'userid' => $userid]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'key' => $row->patternkey, 'label' => $row->label, 'type' => $row->ptype,
                'ok' => substr_count($row->recent, '1'), 'total' => strlen($row->recent), 'recent' => $row->recent,
            ];
        }
        usort($out, function ($a, $b) {
            $ra = $a['total'] ? $a['ok'] / $a['total'] : 1;
            $rb = $b['total'] ? $b['ok'] / $b['total'] : 1;
            return $ra <=> $rb ?: $b['total'] <=> $a['total'];
        });
        return $out;
    }

    /**
     * The learner's open focus areas, as sent with a marking request.
     *
     * @param int $aid
     * @param int $userid
     * @return array[] each ['id', 'name', 'recent']
     */
    public static function history(int $aid, int $userid): array {
        $out = [];
        foreach (self::for_user($aid, $userid) as $p) {
            if ($p['total'] - $p['ok'] >= 2) {
                $out[] = ['id' => $p['key'], 'name' => $p['label'], 'recent' => $p['ok'] . '/' . $p['total']];
            }
            if (count($out) >= 6) {
                break;
            }
        }
        return $out;
    }
}
