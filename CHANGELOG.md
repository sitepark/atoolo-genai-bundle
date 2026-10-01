# Changelog

## Unreleased

### BREAKING CHANGES

- `genAiQuestion` returns the union `GenAiQuestionResult` of `GenAiAnswer`,
  `GenAiNoDocumentsError`, `GenAiNoMatchingDocumentsError`,
  `GenAiAnswerCutOffError` and `GenAiUnansweredError`, which all implement
  `GenAiAnsweredQuestion { id, feedbackToken, duration }`. The sections are
  the interface `GenAiAnswerSection` with `GenAiTextSection { html }` and
  `GenAiLinksSection { links }`; the hints and `suggestedQuestions` are only
  part of `GenAiNoMatchingDocumentsError`. The field `error`, the field
  `questions` and the types `GenAiAnswerError` and `GenAiAnswerSectionType`
  are removed. In PHP `Assistant::ask()` returns a `QuestionResult`;
  `AnswerError` and `AnswerSectionType` are replaced by the result and
  section classes. Requires the GenAI application with the `QuestionResult`
  union (commit `c116bc5`); release and deploy both together, the previous
  bundle fails against it.
- `genAiAnswerFeedback` takes the `feedbackToken` of the answer instead of
  its `answerId`; `genAiQuestion` now returns the token, and
  `Assistant::feedback()` takes it instead of the answer id. The token is
  valid for 15 minutes by default.

### Features

- Documents without content beyond their title, headline and kicker are no
  longer sent to the GenAI application but deleted from it and logged.
- Events of the events calendar carry their dates, venue, organizers and
  ticket agency as sections of their own, and the categories of venue and
  organizers.
- A contact point names its organisation.

### Bug Fixes

- An answer the model cut off at its maximum number of tokens is reported as
  `GenAiAnswerCutOffError` instead of a successful answer without sections.
- An index request the GenAI application refuses with `409`, because another
  run writes the same source, is sent again after 15, 30 and 60 seconds
  (`GENAI_BUSY_RETRIES`) instead of aborting the index run.
