<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_aispeakwrite\local\byok;

/**
 * The LMS Labs marking service (POST /api/moodle/ai-speak-write/mark), ported to run on the site's own AI.
 *
 * The request checks, the two marking stages (evidence, then scores) with their prompts and response schemas, and every
 * check and repair of the AI's answers are the service's (server/aiSpeakWriteContract.ts, aiSpeakWriteGrading.ts,
 * aiSpeakWriteSchema.ts and aiSpeakWriteProvider.ts); only the calls to the AI go to the site's own AI through the hook
 * below. The behaviour was compared with the service's code on generated requests and AI answers.
 *
 * Values from JSON are held as js::parse() returns them: objects are \stdClass and arrays are lists.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {
    /** @var callable|null fn(string $system, string $user, int $maxtokens, float $temperature, array $schema): string */
    public static $text = null;

    /** @var int The service's max_tokens for each stage. */
    const MAX_TOKENS = 4000;
    /** @var int The largest result the service delivers, in UTF-8 bytes of its JSON. */
    const RESULT_MAX_BYTES = 65536;
    /** @var int[] The score bands. */
    const BANDS = [0, 3, 9, 15];
    /** @var string[] Task point statuses. */
    const STATUSES = ['covered', 'extended', 'short', 'missing', 'mismatch'];
    /** @var string[] Mark severities. */
    const SEVERITIES = ['minor', 'major', 'unclear'];
    /** @var string[] Mark types. */
    const MARK_TYPES = ['error', 'good', 'selfcorrection'];
    /** @var string What the folded scoring request calls the evidence stage's answer (an assistant turn at the service). */
    const EVIDENCE_LABEL = 'Previous assistant message (the validated evidence):';

    /**
     * Mark one answer as the service's mark route does.
     *
     * @param string $json The request body the plugin sends to LMS Labs.
     * @return array [HTTP status, body as the service answers (creditsCharged and result, or error)]
     */
    public static function mark(string $json): array {
        try {
            $body = js::parse($json);
        } catch (\Throwable $e) {
            return [422, self::refusal('INVALID_INPUT')];
        }
        if (!self::valid_input($body)) {
            return [422, self::refusal('INVALID_INPUT')];
        }
        try {
            $raw = self::generate($body);
            $result = self::validate_result($raw, $body);
            $out = js::stringify($result);
            if (strlen($out) > self::RESULT_MAX_BYTES) {
                throw new \RuntimeException('Invalid result size');
            }
        } catch (\Throwable $e) {
            return [502, self::refusal('INVALID_PROVIDER_RESULT', $e->getMessage())];
        }
        return [200, ['creditsCharged' => 0, 'result' => json_decode($out, true)]];
    }

    /**
     * The service's refusal body.
     *
     * @param string $code
     * @param string $reason Why (for the site's logs; the service logs it).
     * @return array
     */
    protected static function refusal(string $code, string $reason = ''): array {
        return ['error' => ['code' => $code, 'message' => strtolower(str_replace('_', ' ', $code)), 'reason' => $reason]];
    }

    /**
     * The service's two-stage marking (OpenAISpeakWriteProvider::generate()).
     *
     * The service sends each stage's instructions as a developer message and, for the scores, the evidence as an
     * earlier assistant message. The site's AI takes one system and one user message, so the instructions follow the
     * system prompt and the evidence follows the request, labelled as the earlier answer.
     *
     * @param \stdClass $input
     * @return \stdClass The result before the route's own check.
     */
    protected static function generate(\stdClass $input): \stdClass {
        $schemas = self::stage_schemas($input);
        $user = "Mark this answer. Request:\n" . js::stringify($input);
        $raw = self::complete('language_evidence', $schemas['evidence'], prompts::SYSTEM . "\n\n" . prompts::EVIDENCE, $user);
        [$assessment, $evidence, $pointevidence] = self::validate_evidence($raw, $input);
        $haserrors = false;
        $needs = [];
        foreach ($evidence->marks as $mark) {
            $haserrors = $haserrors || $mark->type === 'error';
            if ($mark->type === 'selfcorrection' && (!isset($mark->why) || js::trim($mark->why) === '')) {
                $needs[] = $mark;
            }
        }
        $allcovered = true;
        foreach ($evidence->points as $point) {
            $allcovered = $allcovered && in_array($point->status, ['covered', 'extended'], true);
        }
        $scoring = self::scoring_schema(
            $schemas['criteria'],
            $assessment->formAssessable,
            $haserrors,
            $input->feedbackLanguage,
            array_map(fn($m) => $m->text, $needs),
            $allcovered
        );
        $previous = new \stdClass();
        foreach (js::keys($evidence) as $key) {
            if ($key !== 'summary') {
                $previous->$key = $evidence->$key;
            }
        }
        $previous->assessment = $assessment;
        $previous->pointEvidence = $pointevidence;
        $scores = self::complete(
            'language_scores',
            $scoring,
            prompts::SYSTEM . "\n\n" . prompts::SCORING,
            $user . "\n\n" . self::EVIDENCE_LABEL . "\n" . js::stringify($previous)
        );
        if (
            !($scores instanceof \stdClass) || count(get_object_vars($scores)) !== 3
            || !property_exists($scores, 'criteria') || !property_exists($scores, 'summary')
            || !property_exists($scores, 'correctionFeedback') || !is_array($scores->correctionFeedback)
        ) {
            throw new \RuntimeException('Invalid scoring envelope');
        }
        $feedback = $scores->correctionFeedback;
        $texts = [];
        foreach ($feedback as $item) {
            if ($item === null) {
                throw new \RuntimeException('Invalid provider result');
            }
            $text = $item instanceof \stdClass && property_exists($item, 'text') ? $item->text : null;
            $texts[] = $text === null ? "\0undefined" : js::stringify($text) . gettype($text);
        }
        if (count($feedback) !== count($needs) || count(array_unique($texts)) !== count($needs)) {
            throw new \RuntimeException('Missing correction feedback');
        }
        foreach ($needs as $missing) {
            $note = null;
            foreach ($feedback as $item) {
                if (
                    $item instanceof \stdClass && js::get($item, 'text') === $missing->text
                    && is_string(js::get($item, 'why')) && js::trim($item->why) !== ''
                ) {
                    $note = $item;
                    break;
                }
            }
            if ($note === null) {
                throw new \RuntimeException('Invalid correction feedback');
            }
            $missing->why = $note->why;
        }
        $raw = new \stdClass();
        foreach (js::keys($evidence) as $key) {
            $raw->$key = $key === 'summary' ? $scores->summary : $evidence->$key;
        }
        $raw->criteria = $scores->criteria;
        $result = self::validate_result($raw, $input);
        if (!$assessment->formAssessable || !$haserrors) {
            $expected = $assessment->formAssessable ? 15 : 0;
            if ($result->criteria->form->atg !== $expected || $result->criteria->form->telc !== $expected) {
                throw new \RuntimeException('Unsupported form score');
            }
        }
        if (!$allcovered && ($result->criteria->task->atg === 15 || $result->criteria->task->telc === 15)) {
            throw new \RuntimeException('Unsupported full task score');
        }
        return $result;
    }

    /**
     * One structured request to the site's AI, read as the service reads OpenAI's answer.
     *
     * @param string $name The schema's name.
     * @param array $schema
     * @param string $system
     * @param string $user
     * @return mixed The parsed answer without null values.
     */
    protected static function complete(string $name, array $schema, string $system, string $user) {
        $text = (self::$text)($system, $user, self::MAX_TOKENS, 0.0, ['name' => $name, 'schema' => $schema]);
        return self::omit_null(js::parse($text));
    }

    /**
     * Drop null values from objects, as the service does to OpenAI's strict answers.
     *
     * @param mixed $value
     * @return mixed
     */
    protected static function omit_null($value) {
        if (is_array($value)) {
            return array_map([self::class, 'omit_null'], $value);
        }
        if ($value instanceof \stdClass) {
            $out = new \stdClass();
            foreach (get_object_vars($value) as $k => $v) {
                if ($v !== null) {
                    $out->$k = self::omit_null($v);
                }
            }
            return $out;
        }
        return $value;
    }

    // Request checks (speakWriteInput).

    /**
     * Whether a request passes the service's input schema.
     *
     * @param mixed $v
     * @return bool
     */
    public static function valid_input($v): bool {
        $str = fn($s, int $max) => is_string($s) && js::len($s) <= $max;
        $ne = fn($s, int $max) => $str($s, $max) && js::trim($s) !== '';
        $int = fn($n, int $min, int $max) => js::is_integer($n) && $n >= $min && $n <= $max;
        $num = fn($n, int $max) => (is_int($n) || is_float($n)) && $n >= 0 && $n <= $max;
        $list = fn($a, int $max) => is_array($a) && count($a) <= $max;
        $has = fn($o, string $k) => property_exists($o, $k);
        if (
            !self::strict($v, ['kind', 'taskType', 'mode', 'level', 'targetLanguage', 'feedbackLanguage', 'draft', 'task',
            'tips', 'patterns', 'scoring', 'options', 'answer'], ['history', 'test', 'conversation', 'partnerBrief', 'speech'])
        ) {
            return false;
        }
        if (
            !in_array($v->kind, ['writing', 'speaking'], true)
            || !in_array($v->taskType, ['email', 'translation', 'free', 'speak1', 'speak2', 'speak3'], true)
            || !in_array($v->mode, ['think', 'explain'], true) || !$ne($v->level, 100)
            || !$ne($v->targetLanguage, 50) || !$ne($v->feedbackLanguage, 50) || !$int($v->draft, 1, 1000)
        ) {
            return false;
        }
        $task = $v->task;
        if (
            !self::strict($task, ['title', 'situation', 'sourceText', 'points', 'formality', 'targetWords'])
            || !$str($task->title, 1000) || !$str($task->situation, 12000) || !$str($task->sourceText, 20000)
            || !$list($task->points, 50) || !$str($task->formality, 100) || !$int($task->targetWords, 0, 20000)
        ) {
            return false;
        }
        foreach ($task->points as $p) {
            if (
                !self::strict($p, ['id', 'text', 'minCount']) || !$ne($p->id, 150) || !$ne($p->text, 4000)
                || !$int($p->minCount, 1, 100)
            ) {
                return false;
            }
        }
        if (!$list($v->tips, 100) || !$list($v->patterns, 100)) {
            return false;
        }
        foreach ($v->tips as $t) {
            if (
                !self::strict($t, ['id', 'name', 'tip', 'example', 'level']) || !$ne($t->id, 150) || !$ne($t->name, 300)
                || !$str($t->tip, 4000) || !$str($t->example, 4000) || !$str($t->level, 100)
            ) {
                return false;
            }
        }
        foreach ($v->patterns as $p) {
            if (!self::strict($p, ['id', 'name']) || !$ne($p->id, 150) || !$ne($p->name, 300)) {
                return false;
            }
        }
        $scoring = $v->scoring;
        if (
            !self::strict($scoring, ['criteria', 'bands', 'examScore']) || $scoring->criteria !== ['task', 'communication', 'form']
            || !is_array($scoring->bands) || count($scoring->bands) !== 4 || !is_bool($scoring->examScore)
        ) {
            return false;
        }
        foreach (self::BANDS as $i => $band) {
            $b = $scoring->bands[$i] ?? null;
            if (!(is_int($b) || is_float($b)) || $b != $band) {
                return false;
            }
        }
        if (!self::strict($v->options, ['markGood']) || !is_bool($v->options->markGood)) {
            return false;
        }
        $answer = $v->answer;
        if (
            !self::strict($answer, ['text'], ['previousText']) || !$ne($answer->text, 30000)
            || ($has($answer, 'previousText') && !$str($answer->previousText, 30000))
        ) {
            return false;
        }
        if ($has($v, 'history')) {
            if (!$list($v->history, 200)) {
                return false;
            }
            foreach ($v->history as $h) {
                if (
                    !self::strict($h, ['id', 'name', 'recent']) || !$ne($h->id, 150) || !$str($h->name, 300)
                    || !$str($h->recent, 100)
                ) {
                    return false;
                }
            }
        }
        if ($has($v, 'test') && !is_bool($v->test)) {
            return false;
        }
        if ($has($v, 'conversation')) {
            if (!$list($v->conversation, 100)) {
                return false;
            }
            foreach ($v->conversation as $turn) {
                if (
                    !self::strict($turn, ['role', 'text']) || !in_array($turn->role, ['partner', 'learner'], true)
                    || !$str($turn->text, 10000)
                ) {
                    return false;
                }
            }
        }
        if ($has($v, 'partnerBrief') && !$str($v->partnerBrief, 8000)) {
            return false;
        }
        if ($has($v, 'speech')) {
            $s = $v->speech;
            if (
                !self::strict($s, ['seconds', 'speakingSeconds', 'longPauses', 'wordsPerMinute']) || !$num($s->seconds, 86400)
                || !$num($s->speakingSeconds, 86400) || !$int($s->longPauses, 0, 10000) || !$num($s->wordsPerMinute, 2000)
            ) {
                return false;
            }
        }
        // The service's cross-field checks.
        if (($v->kind === 'speaking') !== str_starts_with($v->taskType, 'speak')) {
            return false;
        }
        $ids = array_merge(array_column($v->tips, 'id'), array_column($v->patterns, 'id'));
        $pointids = array_map(fn($p) => $p->id, $task->points);
        if (count(array_unique($ids)) !== count($ids) || count(array_unique($pointids)) !== count($pointids)) {
            return false;
        }
        if ($v->kind === 'speaking' && $has($v, 'conversation')) {
            $learner = array_filter($v->conversation, fn($t) => $t->role === 'learner');
            if ($answer->text !== implode("\n", array_map(fn($t) => $t->text, $learner))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a value is an object with all required keys and no others (a strict zod object).
     *
     * @param mixed $o
     * @param string[] $required
     * @param string[] $optional
     * @return bool
     */
    protected static function strict($o, array $required, array $optional = []): bool {
        if (!($o instanceof \stdClass)) {
            return false;
        }
        $keys = array_map('strval', array_keys(get_object_vars($o)));
        return !array_diff($keys, $required, $optional) && !array_diff($required, $keys);
    }

    // Response schemas (aiSpeakWriteSchema.ts and aiSpeakWriteGrading.ts).

    /**
     * The service's base response schema (speakWriteToolSchema).
     *
     * @return array
     */
    protected static function tool_schema(): array {
        $str = ['type' => 'string'];
        $bool = ['type' => 'boolean'];
        $arr = fn(array $items) => ['type' => 'array', 'items' => $items];
        $obj = fn(array $properties, ?array $required = null) => ['type' => 'object', 'properties' => $properties,
            'required' => $required ?? array_keys($properties), 'additionalProperties' => false];
        $ruleid = ['type' => ['string', 'null']];
        $band = ['type' => 'integer', 'enum' => self::BANDS];
        $criterion = $obj(['atg' => $band, 'telc' => $band, 'reason' => $str]);
        return $obj([
            'summary' => $str, 'understood' => $bool,
            'criteria' => $obj(['task' => $criterion, 'communication' => $criterion, 'form' => $criterion]),
            'points' => $arr($obj([
                'id' => $str, 'status' => ['type' => 'string', 'enum' => self::STATUSES],
                'quote' => $str, 'comment' => $str, 'phrases' => $arr($str),
            ])),
            'marks' => $arr($obj([
                'type' => ['type' => 'string', 'enum' => self::MARK_TYPES], 'text' => $str,
                'start' => ['type' => 'integer'], 'end' => ['type' => 'integer'], 'correction' => $str,
                'severity' => ['type' => 'string', 'enum' => self::SEVERITIES],
                'ruleId' => $ruleid, 'hint' => $str, 'why' => $str, 'rule' => $str, 'examples' => $arr($str),
            ], ['type', 'text'])),
            'communication' => $obj([
                'strengths' => $arr($str),
                'improve' => $arr($obj(['ruleId' => $ruleid, 'quote' => $str, 'tip' => $str,
                    'rewrites' => $arr($obj(['ruleId' => $ruleid, 'text' => $str], ['text']))], ['quote', 'tip', 'rewrites'])),
            ]),
            'vocabulary' => $arr($obj(['word' => $str, 'count' => ['type' => 'integer'], 'alternatives' => $arr($str)])),
            'ruleHits' => $arr($obj(['ruleId' => $str, 'ok' => $bool])), 'focus' => $arr($str),
            'fluency' => $obj(['advice' => $str]), 'pronunciation' => $arr($obj(['word' => $str, 'tip' => $str])),
        ], ['summary', 'criteria', 'points', 'marks', 'communication', 'vocabulary', 'ruleHits', 'focus']);
    }

    /**
     * OpenAI's strict form: optional properties become nullable and every property is required (strictSpeakWriteSchema).
     *
     * @param array $schema
     * @return array
     */
    public static function strict_schema(array $schema): array {
        if (!empty($schema['anyOf'])) {
            $schema['anyOf'] = array_map([self::class, 'strict_schema'], $schema['anyOf']);
            return $schema;
        }
        if (($schema['type'] ?? null) === 'array') {
            $schema['items'] = self::strict_schema($schema['items']);
            return $schema;
        }
        if (($schema['type'] ?? null) !== 'object') {
            return $schema;
        }
        $required = $schema['required'] ?? [];
        $properties = [];
        foreach ($schema['properties'] as $key => $value) {
            $converted = self::strict_schema($value);
            $properties[$key] = in_array($key, $required, true) ? $converted : ['anyOf' => [$converted, ['type' => 'null']]];
        }
        $schema['properties'] = $properties;
        $schema['required'] = array_map('strval', array_keys($properties));
        return $schema;
    }

    /**
     * The evidence stage's schema and the criteria for the scoring stage (speakWriteStageSchemas).
     *
     * @param \stdClass $request
     * @return array ['evidence' => array, 'criteria' => array]
     */
    public static function stage_schemas(\stdClass $request): array {
        $schema = self::tool_schema();
        $feedback = "Write in feedbackLanguage={$request->feedbackLanguage}, not the target language.";
        $schema['properties']['summary'] = ['type' => 'string', 'description' => $feedback];
        foreach (['task', 'communication', 'form'] as $name) {
            $schema['properties']['criteria']['properties'][$name]['properties']['reason'] =
                ['type' => 'string', 'description' => $feedback];
        }
        $schema['properties']['criteria']['properties']['task']['description'] =
            'Respect each task point\'s minCount. Count only distinct relevant ideas.';
        $schema['properties']['criteria']['properties']['form']['description'] =
            'Judge actual uncorrected errors, not successful self-corrections or stylistic variety.';
        $exactquote = 'One exact contiguous substring of answer.text, preserving punctuation and spacing. ' .
            'Never join separate sentences or invent a quote.';
        $schema['properties']['points']['items']['properties']['quote'] = ['type' => 'string', 'description' => $exactquote];
        $schema['properties']['points']['items']['properties']['comment'] = ['type' => 'string', 'description' => $feedback];
        $schema['properties']['communication']['properties']['improve']['items']['properties']['quote'] =
            ['type' => 'string', 'description' => $exactquote];
        $schema['properties']['communication']['properties']['improve']['items']['properties']['tip'] =
            ['type' => 'string', 'description' => $feedback];
        $ruleids = array_merge(array_map(fn($t) => $t->id, $request->tips), array_map(fn($p) => $p->id, $request->patterns));
        $schema['properties']['focus'] = $ruleids
            ? ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $ruleids], 'maxItems' => 3]
            : ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 0];
        $mark = $schema['properties']['marks']['items'];
        $mark['properties']['text'] = ['type' => 'string', 'description' => $exactquote];
        unset($mark['properties']['start'], $mark['properties']['end']);
        $variants = [];
        foreach (self::MARK_TYPES as $type) {
            $variant = $mark;
            $variant['properties']['type'] = ['type' => 'string', 'enum' => [$type]];
            if ($type === 'good') {
                $variant['properties']['text']['description'] = 'A short, exact contiguous learner-text quotation showing a ' .
                    'distinct supported strength; do not overlap an error quote.';
                $variant['properties']['why'] = ['type' => 'string', 'minLength' => 1,
                    'description' => "{$feedback} Explain the evidenced strength without inventing praise."];
                $variant['properties']['ruleId']['description'] =
                    'Use only a relevant sent teacher-tip or pattern ID; omit when none applies.';
            }
            if ($type === 'error') {
                array_push($variant['required'], 'correction', 'why', 'rule', 'examples', 'hint');
                $descriptions = [
                    'correction' => 'Replace only the quoted uncorrected error span in the target language; ' .
                        'do not rewrite surrounding text.',
                    'why' => "{$feedback} Claim a repeated mistake only when supplied history supports that exact rule ID.",
                    'rule' => $feedback,
                    'hint' => "{$feedback} Point to the rule without revealing the answer.",
                ];
                foreach (['correction', 'why', 'rule', 'hint'] as $name) {
                    $variant['properties'][$name] = ['type' => 'string', 'minLength' => 1, 'description' => $descriptions[$name]];
                }
                $variant['properties']['text']['description'] = 'The smallest exact contiguous substring containing this ' .
                    'distinct error, normally one to three words. A whole sentence is allowed only for a word-order error ' .
                    'that cannot be represented more narrowly.';
                $variant['properties']['examples'] = ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2,
                    'maxItems' => 3];
            }
            $variants[] = $variant;
        }
        $schema['properties']['marks']['items'] = ['anyOf' => $variants];
        if ($request->kind !== 'speaking') {
            unset($schema['properties']['understood'], $schema['properties']['fluency'], $schema['properties']['pronunciation']);
        } else {
            array_push($schema['required'], 'understood', 'fluency', 'pronunciation');
        }
        $criteria = $schema['properties']['criteria'];
        unset($schema['properties']['criteria']);
        $schema['required'] = array_values(array_filter($schema['required'], fn($k) => $k !== 'criteria'));
        $schema['properties']['assessment'] = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['formAssessable', 'reason'],
            'properties' => ['formAssessable' => ['type' => 'boolean'],
                'reason' => ['type' => 'string', 'minLength' => 1, 'description' => $feedback]],
        ];
        $schema['required'][] = 'assessment';
        $item = function (?\stdClass $point) use ($request, $exactquote, $feedback): array {
            $text = $point ? js::stringify($point->text) : null;
            return [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'ideas'],
                'description' => $point
                    ? "Assess only this task point: {$text}. It requires " . js::number($point->minCount) .
                        ' distinct relevant idea(s).'
                    : 'Assess the exact task point identified by id.',
                'properties' => [
                    'id' => ['type' => 'string',
                        'enum' => $point ? [$point->id] : array_map(fn($p) => $p->id, $request->task->points)],
                    'ideas' => ['type' => 'array', 'maxItems' => 100,
                        'items' => ['type' => 'object', 'additionalProperties' => false,
                            'required' => ['quote', 'fulfilsPoint', 'reason'],
                            'properties' => [
                                'quote' => ['type' => 'string', 'minLength' => 1, 'description' => $exactquote],
                                'fulfilsPoint' => ['type' => 'boolean',
                                    'description' => 'True only when the quoted idea fulfils ' .
                                        ($point ? $text : 'this exact task point') . '. An unrelated information question, ' .
                                        'attendance statement or closing does not supply an offer of help.'],
                                'reason' => ['type' => 'string', 'minLength' => 1,
                                    'description' => "{$feedback} Explain what the quoted idea actually does and whether that " .
                                        'fulfils this task point, not merely whether it is relevant to the event.'],
                            ]],
                    ],
                ],
            ];
        };
        $schema['properties']['pointEvidence'] = [
            'type' => 'array', 'maxItems' => 50,
            'description' => 'One entry for each task point, quoting each distinct, relevant idea separately. ' .
                'An information request is not a second offer to help.',
            'items' => $request->task->points
                ? ['anyOf' => array_map(fn($p) => $item($p), $request->task->points)]
                : $item(null),
        ];
        $schema['required'][] = 'pointEvidence';
        return ['evidence' => self::strict_schema($schema), 'criteria' => $criteria];
    }

    /**
     * The scoring stage's schema, which allows only the scores the evidence supports (speakWriteScoringSchema).
     *
     * @param array $criteria
     * @param bool $formassessable
     * @param bool $haserrors
     * @param string $feedbacklanguage
     * @param string[] $corrections Self-corrections that still need an explanation.
     * @param bool $allcovered
     * @return array
     */
    public static function scoring_schema(
        array $criteria,
        bool $formassessable,
        bool $haserrors,
        string $feedbacklanguage,
        array $corrections,
        bool $allcovered
    ): array {
        if (!$allcovered) {
            foreach (['atg', 'telc'] as $name) {
                $criteria['properties']['task']['properties'][$name] = ['type' => 'integer', 'enum' => [0, 3, 9]];
            }
        }
        if (!$formassessable || !$haserrors) {
            $band = $formassessable ? 15 : 0;
            foreach (['atg', 'telc'] as $name) {
                $criteria['properties']['form']['properties'][$name] = ['type' => 'integer', 'enum' => [$band]];
            }
        }
        $n = count($corrections);
        return self::strict_schema([
            'type' => 'object', 'additionalProperties' => false, 'required' => ['criteria', 'summary', 'correctionFeedback'],
            'properties' => [
                'criteria' => $criteria,
                'summary' => ['type' => 'string', 'minLength' => 1,
                    'description' => "Summarise validated evidence only in feedbackLanguage={$feedbacklanguage}. " .
                        'Never call a successful self-correction an error.'],
                'correctionFeedback' => [
                    'type' => 'array', 'minItems' => $n, 'maxItems' => $n,
                    'description' => 'For the self-corrections whose explanations are missing, praise the successful ' .
                        "correction in feedbackLanguage={$feedbacklanguage}. These are not errors.",
                    'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['text', 'why'],
                        'properties' => [
                            'text' => ['type' => 'string', 'enum' => $n ? array_values($corrections) : ['']],
                            'why' => ['type' => 'string', 'minLength' => 1,
                                'description' => 'Explain this successful self-correction in ' .
                                    "feedbackLanguage={$feedbacklanguage}. " .
                                    'Never call it an error.'],
                        ]],
                ],
            ],
        ]);
    }

    // Checks and repairs of the AI's answers (aiSpeakWriteContract.ts).

    /**
     * A string trimmed, or null when it is not a string or is blank.
     *
     * @param mixed $value
     * @return string|null
     */
    protected static function trimmed($value): ?string {
        return is_string($value) && js::trim($value) !== '' ? js::trim($value) : null;
    }

    /**
     * Feedback text: trimmed and cut to 4000 characters, or null.
     *
     * @param mixed $value
     * @return string|null
     */
    protected static function feedback_text($value): ?string {
        $clean = self::trimmed($value);
        if ($clean !== null && js::len($clean) > 4000) {
            return js::slice($clean, 0, 4000);
        }
        return $clean;
    }

    /**
     * A list of clean texts.
     *
     * @param mixed $value
     * @param int $limit
     * @param int $max Longest text kept.
     * @return string[]
     */
    protected static function text_array($value, int $limit, int $max = 4000): array {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $clean = self::trimmed($item);
            if ($clean === null || js::len($clean) > $max) {
                continue;
            }
            if (count($out) < $limit) {
                $out[] = $clean;
            }
        }
        return $out;
    }

    /**
     * A rule ID kept only when it was sent.
     *
     * @param mixed $value
     * @param array $sent
     * @return string|null
     */
    protected static function rule($value, array $sent): ?string {
        return is_string($value) && isset($sent[$value]) ? $value : null;
    }

    /**
     * The tip and pattern IDs sent with the request.
     *
     * @param \stdClass $input
     * @return array id => true
     */
    protected static function sent_rules(\stdClass $input): array {
        $sent = [];
        foreach (array_merge($input->tips, $input->patterns) as $item) {
            $sent[$item->id] = true;
        }
        return $sent;
    }

    /**
     * Repair the AI's formatting and optional feedback without relaxing the contract (sanitizeSpeakWriteResult).
     *
     * @param mixed $source
     * @param \stdClass $input
     * @param bool $withcriteria
     * @return \stdClass
     */
    protected static function sanitize($source, \stdClass $input, bool $withcriteria): \stdClass {
        if (!($source instanceof \stdClass)) {
            throw new \RuntimeException('Invalid marking result');
        }
        $answer = $input->answer->text;
        $sent = self::sent_rules($input);
        $isobj = fn($v) => $v instanceof \stdClass;

        $summary = is_string($source->summary ?? null) ? js::slice(js::trim($source->summary), 0, 4000) : '';
        if ($summary === '') {
            throw new \RuntimeException('Invalid marking result');
        }

        $byid = [];
        if (is_array($source->points ?? null)) {
            foreach ($source->points as $item) {
                if (!$isobj($item) || !is_string($item->id ?? null) || array_key_exists($item->id, $byid)) {
                    continue;
                }
                foreach ($input->task->points as $point) {
                    if ($point->id === $item->id) {
                        $byid[$item->id] = $item;
                        break;
                    }
                }
            }
        }
        $points = [];
        foreach ($input->task->points as $expected) {
            $item = $byid[$expected->id] ?? null;
            if (!$item) {
                $points[] = (object) ['id' => $expected->id, 'status' => 'missing', 'quote' => '',
                    'comment' => 'No comment provided.', 'phrases' => []];
                continue;
            }
            $status = js::get($item, 'status');
            $status = in_array($status, self::STATUSES, true) ? $status : 'missing';
            $quote = is_string($item->quote ?? null) ? js::trim($item->quote) : '';
            if ($quote !== '' && (js::len($quote) > 4000 || !str_contains($answer, $quote))) {
                $quote = '';
            }
            $points[] = (object) ['id' => $expected->id, 'status' => $status, 'quote' => $quote,
                'comment' => self::feedback_text(js::get($item, 'comment')) ?? 'No comment provided.',
                'phrases' => self::text_array(js::get($item, 'phrases'), 3, 1000)];
        }

        $marks = [];
        if (is_array($source->marks ?? null)) {
            foreach (array_slice($source->marks, 0, 100) as $item) {
                if (!$isobj($item)) {
                    continue;
                }
                $type = js::get($item, 'type');
                $text = js::get($item, 'text');
                if (
                    !in_array($type, self::MARK_TYPES, true) || !is_string($text) || js::trim($text) === ''
                    || js::len($text) > 4000 || !str_contains($answer, $text)
                    || ($type === 'good' && !$input->options->markGood)
                    || ($type === 'selfcorrection' && $input->kind !== 'speaking')
                ) {
                    continue;
                }
                $mark = (object) ['type' => $type, 'text' => $text];
                foreach (['correction', 'severity', 'hint', 'why', 'rule'] as $key) {
                    $value = js::get($item, $key);
                    $value = $key === 'severity' ? (in_array($value, self::SEVERITIES, true) ? $value : null)
                        : self::feedback_text($value);
                    if ($value !== null) {
                        $mark->$key = $value;
                    }
                }
                if (property_exists($item, 'ruleId')) {
                    $mark->ruleId = self::rule($item->ruleId, $sent);
                }
                if ($type === 'error') {
                    $examples = self::text_array(js::get($item, 'examples'), 3, 1000);
                    if (count($examples) >= 2) {
                        $mark->examples = $examples;
                    }
                    if (
                        !isset($mark->correction) || !isset($mark->why) || !isset($mark->rule)
                        || ($input->mode === 'think' && !isset($mark->hint)) || !isset($mark->examples)
                    ) {
                        continue;
                    }
                } else if (is_array(js::get($item, 'examples'))) {
                    $mark->examples = self::text_array($item->examples, 3, 1000);
                }
                if (property_exists($item, 'start') || property_exists($item, 'end')) {
                    $start = js::get($item, 'start');
                    $end = js::get($item, 'end');
                    $valid = js::is_integer($start) && js::is_integer($end) && $start >= 0 && $end >= $start
                        && $end <= mb_strlen($answer, 'UTF-8')
                        && mb_substr($answer, (int) $start, (int) ($end - $start), 'UTF-8') === $text;
                    if ($valid) {
                        $mark->start = $start;
                        $mark->end = $end;
                    }
                }
                $marks[] = $mark;
            }
        }
        if ($input->options->markGood) {
            $errorspans = [];
            foreach ($marks as $mark) {
                if ($mark->type === 'error') {
                    $errorspans = array_merge($errorspans, self::spans($mark, $answer));
                }
            }
            $marks = array_values(array_filter($marks, function ($mark) use ($errorspans, $answer) {
                if ($mark->type !== 'good') {
                    return true;
                }
                foreach (self::spans($mark, $answer) as [$s, $e]) {
                    foreach ($errorspans as [$es, $ee]) {
                        if ($s < $ee && $es < $e) {
                            return false;
                        }
                    }
                }
                return true;
            }));
        }

        $communication = (object) ['strengths' => [], 'improve' => []];
        $c = $source->communication ?? null;
        if ($isobj($c)) {
            $communication->strengths = self::text_array(js::get($c, 'strengths'), 30);
            if (is_array(js::get($c, 'improve'))) {
                foreach (array_slice($c->improve, 0, 30) as $item) {
                    if (!$isobj($item)) {
                        continue;
                    }
                    $quote = self::feedback_text(js::get($item, 'quote'));
                    $tip = self::feedback_text(js::get($item, 'tip'));
                    if ($quote === null || !str_contains($answer, $quote) || $tip === null) {
                        continue;
                    }
                    $rewrites = [];
                    if (is_array(js::get($item, 'rewrites'))) {
                        foreach (array_slice($item->rewrites, 0, 5) as $rewrite) {
                            if (!$isobj($rewrite)) {
                                continue;
                            }
                            $text = self::feedback_text(js::get($rewrite, 'text'));
                            if ($text === null) {
                                continue;
                            }
                            $entry = new \stdClass();
                            if (property_exists($rewrite, 'ruleId')) {
                                $entry->ruleId = self::rule($rewrite->ruleId, $sent);
                            }
                            $entry->text = $text;
                            $rewrites[] = $entry;
                        }
                    }
                    if (!$rewrites) {
                        continue;
                    }
                    $entry = new \stdClass();
                    if (property_exists($item, 'ruleId')) {
                        $entry->ruleId = self::rule($item->ruleId, $sent);
                    }
                    $entry->quote = $quote;
                    $entry->tip = $tip;
                    $entry->rewrites = $rewrites;
                    $communication->improve[] = $entry;
                }
            }
        }

        $vocabulary = [];
        if (is_array($source->vocabulary ?? null)) {
            foreach (array_slice($source->vocabulary, 0, 50) as $item) {
                if (!$isobj($item)) {
                    continue;
                }
                $word = self::feedback_text(js::get($item, 'word'));
                if ($word === null || js::len($word) > 300) {
                    continue;
                }
                $count = js::get($item, 'count');
                $vocabulary[] = (object) ['word' => $word, 'count' => js::is_integer($count) && $count >= 0 ? $count : 0,
                    'alternatives' => self::text_array(js::get($item, 'alternatives'), 20, 300)];
            }
        }

        $rulehits = [];
        if (is_array($source->ruleHits ?? null)) {
            $seen = [];
            foreach (array_slice($source->ruleHits, 0, 200) as $item) {
                $id = js::get($item, 'ruleId');
                if (
                    !$isobj($item) || !is_string($id) || !isset($sent[$id]) || !is_bool(js::get($item, 'ok'))
                    || isset($seen[$id])
                ) {
                    continue;
                }
                $seen[$id] = true;
                $rulehits[] = (object) ['ruleId' => $id, 'ok' => $item->ok];
            }
        }

        $focus = [];
        if (is_array($source->focus ?? null)) {
            foreach ($source->focus as $value) {
                if (!is_string($value) || !isset($sent[$value]) || in_array($value, $focus, true)) {
                    continue;
                }
                if (count($focus) < 3) {
                    $focus[] = $value;
                }
            }
        }

        $criteria = null;
        if ($withcriteria) {
            $sc = $source->criteria ?? null;
            if (!$isobj($sc) || !$isobj($sc->task ?? null) || !$isobj($sc->communication ?? null) || !$isobj($sc->form ?? null)) {
                throw new \RuntimeException('Missing criteria');
            }
            $criteria = new \stdClass();
            foreach (['task', 'communication', 'form'] as $key) {
                $original = $sc->$key;
                $atg = self::nearest(js::get($original, 'atg'));
                $telc = max($atg, self::nearest(js::get($original, 'telc')));
                $reason = js::slice(self::trimmed(js::get($original, 'reason')) ?? 'Assessment provided.', 0, 4000);
                $criteria->$key = (object) ['atg' => $atg, 'telc' => $telc, 'reason' => $reason];
            }
        }

        $own = ['summary', 'points', 'marks', 'communication', 'vocabulary', 'ruleHits', 'focus', 'criteria', 'understood',
            'fluency', 'pronunciation'];
        $result = new \stdClass();
        foreach (js::keys($source) as $key) {
            if (!in_array($key, $own, true) && in_array($key, ['assessment', 'pointEvidence'], true)) {
                $result->$key = $source->$key;
            }
        }
        $result->summary = $summary;
        $result->points = $points;
        $result->marks = $marks;
        $result->communication = $communication;
        $result->vocabulary = $vocabulary;
        $result->ruleHits = $rulehits;
        $result->focus = $focus;
        if ($withcriteria) {
            $result->criteria = $criteria;
        }
        if ($input->kind === 'speaking') {
            $result->understood = is_bool($source->understood ?? null) ? $source->understood : false;
            $advice = $isobj($source->fluency ?? null) ? self::feedback_text(js::get($source->fluency, 'advice')) : null;
            $result->fluency = (object) ['advice' => $advice ?? 'Keep practising speaking fluently.'];
            $result->pronunciation = [];
            if (is_array($source->pronunciation ?? null)) {
                foreach (array_slice($source->pronunciation, 0, 4) as $item) {
                    if (!$isobj($item)) {
                        continue;
                    }
                    $word = self::feedback_text(js::get($item, 'word'));
                    $tip = self::feedback_text(js::get($item, 'tip'));
                    if ($word === null || js::len($word) > 300 || $tip === null) {
                        continue;
                    }
                    $result->pronunciation[] = (object) ['word' => $word, 'tip' => $tip];
                }
            }
        }
        return $result;
    }

    /**
     * The nearest score band to a number, or 9 when it is not one.
     *
     * @param mixed $value
     * @return int
     */
    protected static function nearest($value): int {
        if (!(is_int($value) || is_float($value)) || !is_finite((float) $value)) {
            return 9;
        }
        $best = 0;
        foreach (self::BANDS as $candidate) {
            if (abs($candidate - $value) < abs($best - $value)) {
                $best = $candidate;
            }
        }
        return $best;
    }

    /**
     * Where a mark's quote sits in the answer, as byte ranges.
     *
     * @param \stdClass $mark
     * @param string $answer
     * @return array [[start, end], ...]
     */
    protected static function spans(\stdClass $mark, string $answer): array {
        $start = $mark->start ?? null;
        $end = $mark->end ?? null;
        if (
            js::is_integer($start) && js::is_integer($end) && $start >= 0 && $end > $start
            && mb_substr($answer, (int) $start, (int) ($end - $start), 'UTF-8') === $mark->text
        ) {
            $before = strlen(mb_substr($answer, 0, (int) $start, 'UTF-8'));
            return [[$before, $before + strlen($mark->text)]];
        }
        $spans = [];
        $at = 0;
        while (($at = strpos($answer, $mark->text, $at)) !== false) {
            $spans[] = [$at, $at + strlen($mark->text)];
            $at++;
        }
        return $spans;
    }

    /**
     * The result's keys in the service's schema order, as its zod parse returns them (speakWriteResult).
     *
     * @param \stdClass $value
     * @param bool $withcriteria
     * @return \stdClass
     */
    protected static function shape(\stdClass $value, bool $withcriteria): \stdClass {
        $order = fn(\stdClass $o, array $keys) => (object) array_filter(
            array_merge(array_fill_keys($keys, null), get_object_vars($o)),
            fn($k) => property_exists($o, $k),
            ARRAY_FILTER_USE_KEY
        );
        $allowed = ['summary', 'understood', 'criteria', 'points', 'marks', 'communication', 'vocabulary', 'ruleHits', 'focus',
            'fluency', 'pronunciation'];
        foreach (array_keys(get_object_vars($value)) as $key) {
            if (!in_array((string) $key, $allowed, true) || ($key === 'criteria' && !$withcriteria)) {
                throw new \RuntimeException('Invalid marking result');
            }
        }
        $out = $order($value, $allowed);
        if (isset($out->criteria)) {
            $out->criteria = $order($out->criteria, ['task', 'communication', 'form']);
            foreach (['task', 'communication', 'form'] as $k) {
                $out->criteria->$k = $order($out->criteria->$k, ['atg', 'telc', 'reason']);
            }
        }
        $out->points = array_map(fn($p) => $order($p, ['id', 'status', 'quote', 'comment', 'phrases']), $out->points);
        $out->marks = array_map(fn($m) => $order($m, ['type', 'text', 'start', 'end', 'correction', 'severity', 'ruleId',
            'hint', 'why', 'rule', 'examples']), $out->marks);
        $out->communication = $order($out->communication, ['strengths', 'improve']);
        $out->communication->improve = array_map(function ($i) use ($order) {
            $i = $order($i, ['ruleId', 'quote', 'tip', 'rewrites']);
            $i->rewrites = array_map(fn($r) => $order($r, ['ruleId', 'text']), $i->rewrites);
            return $i;
        }, $out->communication->improve);
        $out->vocabulary = array_map(fn($v) => $order($v, ['word', 'count', 'alternatives']), $out->vocabulary);
        $out->ruleHits = array_map(fn($r) => $order($r, ['ruleId', 'ok']), $out->ruleHits);
        return $out;
    }

    /**
     * Reject unusable answers before anything is kept (validateSpeakWriteFields).
     *
     * @param \stdClass $result
     * @param \stdClass $input
     */
    protected static function check_fields(\stdClass $result, \stdClass $input): void {
        $fail = function () {
            throw new \RuntimeException('Invalid marking result');
        };
        $sent = self::sent_rules($input);
        $answer = $input->answer->text;
        $check = function ($value) use ($sent, $fail) {
            if (is_string($value) && $value !== '' && !isset($sent[$value])) {
                $fail();
            }
        };
        $ids = array_map(fn($p) => $p->id, $result->points);
        if (count($result->points) !== count($input->task->points) || count(array_unique($ids)) !== count($ids)) {
            $fail();
        }
        $sentpoints = array_map(fn($p) => $p->id, $input->task->points);
        foreach ($result->points as $point) {
            if (!in_array($point->id, $sentpoints, true) || ($point->quote !== '' && !str_contains($answer, $point->quote))) {
                $fail();
            }
        }
        foreach ($result->marks as $mark) {
            $check($mark->ruleId ?? null);
            if (!str_contains($answer, $mark->text)) {
                $fail();
            }
            if (property_exists($mark, 'start') || property_exists($mark, 'end')) {
                if (
                    !property_exists($mark, 'start') || !property_exists($mark, 'end')
                    || mb_substr($answer, (int) $mark->start, max(0, (int) ($mark->end - $mark->start)), 'UTF-8') !== $mark->text
                ) {
                    $fail();
                }
            }
            if (
                ($mark->type === 'good' && !$input->options->markGood)
                || ($mark->type === 'selfcorrection' && $input->kind !== 'speaking')
            ) {
                $fail();
            }
            if ($mark->type === 'error') {
                foreach (['correction', 'why', 'rule'] as $k) {
                    if (!isset($mark->$k) || js::trim($mark->$k) === '') {
                        $fail();
                    }
                }
                $examples = $mark->examples ?? null;
                if (!is_array($examples) || count($examples) < 2 || count($examples) > 3) {
                    $fail();
                }
                foreach ($examples as $example) {
                    if (!is_string($example) || js::len($example) > 1000 || js::trim($example) === '') {
                        $fail();
                    }
                }
                if ($input->mode === 'think' && (!isset($mark->hint) || js::trim($mark->hint) === '')) {
                    $fail();
                }
            }
        }
        foreach ($result->communication->improve as $improve) {
            $check($improve->ruleId ?? null);
            if (!str_contains($answer, $improve->quote)) {
                $fail();
            }
            foreach ($improve->rewrites as $rewrite) {
                $check($rewrite->ruleId ?? null);
            }
        }
        foreach ($result->ruleHits as $hit) {
            $check($hit->ruleId);
        }
        foreach ($result->focus as $rule) {
            $check($rule);
        }
        $speaking = isset($result->understood) || isset($result->fluency) || isset($result->pronunciation);
        if ($input->kind === 'speaking') {
            if (!isset($result->understood) || !isset($result->fluency) || !isset($result->pronunciation)) {
                $fail();
            }
        } else if ($speaking) {
            $fail();
        }
    }

    /**
     * Find, trim and recover the evidence quotes, then check the evidence before any scores (validateSpeakWriteEvidence).
     *
     * @param mixed $raw
     * @param \stdClass $input
     * @return array [assessment, evidence, pointEvidence]
     */
    protected static function validate_evidence($raw, \stdClass $input): array {
        $answer = $input->answer->text;
        $source = self::sanitize($raw, $input, false);
        $a = $source->assessment ?? null;
        if (
            !self::strict($a, ['formAssessable', 'reason']) || !is_bool($a->formAssessable) || !is_string($a->reason)
            || js::len($a->reason) > 4000 || js::trim($a->reason) === ''
        ) {
            throw new \RuntimeException('Invalid marking result');
        }
        $assessment = (object) ['formAssessable' => $a->formAssessable, 'reason' => $a->reason];

        $recover = self::quote_recoverer($answer);
        $sentids = array_map(fn($p) => $p->id, $input->task->points);
        $byid = [];
        if (is_array($source->pointEvidence ?? null)) {
            foreach ($source->pointEvidence as $candidate) {
                $id = js::get($candidate, 'id');
                if (!($candidate instanceof \stdClass) || !is_string($id) || !in_array($id, $sentids, true) || isset($byid[$id])) {
                    continue;
                }
                $byid[$id] = $candidate;
            }
        }
        $pointevidence = [];
        foreach ($input->task->points as $point) {
            $candidate = $byid[$point->id] ?? null;
            $rawideas = $candidate && is_array(js::get($candidate, 'ideas')) ? $candidate->ideas : [];
            $ideas = [];
            $seen = [];
            foreach (array_slice($rawideas, 0, 100) as $idea) {
                if (
                    !($idea instanceof \stdClass) || !is_string(js::get($idea, 'quote'))
                    || !is_bool(js::get($idea, 'fulfilsPoint')) || !is_string(js::get($idea, 'reason'))
                    || js::trim($idea->reason) === ''
                ) {
                    continue;
                }
                $recovered = $recover($idea->quote);
                if ($recovered === null || $recovered === '') {
                    continue;
                }
                $key = js::lower(self::normalise_quote($recovered));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $ideas[] = (object) ['quote' => $recovered, 'fulfilsPoint' => $idea->fulfilsPoint,
                    'reason' => js::trim($idea->reason)];
            }
            foreach ($ideas as $idea) {
                if (js::trim($idea->quote) === '' || js::len($idea->quote) > 4000 || js::len($idea->reason) > 4000) {
                    throw new \RuntimeException('Invalid marking result');
                }
            }
            $pointevidence[] = (object) ['id' => $point->id, 'ideas' => $ideas];
        }

        $content = new \stdClass();
        foreach (js::keys($source) as $key) {
            if ($key !== 'assessment' && $key !== 'pointEvidence') {
                $content->$key = $source->$key;
            }
        }
        $claimed = [];
        foreach ($content->points as $p) {
            $claimed[$p->id] = $p;
        }
        foreach ($input->task->points as $i => $taskpoint) {
            $fulfilled = count(array_filter($pointevidence[$i]->ideas, fn($idea) => $idea->fulfilsPoint));
            $point = $claimed[$taskpoint->id] ?? null;
            if (!$point) {
                continue;
            }
            $status = $point->status;
            if ($fulfilled >= $taskpoint->minCount) {
                $status = in_array($status, ['covered', 'extended'], true) ? $status : 'mismatch';
            } else {
                $status = in_array($status, ['short', 'missing'], true) ? $status : 'mismatch';
            }
            $point->status = $status;
        }
        $evidence = self::shape(self::sanitize($content, $input, false), false);
        $evidence->communication->improve = array_values(array_filter(
            $evidence->communication->improve,
            fn($i) => str_contains($answer, $i->quote)
        ));
        self::check_fields($evidence, $input);

        // Classify only provably corrected duplicates; ambiguous overlaps fail closed.
        $removed = [];
        $marks = [];
        foreach ($evidence->marks as $mark) {
            if ($mark->type !== 'error') {
                $marks[] = $mark;
                continue;
            }
            $corrected = false;
            foreach ($evidence->marks as $self) {
                if ($self->type !== 'selfcorrection') {
                    continue;
                }
                $parts = preg_split('/…|\.{3}/u', $self->text);
                if (count($parts) !== 2 || js::trim($mark->correction ?? '') === '' || js::trim($mark->text) === '') {
                    continue;
                }
                [$before, $after] = $parts;
                if ($mark->text === $self->text) {
                    if (
                        js::trim($after) !== '' && str_contains($mark->correction, js::trim($after))
                        && !str_contains(self::replace_first($answer, $self->text), $mark->text)
                    ) {
                        $corrected = true;
                        break;
                    }
                    continue;
                }
                if (
                    !self::whole_phrase($before, $mark->text) || self::whole_phrase($after, $mark->text)
                    || !self::whole_phrase($after, $mark->correction)
                ) {
                    continue;
                }
                if (!self::whole_phrase(self::replace_first($answer, $self->text), $mark->text)) {
                    $corrected = true;
                    break;
                }
            }
            if (!$corrected) {
                $marks[] = $mark;
                continue;
            }
            if (!empty($mark->ruleId)) {
                $removed[$mark->ruleId] = true;
            }
        }
        $classified = [];
        foreach ($marks as $mark) {
            if ($input->kind !== 'speaking' || $mark->type !== 'error') {
                $classified[] = $mark;
                continue;
            }
            $parts = preg_split('/…|\.{3}/u', $mark->text);
            if (
                count($parts) !== 2 || js::trim($parts[0]) === '' || js::trim($parts[1]) === ''
                || !isset($mark->correction) || js::trim($parts[1]) !== js::trim($mark->correction)
            ) {
                $classified[] = $mark;
                continue;
            }
            if (self::whole_phrase(self::replace_first($answer, $mark->text), js::trim($parts[0]))) {
                $classified[] = $mark;
                continue;
            }
            if (!empty($mark->ruleId)) {
                $removed[$mark->ruleId] = true;
            }
            $classified[] = (object) ['type' => 'selfcorrection', 'text' => $mark->text];
        }
        $ambiguous = [];
        foreach ($classified as $i => $error) {
            if ($error->type !== 'error') {
                continue;
            }
            foreach ($classified as $other) {
                if (
                    $other->type === 'selfcorrection'
                    && (str_contains($other->text, $error->text) || str_contains($error->text, $other->text))
                    && !self::whole_phrase(self::replace_first($answer, $other->text), $error->text)
                ) {
                    $ambiguous[$i] = true;
                    if (!empty($error->ruleId)) {
                        $removed[$error->ruleId] = true;
                    }
                    break;
                }
            }
        }
        $unambiguous = [];
        $remaining = [];
        foreach ($classified as $i => $mark) {
            if ($mark->type === 'error' && isset($ambiguous[$i])) {
                continue;
            }
            $unambiguous[] = $mark;
            if ($mark->type === 'error' && is_string($mark->ruleId ?? null)) {
                $remaining[$mark->ruleId] = true;
            }
        }
        $stale = array_diff_key($removed, $remaining);
        $evidence->marks = $unambiguous;
        $evidence->ruleHits = array_values(array_filter(
            $evidence->ruleHits,
            fn($hit) => !(isset($stale[$hit->ruleId]) && !$hit->ok)
        ));
        $evidence->focus = array_values(array_filter($evidence->focus, fn($rule) => !isset($stale[$rule])));
        return [$assessment, $evidence, $pointevidence];
    }

    /**
     * Check the complete result (validateSpeakWriteResult).
     *
     * @param mixed $raw
     * @param \stdClass $input
     * @return \stdClass
     */
    protected static function validate_result($raw, \stdClass $input): \stdClass {
        $result = self::shape(self::sanitize($raw, $input, true), true);
        self::check_fields($result, $input);
        if ($input->kind === 'speaking') {
            $types = array_map(fn($m) => $m->type, $result->marks);
            if (
                in_array('selfcorrection', $types, true) && !in_array('error', $types, true)
                && ($result->criteria->form->atg < 15 || $result->criteria->form->telc < 15)
            ) {
                $result->marks = array_values(array_filter($result->marks, fn($m) => $m->type !== 'selfcorrection'));
            }
        }
        return $result;
    }

    /**
     * One character of an evidence quote as compared (normalizeEvidenceCharacter).
     *
     * @param string $char One character.
     * @return string
     */
    protected static function normalise_char(string $char): string {
        if (preg_match('/^[' . js::WS . ']$/u', $char)) {
            return ' ';
        }
        if (in_array($char, ['‘', '’', '‚', '‛', '′'], true)) {
            return "'";
        }
        if (in_array($char, ['“', '”', '„', '‟', '″'], true)) {
            return '"';
        }
        if (in_array($char, ['‐', '‑', '‒', '–', '—', '―', '−'], true)) {
            return '-';
        }
        return $char;
    }

    /**
     * An evidence quote as compared (normalizeEvidenceQuote).
     *
     * @param string $value
     * @return string
     */
    protected static function normalise_quote(string $value): string {
        $chars = mb_str_split(js::trim($value), 1, 'UTF-8');
        return preg_replace('/ +/', ' ', implode('', array_map([self::class, 'normalise_char'], $chars)));
    }

    /**
     * A function that finds the learner's exact words for a quote that differs only in typography
     * (createEvidenceQuoteRecoverer).
     *
     * The service indexes its list of character spans with a UTF-16 position; that is kept.
     *
     * @param string $learner
     * @return callable fn(string $quote): ?string
     */
    protected static function quote_recoverer(string $learner): callable {
        $normalised = '';
        $spans = [];
        $offset = 0;
        foreach (mb_str_split($learner, 1, 'UTF-8') as $char) {
            $start = $offset;
            $offset += strlen($char) === 4 ? 2 : 1;
            $value = self::normalise_char($char);
            if ($value === ' ' && str_ends_with($normalised, ' ')) {
                $spans[count($spans) - 1][1] = $offset;
            } else {
                $normalised .= $value;
                $spans[] = [$start, $offset];
            }
        }
        return function (string $quote) use ($learner, $normalised, $spans): ?string {
            if (str_contains($learner, $quote)) {
                return $quote;
            }
            $target = self::normalise_quote($quote);
            if ($target === '') {
                return null;
            }
            $at = strpos($normalised, $target);
            if ($at === false) {
                return null;
            }
            $index = js::len(substr($normalised, 0, $at));
            $first = $spans[$index] ?? null;
            $last = $spans[$index + js::len($target) - 1] ?? null;
            return $first && $last ? js::slice($learner, $first[0], $last[1]) : null;
        };
    }

    /**
     * Whether a phrase occurs in a text with no letter or digit straight after it (wholePhrase()).
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    protected static function whole_phrase(string $haystack, string $needle): bool {
        if ($needle === '') {
            return false;
        }
        $letter = function (string $char): bool {
            // JavaScript tests one UTF-16 unit: half of a character outside the BMP is never a letter.
            return strlen($char) < 4 && preg_match('/^[\p{L}\p{N}]$/u', $char);
        };
        $chars = mb_str_split($needle, 1, 'UTF-8');
        $lastletter = $letter(end($chars));
        $at = 0;
        while (($at = strpos($haystack, $needle, $at)) !== false) {
            $after = mb_substr(substr($haystack, $at + strlen($needle)), 0, 1, 'UTF-8');
            if ($after === '' || !$letter($after) || !$lastletter) {
                return true;
            }
            $at++;
        }
        return false;
    }

    /**
     * String.prototype.replace() with a string pattern: the first occurrence only.
     *
     * @param string $text
     * @param string $search
     * @return string
     */
    protected static function replace_first(string $text, string $search): string {
        $at = strpos($text, $search);
        return $at === false ? $text : substr_replace($text, '', $at, strlen($search));
    }
}
