# Changelog

All notable changes to this plugin are documented here, newest first. This project follows
[Semantic Versioning](https://semver.org/).

## 1.0.4 - 2026-10-28

Helps teachers check that a written answer is the learner's own work. Database upgrade: one activity setting and six
fields per draft.

- **Pasting into answers**: a new activity setting to allow pasting, allow and log it (default), or block pasting
  from outside the answer box. Cutting and pasting inside the box is always allowed. Each draft stores how many
  pastes came from outside, how many characters, and how many were stopped. The writing screen tells learners what
  is recorded.
- **Leaving the writing screen** is counted per draft.
- **Rewrite check**: on draft 2 and later, if more than 40% of the words outside the marked spots changed, the draft
  is flagged to teachers. The threshold is a site setting (*Rewrite check threshold*).
- **Writing integrity panel** for teachers on the feedback page, a list of flagged drafts in class analytics and a
  flag count per task on the task list. Never shown to learners and never sent to LMS Labs.
- **Plagiarism plugins**: the activity supports Moodle's plagiarism API. Disclosures appear on the writing screen,
  plagiarism links in teacher views only, and a new event, *Written answer submitted*
  (`\mod_aispeakwrite\event\answer_submitted`), fires when a written draft is marked. Works with Essay Guard 2.0.1:
  one keystroke analysis per draft, the activity's phrase and special-character buttons are not counted as pastes,
  and the private draft note is not monitored.
- The answer box turns off spell check, autocorrect, autocomplete, autocapitalisation and page translation.
- Backup and restore, the privacy export and the privacy metadata include the new fields. New strings in all 18
  languages.

## 1.0.3 - 2026-10-28

Fixes found by testing every language being learned end to end.

- Fixed: only the activity's own language patterns are accepted from LMS Labs, so a French activity can never show a
  German-only pattern.
- Fixed: focus areas and class analytics show grammar patterns in the viewer's own language, whoever was marked.

## 1.0.2 - 2026-10-28

Works for every language learners can practise, not only German.

- **Languages to learn**: German (Germany, Austria, Switzerland), English (UK, US), French, Spanish, Italian, Dutch and
  Portuguese. A new site setting chooses the default for new activities.
- **Grammar patterns per language**: the AI can cite the shared patterns (verb forms, tenses, articles and gender,
  agreement, prepositions, word order and more) plus the ones each language needs, for example the subjunctive and
  past participle agreement in French, ser/estar and por/para in Spanish, essere/avere in Italian, de/het in Dutch,
  contractions and pronoun position in Portuguese, phrasal verbs in English. German keeps its cases, verb position and
  separable verbs.
- **Criteria names** are neutral (content, communication, grammar and spelling; task, expression, accuracy). When
  German is being learned, the telc exam's German names are shown first, as before.
- **Form of address** is labelled with the language's own pronouns: tu/vous, tú/usted, tu/Lei, jij/u, tu/você, du/Sie,
  and informal/formal for English.
- **Self-corrections when speaking** are recognised from restart words in every language ("euh", "o sea", "cioè",
  "ik bedoel", "quer dizer", "I mean"…), and ordinary words such as "no" or "non" no longer count as a restart.
- Tip names with accents (é, ñ, ç, ã) make readable ids.
- New and changed strings in all 18 interface languages. New tests for every language.

## 1.0.1 - 2026-10-26

- **Interface in 18 languages**: English, German, French, Spanish, Italian, Dutch, Portuguese, Brazilian Portuguese,
  Polish, Russian, Ukrainian, Turkish, Arabic, Simplified Chinese, Japanese, Korean, Vietnamese and Thai. The interface
  follows each user's Moodle language.
- The "AI" label on the conversation partner's turns is now translatable.
- Fixed: the 1.0.0 ZIP could not be installed from Site administration because it contained an empty folder.
- Security: no PARAM_RAW anywhere. Learners' text is accepted as PARAM_TEXT; the writing screen adds a space after a
  typed "<" that could start a tag, so text such as "3<4" is kept instead of being cut.
- The course activity list (index.php) now uses require_login().
- Added thirdpartylibs.xml (no third-party libraries).
- Code style: multi-line function calls put the opening bracket last on the line.
- Class analytics: "Most common mistakes" now ranks tips and grammar patterns by how many learners got them wrong
  recently (for example 7/12), and lists those learners, most misses first.

## 1.0.0 - 2026-10-26

First release, for testing with Advent Training Group. Marked beta until the LMS Labs marking routes are live.

### What's new

- **Writing practice with instant feedback** on content, communication and form, scored on the 0 / 3 / 9 / 15 bands
  with the school's strict score first and the realistic exam score in brackets.
- **Two correction modes**: Make me think (hints that name the school tip, no answers) and Explain (what is wrong,
  why, the correct form, the rule and similar sentences). Two-step by default: hints on the first draft, explanations
  on the rewrite. Teachers choose; learners can't switch.
- **Linked marks**: a fine red underline for mistakes (with a small footnote number) and green for expressions that
  score well, each linked to its comment. Calm, premium styling with no coloured dots or badges.
- **Task points**: one box per point, quoting what the learner wrote, with a verdict and phrases to use.
- **School tips and tricks** at site, activity and task level, cited by name in feedback and tracked per learner.
- **Speaking practice** in Training or Exam mode, with an AI partner for conversation tasks. Feedback puts "were you
  understood" first and judges flow and the overall message over error counts. Saying something wrong and then fixing
  it is praised as a self-correction, never counted as a mistake (checked by Moodle as well as the AI). Only mistakes
  that made the learner hard to follow are marked in red.
  "Say it again" checks a corrected sentence in the browser.
- **Dashboard, practice library, class analytics** (common mistakes, who needs which tip, assign practice in one
  step, learners × tips grid) and **credit usage** with a low-balance warning.
- **Credits**: 1 LMS Labs credit per marked answer. Paid requests are saved with their key before sending, never
  retried automatically, and never charged twice. Identical resubmissions and too-short answers are free.
- Moodle 4.4 – 5.3, Privacy API, backup and restore, course reset, completion rule, gradebook.
