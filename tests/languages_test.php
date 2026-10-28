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

namespace mod_aispeakwrite;

use mod_aispeakwrite\local\manager;
use mod_aispeakwrite\local\marker;
use mod_aispeakwrite\local\memory;
use mod_aispeakwrite\local\tips;

/**
 * Tests that every language that can be learned is handled in its own terms.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aispeakwrite\local\manager
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\local\manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\local\tips::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aispeakwrite\local\marker::class)]
final class languages_test extends \advanced_testcase {
    /**
     * Each language gets the shared patterns plus its own, and none of another language's.
     */
    public function test_pattern_names(): void {
        $de = tips::pattern_names('de-AT');
        $this->assertContains('casedative', $de);
        $this->assertContains('separableverb', $de);
        $fr = tips::pattern_names('fr-FR');
        $this->assertContains('subjunctive', $fr);
        $this->assertContains('pastparticiple', $fr);
        $this->assertNotContains('casedative', $fr);
        $es = tips::pattern_names('es-ES');
        $this->assertContains('serestar', $es);
        $this->assertContains('porpara', $es);
        $it = tips::pattern_names('it-IT');
        $this->assertContains('auxiliary', $it);
        $this->assertContains('articulatedprep', $it);
        $nl = tips::pattern_names('nl-NL');
        $this->assertContains('dehet', $nl);
        $this->assertContains('verbsecond', $nl);
        $pt = tips::pattern_names('pt-PT');
        $this->assertContains('contraction', $pt);
        $this->assertContains('pronounplacement', $pt);
        $en = tips::pattern_names('en-GB');
        $this->assertContains('phrasalverb', $en);
        $this->assertNotContains('verbsecond', $en);
        $this->assertNotContains('register', array_diff(tips::COMMON_PATTERNS, $en));
        // The patterns sent to LMS Labs follow the activity's language.
        $ids = array_column(tips::patterns('es-ES'), 'id');
        $this->assertContains('grammar:serestar', $ids);
        $this->assertNotContains('grammar:casedative', $ids);
    }

    /**
     * Every pattern, criterion and new string exists in all 18 interface languages.
     */
    public function test_strings_in_every_language(): void {
        global $CFG;
        $dir = $CFG->dirroot . '/mod/aispeakwrite/lang';
        if (!is_dir($dir)) {
            $dir = $CFG->dirroot . '/public/mod/aispeakwrite/lang';
        }
        $string = [];
        require($dir . '/en/aispeakwrite.php');
        $en = array_keys($string);
        $needed = array_map(fn($p) => 'pattern_' . $p, tips::all_pattern_names());
        foreach (['writing', 'speaking'] as $kind) {
            foreach (manager::CRITERIA as $key) {
                $needed[] = 'criterion_' . $kind . '_' . $key;
            }
        }
        $needed = array_merge($needed, ['formality_informal', 'formality_formal', 'formality_informal_plain',
            'formality_formal_plain', 'defaulttargetlang', 'defaulttargetlang_desc']);
        foreach (manager::LANGUAGES as $code) {
            $needed[] = 'lang_' . str_replace('-', '', strtolower($code));
        }
        $langs = array_filter(scandir($dir), fn($l) => $l[0] !== '.');
        $this->assertCount(18, $langs);
        foreach ($langs as $lang) {
            $string = [];
            require($dir . '/' . $lang . '/aispeakwrite.php');
            foreach ($needed as $key) {
                $this->assertArrayHasKey($key, $string, "$lang is missing $key");
                $this->assertNotSame('', trim($string[$key]), "$lang has an empty $key");
            }
            $this->assertSame([], array_diff($en, array_keys($string)), "$lang is missing strings");
            $this->assertStringContainsString('{$a}', $string['formality_informal'], $lang);
        }
    }

    /**
     * Criteria use neutral names, with the telc exam's German names only when German is being learned.
     */
    public function test_criterion_label(): void {
        $this->assertSame('Aufgabenbewältigung · Content', manager::criterion_label('writing', 'task', 'de-DE'));
        $this->assertSame('Ausdrucksfähigkeit · Expression', manager::criterion_label('speaking', 'communication', 'de-CH'));
        $this->assertSame('Content', manager::criterion_label('writing', 'task', 'fr-FR'));
        $this->assertSame('Grammar and spelling', manager::criterion_label('writing', 'form', 'en-US'));
        $this->assertSame('Accuracy', manager::criterion_label('speaking', 'form', 'pt-PT'));
    }

    /**
     * The form of address is labelled with each language's own pronouns; English has none.
     */
    public function test_formality_options(): void {
        $this->assertSame('Informal (du)', manager::formality_options('de-DE')['du']);
        $this->assertSame('Formal (vous)', manager::formality_options('fr-FR')['sie']);
        $this->assertSame('Formal (usted)', manager::formality_options('es-ES')['sie']);
        $this->assertSame('Formal (Lei)', manager::formality_options('it-IT')['sie']);
        $this->assertSame('Informal (jij)', manager::formality_options('nl-NL')['du']);
        $this->assertSame('Formal (você)', manager::formality_options('pt-PT')['sie']);
        $this->assertSame('Informal', manager::formality_options('en-GB')['du']);
        $this->assertSame(['du', 'sie', 'any'], array_keys(manager::formality_options('en-GB')));
    }

    /**
     * Only the supported languages are saved; anything else falls back to the site's default.
     */
    public function test_language_saved_and_default(): void {
        $this->resetAfterTest();
        $this->assertSame('de-DE', manager::default_language());
        set_config('defaulttargetlang', 'fr-FR', 'mod_aispeakwrite');
        $this->assertSame('fr-FR', manager::default_language());
        $data = manager::prepare_instance((object)['targetlang' => 'xx-YY']);
        $this->assertSame('fr-FR', $data->targetlang);
        $data = manager::prepare_instance((object)['targetlang' => 'it-IT']);
        $this->assertSame('it-IT', $data->targetlang);
        set_config('defaulttargetlang', 'klingon', 'mod_aispeakwrite');
        $this->assertSame('de-DE', manager::default_language());
    }

    /**
     * A slip the learner fixes in the same breath counts as a self-correction in every language.
     */
    public function test_self_correction_in_every_language(): void {
        $cases = [
            ['Ich habe … ich bin nach Hause gegangen.', 'Ich habe … ich bin', 'ich bin'],
            ['I goed, I mean I went to the shop.', 'I goed, I mean I went', 'I went'],
            ['Je suis allé, euh, je suis allée au marché.', 'Je suis allé, euh, je suis allée', 'je suis allée'],
            ['Yo soy cansado, o sea, estoy cansado hoy.', 'Yo soy cansado, o sea, estoy cansado', 'estoy cansado'],
            ['Ho andato, cioè, sono andato al mare.', 'Ho andato, cioè, sono andato', 'sono andato'],
            ['Ik heb gegaan, ik bedoel ik ben gegaan.', 'Ik heb gegaan, ik bedoel ik ben gegaan', 'ik ben gegaan'],
            ['Eu fui no, quer dizer, eu fui ao cinema.', 'Eu fui no, quer dizer, eu fui ao', 'eu fui ao'],
        ];
        foreach ($cases as [$text, $marked, $fix]) {
            $start = \core_text::strpos($text, $marked);
            $mark = ['text' => $marked, 'correction' => $fix, 'start' => $start, 'end' => $start + \core_text::strlen($marked)];
            $this->assertTrue(marker::fixed_in_speech($text, $mark), $text);
        }
        // An ordinary "no" or "non" is not a restart: nothing was fixed here.
        $text = 'No tengo el libro en casa.';
        $mark = ['text' => 'No tengo el libro', 'correction' => 'No tengo un libro', 'start' => 0, 'end' => 17];
        $this->assertFalse(marker::fixed_in_speech($text, $mark));
        $text = 'Non ho visto il film ieri.';
        $mark = ['text' => 'Non ho visto il film', 'correction' => 'ho visto', 'start' => 0, 'end' => 20];
        $this->assertFalse(marker::fixed_in_speech($text, $mark));
    }

    /**
     * Tip names with accents in any language make readable ids.
     */
    public function test_slug_accents(): void {
        $this->assertSame('accord-du-participe-passe', tips::slug('Accord du participe passé'));
        $this->assertSame('uso-de-la-n', tips::slug('Uso de la ñ'));
        $this->assertSame('verb-am-ende', tips::slug('Verb am Ende'));
    }

    /**
     * Marks citing any language's pattern are kept; unknown ids are dropped.
     */
    public function test_normalise_accepts_language_patterns(): void {
        $this->resetAfterTest();
        $text = 'Je veux que tu viens demain.';
        $r = [
            'criteria' => ['task' => ['strict' => 9, 'exam' => 9], 'communication' => ['strict' => 9, 'exam' => 9],
                'form' => ['strict' => 3, 'exam' => 9]],
            'marks' => [
                ['type' => 'error', 'text' => 'viens', 'correction' => 'viennes', 'ruleId' => 'grammar:subjunctive',
                    'severity' => 'major', 'note' => 'After "vouloir que", use the subjunctive.'],
                ['type' => 'error', 'text' => 'demain', 'correction' => 'demain', 'ruleId' => 'grammar:klingon',
                    'severity' => 'minor', 'note' => 'x'],
            ],
        ];
        $task = (object)['points' => '', 'kind' => 'writing', 'tasktype' => 'free'];
        $out = marker::normalise($r, $text, $task, []);
        $this->assertNotNull($out);
        $rules = array_column($out['marks'], 'ruleid', 'text');
        $this->assertSame('grammar:subjunctive', $rules['viens']);
        $this->assertSame('', $rules['demain']);
        // For a French activity, a German-only pattern is not accepted; the French one is.
        $r['marks'][1]['ruleId'] = 'grammar:casedative';
        $r['focus'] = ['grammar:casedative', 'grammar:subjunctive'];
        $out = marker::normalise($r, $text, $task, [], 'fr-FR');
        $rules = array_column($out['marks'], 'ruleid', 'text');
        $this->assertSame('grammar:subjunctive', $rules['viens']);
        $this->assertSame('', $rules['demain']);
        $this->assertSame(['grammar:subjunctive'], $out['focus']);
    }

    /**
     * Focus areas are shown in the viewer's language, whatever language the learner used when they were recorded.
     */
    public function test_focus_labels_in_viewer_language(): void {
        $this->resetAfterTest();
        memory::update(7, 3, 'grammar:subjunctive', 'Subjonctif', false);
        memory::update(7, 3, 'tip:satzanfang', 'Satzanfang', false);
        $labels = array_column(memory::for_user(7, 3), 'label', 'key');
        $this->assertSame('Subjunctive', $labels['grammar:subjunctive']);
        $this->assertSame('Satzanfang', $labels['tip:satzanfang']);
        $this->assertSame('Old name', memory::display_label('grammar:retired', 'Old name'));
    }
}
