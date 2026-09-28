# Changelog

## Unreleased

### BREAKING CHANGES

- `genAiAnswerFeedback` requires the `feedbackToken` of the answer, which
  `genAiQuestion` now returns; `Assistant::feedback()` takes it as its second
  argument. The token is valid for 15 minutes by default.

### Features

- Documents without content beyond their title, headline and kicker are no
  longer sent to the GenAI application but deleted from it and logged.
- Events of the events calendar carry their dates, venue, organizers and
  ticket agency as sections of their own, and the categories of venue and
  organizers.
- A contact point names its organisation.
