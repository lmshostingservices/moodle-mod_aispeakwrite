# AI Advent Speaking and Writing (mod_aispeakwrite)

A Moodle activity for exam-style writing and speaking practice with instant AI feedback. Learners write an email,
translate sentences or hold a spoken conversation with an AI partner, and get feedback on **content**,
**communication** and **form** within seconds, in the style of their own school.

| | |
|---|---|
| Component | `mod_aispeakwrite` |
| Version | 1.0.3 (beta) |
| Moodle | 4.4 – 5.3 (`requires` 2024042200, `supported` [404, 503]) |
| PHP | 8.1 or later (8.3+ on Moodle 5.3) |
| Databases | PostgreSQL, MySQL, MariaDB |
| Languages learners practise | German (Germany, Austria, Switzerland), English (UK, US), French, Spanish, Italian, Dutch, Portuguese |
| Interface languages | English, German, French, Spanish, Italian, Dutch, Portuguese (pt, pt_br), Polish, Russian, Ukrainian, Turkish, Arabic, Simplified Chinese, Japanese, Korean, Vietnamese, Thai |
| Licence | GNU GPL v3 or later |

## External service: LMS Labs subscription required

**This plugin does not mark answers itself.** Answers are marked by the LMS Labs service (https://lms-labs.com), which
needs an LMS Labs account with credits:

- **1 credit per marked answer** (a written answer, or a spoken conversation).
- In conversations the AI partner's turns use the LMS Labs conversation service.
- Speech is turned into text by the learner's own browser, at no charge.
- Re-opening feedback, empty or too-short answers, and resubmitting an identical text are never charged.

To connect a site: get a Site ID and API key from your LMS Labs account, then enter them once in **LMS Labs Central
Config** (`local_aiconfig`), or in this plugin's settings (Site administration › Plugins › Activity modules › AI Advent
Speaking and Writing). Without them, everything except marking still works (tasks, drafts, reports).

## What learners see

- **Dashboard**: readiness, a "continue" card for a rewrite or draft, the recommended next task, skill bars per
  criterion, focus areas (the school tips and grammar patterns they keep missing), self-corrections when speaking,
  and recent results.
- **Practice**: task types with mastery rings, and prompt cards filtered by status (not started, keep practising,
  mastered), with due dates for assigned tasks.
- **Writing screen**: the task on top, the text box with a live word count and special characters, a time plan that
  follows the clock (read, plan, write, check), the school's tips as a checklist, phrase chips that insert at the
  cursor, a draft note, and autosave.
- **Feedback**:
  - Score per criterion on the 0 / 3 / 9 / 15 bands: the school's strict score first, the realistic exam score in
    brackets.
  - The answer with numbered marks: red for mistakes, green for expressions that score well. Each mark is linked to
    its comment.
  - **Make me think**: a hint that names the school tip or rule, without the answer; "Show me an example" reveals the
    rule and similar sentences. **Explain**: what is wrong, why, the correct form, the rule and similar sentences.
  - One box per task point, quoting what the learner wrote, with a verdict (covered, well extended, too short,
    missing, doesn't match) and phrases to use.
  - Communication: strengths for the level, and the learner's own sentence rewritten with the school's tips.
  - "Seen before" when a mistake repeats.
- **Speaking**: Training mode (tap Speak, then Done) or Exam mode (a turn ends after a pause). With an AI partner for
  conversation tasks. Feedback starts with whether the learner was understood, then flow (long pauses), connectors,
  words to practise, and the transcript: self-corrections in green, "clear but form off" in amber, "hard to follow"
  in red. Saying something wrong and then fixing it is treated as good speaking, never as a mistake: it is praised,
  does not lower the score, and counts as "right" in the learner's focus areas. Moodle double-checks this, so a slip
  the learner fixes in the same sentence is stored as a self-correction even if the AI called it an error. "Say it
  again" checks a corrected sentence in the browser.

Speaking needs a browser with built-in speech recognition (Chrome, Edge or Safari). Firefox users are told so.
Writing works in every browser.

## What teachers set

- **Correction mode** per activity: two-step (Make me think on the first draft, Explain on the rewrite), Make me think
  only, or Explain only. Learners can't change it.
- **School tips and tricks** (site), **activity tips** and **task tips**: one tip per line, `Name: tip | example`,
  up to 4,000 characters per box. The most specific tip wins when names match. The AI cites tips by name; each tip
  is tracked per learner. Every marking stores which version of the tips was used.
- **Tasks**: writing (email with task points, translation, free writing) and speaking (parts 1–3) with situation,
  source text, task points (`Point | 2` for "at least two"), time, target length, guide, phrase bank and the AI
  partner's role. Assign to the whole class, one group or selected learners, with a due date.
- **Limits**: markings per learner per day and rewrites per task.
- **Test with a sample answer**: marks a pasted text with the current tips (1 credit, not saved as an attempt).
- **Class analytics**: most common mistakes, which learners keep missing each one, assign practice to them in one
  step, a learners × tips grid, and credit usage with a low-balance warning.

## Writing integrity

Teachers only. Learners never see these figures, and none of them are sent to LMS Labs.

- **Pasting into answers** (per activity): *allow*, *allow and log* (default) or *block pasting from outside the
  box*. Cutting and pasting inside the answer box is always allowed. With logging, each draft keeps how many times
  text was pasted in from elsewhere and how many characters; with blocking, how many tries were stopped. The
  writing screen tells learners what is recorded.
- **Leaving the writing screen**: each draft counts how many times the learner switched to another tab, window or app.
- **Rewrite check**: on draft 2 and later, the rewrite is compared word by word with the marked draft it started
  from. Changes at or next to the marked spots are ignored; when more than 40% of the other words changed (a site
  setting), the draft is flagged.
- **Where teachers see it**: a *Writing integrity* panel on the feedback page when a teacher opens a learner's
  answer, a list of flagged drafts in class analytics, and a flag count per task on the task list.
- **Essay Guard**: when the Essay Guard plagiarism plugin (2.0.1 or later) is installed and switched on for the
  activity, it records how each answer is typed on the writing screen and its badge appears in the panel, one score
  per draft. The plugin declares `FEATURE_PLAGIARISM`, shows plagiarism disclosures on the writing screen and fires
  `\mod_aispeakwrite\event\answer_submitted` when a written answer is marked.
- The answer box turns off the browser's spell check, autocorrect, autocomplete and page translation.

These are signals for a conversation with the learner, not proof.

## Grades and completion

The grade is the average of each learner's best strict score per task. Completion can require a number of tasks to
be marked.

## Privacy

Stored: drafts and marked answers (with feedback and scores, and per draft the paste, stopped-paste and
screen-leaving counts and the rewrite check result), each learner's focus-area record, assignments, and a
record of each paid request (its body is kept only while it is being sent, then emptied; requests still pending after
seven days are ended by a scheduled task).

Sent to LMS Labs: the answer text or transcript, the task, the school's tips, speaking time and pauses, the learner's
open focus areas (tip and pattern names only) and the feedback language. No name, email or user id is sent.

Speech recognition is a browser feature: depending on the browser, audio may be processed by the browser maker
(for example Google or Apple). The plugin itself never records, sends or stores audio.

All of this is declared through Moodle's Privacy API, with export and deletion.

## Install

1. Unzip into `mod/aispeakwrite` (Moodle 5.1+: `public/mod/aispeakwrite`).
2. Visit Site administration › Notifications.
3. Enter the LMS Labs Site ID and API key (or install LMS Labs Central Config), and your school's tips.
4. Add an "AI Advent Speaking and Writing" activity to a course, then add tasks.

## Acknowledgements

AI Advent Speaking and Writing was developed with **[Advent Training Group](https://www.advent-training-group.com/)**,
*Where Preparation Meets Opportunity*. Its feedback approach comes from Advent's own classroom practice: task points
answered in full, ideas connected clearly, and grammar improved by thinking it through rather than copying a
correction. Thank you to the Advent Training Group team for their ideas, testing and feedback.

## Developers

See `docs/ARCHITECTURE.md` and `docs/LMSLABS_CONTRACT.md`. Tests:
`vendor/bin/phpunit --testsuite mod_aispeakwrite_testsuite`.
