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
 * Marks a writing or speaking answer through LMS Labs and stores the checked result.
 *
 * Nothing the AI returns is trusted as it is: every mark must match the learner's own text, every rule id must be one
 * of the tips or grammar patterns that were sent, scores are snapped to the four bands, and all text is cleaned and
 * length-limited before it is stored.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class marker {
    /**
     * @var string Fillers and restart words that show a learner correcting themselves, in every language learned.
     * Only words that are not ordinary words in another of the languages (so no "um", "no", "non" or "não").
     */
    public const HESITATIONS = 'äh|ähm|ehm|uh|uhm|erm|euh|hã|ich meine|i mean|je veux dire|quiero decir|o sea|'
        . 'voglio dire|cioè|ik bedoel|quer dizer|nein|nee|sorry|pardon|perdón|scusa|desculpa';

    /** @var string[] Point statuses. */
    public const POINT_STATUS = ['covered', 'extended', 'short', 'missing', 'mismatch'];

    /** @var string[] Mark types. */
    public const MARK_TYPES = ['error', 'good', 'selfcorrection'];

    /** @var string[] Mark severities. */
    public const SEVERITY = ['minor', 'major', 'unclear'];

    /** @var int Maximum marks kept. */
    public const MAXMARKS = 40;

    /**
     * The text that is marked: the written answer, or the learner's turns of a conversation.
     *
     * @param \stdClass $sub
     * @return string
     */
    public static function answer_text(\stdClass $sub): string {
        if ($sub->kind !== 'speaking') {
            return (string)$sub->content;
        }
        $lines = [];
        foreach (self::turns($sub) as $turn) {
            if ($turn['role'] === 'learner') {
                $lines[] = $turn['text'];
            }
        }
        return implode("\n", $lines);
    }

    /**
     * The turns of a speaking answer.
     *
     * @param \stdClass $sub
     * @return array[] each ['role' => learner|partner, 'text' => string]
     */
    public static function turns(\stdClass $sub): array {
        $data = json_decode((string)$sub->content, true);
        $out = [];
        foreach ((is_array($data) ? ($data['turns'] ?? []) : []) as $turn) {
            if (!is_array($turn) || !in_array($turn['role'] ?? '', ['learner', 'partner'], true)) {
                continue;
            }
            $text = self::clean($turn['text'] ?? '', 2000);
            if ($text !== '') {
                $out[] = ['role' => $turn['role'], 'text' => $text];
            }
        }
        return array_slice($out, 0, 60);
    }

    /**
     * Builds the request body for a marking.
     *
     * @param \stdClass $instance
     * @param \stdClass $task
     * @param \stdClass $sub
     * @param array $merged merged tips (tips::merged)
     * @return array
     */
    public static function body(\stdClass $instance, \stdClass $task, \stdClass $sub, array $merged): array {
        global $DB;
        $points = [];
        foreach (tips::points($task->points) as $p) {
            $points[] = ['id' => $p['id'], 'text' => $p['text'], 'minCount' => $p['mincount']];
        }
        $body = [
            'kind' => $task->kind,
            'taskType' => $task->tasktype,
            'mode' => $sub->fbmode,
            'level' => $instance->examlevel,
            'targetLanguage' => $instance->targetlang,
            'feedbackLanguage' => str_replace('_', '-', manager::feedback_language($instance)),
            'draft' => (int)$sub->attempt,
            'task' => [
                'title' => (string)$task->title,
                'situation' => (string)$task->situation,
                'sourceText' => (string)$task->sourcetext,
                'points' => $points,
                'formality' => (string)$task->formality,
                'targetWords' => (int)$task->targetwords,
            ],
            'tips' => array_map(
                fn($t) => ['id' => $t['id'], 'name' => $t['name'], 'tip' => $t['tip'],
                'example' => $t['example'],
                'level' => $t['source']],
                $merged['tips']
            ),
            'patterns' => tips::patterns($instance->targetlang),
            'scoring' => ['criteria' => manager::CRITERIA, 'bands' => manager::BANDS, 'examScore' => true],
            'options' => ['markGood' => (bool)$instance->showgood],
            'answer' => ['text' => self::answer_text($sub)],
        ];
        if ($sub->attempt > 1) {
            $previous = $DB->get_records(
                'aispeakwrite_sub',
                ['taskid' => $sub->taskid, 'userid' => $sub->userid, 'status' => 'marked'],
                'attempt DESC',
                'id, content, kind',
                0,
                1
            );
            if ($previous) {
                $body['answer']['previousText'] = self::answer_text(reset($previous));
            }
        }
        if ($task->kind === 'speaking') {
            $body['conversation'] = self::turns($sub);
            $body['partnerBrief'] = (string)$task->partnerbrief;
            $metrics = json_decode((string)$sub->metrics, true) ?: [];
            $body['speech'] = [
                'seconds' => (int)($metrics['seconds'] ?? 0),
                'speakingSeconds' => (int)($metrics['speakingseconds'] ?? 0),
                'longPauses' => (int)($metrics['longpauses'] ?? 0),
                'wordsPerMinute' => (int)($metrics['wpm'] ?? 0),
            ];
        }
        if (!empty($instance->sendhistory)) {
            $body['history'] = memory::history((int)$instance->id, (int)$sub->userid);
        }
        return $body;
    }

    /**
     * Marks a submission: re-uses an identical earlier result, otherwise sends one paid request.
     *
     * @param \stdClass $instance
     * @param \stdClass $task
     * @param \stdClass $sub draft or pending submission
     * @param \context_module $context
     * @return \stdClass the marked submission
     */
    public static function mark(\stdClass $instance, \stdClass $task, \stdClass $sub, \context_module $context): \stdClass {
        global $DB;
        if (!in_array($sub->status, ['draft', 'pending', 'failed'], true)) {
            throw new \moodle_exception('alreadymarked', 'mod_aispeakwrite');
        }
        $text = self::answer_text($sub);
        if (manager::words($text) < manager::min_words($task)) {
            throw new \moodle_exception('tooshort', 'mod_aispeakwrite', '', manager::min_words($task));
        }
        $merged = tips::merged($instance, $task);
        $hash = hash('sha256', $sub->fbmode . '|' . $merged['version'] . '|' . $text);

        // The same text, marked before in the same mode with the same tips, gets the stored result at no charge.
        if ($sub->status !== 'pending') {
            $same = $DB->get_records_select(
                'aispeakwrite_sub',
                "taskid = :taskid AND userid = :userid AND status = 'marked'
                AND contenthash = :hash AND id <> :id",
                ['taskid' => $sub->taskid, 'userid' => $sub->userid, 'hash' => $hash, 'id' => $sub->id],
                'id DESC',
                '*',
                0,
                1
            );
            if ($same) {
                $same = reset($same);
                if ($sub->attempt > $same->attempt) {
                    $DB->delete_records('aispeakwrite_sub', ['id' => $sub->id]);
                }
                return $same;
            }
            if (!manager::can_start_draft($instance, (int)$task->id, (int)$sub->userid)) {
                throw new \moodle_exception('norewritesleft', 'mod_aispeakwrite');
            }
            $left = manager::markings_left($instance, (int)$sub->userid);
            if ($left !== null && $left <= 0) {
                throw new \moodle_exception('dailylimitreached', 'mod_aispeakwrite');
            }
        }

        $op = operation::claim(
            (int)$instance->id,
            (int)$sub->userid,
            'mark',
            (int)$sub->id,
            self::body($instance, $task, $sub, $merged)
        );
        $sub->status = 'pending';
        $sub->contenthash = $hash;
        $sub->tipsversion = $merged['version'];
        $sub->timemodified = time();
        $DB->update_record('aispeakwrite_sub', $sub);

        // When no answer arrives, request() throws and the request stays pending with the same key and body, so the
        // learner's next try cannot be charged twice.
        [$status, $response, , $headers] = remote::request(remote::MARK, operation::body($op), $op->idemkey);
        if ($status !== 200) {
            if (remote::outcome($status, $response) === 'refused') {
                $DB->set_field('aispeakwrite_sub', 'status', 'draft', ['id' => $sub->id]);
            }
            operation::not_delivered($op, $status, $response, $headers);
        }
        $data = json_decode($response, true);
        $credits = is_array($data) && isset($data['creditsCharged']) && is_numeric($data['creditsCharged'])
            ? (int)$data['creditsCharged'] : 1;
        $feedback = is_array($data) && is_array($data['result'] ?? null)
            ? self::normalise($data['result'], $text, $task, $merged['tips'], $instance->targetlang) : null;
        operation::complete($op, $credits);
        if ($feedback === null) {
            $DB->set_field('aispeakwrite_sub', 'status', 'failed', ['id' => $sub->id]);
            throw new \moodle_exception(
                'unusableresult',
                'mod_aispeakwrite',
                '',
                clean_param((string)($data['requestId'] ?? $op->idemkey), PARAM_ALPHANUMEXT)
            );
        }
        $sub->status = 'marked';
        $sub->feedback = json_encode($feedback, JSON_UNESCAPED_UNICODE);
        $sub->score = $feedback['score'];
        $sub->examscore = $feedback['examscore'];
        $sub->maxscore = $feedback['maxscore'];
        $sub->timemarked = time();
        $sub->timemodified = $sub->timemarked;
        $DB->update_record('aispeakwrite_sub', $sub);

        memory::record((int)$instance->id, (int)$sub->userid, $feedback, $merged['tips']);
        self::after_marking($instance, $sub, $context);
        return $sub;
    }

    /**
     * Grades, completion and the event after a marking.
     *
     * @param \stdClass $instance
     * @param \stdClass $sub
     * @param \context_module $context
     */
    public static function after_marking(\stdClass $instance, \stdClass $sub, \context_module $context): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/aispeakwrite/lib.php');
        aispeakwrite_update_grades($instance, (int)$sub->userid);
        $cm = get_coursemodule_from_instance('aispeakwrite', $instance->id, $instance->course, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $instance->course], '*', MUST_EXIST);
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm) && !empty($instance->completionmarked)) {
            $completion->update_state($cm, COMPLETION_COMPLETE, (int)$sub->userid);
        }
        \mod_aispeakwrite\event\submission_marked::create(
            [
                'objectid' => $sub->id,
                'context' => $context,
                'relateduserid' => $sub->userid,
                'other' => ['taskid' => (int)$sub->taskid, 'attempt' => (int)$sub->attempt],
            ]
        )->trigger();
    }

    /**
     * Checks and cleans what LMS Labs returned.
     *
     * @param array $r the "result" object
     * @param string $text the text that was marked
     * @param \stdClass $task
     * @param array $tips merged tips
     * @param string|null $targetlang the language being learned: only its patterns may be cited (all when null)
     * @return array|null null when nothing usable came back
     */
    public static function normalise(array $r, string $text, \stdClass $task, array $tips, ?string $targetlang = null): ?array {
        $ruleids = array_column($tips, 'id');
        foreach ($targetlang === null ? tips::all_pattern_names() : tips::pattern_names($targetlang) as $p) {
            $ruleids[] = 'grammar:' . $p;
        }
        $rule = fn($id) => is_string($id) && in_array($id, $ruleids, true) ? $id : '';

        $criteria = [];
        $score = 0;
        $examscore = 0;
        foreach (manager::CRITERIA as $key) {
            $c = is_array($r['criteria'][$key] ?? null) ? $r['criteria'][$key] : null;
            if ($c === null) {
                return null;
            }
            $strict = manager::band($c['atg'] ?? $c['strict'] ?? 0);
            $exam = manager::band($c['telc'] ?? $c['exam'] ?? $strict);
            $exam = max($exam, $strict);
            $criteria[$key] = ['strict' => $strict, 'exam' => $exam, 'reason' => self::clean($c['reason'] ?? '', 600)];
            $score += $strict;
            $examscore += $exam;
        }

        $known = [];
        foreach (tips::points($task->points) as $p) {
            $known[$p['id']] = $p;
        }
        $points = [];
        foreach ((array)($r['points'] ?? []) as $p) {
            if (!is_array($p) || !isset($known[$p['id'] ?? '']) || isset($points[$p['id']])) {
                continue;
            }
            $status = in_array($p['status'] ?? '', self::POINT_STATUS, true) ? $p['status'] : 'covered';
            $points[$p['id']] = [
                'id' => $p['id'], 'n' => $known[$p['id']]['n'], 'text' => $known[$p['id']]['text'], 'status' => $status,
                'quote' => self::clean($p['quote'] ?? '', 300), 'comment' => self::clean($p['comment'] ?? '', 600),
                'phrases' => self::strings($p['phrases'] ?? [], 3, 160),
            ];
        }
        ksort($points, SORT_NATURAL);

        $marks = [];
        $taken = [];
        $raw = array_slice((array)($r['marks'] ?? []), 0, 80);
        foreach ($raw as $m) {
            if (!is_array($m) || !in_array($m['type'] ?? '', self::MARK_TYPES, true)) {
                continue;
            }
            $quote = (string)($m['text'] ?? '');
            [$start, $end] = self::locate($text, $quote, $m['start'] ?? null, $m['end'] ?? null, $taken);
            if ($start === null) {
                continue;
            }
            $taken[] = [$start, $end];
            $marks[] = [
                'type' => $m['type'],
                'start' => $start,
                'end' => $end,
                'text' => \core_text::substr($text, $start, $end - $start),
                'correction' => self::clean($m['correction'] ?? '', 400),
                'severity' => in_array($m['severity'] ?? '', self::SEVERITY, true) ? $m['severity'] : 'major',
                'ruleid' => $rule($m['ruleId'] ?? $m['ruleid'] ?? null),
                'hint' => self::clean($m['hint'] ?? '', 400),
                'why' => self::clean($m['why'] ?? '', 600),
                'rule' => self::clean($m['rule'] ?? '', 400),
                'examples' => self::strings($m['examples'] ?? [], 3, 200),
            ];
            if (count($marks) >= self::MAXMARKS) {
                break;
            }
        }
        usort($marks, fn($a, $b) => $a['start'] <=> $b['start']);
        if (($task->kind ?? '') === 'speaking') {
            foreach ($marks as &$mark) {
                if ($mark['type'] === 'error' && self::fixed_in_speech($text, $mark)) {
                    // The learner said it wrong, then right: a self-correction, never a mistake.
                    $mark['type'] = 'selfcorrection';
                    $mark['severity'] = 'minor';
                }
            }
            unset($mark);
        }
        $n = 0;
        foreach ($marks as &$mark) {
            $mark['n'] = $mark['type'] === 'error' ? ++$n : 0;
        }
        unset($mark);

        $improve = [];
        foreach (array_slice((array)($r['communication']['improve'] ?? []), 0, 4) as $i) {
            if (!is_array($i)) {
                continue;
            }
            $rewrites = [];
            foreach (array_slice((array)($i['rewrites'] ?? []), 0, 3) as $w) {
                if (is_array($w) && self::clean($w['text'] ?? '', 300) !== '') {
                    $rewrites[] = ['ruleid' => $rule($w['ruleId'] ?? null), 'text' => self::clean($w['text'], 300)];
                }
            }
            $improve[] = ['ruleid' => $rule($i['ruleId'] ?? null), 'quote' => self::clean($i['quote'] ?? '', 300),
                'tip' => self::clean($i['tip'] ?? '', 500), 'rewrites' => $rewrites];
        }

        $vocab = [];
        foreach (array_slice((array)($r['vocabulary'] ?? []), 0, 4) as $v) {
            if (is_array($v) && self::clean($v['word'] ?? '', 60) !== '') {
                $vocab[] = ['word' => self::clean($v['word'], 60), 'count' => max(0, (int)($v['count'] ?? 0)),
                    'alternatives' => self::strings($v['alternatives'] ?? [], 4, 60)];
            }
        }

        $rulehits = [];
        foreach ((array)($r['ruleHits'] ?? []) as $h) {
            if (is_array($h) && $rule($h['ruleId'] ?? null) !== '') {
                $rulehits[] = ['ruleid' => $h['ruleId'], 'ok' => !empty($h['ok'])];
            }
        }

        return [
            'summary' => self::clean($r['summary'] ?? '', 800),
            'understood' => array_key_exists('understood', $r) ? (bool)$r['understood'] : null,
            'criteria' => $criteria,
            'score' => $score,
            'examscore' => $examscore,
            'maxscore' => 15 * count(manager::CRITERIA),
            'points' => array_values($points),
            'marks' => $marks,
            'strengths' => self::strings($r['communication']['strengths'] ?? [], 4, 300),
            'improve' => $improve,
            'vocabulary' => $vocab,
            'rulehits' => $rulehits,
            'focus' => array_values(array_filter(array_map($rule, array_slice((array)($r['focus'] ?? []), 0, 3)))),
            'fluency' => self::clean($r['fluency']['advice'] ?? ($r['fluency'] ?? ''), 500),
            'pronunciation' => self::pronunciation($r['pronunciation'] ?? []),
        ];
    }

    /**
     * Whether a speaking mistake was put right by the learner straight away.
     *
     * True when the learner's own words right after the slip (or after a restart inside the marked text, such as
     * "mit meine … mit meiner") already contain the correct form.
     *
     * @param string $text the learner's transcript
     * @param array $mark a located mark with start, end, text and correction
     * @return bool
     */
    public static function fixed_in_speech(string $text, array $mark): bool {
        $norm = fn(string $s) => trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', \core_text::strtolower($s)));
        $correction = $norm($mark['correction']);
        if ($correction === '' || $correction === $norm($mark['text'])) {
            return false;
        }
        // A restart inside the marked words: what comes after the hesitation.
        $parts = preg_split('/(?:…|\.\.\.|\s-\s|\b(?:' . self::HESITATIONS . ')\b)/iu', $mark['text']);
        if (count($parts) > 1 && str_contains(' ' . $norm(end($parts)) . ' ', ' ' . $correction . ' ')) {
            return true;
        }
        // The learner's next words, up to the end of the sentence.
        $after = \core_text::substr($text, $mark['end'], 120);
        $after = preg_split('/[.!?\n]/u', $after)[0] ?? '';
        return str_contains(' ' . $norm($after) . ' ', ' ' . $correction . ' ');
    }

    /**
     * Finds where a mark's text is in the answer, checking any offsets the AI gave.
     *
     * @param string $text the answer
     * @param string $quote the marked text
     * @param mixed $start
     * @param mixed $end
     * @param array $taken ranges already used
     * @return array [start, end] in characters, or [null, null]
     */
    public static function locate(string $text, string $quote, $start, $end, array $taken): array {
        if ($quote === '' || \core_text::strlen($quote) > 400) {
            return [null, null];
        }
        $len = \core_text::strlen($quote);
        $free = function (int $s, int $e) use ($taken): bool {
            foreach ($taken as [$ts, $te]) {
                if ($s < $te && $e > $ts) {
                    return false;
                }
            }
            return true;
        };
        if (
            is_numeric($start) && (int)$start >= 0 && \core_text::substr($text, (int)$start, $len) === $quote
                && $free((int)$start, (int)$start + $len)
        ) {
            return [(int)$start, (int)$start + $len];
        }
        $offset = 0;
        while (($pos = \core_text::strpos($text, $quote, $offset)) !== false) {
            if ($free($pos, $pos + $len)) {
                return [$pos, $pos + $len];
            }
            $offset = $pos + 1;
        }
        return [null, null];
    }

    /**
     * Words to practise saying.
     *
     * @param mixed $list
     * @return array[]
     */
    protected static function pronunciation($list): array {
        $out = [];
        foreach (array_slice((array)$list, 0, 4) as $p) {
            if (is_array($p) && self::clean($p['word'] ?? '', 60) !== '') {
                $out[] = ['word' => self::clean($p['word'], 60), 'tip' => self::clean($p['tip'] ?? '', 200)];
            }
        }
        return $out;
    }

    /**
     * Plain text, length-limited.
     *
     * @param mixed $value
     * @param int $max characters
     * @return string
     */
    public static function clean($value, int $max): string {
        if (!is_scalar($value)) {
            return '';
        }
        $value = clean_param((string)$value, PARAM_NOTAGS);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        return trim(\core_text::substr($value, 0, $max));
    }

    /**
     * A short list of plain strings.
     *
     * @param mixed $list
     * @param int $count
     * @param int $max
     * @return string[]
     */
    protected static function strings($list, int $count, int $max): array {
        $out = [];
        foreach ((array)$list as $item) {
            $s = self::clean($item, $max);
            if ($s !== '') {
                $out[] = $s;
            }
            if (count($out) >= $count) {
                break;
            }
        }
        return $out;
    }

    /**
     * The AI partner's next turn in a speaking conversation.
     *
     * @param \stdClass $instance
     * @param \stdClass $task
     * @param \stdClass $sub
     * @param array $turns conversation so far
     * @return array ['reply' => string, 'done' => bool]
     */
    public static function converse(\stdClass $instance, \stdClass $task, \stdClass $sub, array $turns): array {
        $clean = [];
        foreach (array_slice($turns, 0, 40) as $turn) {
            $text = self::clean($turn['text'] ?? '', 2000);
            if ($text !== '' && in_array($turn['role'] ?? '', ['learner', 'partner'], true)) {
                $clean[] = ['role' => $turn['role'], 'text' => $text];
            }
        }
        // An empty conversation asks the partner to open it; otherwise the learner must have spoken last.
        if ($clean && end($clean)['role'] !== 'learner') {
            throw new \moodle_exception('nothingheard', 'mod_aispeakwrite');
        }
        $merged = tips::merged($instance, $task);
        $body = [
            'taskType' => $task->tasktype,
            'level' => $instance->examlevel,
            'targetLanguage' => $instance->targetlang,
            'task' => ['title' => (string)$task->title, 'situation' => (string)$task->situation,
                'sourceText' => (string)$task->sourcetext,
                'points' => array_column(tips::points($task->points), 'text')],
            'partnerBrief' => (string)$task->partnerbrief,
            'tips' => array_map(fn($t) => ['name' => $t['name'], 'tip' => $t['tip']], $merged['tips']),
            'turns' => $clean,
        ];
        $op = operation::claim((int)$instance->id, (int)$sub->userid, 'converse', (int)$sub->id * 1000 + count($clean), $body);
        [$status, $response, , $headers] = remote::request(remote::CONVERSE, operation::body($op), $op->idemkey, 30);
        if ($status !== 200) {
            operation::not_delivered($op, $status, $response, $headers);
        }
        $data = json_decode($response, true);
        $credits = is_array($data) && is_numeric($data['creditsCharged'] ?? null) ? (int)$data['creditsCharged'] : 0;
        operation::complete($op, $credits);
        $reply = is_array($data) ? self::clean($data['reply'] ?? '', 1000) : '';
        if ($reply === '') {
            throw new \moodle_exception('unusableresult', 'mod_aispeakwrite', '', '-');
        }
        return ['reply' => $reply, 'done' => !empty($data['done'])];
    }
}
