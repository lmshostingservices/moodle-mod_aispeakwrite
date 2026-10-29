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
 * Writing integrity: paste and tab-switch counts per draft, and the rewrite check.
 *
 * Everything here is for teachers only. None of it is shown to learners and none of it is sent to LMS Labs.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class integrity {
    /** @var string Component. */
    public const P = 'mod_aispeakwrite';

    /** @var int Rewrites with fewer unmarked words than this are not compared. */
    public const MINWORDS = 10;

    /** @var int Largest count accepted from one request, so a broken or hostile client cannot inflate figures. */
    public const MAXSTEP = 1000;

    /** @var int Largest number of pasted characters accepted from one request. */
    public const MAXCHARSTEP = 200000;

    /** @var int Unmarked words next to a marked spot that a correction may change without counting. */
    public const SLACK = 2;

    /** @var int Above this many word pairs the comparison falls back to counting shared words. */
    public const MAXCELLS = 1000000;

    /**
     * The paste settings an activity can have.
     *
     * @return string[] mode => label
     */
    public static function paste_modes(): array {
        return [
            'allow' => get_string('pastemode_allow', self::P),
            'log' => get_string('pastemode_log', self::P),
            'block' => get_string('pastemode_block', self::P),
        ];
    }

    /**
     * The paste setting new activities get.
     *
     * @return string
     */
    public static function default_paste_mode(): string {
        $mode = (string)get_config(self::P, 'defaultpastemode');
        return in_array($mode, ['allow', 'log', 'block'], true) ? $mode : 'log';
    }

    /**
     * The paste setting of an activity.
     *
     * @param \stdClass $instance
     * @return string allow, log or block
     */
    public static function paste_mode(\stdClass $instance): string {
        $mode = (string)($instance->pastemode ?? '');
        return in_array($mode, ['allow', 'log', 'block'], true) ? $mode : 'log';
    }

    /**
     * The rewrite check threshold: a rewrite that changes more than this percentage of words outside the marked
     * spots is flagged to teachers.
     *
     * @return int 1 to 100
     */
    public static function threshold(): int {
        $value = get_config(self::P, 'rewritethreshold');
        if ($value === false || $value === '' || !is_numeric($value)) {
            return 40;
        }
        return max(1, min(100, (int)$value));
    }

    /**
     * Adds the counts the writing screen collected since its last save to a draft.
     *
     * Pastes are kept only when the activity logs them, stopped pastes only when it blocks them; tab switches are
     * always kept. Counts are added, never replaced, so a draft written over several visits keeps the total.
     *
     * @param \stdClass $instance
     * @param \stdClass $sub the draft; updated in place
     * @param int $pastes pastes from outside the answer box
     * @param int $pastechars characters in those pastes
     * @param int $blocked pastes stopped
     * @param int $tabs times the learner left the screen
     */
    public static function add_counts(
        \stdClass $instance,
        \stdClass $sub,
        int $pastes,
        int $pastechars,
        int $blocked,
        int $tabs
    ): void {
        global $DB;
        if ($sub->kind !== 'writing' || $sub->status === 'marked') {
            return;
        }
        $clamp = fn(int $v, int $max) => max(0, min($max, $v));
        $mode = self::paste_mode($instance);
        $add = [];
        if ($mode === 'log' && ($pastes > 0 || $pastechars > 0)) {
            $add['pastecount'] = $clamp($pastes, self::MAXSTEP);
            $add['pastechars'] = $clamp($pastechars, self::MAXCHARSTEP);
        }
        if ($mode === 'block' && $blocked > 0) {
            $add['pasteblocked'] = $clamp($blocked, self::MAXSTEP);
        }
        if ($tabs > 0) {
            $add['tabswitches'] = $clamp($tabs, self::MAXSTEP);
        }
        $add = array_filter($add);
        if (!$add) {
            return;
        }
        // Added in SQL, so two saves arriving together cannot lose a count.
        $sets = [];
        $params = [];
        foreach ($add as $field => $value) {
            $sets[] = "$field = $field + :$field";
            $params[$field] = $value;
        }
        $params['id'] = $sub->id;
        $DB->execute("UPDATE {aispeakwrite_sub} SET " . implode(', ', $sets) .
            " WHERE id = :id AND status <> 'marked'", $params);
        foreach ($add as $field => $value) {
            $sub->$field = (int)($sub->$field ?? 0) + $value;
        }
    }

    /**
     * The marked draft a rewrite was started from.
     *
     * @param \stdClass $sub
     * @return \stdClass|null
     */
    public static function previous(\stdClass $sub): ?\stdClass {
        global $DB;
        if ((int)$sub->attempt < 2) {
            return null;
        }
        $rows = $DB->get_records_select(
            'aispeakwrite_sub',
            "taskid = :taskid AND userid = :userid AND kind = 'writing' AND status = 'marked'
            AND attempt < :attempt AND id <> :id",
            ['taskid' => $sub->taskid, 'userid' => $sub->userid, 'attempt' => $sub->attempt, 'id' => $sub->id],
            'attempt DESC, id DESC',
            '*',
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }

    /**
     * Runs the rewrite check on a draft and stores the result on it (not saved: the caller saves the draft).
     *
     * @param \stdClass $sub the draft being marked; rewritechanged and rewriteflag are set
     */
    public static function check_rewrite(\stdClass $sub): void {
        $sub->rewritechanged = null;
        $sub->rewriteflag = 0;
        if ($sub->kind !== 'writing') {
            return;
        }
        $prev = self::previous($sub);
        if (!$prev) {
            return;
        }
        $fb = json_decode((string)$prev->feedback, true);
        $marks = is_array($fb) && is_array($fb['marks'] ?? null) ? $fb['marks'] : [];
        $pct = self::changed_percent(
            submissions::plain((string)$prev->content),
            submissions::plain((string)$sub->content),
            $marks
        );
        if ($pct === null) {
            return;
        }
        $sub->rewritechanged = $pct;
        $sub->rewriteflag = $pct > self::threshold() ? 1 : 0;
    }

    /**
     * Percentage of the earlier draft's words, outside the marked spots, that the rewrite did not keep.
     *
     * The two drafts are lined up word by word. Marked words never count, and each run of changes that touches a
     * marked spot (or the kept words either side of it) may also change SLACK unmarked words per mark without
     * counting, because fixing what the feedback pointed at often changes the words beside it. Words added without
     * replacing anything are not counted either: they are new writing, which Essay Guard (when installed) checks on
     * its own.
     *
     * @param string $before the earlier draft as plain text
     * @param string $after the rewrite as plain text
     * @param array $marks the earlier draft's marks, with character offsets start and end
     * @return int|null 0 to 100, or null when the earlier draft is too short outside the marks to judge
     */
    public static function changed_percent(string $before, string $after, array $marks): ?int {
        $old = self::words($before);
        $new = self::words($after);

        // Marks are in characters; words are found in bytes.
        $ranges = [];
        foreach ($marks as $m) {
            if (!is_array($m) || !isset($m['start'], $m['end'])) {
                continue;
            }
            $s = strlen(\core_text::substr($before, 0, max(0, (int)$m['start'])));
            $e = strlen(\core_text::substr($before, 0, max(0, (int)$m['end'])));
            if ($e >= $s) {
                $ranges[] = [$s, $e];
            }
        }
        $touching = function (int $from, int $to) use ($ranges): int {
            $n = 0;
            foreach ($ranges as [$s, $e]) {
                if ($s <= $to && $e >= $from) {
                    $n++;
                }
            }
            return $n;
        };
        $inmark = [];
        $unmarked = 0;
        foreach ($old as $k => $w) {
            $inmark[$k] = $touching($w[1], $w[2]) > 0;
            if (!$inmark[$k]) {
                $unmarked++;
            }
        }
        if ($unmarked < self::MINWORDS) {
            return null;
        }

        $pairs = self::align(array_column($old, 0), array_column($new, 0));
        $removed = 0;
        $pi = 0;
        $pairs[] = [count($old), count($new)];
        foreach ($pairs as [$oi, $ni]) {
            if ($oi > $pi) {
                // Words $pi .. $oi - 1 of the earlier draft were not kept. Unmarked words among them count, less an
                // allowance of SLACK words for each mark the change touches (a fix often moves or changes the words
                // beside the marked spot). The span runs from the kept word before the gap to the kept word after it.
                $from = $pi > 0 ? $old[$pi - 1][1] : 0;
                $to = $oi < count($old) ? $old[$oi][2] : strlen($before);
                $lost = 0;
                for ($k = $pi; $k < $oi; $k++) {
                    $lost += $inmark[$k] ? 0 : 1;
                }
                $removed += max(0, $lost - self::SLACK * $touching($from, $to));
            }
            $pi = $oi + 1;
        }
        return (int)min(100, round($removed / $unmarked * 100));
    }

    /**
     * Words of a text with their byte positions.
     *
     * @param string $text
     * @return array[] [lower-case word, start byte, end byte]
     */
    public static function words(string $text): array {
        preg_match_all("/[\\p{L}\\p{N}]+(?:['’\\-][\\p{L}\\p{N}]+)*/u", $text, $m, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m[0] as [$word, $pos]) {
            $out[] = [\core_text::strtolower($word), $pos, $pos + strlen($word)];
        }
        return $out;
    }

    /**
     * Lines up two word lists: the longest common subsequence.
     *
     * @param string[] $a
     * @param string[] $b
     * @return array[] [index in a, index in b] for every word kept, in order
     */
    public static function align(array $a, array $b): array {
        $n = count($a);
        $m = count($b);
        // Shared start and end need no table.
        $head = 0;
        while ($head < $n && $head < $m && $a[$head] === $b[$head]) {
            $head++;
        }
        $tail = 0;
        while ($tail < $n - $head && $tail < $m - $head && $a[$n - 1 - $tail] === $b[$m - 1 - $tail]) {
            $tail++;
        }
        $pairs = [];
        for ($i = 0; $i < $head; $i++) {
            $pairs[] = [$i, $i];
        }
        $ma = array_slice($a, $head, $n - $head - $tail);
        $mb = array_slice($b, $head, $m - $head - $tail);
        $ca = count($ma);
        $cb = count($mb);
        if ($ca && $cb) {
            if ($ca * $cb > self::MAXCELLS) {
                // Too long to line up exactly: keep each word of the earlier draft that the rewrite still has.
                $left = array_count_values($mb);
                $j = 0;
                foreach ($ma as $i => $w) {
                    if (!empty($left[$w])) {
                        $left[$w]--;
                        $pairs[] = [$head + $i, $head + min($j++, $cb - 1)];
                    }
                }
            } else {
                // Rows of the table as strings of 16-bit lengths keep memory small.
                $rows = [str_repeat("\0\0", $cb + 1)];
                for ($i = 1; $i <= $ca; $i++) {
                    $prev = $rows[$i - 1];
                    $row = "\0\0";
                    $left = 0;
                    for ($j = 1; $j <= $cb; $j++) {
                        if ($ma[$i - 1] === $mb[$j - 1]) {
                            $v = unpack('n', $prev, 2 * ($j - 1))[1] + 1;
                        } else {
                            $v = max(unpack('n', $prev, 2 * $j)[1], $left);
                        }
                        $v = min($v, 65535);
                        $row .= pack('n', $v);
                        $left = $v;
                    }
                    $rows[] = $row;
                }
                $cell = fn($i, $j) => unpack('n', $rows[$i], 2 * $j)[1];
                $mid = [];
                $i = $ca;
                $j = $cb;
                while ($i > 0 && $j > 0) {
                    if ($ma[$i - 1] === $mb[$j - 1]) {
                        $mid[] = [$head + $i - 1, $head + $j - 1];
                        $i--;
                        $j--;
                    } else if ($cell($i - 1, $j) >= $cell($i, $j - 1)) {
                        $i--;
                    } else {
                        $j--;
                    }
                }
                $pairs = array_merge($pairs, array_reverse($mid));
            }
        }
        for ($k = 0; $k < $tail; $k++) {
            $pairs[] = [$n - $tail + $k, $m - $tail + $k];
        }
        return $pairs;
    }

    /**
     * Whether a draft should be looked at.
     *
     * @param \stdClass $sub
     * @return bool
     */
    public static function flagged(\stdClass $sub): bool {
        return !empty($sub->rewriteflag) || (int)($sub->pastecount ?? 0) > 0 || (int)($sub->pasteblocked ?? 0) > 0;
    }

    /**
     * One-line reasons a draft is flagged, for teachers.
     *
     * @param \stdClass $instance
     * @param \stdClass $sub
     * @return string[]
     */
    public static function reasons(\stdClass $instance, \stdClass $sub): array {
        $out = [];
        if (!empty($sub->rewriteflag)) {
            $out[] = get_string('integrity_rewrite', self::P, (int)$sub->rewritechanged);
        }
        if ((int)$sub->pastecount > 0) {
            $out[] = get_string('integrity_pastes', self::P, ['count' => (int)$sub->pastecount, 'chars' => (int)$sub->pastechars]);
        }
        if ((int)$sub->pasteblocked > 0) {
            $out[] = get_string('integrity_pasteblocked', self::P, (int)$sub->pasteblocked);
        }
        if ($out && (int)$sub->tabswitches > 0) {
            $out[] = get_string('integrity_tabs', self::P, (int)$sub->tabswitches);
        }
        return $out;
    }

    /**
     * Essay Guard's badge (or any plagiarism plugin's links) for a draft, when plagiarism is on for the site.
     *
     * @param \cm_info|\stdClass $cm
     * @param \stdClass $sub
     * @return string HTML, or the empty string
     */
    public static function plagiarism_links($cm, \stdClass $sub): string {
        global $CFG;
        if (empty($CFG->enableplagiarism) || $sub->kind !== 'writing') {
            return '';
        }
        require_once($CFG->libdir . '/plagiarismlib.php');
        return (string)plagiarism_get_links([
            'userid' => (int)$sub->userid,
            'cmid' => (int)$cm->id,
            'course' => (int)$cm->course,
            'component' => 'mod_aispeakwrite',
            'itemid' => (int)$sub->id,
            'objectid' => (int)$sub->id,
            'content' => '',
        ]);
    }

    /**
     * The teacher-only panel on the feedback page.
     *
     * @param \stdClass $instance
     * @param \cm_info|\stdClass $cm
     * @param \stdClass $sub
     * @return array|null null for speaking answers
     */
    public static function panel(\stdClass $instance, $cm, \stdClass $sub): ?array {
        if ($sub->kind !== 'writing') {
            return null;
        }
        $mode = self::paste_mode($instance);
        $rows = [];
        if ($mode === 'allow') {
            $rows[] = ['text' => get_string('integrity_pasteallowed', self::P), 'warn' => false];
        } else if ($mode === 'block') {
            $rows[] = (int)$sub->pasteblocked > 0
                ? ['text' => get_string('integrity_pasteblocked', self::P, (int)$sub->pasteblocked), 'warn' => true]
                : ['text' => get_string('integrity_nopasteblocked', self::P), 'warn' => false];
        } else {
            $rows[] = (int)$sub->pastecount > 0
                ? ['text' => get_string('integrity_pastes', self::P, ['count' => (int)$sub->pastecount,
                    'chars' => (int)$sub->pastechars]), 'warn' => true]
                : ['text' => get_string('integrity_nopastes', self::P), 'warn' => false];
        }
        $rows[] = ['text' => get_string('integrity_tabs', self::P, (int)$sub->tabswitches), 'warn' => false];
        if ((int)$sub->attempt < 2) {
            $rows[] = ['text' => get_string('integrity_rewritefirst', self::P), 'warn' => false];
        } else if ($sub->rewritechanged === null) {
            $rows[] = ['text' => get_string('integrity_rewritenone', self::P), 'warn' => false];
        } else {
            $rows[] = ['text' => get_string('integrity_rewrite', self::P, (int)$sub->rewritechanged)
                . (!empty($sub->rewriteflag) ? ' ' . get_string('integrity_rewriteflag', self::P, self::threshold()) : ''),
                'warn' => !empty($sub->rewriteflag)];
        }
        $links = self::plagiarism_links($cm, $sub);
        return [
            'rows' => $rows,
            'flagged' => self::flagged($sub),
            'plagiarism' => $links,
            'hasplagiarism' => $links !== '',
        ];
    }
}
