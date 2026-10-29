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

namespace mod_aispeakwrite\output;

use mod_aispeakwrite\local\integrity;
use mod_aispeakwrite\local\manager;
use mod_aispeakwrite\local\memory;
use mod_aispeakwrite\local\marker;
use mod_aispeakwrite\local\submissions;
use mod_aispeakwrite\local\tips;

/**
 * Template data for the learner screens.
 *
 * Text reaches templates raw and is escaped by Mustache ({{ }}); only lines() builds HTML (escaped with s())
 * for the few {{{ }}} fields.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class views {
    /** @var string Plugin name for strings. */
    private const P = 'mod_aispeakwrite';

    /**
     * Teacher-entered plain text as HTML with line breaks.
     *
     * @param string|null $text
     * @return string
     */
    public static function lines(?string $text): string {
        return nl2br(s(trim((string)$text)));
    }

    /**
     * Percent to a status chip.
     *
     * @param float|null $percent
     * @return array ['status', 'statuslabel', 'statusclass']
     */
    public static function status(?float $percent): array {
        if ($percent === null) {
            return ['status' => 'open', 'statuslabel' => get_string('status_open', self::P), 'statusclass' => 'neutral'];
        }
        if ($percent >= 80) {
            return ['status' => 'done', 'statuslabel' => get_string('status_done', self::P), 'statusclass' => 'good'];
        }
        return ['status' => 'keep', 'statuslabel' => get_string('status_keep', self::P), 'statusclass' => 'warn'];
    }

    /**
     * A score as "27 / 45 (telc 33)".
     *
     * @param \stdClass $instance
     * @param \stdClass $sub
     * @return string
     */
    public static function score_label(\stdClass $instance, \stdClass $sub): string {
        $a = ['score' => format_float((float)$sub->score, 0), 'max' => format_float((float)$sub->maxscore, 0),
            'exam' => format_float((float)$sub->examscore, 0)];
        return get_string(!empty($instance->showtelc) ? 'scorewithexam' : 'scoreonly', self::P, $a);
    }

    /**
     * Ring geometry for a percentage (circumference 2πr with r = 54, or 21 for small rings).
     *
     * @param float|null $percent
     * @param float $r
     * @return string stroke-dasharray value
     */
    public static function dash(?float $percent, float $r = 54): string {
        $c = 2 * M_PI * $r;
        $on = $percent === null ? 0 : max(0, min(100, $percent)) / 100 * $c;
        return round($on, 1) . ' ' . round($c, 1);
    }

    /**
     * The learner dashboard.
     *
     * @param \stdClass $instance
     * @param \cm_info|\stdClass $cm
     * @param int $userid
     * @return array
     */
    public static function dashboard(\stdClass $instance, $cm, int $userid): array {
        global $DB, $USER;
        $aid = (int)$instance->id;
        $tasks = manager::tasks_for_user($instance, $cm, $userid);
        $best = submissions::best_by_task($aid, $userid);
        $subs = submissions::for_user($aid, $userid);

        $hour = (int)userdate(time(), '%H');
        $greetkey = $hour < 12 ? 'greeting_morning' : ($hour < 18 ? 'greeting_afternoon' : 'greeting_evening');
        $data = [
            'cmid' => $cm->id,
            'ondashboard' => true,
            'greeting' => get_string($greetkey, self::P, (string)($USER->firstname)),
            'practiceurl' => (new \moodle_url('/mod/aispeakwrite/practice.php', ['id' => $cm->id]))->out(false),
        ];
        if (!empty($instance->examdate) && $instance->examdate > time()) {
            $weeks = (int)ceil(($instance->examdate - time()) / WEEKSECS);
            $data['examline'] = get_string('examin', self::P, ['level' => (string)($instance->examlevel), 'weeks' => $weeks]);
        }
        $left = manager::markings_left($instance, $userid);
        if ($left !== null) {
            $data['markingsleft'] = get_string('markingsleft', self::P, ['left' => $left, 'total' => $instance->dailylimit]);
        }

        // Readiness: average best strict score across tasks with a marked answer.
        $readiness = $best ? round(array_sum($best) / count($best)) : null;
        $data['readiness'] = $readiness === null ? '–' : $readiness . '%';
        $data['readinessdash'] = self::dash($readiness);
        $data['hasreadiness'] = $readiness !== null;
        if ($readiness !== null) {
            $data['readinesstag'] = get_string($readiness >= 60 ? 'ontrack' : 'focus', self::P);
            $data['readinessgood'] = $readiness >= 60;
            $data['readinesstext'] = get_string('readinessbased', self::P, count($best));
        }

        // Continue: an open draft, or a marked first draft that can still be rewritten.
        foreach ($subs as $sub) {
            if (!isset($tasks[$sub->taskid])) {
                continue;
            }
            $task = $tasks[$sub->taskid];
            if (
                $sub->status === 'marked' && manager::can_start_draft($instance, (int)$task->id, $userid)
                    && $sub->fbmode === 'think'
            ) {
                $fb = submissions::feedback($sub);
                $errors = count(array_filter($fb['marks'] ?? [], fn($m) => $m['type'] === 'error'));
                $data['continue'] = [
                    'title' => format_string($task->title),
                    'meta' => get_string('continue_rewrite', self::P, $sub->attempt),
                    'tag' => get_string('rewritewaiting', self::P),
                    'hints' => get_string('hintscount', self::P, $errors),
                    'url' => self::task_url($cm, $task),
                    'button' => get_string('continuerewriting', self::P),
                ];
                break;
            }
        }
        if (empty($data['continue'])) {
            $draft = $DB->get_records_select(
                'aispeakwrite_sub',
                "aispeakwriteid = :aid AND userid = :userid
                AND status = 'draft' AND kind = 'writing'",
                ['aid' => $aid, 'userid' => $userid],
                'timemodified DESC',
                '*',
                0,
                1
            );
            $draft = $draft ? reset($draft) : null;
            if ($draft && isset($tasks[$draft->taskid]) && trim((string)$draft->content) !== '') {
                $task = $tasks[$draft->taskid];
                $data['continue'] = [
                    'title' => format_string($task->title),
                    'meta' => get_string('continue_draft', self::P, manager::words((string)$draft->content)),
                    'tag' => get_string('draftsaved', self::P),
                    'url' => self::task_url($cm, $task),
                    'button' => get_string('continuewriting', self::P),
                ];
            }
        }

        // Recommended next: assigned and not done (soonest due), then anything not started.
        $next = null;
        foreach ($tasks as $task) {
            if (isset($best[$task->id])) {
                continue;
            }
            if (
                $next === null || ($task->assigned && !$next->assigned)
                    || ($task->assigned && $next->assigned && $task->due && (!$next->due || $task->due < $next->due))
            ) {
                $next = $task;
            }
        }
        if ($next) {
            $meta = get_string('tasktype_' . $next->tasktype, self::P);
            if ($next->due) {
                $meta .= ' · ' . get_string('duedate', self::P, userdate($next->due, get_string('strftimedaydate', 'langconfig')));
            }
            $data['next'] = [
                'title' => format_string($next->title),
                'meta' => $meta,
                'speaking' => $next->kind === 'speaking',
                'url' => self::task_url($cm, $next),
                'button' => get_string($next->kind === 'speaking' ? 'startspeaking' : 'startwriting', self::P),
            ];
        }

        // Skills: latest marked answer per task, averaged per criterion.
        $latest = [];
        foreach ($subs as $sub) {
            if ($sub->status === 'marked' && !isset($latest[$sub->taskid])) {
                $latest[$sub->taskid] = $sub;
            }
        }
        $sums = ['writing' => [], 'speaking' => []];
        $understood = [0, 0];
        $selfcorrections = 0;
        foreach ($latest as $sub) {
            $fb = submissions::feedback($sub);
            foreach (manager::CRITERIA as $key) {
                if (isset($fb['criteria'][$key])) {
                    $sums[$sub->kind][$key][] = $fb['criteria'][$key]['strict'];
                }
            }
            if ($sub->kind === 'speaking' && $fb['understood'] !== null) {
                $understood[0] += $fb['understood'] ? 1 : 0;
                $understood[1]++;
            }
        }
        foreach ($subs as $sub) {
            if ($sub->status === 'marked' && $sub->kind === 'speaking' && $sub->timemarked > time() - 30 * DAYSECS) {
                $fb = submissions::feedback($sub);
                $selfcorrections += count(array_filter($fb['marks'] ?? [], fn($m) => $m['type'] === 'selfcorrection'));
            }
        }
        $skills = [];
        foreach (['writing', 'speaking'] as $kind) {
            $rows = [];
            foreach (manager::CRITERIA as $key) {
                if (empty($sums[$kind][$key])) {
                    continue;
                }
                $pct = (int)round(array_sum($sums[$kind][$key]) / count($sums[$kind][$key]) / 15 * 100);
                $rows[] = self::skill(manager::criterion_label($kind, $key, $instance->targetlang), $pct);
            }
            if ($kind === 'speaking' && $understood[1]) {
                array_unshift(
                    $rows,
                    self::skill(
                        get_string('understood', self::P),
                        (int)round($understood[0] / $understood[1] * 100)
                    )
                );
            }
            if ($rows) {
                $skills[] = ['label' => get_string('kind_' . $kind, self::P), 'rows' => $rows];
            }
        }
        $data['skills'] = $skills;
        $data['hasskills'] = !empty($skills);
        if ($selfcorrections) {
            $data['selfcorrections'] = get_string('selfcorrectionsmonth', self::P, $selfcorrections);
        }

        // Focus areas from the learner's memory.
        $focus = [];
        foreach (memory::for_user($aid, $userid) as $p) {
            if ($p['total'] < 2 || $p['ok'] === $p['total']) {
                continue;
            }
            $focus[] = [
                'label' => (string)($p['label']),
                'type' => get_string('ptype_' . $p['type'], self::P),
                'recentpct' => self::recent_percent($p['recent']),
                'line' => get_string('rightofrecent', self::P, ['ok' => $p['ok'], 'total' => $p['total']]),
            ];
            if (count($focus) >= 4) {
                break;
            }
        }
        $data['focus'] = $focus;
        $data['hasfocus'] = !empty($focus);

        // Recent results.
        $recent = [];
        foreach (array_slice($subs, 0, 6) as $sub) {
            if (!isset($tasks[$sub->taskid])) {
                continue;
            }
            $recent[] = self::result_row($instance, $cm, $tasks[$sub->taskid], $sub);
        }
        $data['results'] = $recent;
        $data['hasresults'] = !empty($recent);
        $data['notasks'] = empty($tasks);
        return $data;
    }

    /**
     * One row in a results list.
     *
     * @param \stdClass $instance
     * @param \cm_info|\stdClass $cm
     * @param \stdClass $task
     * @param \stdClass $sub
     * @return array
     */
    public static function result_row(\stdClass $instance, $cm, \stdClass $task, \stdClass $sub): array {
        $row = [
            'title' => format_string($task->title),
            'meta' => get_string('tasktype_' . $task->tasktype, self::P) . ' · ' .
                userdate($sub->timemodified, get_string('strftimedatefullshort', 'langconfig')) . ' · ' .
                get_string('draftn', self::P, $sub->attempt),
            'speaking' => $sub->kind === 'speaking',
        ];
        if ($sub->status === 'marked') {
            $pct = $sub->maxscore > 0 ? $sub->score / $sub->maxscore * 100 : 0;
            $row['score'] = self::score_label($instance, $sub);
            $row['pillclass'] = $pct >= 60 ? 'good' : ($pct >= 40 ? 'warn' : 'bad');
            $row['url'] = (new \moodle_url('/mod/aispeakwrite/feedback.php', ['id' => $cm->id, 'sub' => $sub->id]))->out(false);
        } else {
            $row['score'] = get_string('status_' . $sub->status, self::P);
            $row['pillclass'] = 'neutral';
            $row['url'] = self::task_url($cm, $task);
        }
        return $row;
    }

    /**
     * A skill bar.
     *
     * @param string $label
     * @param int $pct
     * @return array
     */
    protected static function skill(string $label, int $pct): array {
        return ['label' => $label, 'pct' => $pct, 'good' => $pct >= 60,
            'tag' => get_string($pct >= 60 ? 'ontrack' : 'focus', self::P)];
    }

    /**
     * Share of the recent results of a pattern that were right.
     *
     * @param string $recent '1' right, '0' missed, newest last
     * @return int 0-100
     */
    public static function recent_percent(string $recent): int {
        $recent = preg_replace('/[^01]/', '', $recent);
        if ($recent === '') {
            return 0;
        }
        return (int)round(substr_count($recent, '1') * 100 / strlen($recent));
    }

    /**
     * The screen a task opens on.
     *
     * @param \cm_info|\stdClass $cm
     * @param \stdClass $task
     * @return string
     */
    public static function task_url($cm, \stdClass $task): string {
        $page = $task->kind === 'speaking' ? 'speak.php' : 'write.php';
        return (new \moodle_url('/mod/aispeakwrite/' . $page, ['id' => $cm->id, 'task' => $task->id]))->out(false);
    }

    /**
     * The practice library.
     *
     * @param \stdClass $instance
     * @param \cm_info|\stdClass $cm
     * @param int $userid
     * @param string $kind writing or speaking
     * @return array
     */
    public static function practice(\stdClass $instance, $cm, int $userid, string $kind): array {
        $tasks = manager::tasks_for_user($instance, $cm, $userid);
        $best = submissions::best_by_task((int)$instance->id, $userid);
        $types = [];
        $cards = [];
        $i = 0;
        foreach ($tasks as $task) {
            if ($task->kind !== $kind) {
                continue;
            }
            $pct = $best[$task->id] ?? null;
            $type = $task->tasktype;
            if (!isset($types[$type])) {
                $types[$type] = ['name' => get_string('tasktype_' . $type, self::P),
                    'desc' => get_string('tasktype_' . $type . '_desc', self::P), 'done' => 0, 'total' => 0, 'sum' => [],
                    'url' => self::task_url($cm, $task)];
            }
            $types[$type]['total']++;
            if ($pct !== null) {
                $types[$type]['done']++;
                $types[$type]['sum'][] = $pct;
            }
            $cards[] = array_merge(
                [
                    'title' => format_string($task->title),
                    'topic' => (string)($task->topic !== '' ? $task->topic : get_string('tasktype_' . $type, self::P)),
                    'sub' => get_string('tasktype_' . $type, self::P),
                    'best' => $pct === null ? '' : get_string('bestpct', self::P, (int)round($pct)),
                    'due' => $task->due ? userdate($task->due, get_string('strftimedateshort', 'langconfig')) : '',
                    'assigned' => !empty($task->assigned) && $task->due,
                    'band' => 'band' . ($i++ % 6),
                    'url' => self::task_url($cm, $task),
                ],
                self::status($pct)
            );
        }
        $typecards = [];
        foreach ($types as $type) {
            $m = $type['sum'] ? array_sum($type['sum']) / count($type['sum']) : null;
            $typecards[] = [
                'name' => $type['name'], 'desc' => $type['desc'], 'url' => $type['url'],
                'done' => $type['done'] . ' / ' . $type['total'],
                'mastery' => $m === null ? '–' : (int)round($m),
                'dash' => self::dash($m, 21), 'good' => $m !== null && $m >= 60,
            ];
        }
        $base = new \moodle_url('/mod/aispeakwrite/practice.php', ['id' => $cm->id]);
        return [
            'cmid' => $cm->id,
            'onpractice' => true,
            'tabs' => [
                [
                    'label' => get_string('kind_writing', self::P),
                    'url' => (new \moodle_url($base, ['kind' => 'writing']))->out(false),
                    'active' => $kind === 'writing',
                ],
                ['label' => get_string('kind_speaking', self::P),
                    'url' => (new \moodle_url($base, ['kind' => 'speaking']))->out(false), 'active' => $kind === 'speaking'],
            ],
            'types' => $typecards,
            'cards' => $cards,
            'hascards' => !empty($cards),
            'listtitle' => get_string($kind === 'writing' ? 'writingprompts' : 'speakingtopics', self::P),
        ];
    }

    /**
     * The task panel shared by the writing and speaking screens.
     *
     * @param \stdClass $task
     * @return array
     */
    public static function task_panel(\stdClass $task): array {
        $points = [];
        foreach (tips::points($task->points) as $p) {
            $points[] = ['n' => $p['n'], 'text' => (string)($p['text']),
                'min' => $p['mincount'] > 1 ? get_string('atleast', self::P, $p['mincount']) : ''];
        }
        return [
            'title' => format_string($task->title),
            'typelabel' => get_string('tasktype_' . $task->tasktype, self::P),
            'situation' => self::lines($task->situation),
            'hassituation' => trim((string)$task->situation) !== '',
            'sourcetext' => self::lines($task->sourcetext),
            'hassource' => trim((string)$task->sourcetext) !== '',
            'points' => $points,
            'haspoints' => !empty($points),
            'targetwords' => (int)$task->targetwords,
            'guide' => self::lines($task->guide),
            'hasguide' => trim((string)$task->guide) !== '',
        ];
    }

    /**
     * The writing screen.
     *
     * @param \stdClass $instance
     * @param \cm_info|\stdClass $cm
     * @param \stdClass $task
     * @param \stdClass $sub
     * @return array
     */
    public static function writer(\stdClass $instance, $cm, \stdClass $task, \stdClass $sub): array {
        $merged = tips::merged($instance, $task);
        $checks = [];
        foreach (array_slice($merged['tips'], 0, 8) as $tip) {
            $checks[] = ['name' => (string)($tip['name']), 'tip' => (string)($tip['tip'])];
        }
        $groups = [];
        foreach (tips::phrases($task->phrases) as $g) {
            $groups[] = ['name' => (string)($g['name']), 'items' => array_map(fn($i) => ['text' => (string)($i)], $g['items'])];
        }
        $left = manager::markings_left($instance, (int)$sub->userid);
        $minutes = max(5, (int)$task->timelimit);
        $read = max(1, (int)round($minutes * 0.1));
        $plan = max(1, (int)round($minutes * 0.17));
        $check = max(1, (int)round($minutes * 0.17));
        $write = max(1, $minutes - $read - $plan - $check);
        return array_merge(
            self::task_panel($task),
            [
                'cmid' => $cm->id,
                'subid' => $sub->id,
                'content' => (string)$sub->content,
                'notes' => (string)$sub->notes,
                'words' => manager::words((string)$sub->content),
                'pending' => $sub->status === 'pending',
                'draftn' => get_string('draftn', self::P, $sub->attempt),
                'modelabel' => get_string('fbmode_' . $sub->fbmode, self::P),
                'modeexplain' => get_string('fbmode_' . $sub->fbmode . '_desc', self::P),
                'checks' => $checks,
                'haschecks' => !empty($checks),
                'groups' => $groups,
                'hasgroups' => !empty($groups),
                'keys' => array_map(fn($k) => ['key' => $k], self::keys($instance->targetlang)),
                'haskeys' => !empty(self::keys($instance->targetlang)),
                'markingsleft' => $left === null ? '' : get_string(
                    'markingsleft',
                    self::P,
                    ['left' => $left, 'total' => $instance->dailylimit]
                ),
                'nomarkings' => $left !== null && $left <= 0 && $sub->status !== 'pending',
                'minutes' => $minutes,
                'phases' => [
                    ['label' => get_string('phase_read', self::P), 'min' => $read, 'phase' => 'read'],
                    ['label' => get_string('phase_plan', self::P), 'min' => $plan, 'phase' => 'plan'],
                    ['label' => get_string('phase_write', self::P), 'min' => $write, 'phase' => 'write'],
                    ['label' => get_string('phase_check', self::P), 'min' => $check, 'phase' => 'check'],
                ],
                'backurl' => (new \moodle_url('/mod/aispeakwrite/practice.php', ['id' => $cm->id]))->out(false),
                'lang' => $instance->targetlang,
                'pastenotice' => get_string('pastenotice_' . integrity::paste_mode($instance), self::P),
            ]
        );
    }

    /**
     * Special characters for the target language's keyboard row.
     *
     * @param string $lang
     * @return string[]
     */
    public static function keys(string $lang): array {
        $base = substr($lang, 0, 2);
        $map = [
            'de' => ['ä', 'ö', 'ü', 'ß', 'Ä', 'Ö', 'Ü', '„', '“'],
            'fr' => ['é', 'è', 'ê', 'à', 'ç', 'ô', 'î', 'ù', 'œ', '«', '»'],
            'es' => ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü', '¿', '¡'],
            'it' => ['à', 'è', 'é', 'ì', 'ò', 'ù'],
            'pt' => ['á', 'â', 'ã', 'à', 'ç', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú'],
            'nl' => ['é', 'ë', 'ï', 'ó', 'ö', 'ü'],
        ];
        return $map[$base] ?? [];
    }

    /**
     * The speaking screen.
     *
     * @param \stdClass $instance
     * @param \cm_info|\stdClass $cm
     * @param \stdClass $task
     * @param \stdClass $sub
     * @return array
     */
    public static function speaker(\stdClass $instance, $cm, \stdClass $task, \stdClass $sub): array {
        $merged = tips::merged($instance, $task);
        $left = manager::markings_left($instance, (int)$sub->userid);
        $conversation = $task->tasktype !== 'speak1' || trim((string)$task->partnerbrief) !== '';
        return array_merge(
            self::task_panel($task),
            [
                'cmid' => $cm->id,
                'subid' => $sub->id,
                'lang' => $instance->targetlang,
                'pending' => $sub->status === 'pending',
                'tipline' => (string)(implode(' · ', array_slice(array_column($merged['tips'], 'name'), 0, 4))),
                'hastips' => !empty($merged['tips']),
                'conversation' => $conversation,
                'markingsleft' => $left === null ? '' : get_string(
                    'markingsleft',
                    self::P,
                    ['left' => $left, 'total' => $instance->dailylimit]
                ),
                'nomarkings' => $left !== null && $left <= 0 && $sub->status !== 'pending',
                'minutes' => max(1, (int)$task->timelimit),
                'backurl' => self::url('practice.php', ['id' => $cm->id, 'kind' => 'speaking']),
                'config' => json_encode(
                    [
                        'cmid' => (int)$cm->id,
                        'subid' => (int)$sub->id,
                        'locale' => $instance->targetlang,
                        'seconds' => max(60, (int)$task->timelimit * 60),
                        'conversation' => $conversation,
                        'partner' => (bool)get_config('mod_aispeakwrite', 'partner'),
                        'pending' => $sub->status === 'pending',
                    ]
                ),
            ]
        );
    }

    /**
     * The feedback screen.
     *
     * @param \stdClass $instance
     * @param \cm_info|\stdClass $cm
     * @param \stdClass $task
     * @param \stdClass $sub
     * @param bool $own the learner viewing their own answer
     * @return array
     */
    public static function feedback(\stdClass $instance, $cm, \stdClass $task, \stdClass $sub, bool $own): array {
        $fb = submissions::feedback($sub);
        $think = $sub->fbmode === 'think';
        $merged = tips::merged($instance, $task);
        $text = marker::answer_text($sub);
        $showgood = !empty($instance->showgood);

        $marks = [];
        $notes = [];
        $speaking = $sub->kind === 'speaking';
        foreach ($fb['marks'] ?? [] as $i => $m) {
            if ($m['type'] === 'good' && !$showgood) {
                continue;
            }
            $id = 'm' . $i;
            $label = $m['ruleid'] !== '' ? tips::label($m['ruleid'], $merged['tips']) : '';
            $istip = str_starts_with($m['ruleid'], 'tip:');
            $marks[$i] = $m + ['id' => $id];
            $note = [
                'id' => $id,
                'n' => $m['n'],
                'error' => $m['type'] === 'error',
                'good' => $m['type'] === 'good',
                'self' => $m['type'] === 'selfcorrection',
                'tag' => self::note_tag($m, $label, $istip, $speaking),
                'tagclass' => $m['type'] === 'error' ? ($istip ? 'ink' : 'neutral') : 'good',
                'severity' => $m['type'] === 'error' && !$speaking ? get_string('severity_' . $m['severity'], self::P) : '',
                'quote' => (string)($m['text']),
            ];
            if ($m['type'] === 'error' && $think && $own) {
                // When speaking was hard to follow, saying what the listener missed is the hint, not the answer.
                $why = $speaking && $m['severity'] === 'unclear' ? $m['why'] : '';
                $note['head'] = $m['hint'] !== '' ? $m['hint'] : ($why !== '' ? $why : get_string('findit', self::P));
                $note['rule'] = (string)($m['rule']);
                $note['hasreveal'] = $m['rule'] !== '' || !empty($m['examples']);
            } else if ($m['type'] === 'error') {
                $note['head'] = (string)($m['text']) . ' → ' . (string)($m['correction']);
                $note['why'] = (string)($m['why']);
                $note['rule'] = (string)($m['rule']);
                $note['open'] = true;
            } else {
                $note['head'] = $m['why'] !== '' ? $m['why'] : ($m['hint'] !== '' ? $m['hint'] : '„' . $m['text'] . '“');
                $note['open'] = $m['type'] === 'selfcorrection' && $m['correction'] !== '';
                $note['fix'] = (string)($m['correction']);
                $note['canrepeat'] = false;
            }
            $note['examples'] = array_map(fn($e) => ['text' => (string)($e)], $m['examples']);
            $note['hasexamples'] = !empty($m['examples']);
            if ($m['type'] === 'error' && $sub->kind === 'speaking' && $m['correction'] !== '' && $own) {
                $note['canrepeat'] = true;
                $note['target'] = (string)($m['correction']);
            }
            if ($m['ruleid'] !== '' && $own) {
                $hist = memory::for_user((int)$instance->id, (int)$sub->userid);
                foreach ($hist as $h) {
                    if ($h['key'] === $m['ruleid'] && $h['total'] - $h['ok'] >= 2) {
                        $note['memory'] = get_string(
                            'seenbefore',
                            self::P,
                            ['missed' => $h['total'] - $h['ok'], 'total' => $h['total']]
                        );
                    }
                }
            }
            $notes[] = $note;
        }

        $data = [
            'cmid' => $cm->id,
            'lang' => $instance->targetlang,
            'think' => $think,
            'explain' => !$think,
            'speaking' => $sub->kind === 'speaking',
            'writing' => $sub->kind !== 'speaking',
            'title' => format_string($task->title),
            'typelabel' => get_string('tasktype_' . $task->tasktype, self::P),
            'draftn' => get_string('draftn', self::P, $sub->attempt),
            'modelabel' => get_string('fbmode_' . $sub->fbmode, self::P),
            'summary' => (string)($fb['summary'] ?? ''),
            'score' => format_float((float)$sub->score, 0),
            'max' => format_float((float)$sub->maxscore, 0),
            'exam' => format_float((float)$sub->examscore, 0),
            'showtelc' => !empty($instance->showtelc),
            'notes' => $notes,
            'hasnotes' => !empty($notes),
            'errorcount' => count(array_filter($notes, fn($n) => $n['error'])),
            'goodcount' => count(array_filter($notes, fn($n) => $n['good'])),
            'backurl' => self::url('practice.php', ['id' => $cm->id, 'kind' => $sub->kind]),
            'own' => $own,
        ];
        $data['criteria'] = [];
        foreach (manager::CRITERIA as $key) {
            $c = $fb['criteria'][$key] ?? ['strict' => 0, 'exam' => 0, 'reason' => ''];
            $steps = [];
            foreach (manager::BANDS as $band) {
                $steps[] = ['value' => $band, 'on' => $band <= $c['strict'] && $band > 0,
                    'exam' => $band > $c['strict'] && $band <= $c['exam'] && !empty($instance->showtelc),
                    'current' => $band === $c['strict']];
            }
            $data['criteria'][] = [
                'name' => manager::criterion_label($sub->kind, $key, $instance->targetlang),
                'strict' => $c['strict'], 'exam' => $c['exam'], 'reason' => (string)($c['reason']), 'steps' => $steps,
                'showexam' => !empty($instance->showtelc),
            ];
        }

        // The answer with its marks, split into paragraphs (writing) or turns (speaking).
        if ($sub->kind === 'speaking') {
            $data['turns'] = self::turn_segments($sub, $marks, $think && $own);
            if ($fb['understood'] !== null) {
                $data['understood'] = (bool)$fb['understood'];
                $data['notunderstood'] = !$fb['understood'];
            }
            $metrics = json_decode((string)$sub->metrics, true) ?: [];
            $data['flowbars'] = array_map(
                fn($v) => ['h' => 6 + (int)$v * 5, 'pause' => (int)$v === 0],
                $metrics['flow'] ?? []
            );
            $data['hasflow'] = !empty($data['flowbars']);
            $data['longpauses'] = (int)($metrics['longpauses'] ?? 0);
            $data['duration'] = format_time((int)($metrics['seconds'] ?? 0));
            $data['wpm'] = (int)($metrics['wpm'] ?? 0);
            $data['fluency'] = (string)($fb['fluency'] ?? '');
            $data['pronunciation'] = array_map(
                fn($p) => ['word' => (string)($p['word']), 'tip' => (string)($p['tip'])],
                $fb['pronunciation'] ?? []
            );
            $data['haspronunciation'] = !empty($data['pronunciation']);
            $data['selfcount'] = count(array_filter($notes, fn($n) => $n['self']));
            $data['unclearcount'] = count(
                array_filter(
                    $fb['marks'] ?? [],
                    fn($m) => $m['severity'] === 'unclear'
                    && $m['type'] === 'error'
                )
            );
            $data['repeaturl'] = self::task_url($cm, $task);
            $data['speakconfig'] = json_encode(['locale' => $instance->targetlang]);
        } else {
            $data['paragraphs'] = self::paragraphs($text, $marks, $think && $own);
            $data['words'] = manager::words($text);
        }

        $data['points'] = [];
        foreach ($fb['points'] ?? [] as $p) {
            $data['points'][] = [
                'n' => $p['n'],
                'text' => (string)$p['text'],
                'quote' => (string)$p['quote'],
                'comment' => (string)$p['comment'],
                'verdict' => get_string('point_' . $p['status'], self::P),
                'vclass' => in_array($p['status'], ['missing', 'mismatch'], true) ? 'bad'
                    : ($p['status'] === 'short' ? 'warn' : 'good'),
                'missing' => in_array($p['status'], ['missing', 'mismatch'], true),
                'phrases' => array_map(fn($x) => ['text' => (string)($x)], $p['phrases']),
                'hasphrases' => !empty($p['phrases']),
            ];
        }
        $data['haspoints'] = !empty($data['points']);
        $covered = count(array_filter($fb['points'] ?? [], fn($p) => !in_array($p['status'], ['missing', 'mismatch'], true)));
        $data['pointsline'] = get_string('pointscovered', self::P, ['n' => $covered, 'total' => count($fb['points'] ?? [])]);

        $data['strengths'] = array_map(fn($s) => ['text' => (string)($s)], $fb['strengths'] ?? []);
        $data['improve'] = [];
        foreach ($fb['improve'] ?? [] as $i) {
            $data['improve'][] = [
                'quote' => (string)($i['quote']), 'tip' => (string)($i['tip']),
                'rewrites' => array_map(
                    fn($w) => ['label' => $w['ruleid'] !== ''
                    ? (string)(tips::label($w['ruleid'], $merged['tips'])) : '',
                    'text' => (string)($w['text'])],
                    $i['rewrites']
                ),
            ];
        }
        $data['vocabulary'] = array_map(
            fn($v) => ['word' => (string)($v['word']), 'count' => $v['count'],
            'alternatives' => (string)(implode(' · ', $v['alternatives']))],
            $fb['vocabulary'] ?? []
        );
        $data['hascommunication'] = $data['strengths'] || $data['improve'] || $data['vocabulary'];

        if ($own) {
            $canrewrite = manager::can_start_draft($instance, (int)$task->id, (int)$sub->userid)
                && $sub->kind === 'writing';
            $data['canrewrite'] = $canrewrite;
            $data['rewriteurl'] = self::task_url($cm, $task);
            $data['nextmode'] = $canrewrite ? get_string(
                'fbmode_' . manager::mode_for_attempt(
                    $instance,
                    (int)$sub->attempt + 1
                ),
                self::P
            ) : '';
        }
        return $data;
    }

    /**
     * Splits an answer into paragraphs of plain and marked segments.
     *
     * @param string $text
     * @param array $marks validated marks (character offsets into $text)
     * @param bool $think hide corrections
     * @return array[] paragraphs, each ['segments' => array[]]
     */
    public static function paragraphs(string $text, array $marks, bool $think): array {
        $segments = self::segments($text, $marks, $think, 0, \core_text::strlen($text));
        $paragraphs = [['segments' => []]];
        foreach ($segments as $seg) {
            if (!empty($seg['plain'])) {
                $parts = explode("\n", $seg['text']);
                foreach ($parts as $k => $part) {
                    if ($k > 0) {
                        $paragraphs[] = ['segments' => []];
                    }
                    if ($part !== '') {
                        $paragraphs[count($paragraphs) - 1]['segments'][] = ['plain' => true, 'text' => $part];
                    }
                }
            } else {
                $paragraphs[count($paragraphs) - 1]['segments'][] = $seg;
            }
        }
        return array_values(array_filter($paragraphs, fn($p) => !empty($p['segments'])));
    }

    /**
     * Segments of a range of the answer.
     *
     * @param string $text
     * @param array $marks
     * @param bool $think
     * @param int $from
     * @param int $to
     * @return array[]
     */
    protected static function segments(string $text, array $marks, bool $think, int $from, int $to): array {
        $out = [];
        $pos = $from;
        foreach ($marks as $m) {
            if ($m['start'] < $from || $m['end'] > $to) {
                continue;
            }
            if ($m['start'] > $pos) {
                $out[] = ['plain' => true, 'text' => \core_text::substr($text, $pos, $m['start'] - $pos)];
            }
            $out[] = [
                'mark' => true,
                'id' => $m['id'],
                'n' => $m['n'],
                'text' => \core_text::substr($text, $m['start'], $m['end'] - $m['start']),
                'correction' => $think ? '' : $m['correction'],
                'showcorrection' => !$think && $m['type'] === 'error' && $m['correction'] !== '',
                'kind' => $m['type'] === 'error' && $m['severity'] === 'unclear' ? 'unclear' : $m['type'],
                'error' => $m['type'] === 'error',
            ];
            $pos = $m['end'];
        }
        if ($pos < $to) {
            $out[] = ['plain' => true, 'text' => \core_text::substr($text, $pos, $to - $pos)];
        }
        return $out;
    }

    /**
     * Speaking turns with the learner's marks placed in them.
     *
     * @param \stdClass $sub
     * @param array $marks offsets into the learner's turns joined with "\n"
     * @param bool $think
     * @return array[]
     */
    protected static function turn_segments(\stdClass $sub, array $marks, bool $think): array {
        $out = [];
        $offset = 0;
        foreach (marker::turns($sub) as $turn) {
            if ($turn['role'] === 'partner') {
                $out[] = ['partner' => true, 'segments' => [['plain' => true, 'text' => $turn['text']]]];
                continue;
            }
            $len = \core_text::strlen($turn['text']);
            $local = [];
            foreach ($marks as $m) {
                if ($m['start'] >= $offset && $m['end'] <= $offset + $len) {
                    $m['start'] -= $offset;
                    $m['end'] -= $offset;
                    $local[] = $m;
                }
            }
            $out[] = ['learner' => true, 'segments' => self::segments($turn['text'], $local, $think, 0, $len)];
            $offset += $len + 1;
        }
        return $out;
    }

    /**
     * A link to one of this plugin's pages.
     *
     * @param string $page file name, e.g. "manage.php"
     * @param array $params
     * @return string
     */
    protected static function url(string $page, array $params): string {
        return (new \moodle_url('/mod/aispeakwrite/' . $page, $params))->out(false);
    }

    /**
     * The tag shown on a feedback note: what kind of mark it is, or the tip or rule it is about.
     *
     * @param array $m the mark
     * @param string $label the tip or pattern name, or ''
     * @param bool $istip whether the label is one of the school's tips
     * @param bool $speaking
     * @return string
     */
    protected static function note_tag(array $m, string $label, bool $istip, bool $speaking): string {
        if ($m['type'] === 'good') {
            return get_string('scoreswell', self::P);
        }
        if ($m['type'] === 'selfcorrection') {
            return get_string('youfixedit', self::P);
        }
        if ($label !== '') {
            return $istip ? get_string('schooltip', self::P, $label) : $label;
        }
        if (!$speaking) {
            return get_string('legend_fix', self::P);
        }
        return get_string($m['severity'] === 'unclear' ? 'legend_unclear' : 'legend_form', self::P);
    }
}
