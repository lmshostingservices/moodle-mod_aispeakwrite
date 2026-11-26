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

namespace mod_aispeakwrite;

use mod_aispeakwrite\local\byok\engine;
use mod_aispeakwrite\local\byok\prompts;
use mod_aispeakwrite\local\credentials;
use mod_aispeakwrite\local\marker;
use mod_aispeakwrite\local\remote;
use mod_aispeakwrite\local\submissions;

/**
 * Tests Bring your own AI: markings on the site's own AI, with the service's prompts and checks, at no charge.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aispeakwrite\byok
 * @covers     \mod_aispeakwrite\local\byok\engine
 * @covers     \mod_aispeakwrite\local\remote
 */
#[\PHPUnit\Framework\Attributes\CoversClass(byok::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(engine::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(remote::class)]
final class byok_test extends \advanced_testcase {
    /** @var array Requests the mocked Anthropic received: [url, body]. */
    private array $requests = [];

    /** @var array Tool answers the mocked Anthropic gives, in order. */
    private array $answers = [];

    /** @var string A learner answer long enough to be marked. */
    private const TEXT = "Lieber Tim,\nvielen Dank für den Einladung. Natürlich ich kommen gern zu deiner Party am Samstag. " .
        "Herzlichen Glückwunsch zu dein Geburtstag. Ich bringe einen Kuchen mit. Bis bald, Justus";

    /**
     * Licence the site for Bring your own AI on a test key, with Text on Anthropic and no LMS Labs credentials.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_aiconfig\byok\ai')) {
            $this->markTestSkipped('AI Grader Central Config with Bring your own AI is not installed.');
        }
        $this->resetAfterTest();
        $pair = sodium_crypto_sign_keypair();
        set_config('testpublickey', base64_encode(sodium_crypto_sign_publickey($pair)), 'local_aiconfig');
        $payload = json_encode(['v' => 1, 'id' => 'lic_t', 'site' => \local_aiconfig\byok\licence::site_host(),
            'plan' => 'pro', 'byok' => true, 'products' => ['*'], 'sub' => 'sub_t', 'exp' => time() + DAYSECS]);
        $b64 = fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        set_config('licencekey', $b64($payload) . '.' .
            $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair))), 'local_aiconfig');
        set_config('src_text', 'ownkey', 'local_aiconfig');
        set_config('vendor_text', 'anthropic', 'local_aiconfig');
        set_config('key_anthropic', 'sk-test', 'local_aiconfig');
        credentials::$central = fn() => null;
        $this->requests = [];
        $this->answers = [];
        $GLOBALS['local_aiconfig_byok_mock'] = function ($url, $headers, $body, $vendor, $raw = false) {
            $this->requests[] = [$url, $body];
            $answer = array_shift($this->answers);
            if ($answer === null) {
                throw new \moodle_exception('byok_callfailed', 'local_aiconfig', '', (object) ['vendor' => $vendor,
                    'detail' => 'Overloaded']);
            }
            return json_encode(['content' => [['type' => 'tool_use', 'id' => 't', 'name' => $body['tools'][0]['name'],
                'input' => $answer]], 'stop_reason' => 'tool_use']);
        };
        // LMS Labs must never be called.
        remote::$transport = function () {
            throw new \coding_exception('LMS Labs was called');
        };
    }

    /**
     * Clean up the mocks.
     */
    protected function tearDown(): void {
        unset($GLOBALS['local_aiconfig_byok_mock']);
        remote::$transport = null;
        credentials::$central = null;
        engine::$text = null;
        parent::tearDown();
    }

    /**
     * An activity, a task and a learner's draft.
     *
     * @return array [instance, task, draft, context]
     */
    private function draft(): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/aispeakwrite/lib.php');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('aispeakwrite', ['course' => $course->id]);
        $instance = $DB->get_record('aispeakwrite', ['id' => $module->id]);
        $task = $this->getDataGenerator()->get_plugin_generator('mod_aispeakwrite')->create_task(
            ['aispeakwriteid' => $instance->id]
        );
        $sub = submissions::open_draft($instance, $task, (int)$user->id);
        submissions::save($sub, self::TEXT, '');
        return [$instance, $task, submissions::get((int)$sub->id, (int)$instance->id), \context_module::instance($module->cmid)];
    }

    /**
     * The evidence stage's answer: three task points shown, one missing, one grammar error.
     *
     * @return array
     */
    private function evidence(): array {
        $idea = fn(string $quote) => ['ideas' => [['quote' => $quote, 'fulfilsPoint' => true, 'reason' => 'Covers it.']]];
        return [
            'summary' => 'Old summary.',
            'points' => [
                ['id' => 'p1', 'status' => 'covered', 'quote' => 'Natürlich ich kommen gern zu deiner Party am Samstag.',
                    'comment' => 'You say you will come.', 'phrases' => []],
                ['id' => 'p2', 'status' => 'covered', 'quote' => 'Herzlichen Glückwunsch zu dein Geburtstag.',
                    'comment' => 'Good wishes.', 'phrases' => []],
                ['id' => 'p3', 'status' => 'covered', 'quote' => 'Ich bringe einen Kuchen mit.',
                    'comment' => 'A clear offer.', 'phrases' => []],
                ['id' => 'p4', 'status' => 'missing', 'quote' => '', 'comment' => 'Not mentioned.',
                    'phrases' => ['Letztes Jahr habe ich groß gefeiert.', 'Ich feiere gern mit Freunden.']],
            ],
            'marks' => [
                ['type' => 'error', 'text' => 'den Einladung', 'correction' => 'die Einladung', 'severity' => 'minor',
                    'ruleId' => null, 'hint' => 'Check the gender of Einladung.', 'why' => 'Einladung is feminine.',
                    'rule' => 'für + accusative: die (feminine).', 'examples' => ['Danke für die Karte.',
                    'Danke für die Blumen.']],
            ],
            'communication' => ['strengths' => ['Friendly tone.'], 'improve' => []],
            'vocabulary' => [],
            'ruleHits' => [],
            'focus' => [],
            'assessment' => ['formAssessable' => true, 'reason' => 'There is an answer to assess.'],
            'pointEvidence' => [
                ['id' => 'p1'] + $idea('Natürlich ich kommen gern zu deiner Party am Samstag.'),
                ['id' => 'p2'] + $idea('Herzlichen Glückwunsch zu dein Geburtstag.'),
                // Typography differs from the learner's text: the service recovers the exact words.
                ['id' => 'p3'] + $idea('Ich  bringe einen Kuchen mit.'),
                ['id' => 'p4', 'ideas' => []],
            ],
        ];
    }

    /**
     * A marking runs on the site's own AI in the service's two stages, is stored, and costs nothing.
     */
    public function test_mark(): void {
        global $DB;
        [$instance, $task, $draft, $context] = $this->draft();
        $this->answers = [$this->evidence(), [
            'criteria' => [
                'task' => ['atg' => 9, 'telc' => 9, 'reason' => 'One point is missing.'],
                'communication' => ['atg' => 9, 'telc' => 15, 'reason' => 'Clear and friendly.'],
                'form' => ['atg' => 9, 'telc' => 9, 'reason' => 'One article error.'],
            ],
            'summary' => 'A friendly email; add your own party experience.',
            'correctionFeedback' => [],
        ]];
        $marked = marker::mark($instance, $task, $draft, $context);

        $this->assertSame('marked', $marked->status);
        $feedback = json_decode($marked->feedback, true);
        $this->assertStringContainsString('die Einladung', json_encode($feedback, JSON_UNESCAPED_UNICODE));
        $op = $DB->get_record('aispeakwrite_op', ['itemid' => $marked->id]);
        $this->assertSame('complete', $op->state);
        $this->assertSame(0, (int)$op->credits);

        // The evidence stage: the service's prompts and schema, at temperature 0.
        $this->assertCount(2, $this->requests);
        [$url, $first] = $this->requests[0];
        $this->assertStringEndsWith('/v1/messages', $url);
        $this->assertSame(prompts::SYSTEM . "\n\n" . prompts::EVIDENCE, $first['system']);
        $this->assertSame('language_evidence', $first['tools'][0]['name']);
        $this->assertSame(4000, $first['max_tokens']);
        $this->assertEquals(0, $first['temperature']);
        $this->assertStringStartsWith("Mark this answer. Request:\n{", $first['messages'][0]['content'][0]['text']);
        $this->assertArrayHasKey('pointEvidence', $first['tools'][0]['input_schema']['properties']);

        // The scoring stage gets the checked evidence, and cannot give full task marks with a point missing.
        $second = $this->requests[1][1];
        $this->assertSame(prompts::SYSTEM . "\n\n" . prompts::SCORING, $second['system']);
        $user = $second['messages'][0]['content'][0]['text'];
        $this->assertStringContainsString(engine::EVIDENCE_LABEL, $user);
        $this->assertStringContainsString('"quote":"Ich bringe einen Kuchen mit.","fulfilsPoint":true', $user);
        $this->assertStringNotContainsString('Old summary.', $user);
        $schema = $second['tools'][0]['input_schema'];
        $this->assertSame([0, 3, 9], $schema['properties']['criteria']['properties']['task']['properties']['atg']['enum']);
    }

    /**
     * An unusable answer from the site's AI charges nothing and leaves the draft to try again.
     */
    public function test_failure(): void {
        global $DB;
        [$instance, $task, $draft, $context] = $this->draft();
        $evidence = $this->evidence();
        unset($evidence['assessment']);
        $this->answers = [$evidence];
        try {
            marker::mark($instance, $task, $draft, $context);
            $this->fail('No exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('remoterefused_byok_failed', $e->errorcode);
        }
        $this->assertDebuggingCalled();
        $this->assertSame('draft', $DB->get_field('aispeakwrite_sub', 'status', ['id' => $draft->id]));
        $op = $DB->get_record('aispeakwrite_op', ['itemid' => $draft->id]);
        $this->assertSame('failed', $op->state);
        $this->assertSame(0, (int)$op->credits);
    }

    /**
     * A score the evidence does not support is refused, as the service refuses it.
     */
    public function test_unsupported_score(): void {
        $body = json_decode(json_encode([
            'kind' => 'writing', 'taskType' => 'email', 'mode' => 'explain', 'level' => 'B1', 'targetLanguage' => 'de-DE',
            'feedbackLanguage' => 'en', 'draft' => 1,
            'task' => ['title' => 'Party', 'situation' => '', 'sourceText' => '', 'points' => [], 'formality' => 'du',
                'targetWords' => 0],
            'tips' => [], 'patterns' => [],
            'scoring' => ['criteria' => ['task', 'communication', 'form'], 'bands' => [0, 3, 9, 15], 'examScore' => true],
            'options' => ['markGood' => false], 'answer' => ['text' => 'Ich komme gern.'],
        ]));
        $this->assertTrue(engine::valid_input($body));
        $body->kind = 'speaking';
        $this->assertFalse(engine::valid_input($body));
        $body->kind = 'writing';
        $evidence = ['summary' => 'Fine.', 'points' => [], 'marks' => [], 'communication' => ['strengths' => [], 'improve' => []],
            'vocabulary' => [], 'ruleHits' => [], 'focus' => [], 'assessment' => ['formAssessable' => true, 'reason' => 'Ok.'],
            'pointEvidence' => []];
        $criterion = ['atg' => 15, 'telc' => 15, 'reason' => 'Fine.'];
        $answers = [$evidence, ['criteria' => ['task' => $criterion, 'communication' => $criterion,
            'form' => ['atg' => 9, 'telc' => 9, 'reason' => 'No errors but marked down.']], 'summary' => 'Fine.',
            'correctionFeedback' => []]];
        engine::$text = function () use (&$answers) {
            return json_encode(array_shift($answers));
        };
        [$status, $answer] = engine::mark(json_encode($body));
        $this->assertSame(502, $status);
        $this->assertSame('Unsupported form score', $answer['error']['reason']);
    }
}
