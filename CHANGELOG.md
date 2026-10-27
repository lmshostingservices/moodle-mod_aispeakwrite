# Changelog

All notable changes to this plugin are documented here, newest first. This project follows
[Semantic Versioning](https://semver.org/).

## 1.0.2 - 2026-10-26

- Prepare the marking-only release: disable the unpriced conversation partner by default.
- Declare the absence of bundled third-party libraries.
- Use specific text input validation for settings, reports and draft notes while retaining
  the existing cleaned plain-text handling for learner answers.
- Require course login explicitly on the activity index.

## 1.0.1 - 2026-10-26

- **Interface in 18 languages**: English, German, French, Spanish, Italian, Dutch, Portuguese, Brazilian Portuguese,
  Polish, Russian, Ukrainian, Turkish, Arabic, Simplified Chinese, Japanese, Korean, Vietnamese and Thai. The interface
  follows each user's Moodle language.
- The "AI" label on the conversation partner's turns is now translatable.
- Fixed: the 1.0.0 ZIP could not be installed from Site administration because it contained an empty folder.

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
