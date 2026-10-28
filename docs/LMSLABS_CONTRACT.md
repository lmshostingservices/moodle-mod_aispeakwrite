# LMS Labs routes needed by AI Advent Speaking and Writing

This is the contract `mod_aispeakwrite` 1.0.0 calls. Until LMS Labs publishes these routes they answer 404, and the
plugin tells learners "Marking isn't switched on for this site yet" (nothing is charged, the answer stays saved).

All requests go server-to-server from Moodle to `https://lms-labs.com`. Redirects are never followed.

## Common headers

| Header | Value |
| --- | --- |
| `X-Site-ID` | The site's LMS Labs Site ID |
| `X-API-Key` | The site's LMS Labs API key |
| `Content-Type` | `application/json` |
| `Accept` | `application/json` |
| `Idempotency-Key` | A UUID. Moodle saves it with the exact body **before** sending. Every retry of the same request uses the same key and the same body. |

## Charging and idempotency rules the plugin relies on

1. Charge only when a result is delivered (HTTP 200). Report `creditsCharged` in the answer.
2. The same `Idempotency-Key` sent again must return the stored result of the first request, without charging again.
3. A refusal (4xx, or a 5xx listed below as "provider refused") means nothing was charged. Moodle then ends the request,
   and the learner's next click is a new request with a new key.
4. `202` means still working: Moodle asks again later with the same key (honours `Retry-After`, 1–60 s).
5. Any other 5xx, a timeout or no answer is treated as **uncertain**: Moodle keeps the request pending and only ever
   resends it with the same key and body.
6. `410` means LMS Labs no longer has the request: Moodle ends it and the learner can submit again.

| Status / code | Moodle's reading |
| --- | --- |
| 200 | Delivered |
| 202 | Pending, ask again with the same key |
| 401 | Refused: `INVALID_CREDENTIALS` |
| 402 or `INSUFFICIENT_CREDITS` | Refused |
| 403 | Refused: `NO_ENTITLEMENT` |
| 404 | Refused: route not live |
| 409 | Refused: `IDEMPOTENCY_CONFLICT` (same key, different body) |
| 410 | Gone |
| 429 | Refused: `RATE_LIMITED` |
| 5xx with `PROVIDER_UNAVAILABLE`, `PROVIDER_FAILED`, `PROVIDER_RATE_LIMITED`, `INVALID_PROVIDER_RESULT`, `TARIFF_NOT_APPROVED` | Refused, nothing charged |
| 5xx with `SETTLEMENT_UNCONFIRMED`, `DEADLINE_EXCEEDED`, or any other code | Uncertain |

Error bodies: `{"error": {"code": "INSUFFICIENT_CREDITS", "message": "…"}, "requestId": "…"}`.

## 1. Mark an answer — `POST /api/moodle/ai-speak-write/mark`

**Tariff requested: 1 credit per delivered result.** Learners trigger this route (teachers set a daily limit per
learner in Moodle). A teacher's "Test with a sample answer" uses the same route with `"test": true`.

### Request

```json
{
  "kind": "writing",
  "taskType": "email",
  "mode": "think",
  "level": "B1",
  "targetLanguage": "de-DE",
  "feedbackLanguage": "en",
  "draft": 1,
  "task": {
    "title": "Geburtstagsfeier",
    "situation": "Sie haben von einem Freund folgende E-Mail erhalten:",
    "sourceText": "Lieber …, am Samstag werde ich 30! …",
    "points": [
      {"id": "p1", "text": "Ob Sie zur Feier kommen", "minCount": 1},
      {"id": "p3", "text": "Machen Sie Vorschläge", "minCount": 2}
    ],
    "formality": "du",
    "targetWords": 150
  },
  "tips": [
    {"id": "tip:satzanfang", "name": "Satzanfang", "tip": "Nicht mit ich/wir beginnen – mit einem Detail starten.",
     "example": "Am Samstag komme ich gern.", "level": "school"}
  ],
  "patterns": [{"id": "grammar:verbsecond", "name": "Verb in second position"}, "…"],  // the set for targetLanguage
  "scoring": {"criteria": ["task", "communication", "form"], "bands": [0, 3, 9, 15], "examScore": true},
  "options": {"markGood": true},
  "answer": {"text": "Lieber Tim, …", "previousText": "(only on draft 2+) the previous marked draft"},
  "history": [{"id": "tip:satzanfang", "name": "Satzanfang", "recent": "2/5"}],
  "test": false
}
```

Speaking adds:

```json
{
  "kind": "speaking",
  "taskType": "speak3",
  "conversation": [{"role": "partner", "text": "…"}, {"role": "learner", "text": "…"}],
  "partnerBrief": "Your colleague. Prefers the office kitchen.",
  "speech": {"seconds": 400, "speakingSeconds": 190, "longPauses": 2, "wordsPerMinute": 96}
}
```

For speaking, `answer.text` is the learner's turns joined with a newline. Mark offsets refer to that text.

No learner name, email, Moodle user id or other identifier is sent.

### Marking guidance for the model

- **Make me think** (`mode: think`): every error mark must have a `hint` that points to the rule or school tip without
  giving the answer, plus `rule` and `examples` (shown only when the learner asks). Still return `correction` and
  `why`: Moodle stores them and shows them only after the rewrite.
- **Explain** (`mode: explain`): `correction`, `why`, `rule`, `examples`.
- Cite tips and patterns **only** by the ids sent. Any other id is dropped by Moodle.
- `targetLanguage` is the language being learned: `de-DE`, `de-AT`, `de-CH`, `en-GB`, `en-US`, `fr-FR`, `es-ES`, `it-IT`,
  `nl-NL` or `pt-PT`. Mark and correct in that language; write hints, explanations and rules in `feedbackLanguage`, keeping
  grammar terms of the language being learned (Dativ, subjonctif, ser/estar) as they are. `patterns` holds the shared
  patterns plus those for that language (for example `grammar:subjunctive` for French, `grammar:serestar` for Spanish).
- `task.formality` is `du` (informal), `sie` (formal) or `any`, whatever the language: tu/vous, tú/usted, tu/Lei, jij/u,
  tu/você. English has no separate forms; treat it as the register of the text.
- `examples`: 2–3 short new sentences that use the same rule, at the task's level, on the task's topic, in the
  same register.
- Scores per criterion: `atg` (the school's strict score) and `telc` (realistic exam score), each one of 0, 3, 9, 15.
  The exam score is never below the strict one.
- Speaking: judge whether the learner was understood and kept the conversation going. **Saying something wrong and
  then fixing it is good speaking, not a mistake** (Advent Training Group's rule): mark it `selfcorrection`, praise
  it, and do not lower any score for it. Hesitations, restarts and fillers ("äh", "ich meine") are normal and not
  penalised. If the overall message was understood and the conversation kept flowing, communication and task scores
  should reflect that even when some forms were wrong. Use `severity: "unclear"` only for mistakes that made the
  learner hard to follow. Do not count errors for their own sake.
- Moodle also checks this itself: a speaking `error` whose `correction` the learner says straight after the slip (in
  the same sentence) is stored as a `selfcorrection`.
- Content: one entry per task point, quoting the learner's own sentence, with status `covered`, `extended`, `short`,
  `missing` or `mismatch` (e.g. the point asks for two suggestions and the learner made one). For missing points give
  2–3 phrases the learner could have used.

### Response 200

```json
{
  "requestId": "r_123",
  "creditsCharged": 1,
  "creditsBalance": 4819,
  "result": {
    "summary": "You accept and congratulate. Point 3 is missing.",
    "understood": true,
    "criteria": {
      "task": {"atg": 9, "telc": 9, "reason": "Point 3 missing"},
      "communication": {"atg": 9, "telc": 15, "reason": "Few connectors"},
      "form": {"atg": 9, "telc": 9, "reason": "Verb position, Dativ"}
    },
    "points": [
      {"id": "p3", "status": "missing", "quote": "", "comment": "Offer to bring food.",
       "phrases": ["Soll ich etwas mitbringen?", "Ich könnte einen Kuchen backen."]}
    ],
    "marks": [
      {"type": "error", "text": "den Einladung", "start": 26, "end": 39, "correction": "die Einladung",
       "severity": "major", "ruleId": "grammar:article", "hint": "Which gender is Einladung?",
       "why": "Einladung is feminine, so after für it is die.", "rule": "Nouns ending in -ung are feminine.",
       "examples": ["Danke für die Nachricht.", "die Wohnung · die Zeitung"]},
      {"type": "good", "text": "Herzlichen Glückwunsch", "ruleId": null, "why": "Fixed exam phrase used correctly."},
      {"type": "selfcorrection", "text": "mit meine … mit meiner", "correction": "mit meiner", "why": "You fixed it."}
    ],
    "communication": {
      "strengths": ["Clear informal register"],
      "improve": [{"ruleId": "tip:satzanfang", "quote": "Ich selbst habe viele Erfahrung …",
        "tip": "Start with a detail.",
        "rewrites": [{"ruleId": "tip:satzanfang", "text": "Mit Geburtstagsfeiern habe ich viel Erfahrung."}]}]
    },
    "vocabulary": [{"word": "und", "count": 5, "alternatives": ["außerdem", "dazu"]}],
    "ruleHits": [{"ruleId": "tip:satzanfang", "ok": false}],
    "focus": ["grammar:verbsecond", "grammar:casedative"],
    "fluency": {"advice": "Two long pauses. Keep talking with „Wie sagt man …?“"},
    "pronunciation": [{"word": "Geschenk", "tip": "Ends in -k, not -kt"}]
  }
}
```

`text` must be an exact substring of the answer. `start`/`end` are character offsets (Unicode code points) and are
optional: Moodle searches for `text` when they are missing or wrong. Marks that don't match the answer are dropped.

## 2. AI partner's turn — `POST /api/moodle/ai-speak-write/converse`

**Tariff to agree.** Called once per partner turn in speaking conversations (at most 20 per conversation; admins can
switch the partner off). An empty `turns` list asks the partner to open the conversation.

```json
{
  "taskType": "speak3",
  "level": "B1",
  "targetLanguage": "de-DE",
  "task": {"title": "Abschiedsfeier", "situation": "…", "sourceText": "…", "points": ["Wann?", "Wo?"]},
  "partnerBrief": "Your colleague. Prefers the office kitchen.",
  "tips": [{"name": "zwar-aber", "tip": "…"}],
  "turns": [{"role": "learner", "text": "Hallo Lena! Was hältst du davon, wenn wir am Freitag feiern?"}]
}
```

Response 200: `{"reply": "Freitag passt mir gut. Aber wo?", "done": false, "creditsCharged": 0}`.

The partner should speak at the task's level, keep each turn short (1–3 sentences), disagree sometimes, ask
questions that bring the learner back to uncovered task points, and never correct the learner during the conversation.

## 3. Balance — `GET /api/credits?siteId=…`

Already live. Moodle reads `isUnlimited` or `creditsRaw` (also `credits`, `creditsBalance`, `balance`).
