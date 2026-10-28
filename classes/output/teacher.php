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

use mod_aispeakwrite\local\manager;
use mod_aispeakwrite\local\memory;
use mod_aispeakwrite\local\operation;
use mod_aispeakwrite\local\remote;

/**
 * Template data for the teacher screens.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class teacher {
    /** @var string Plugin name for strings. */
    private const P = 'mod_aispeakwrite';

    /**
     * The site's credit balance, cached for five minutes.
     *
     * @return array|null
     */
    public static function balance(): ?array {
        $cache = \cache::make('mod_aispeakwrite', 'balance');
        $hit = $cache->get('site');
        if ($hit !== false) {
            return $hit ?: null;
        }
        $balance = remote::balance();
        $cache->set('site', $balance ?? []);
        return $balance;
    }

    /**
     * Credit usage and balance for the cards on the teacher screens.
     *
     * @param \stdClass $instance
     * @return array
     */
    public static function usage(\stdClass $instance): array {
        $monthstart = make_timestamp((int)date('Y'), (int)date('n'), 1);
        $usage = operation::usage($monthstart, (int)$instance->id);
        $balance = self::balance();
        $low = (int)get_config('mod_aispeakwrite', 'lowcredits');
        $balancetext = '';
        if ($balance !== null) {
            $balancetext = $balance['unlimited'] ? get_string('unlimited', self::P) : format_float($balance['credits'], 0);
        }
        $out = [
            'usedmonth' => $usage['credits'],
            'markings' => $usage['mark'],
            'partnerturns' => $usage['converse'],
            'hasbalance' => $balance !== null,
            'balance' => $balancetext,
            'lowcredits' => $balance !== null && !$balance['unlimited'] && $low > 0 && $balance['credits'] < $low,
        ];
        return $out;
    }

    /**
     * The teacher's activity home.
     *
     * @param \stdClass $instance
     * @param \cm_info|\stdClass $cm
     * @param \context_module $context
     * @return array
     */
    public static function home(\stdClass $instance, $cm, \context_module $context): array {
        global $DB;
        $tasks = manager::tasks((int)$instance->id);
        $learners = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT userid) FROM {aispeakwrite_sub}
            WHERE aispeakwriteid = ? AND status = 'marked'",
            [$instance->id]
        );
        return array_merge(
            self::usage($instance),
            [
                'cmid' => $cm->id,
                'onhome' => true,
                'taskcount' => count($tasks),
                'writingcount' => count(array_filter($tasks, fn($t) => $t->kind === 'writing')),
                'speakingcount' => count(array_filter($tasks, fn($t) => $t->kind === 'speaking')),
                'learners' => $learners,
                'canmanage' => has_capability('mod/aispeakwrite:managetasks', $context),
                'canreport' => has_capability('mod/aispeakwrite:viewreports', $context),
                'manageurl' => (new \moodle_url('/mod/aispeakwrite/manage.php', ['id' => $cm->id]))->out(false),
                'analyticsurl' => (new \moodle_url('/mod/aispeakwrite/analytics.php', ['id' => $cm->id]))->out(false),
                'settingsurl' => (new \moodle_url('/course/modedit.php', ['update' => $cm->id]))->out(false),
                'notasks' => !$tasks,
                'credentialsmissing' => \mod_aispeakwrite\local\credentials::find() === null,
            ]
        );
    }

    /**
     * The task list.
     *
     * @param \stdClass $instance
     * @param \cm_info|\stdClass $cm
     * @return array
     */
    public static function manage(\stdClass $instance, $cm): array {
        global $DB;
        $tasks = array_values(manager::tasks((int)$instance->id));
        $counts = $DB->get_records_sql_menu(
            "SELECT taskid, COUNT(1) FROM {aispeakwrite_sub}
            WHERE aispeakwriteid = ? AND status = 'marked' GROUP BY taskid",
            [$instance->id]
        );
        $assigned = $DB->get_records_sql_menu(
            "SELECT tu.taskid, COUNT(1) FROM {aispeakwrite_taskuser} tu
            JOIN {aispeakwrite_task} t ON t.id = tu.taskid WHERE t.aispeakwriteid = ? GROUP BY tu.taskid",
            [$instance->id]
        );
        $rows = [];
        $last = count($tasks) - 1;
        foreach ($tasks as $i => $task) {
            $taskparams = ['id' => $cm->id, 'task' => $task->id];
            $actionparams = $taskparams + ['sesskey' => sesskey()];
            $u = fn($action) => self::url('manage.php', $actionparams + ['action' => $action]);
            $audience = get_string('audience_' . $task->audience, self::P);
            if ($task->audience === 'group' && $task->groupid) {
                $audience .= ': ' . format_string(groups_get_group_name((int)$task->groupid));
            }
            $rows[] = [
                'title' => format_string($task->title),
                'kind' => get_string('kind_' . $task->kind, self::P),
                'speaking' => $task->kind === 'speaking',
                'type' => get_string('tasktype_' . $task->tasktype, self::P),
                'topic' => (string)($task->topic),
                'audience' => $audience,
                'assigned' => (int)($assigned[$task->id] ?? 0),
                'due' => $task->duedate ? userdate($task->duedate, get_string('strftimedateshort', 'langconfig')) : '',
                'answers' => (int)($counts[$task->id] ?? 0),
                'hidden' => empty($task->visible),
                'editurl' => (new \moodle_url('/mod/aispeakwrite/edittask.php', $taskparams))->out(false),
                'testurl' => (new \moodle_url('/mod/aispeakwrite/testmark.php', $taskparams))->out(false),
                'toggleurl' => $u(empty($task->visible) ? 'show' : 'hide'),
                'toggle' => get_string(empty($task->visible) ? 'show' : 'hide'),
                'deleteurl' => $u('delete'),
                'upurl' => $i > 0 ? $u('up') : '',
                'downurl' => $i < $last ? $u('down') : '',
            ];
        }
        return array_merge(
            self::usage($instance),
            [
                'cmid' => $cm->id,
                'onmanage' => true,
                'tasks' => $rows,
                'hastasks' => !empty($rows),
                'addwritingurl' => self::url('edittask.php', ['id' => $cm->id, 'kind' => 'writing']),
                'addspeakingurl' => self::url('edittask.php', ['id' => $cm->id, 'kind' => 'speaking']),
                'analyticsurl' => (new \moodle_url('/mod/aispeakwrite/analytics.php', ['id' => $cm->id]))->out(false),
            ]
        );
    }

    /**
     * Class analytics.
     *
     * @param \stdClass $instance
     * @param \cm_info|\stdClass $cm
     * @param \context_module $context
     * @param int $groupid
     * @param string $selected pattern key to show learners for
     * @return array
     */
    public static function analytics(
        \stdClass $instance,
        $cm,
        \context_module $context,
        int $groupid,
        string $selected
    ): array {
        global $DB;
        $aid = (int)$instance->id;
        $namefields = array_map(fn($f) => 'u.' . $f, \core_user\fields::get_name_fields());
        $users = get_enrolled_users(
            $context,
            'mod/aispeakwrite:practise',
            $groupid,
            'u.id, ' . implode(', ', $namefields),
            'u.lastname, u.firstname'
        );
        $userids = array_keys($users);
        $data = ['cmid' => $cm->id, 'onanalytics' => true, 'learnercount' => count($users)];

        $memory = [];
        $subs = [];
        if ($userids) {
            [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $params['aid'] = $aid;
            $memory = $DB->get_records_select('aispeakwrite_memory', "aispeakwriteid = :aid AND userid $in", $params);
            $params['since'] = time() - 30 * DAYSECS;
            $subs = $DB->get_records_select(
                'aispeakwrite_sub',
                "aispeakwriteid = :aid AND userid $in
                AND status = 'marked' AND timemarked >= :since",
                $params
            );
        }

        // Figures for the last 30 days.
        $writing = array_filter($subs, fn($s) => $s->kind === 'writing');
        $speaking = array_filter($subs, fn($s) => $s->kind === 'speaking');
        $avg = $writing ? array_sum(array_map(fn($s) => (float)$s->score, $writing)) / count($writing) : null;
        $points = [0, 0];
        $understood = [0, 0];
        foreach ($subs as $s) {
            $fb = json_decode((string)$s->feedback, true) ?: [];
            foreach ($fb['points'] ?? [] as $p) {
                $points[1]++;
                $points[0] += in_array($p['status'], ['missing', 'mismatch'], true) ? 0 : 1;
            }
            if ($s->kind === 'speaking' && isset($fb['understood']) && $fb['understood'] !== null) {
                $understood[1]++;
                $understood[0] += $fb['understood'] ? 1 : 0;
            }
        }
        $firsts = array_filter($writing, fn($s) => (int)$s->attempt === 1 && $s->fbmode === 'think');
        $rewrote = 0;
        foreach ($firsts as $s) {
            foreach ($writing as $w) {
                if ($w->taskid === $s->taskid && $w->userid === $s->userid && (int)$w->attempt > 1) {
                    $rewrote++;
                    break;
                }
            }
        }
        $data['kpis'] = [
            ['label' => get_string('kpi_marked', self::P), 'value' => count($writing) . ' / ' . count($speaking),
                'note' => get_string('kpi_marked_note', self::P)],
            ['label' => get_string('kpi_avgscore', self::P), 'value' => $avg === null ? '–' : format_float($avg, 1) . ' / 45',
                'note' => get_string('kpi_strict', self::P)],
            ['label' => get_string('kpi_points', self::P), 'value' => $points[1] ? round($points[0] / $points[1] * 100) . '%'
                : '–', 'note' => get_string('kpi_points_note', self::P)],
            ['label' => get_string('kpi_understood', self::P), 'value' => $understood[1]
                ? round($understood[0] / $understood[1] * 100) . '%' : '–', 'note' => get_string('kpi_speaking', self::P)],
            ['label' => get_string('kpi_rewrites', self::P), 'value' => $firsts ? round($rewrote / count($firsts) * 100) . '%'
                : '–', 'note' => get_string('kpi_rewrites_note', self::P)],
        ];

        // Most common mistakes: how many learners got each tip or pattern wrong in their recent results.
        $bykey = [];
        foreach ($memory as $m) {
            $k = $m->patternkey;
            $bykey[$k] = $bykey[$k] ?? ['label' => memory::display_label($m->patternkey, $m->label), 'type' => $m->ptype,
                'miss' => 0, 'total' => 0, 'users' => []];
            $miss = substr_count($m->recent, '0');
            $bykey[$k]['miss'] += $miss;
            $bykey[$k]['total'] += strlen($m->recent);
            if ($miss >= 1) {
                $bykey[$k]['users'][(int)$m->userid] = $m->recent;
            }
        }
        $issues = [];
        foreach ($bykey as $key => $b) {
            if (!$b['users']) {
                continue;
            }
            $affected = count($b['users']);
            $issues[$key] = $b + ['affected' => $affected, 'key' => $key,
                'pct' => count($users) ? (int)round($affected / count($users) * 100) : 0];
        }
        uasort($issues, fn($a, $b) => [$b['affected'], $b['miss']] <=> [$a['affected'], $a['miss']]);
        $issues = array_slice($issues, 0, 8, true);
        if ($selected === '' || !isset($issues[$selected])) {
            $selected = $issues ? array_key_first($issues) : '';
        }
        $data['issues'] = [];
        foreach ($issues as $key => $i) {
            $data['issues'][] = [
                'label' => (string)($i['label']), 'type' => get_string('ptype_' . $i['type'], self::P), 'pct' => $i['pct'],
                'affected' => $i['affected'], 'learners' => count($users),
                'selected' => $key === $selected,
                'url' => self::url('analytics.php', ['id' => $cm->id, 'group' => $groupid, 'issue' => $key]),
            ];
        }
        $data['hasissues'] = !empty($issues);
        if ($selected !== '') {
            $i = $issues[$selected];
            $people = [];
            foreach ($i['users'] as $uid => $recent) {
                if (!isset($users[$uid])) {
                    continue;
                }
                $people[] = ['name' => fullname($users[$uid]), 'recentpct' => views::recent_percent($recent), 'userid' => $uid,
                    'line' => get_string(
                        'rightofrecent',
                        self::P,
                        ['ok' => substr_count($recent, '1'), 'total' => strlen($recent)]
                    )];
            }
            usort($people, fn($a, $b) => $a['recentpct'] <=> $b['recentpct']);
            $tasks = [];
            foreach (manager::tasks($aid, true) as $t) {
                $tasks[] = ['id' => $t->id, 'name' => format_string($t->title) . ' (' .
                    get_string('tasktype_' . $t->tasktype, self::P) . ')'];
            }
            $data['current'] = [
                'label' => (string)($i['label']), 'type' => get_string('ptype_' . $i['type'], self::P), 'key' => $selected,
                'people' => $people, 'haspeople' => !empty($people),
                'peopleline' => get_string('peoplemissing', self::P, count($people)),
                'userids' => implode(',', array_column($people, 'userid')),
                'tasks' => $tasks, 'hastasks' => !empty($tasks),
                'actionurl' => (new \moodle_url('/mod/aispeakwrite/analytics.php'))->out(false),
                'sesskey' => sesskey(), 'group' => $groupid,
                'defaultdue' => date('Y-m-d', time() + 7 * DAYSECS),
            ];
        }

        // Learners × school tips.
        $tipkeys = [];
        foreach ($bykey as $key => $b) {
            if ($b['type'] === 'tip') {
                $tipkeys[$key] = $b['label'];
            }
        }
        $tipkeys = array_slice($tipkeys, 0, 8, true);
        $grid = [];
        $mem = [];
        foreach ($memory as $m) {
            $mem[(int)$m->userid][$m->patternkey] = $m->recent;
        }
        foreach ($users as $uid => $user) {
            if (!isset($mem[$uid])) {
                continue;
            }
            $cells = [];
            foreach ($tipkeys as $key => $label) {
                $recent = $mem[$uid][$key] ?? '';
                $ok = substr_count($recent, '1');
                $cells[] = ['value' => $recent === '' ? '–' : $ok . '/' . strlen($recent),
                    'state' => $recent === '' ? 'none' : ($ok >= 4 ? 'solid' : ($ok === 3 ? 'mid' : 'focus'))];
            }
            $grid[] = ['name' => fullname($user), 'cells' => $cells];
        }
        $data['tipcols'] = array_map(fn($l) => ['label' => (string)($l)], array_values($tipkeys));
        $data['grid'] = $grid;
        $data['hasgrid'] = $grid && $tipkeys;
        return array_merge(self::usage($instance), $data);
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
}
