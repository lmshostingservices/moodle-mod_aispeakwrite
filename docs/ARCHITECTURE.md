# Architecture

For developers maintaining `mod_aispeakwrite`. The README covers features and administration.

## Layers

| Layer | Files | Role |
| --- | --- | --- |
| Moodle callbacks | `lib.php` | Instance add/update/delete, grades, completion info, navigation, course reset. |
| Tasks and limits | `classes/local/manager.php` | Who sees a task, correction mode per draft, daily and rewrite limits, bands. |
| Tips | `classes/local/tips.php` | Parses "Name: tip \| example" at school, activity and task level; fixed grammar patterns; points and phrases. |
| Answers | `classes/local/submissions.php` | Drafts, autosave, the next draft after a marked one, best score per task. |
| Marking | `classes/local/marker.php` | Builds the request, sends it, checks and cleans the result, stores it, updates memory, grades and completion. |
| Paid requests | `classes/local/operation.php`, `remote.php` | Each paid request is saved with its key and exact body before sending; fixed-host transport; outcome rules. |
| Memory | `classes/local/memory.php` | Each learner's last five results per tip and pattern; focus areas; history sent with markings. |
| Credentials | `classes/local/credentials.php` | Central Config first, then the plugin's own pair, never mixed. |
| Screens | `classes/output/views.php`, `teacher.php`, `templates/*.mustache` | Template data; text escaped by Mustache; only multi-line task text is pre-built with s() + nl2br. |
| Browser | `amd/src/*.js` | `writer` (autosave, insert, time plan, submit), `feedback` (mark ↔ note, reveal, say again), `speaker` (turns, partner, pauses, submit), `speech` (recognition, level meter, TTS), `practice` (filters). |
| Web services | `classes/external/*.php` | save_draft, submit, submit_speaking, converse. All check context and `mod/aispeakwrite:practise`, and load only the caller's own draft. |

## Marking flow

1. The learner submits. Drafts below the minimum length are refused before anything is sent.
2. If the same text was already marked in the same mode with the same tips version, that result is reused (no charge).
3. Limits are checked (rewrites per task, markings per day).
4. `operation::claim()` saves the request with a new Idempotency-Key and its exact JSON body, or returns the pending
   one for this draft. The draft becomes `pending`.
5. `remote::request()` sends it. No answer: the exception leaves everything pending, and the next click resends the
   same key and body. A refusal ends the request (nothing charged) and the draft goes back to `draft`.
6. A 200 is checked by `marker::normalise()`: three criteria required, scores snapped to 0/3/9/15, exam ≥ strict,
   points limited to the task's own ids, marks must match the learner's text (offsets corrected or dropped), rule ids
   limited to the tips and patterns that were sent, all text cleaned and length-limited.
7. The result is stored, the request completed with the credits reported, memory updated, grades, completion and the
   `submission_marked` event run.

"Make me think" feedback is stored complete, but the feedback page leaves corrections and explanations out of the
HTML in that mode, so they can't be read from the page source.

## Speaking

Recognition runs in the browser (`speech.js` Listener, restarting when the browser stops early). A level meter
measures speaking time, long pauses (over 3 s while it is the learner's turn) and a flow chart (share of speech per
5 s). Exam mode ends a turn after 2.2 s of silence. The AI partner's text comes from LMS Labs (`converse`) and is
spoken with the browser's own voice. Only text and these figures reach Moodle.

## Tests

`tests/*_test.php` cover the tips parser, result checking, the full paid flow with a fake LMS Labs (delivered,
identical text, refusal, uncertain with the same key, daily limit, too short, unusable result, missing credentials,
rewrite in Explain mode), credentials, memory, privacy, duplicate (backup/restore) and the web services.
