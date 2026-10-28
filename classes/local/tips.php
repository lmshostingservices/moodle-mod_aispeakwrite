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
 * The school's own tips and tricks, and the grammar patterns for each language being learned.
 *
 * Tips are entered one per line as "Name: tip | example" at three levels (school, activity, task). They are merged, the
 * most specific level winning when two tips share a name. Each tip gets a stable id ("tip:satzanfang") that the AI
 * must use to cite it; any other id the AI returns is dropped.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tips {
    /** @var int Maximum characters per tips box. */
    public const MAXCHARS = 4000;

    /** @var int Maximum number of tips sent with a request. */
    public const MAXTIPS = 80;

    /** @var string[] Grammar and form patterns for every language (lang string pattern_<name>). */
    public const COMMON_PATTERNS = [
        'conjugation', 'tense', 'article', 'agreement', 'preposition', 'plural', 'pronoun', 'wordorder', 'wordchoice',
        'spelling', 'punctuation', 'register',
    ];

    /** @var string[][] Extra patterns for each language being learned. */
    public const LANGUAGE_PATTERNS = [
        'de' => ['verbsecond', 'verbfinal', 'separableverb', 'modalverb', 'casedative', 'caseaccusative', 'adjectiveending',
            'reflexive', 'capitals'],
        'en' => ['irregularverb', 'phrasalverb', 'questions', 'capitals'],
        'fr' => ['accents', 'pastparticiple', 'subjunctive', 'partitive', 'negation', 'reflexive'],
        'es' => ['serestar', 'porpara', 'pasttense', 'subjunctive', 'accents', 'reflexive'],
        'it' => ['auxiliary', 'articulatedprep', 'pasttense', 'subjunctive', 'accents', 'reflexive'],
        'nl' => ['dehet', 'verbsecond', 'verbfinal', 'separableverb', 'modalverb', 'reflexive'],
        'pt' => ['serestar', 'contraction', 'pronounplacement', 'pasttense', 'subjunctive', 'accents'],
    ];

    /**
     * The pattern names the AI may cite for a language being learned.
     *
     * @param string|null $targetlang
     * @return string[]
     */
    public static function pattern_names(?string $targetlang): array {
        return array_values(array_unique(array_merge(
            self::COMMON_PATTERNS,
            self::LANGUAGE_PATTERNS[manager::language_family($targetlang)] ?? []
        )));
    }

    /**
     * Every pattern name in any language, so feedback marked earlier always has a readable label.
     *
     * @return string[]
     */
    public static function all_pattern_names(): array {
        return array_values(array_unique(array_merge(self::COMMON_PATTERNS, ...array_values(self::LANGUAGE_PATTERNS))));
    }
    /**
     * Parses one tips box.
     *
     * @param string|null $text
     * @param string $source school, activity or task
     * @return array[] each ['id', 'name', 'tip', 'example', 'source']
     */
    public static function parse(?string $text, string $source): array {
        $out = [];
        $n = 0;
        foreach (preg_split('/\R/u', (string)$text) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $n++;
            $name = '';
            $rest = $line;
            $colon = \core_text::strpos($line, ':');
            if ($colon !== false && $colon > 0 && $colon <= 40) {
                $name = trim(\core_text::substr($line, 0, $colon));
                $rest = trim(\core_text::substr($line, $colon + 1));
            }
            $parts = explode('|', $rest, 2);
            $tip = trim($parts[0]);
            if ($tip === '') {
                continue;
            }
            if ($name === '') {
                $name = get_string('tipnumbered', 'mod_aispeakwrite', $n);
            }
            $out[] = [
                'id' => 'tip:' . self::slug($name),
                'name' => \core_text::substr($name, 0, 40),
                'tip' => \core_text::substr($tip, 0, 400),
                'example' => isset($parts[1]) ? \core_text::substr(trim($parts[1]), 0, 200) : '',
                'source' => $source,
            ];
        }
        return $out;
    }

    /**
     * A stable id part for a tip name.
     *
     * @param string $name
     * @return string
     */
    public static function slug(string $name): string {
        $slug = \core_text::strtolower($name);
        $slug = strtr($slug, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        // Other accents (é, ñ, ç, ã …) keep their base letter, so French or Spanish tip names make readable ids too.
        $slug = \core_text::specialtoascii($slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug === '' ? substr(sha1($name), 0, 8) : substr($slug, 0, 40);
    }

    /**
     * The school, activity and task tips merged, most specific first wins.
     *
     * @param \stdClass $instance activity record
     * @param \stdClass|null $task
     * @return array ['tips' => array[], 'version' => string]
     */
    public static function merged(\stdClass $instance, ?\stdClass $task = null): array {
        $levels = [
            self::parse($task->tasktips ?? '', 'task'),
            self::parse($instance->activitytips ?? '', 'activity'),
            self::parse((string)get_config('mod_aispeakwrite', 'schooltips'), 'school'),
        ];
        $byid = [];
        foreach ($levels as $level) {
            foreach ($level as $tip) {
                if (!isset($byid[$tip['id']])) {
                    $byid[$tip['id']] = $tip;
                }
            }
        }
        $tips = array_slice(array_values($byid), 0, self::MAXTIPS);
        return ['tips' => $tips, 'version' => hash('sha256', json_encode($tips, JSON_UNESCAPED_UNICODE))];
    }

    /**
     * The grammar patterns for a language, as sent to LMS Labs.
     *
     * @param string|null $targetlang
     * @return array[] each ['id', 'name']
     */
    public static function patterns(?string $targetlang): array {
        $out = [];
        foreach (self::pattern_names($targetlang) as $p) {
            $out[] = ['id' => 'grammar:' . $p, 'name' => get_string('pattern_' . $p, 'mod_aispeakwrite')];
        }
        return $out;
    }

    /**
     * A readable label for any rule id.
     *
     * @param string $id
     * @param array $tips merged tips
     * @return string
     */
    public static function label(string $id, array $tips): string {
        foreach ($tips as $tip) {
            if ($tip['id'] === $id) {
                return $tip['name'];
            }
        }
        if (str_starts_with($id, 'grammar:')) {
            $p = substr($id, 8);
            if (in_array($p, self::all_pattern_names(), true)) {
                return get_string('pattern_' . $p, 'mod_aispeakwrite');
            }
        }
        if ($id === 'content:points') {
            return get_string('contentpoints', 'mod_aispeakwrite');
        }
        return '';
    }

    /**
     * Phrase groups for the writing screen ("Group: phrase | phrase", one group per line).
     *
     * @param string|null $text
     * @return array[] each ['name', 'items' => string[]]
     */
    public static function phrases(?string $text): array {
        $out = [];
        foreach (preg_split('/\R/u', (string)$text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $name = '';
            $colon = \core_text::strpos($line, ':');
            if ($colon !== false && $colon > 0 && $colon <= 40) {
                $name = trim(\core_text::substr($line, 0, $colon));
                $line = trim(\core_text::substr($line, $colon + 1));
            }
            $items = array_values(array_filter(array_map('trim', explode('|', $line)), fn($s) => $s !== ''));
            if ($items) {
                $out[] = ['name' => $name, 'items' => array_slice($items, 0, 12)];
            }
        }
        return array_slice($out, 0, 8);
    }

    /**
     * Content points of a task ("Point text | 2" sets a minimum count, e.g. two suggestions).
     *
     * @param string|null $text
     * @return array[] each ['id', 'n', 'text', 'mincount']
     */
    public static function points(?string $text): array {
        $out = [];
        foreach (preg_split('/\R/u', (string)$text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line, 2);
            $n = count($out) + 1;
            $out[] = [
                'id' => 'p' . $n,
                'n' => $n,
                'text' => \core_text::substr(trim($parts[0]), 0, 300),
                'mincount' => isset($parts[1]) ? max(1, min(9, (int)trim($parts[1]))) : 1,
            ];
            if ($n >= 8) {
                break;
            }
        }
        return $out;
    }
}
