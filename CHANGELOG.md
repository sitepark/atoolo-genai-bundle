# Changelog

## Unreleased

### BREAKING CHANGES

- `GenAiAnswerSource` has the field `kind` (`GenAiAnswerSourceKind`:
  `DOCUMENT` or `KNOWLEDGE`), and its `url` may be null: a section written
  from a knowledge entry names the internal knowledge base, a text without a
  page of its own, as its source, without url and with the title "Interne
  Wissensbasis"; show it without link. In PHP `AnswerSource::$url` is
  nullable and `AnswerSource::$kind` is new. Before, such a source was
  silently dropped. Requires the GenAI application with knowledge entries
  (commit `9104d74`); release and deploy both together.
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

- The glossaries and knowledge entries the CMS keeps in `configs/genai` of the
  resource channel are sent to the GenAI application, as documents of the
  types `glossary` and `knowledge` of the source `internal`: a full run sends
  all of them, a reported change of one of the files sends them again, and a
  removed one is deleted by the id of its resource. A glossary file returns
  `['id' => …, 'title' => …, 'definitions' => [['terms' => […], 'definition'
  => …]]]`, a knowledge file `['id' => …, 'headline' => …, 'keywords' => […],
  'text' => ['text' => '<p>…</p>']]`; only the German files are read.
  Requires the GenAI application with glossary documents (commit `788d305`).
- Resources of the object types in `GENAI_KNOWLEDGE_OBJECT_TYPES` (comma
  separated, empty by default) are sent as knowledge entries of the type
  `knowledge`: one HTML text of the intro and the text sections, without
  url, date, categories or archive. The GenAI application finds them with a
  search of their own and cites them as the internal knowledge base.
  Requires the GenAI application with knowledge entries (commit `9104d74`).
- Documents without content beyond their title, headline and kicker are no
  longer sent to the GenAI application but deleted from it and logged.
- Events of the events calendar carry their dates, venue, organizers and
  ticket agency as sections of their own, and the categories of venue and
  organizers.
- A contact point names its organisation.
- `genAiQuestion` takes `filter: [GenAiQuestionFilter!]`, conditions on
  `objectTypes` and `categoryIds` combined with `and`, `or` and `not` like the
  `InputFilter` of the search; `genai:ask` takes `--object-type` and
  `--exclude-object-type`. A project sets the filter of a question that
  brings none with `atoolo_genai.assistant.default_filter`
  (`GENAI_DEFAULT_FILTER`, JSON); any filter of the question replaces it,
  `filter: []` (`--no-default-filter`) asks without, also without the default
  filter of the channel in the application, which applies when neither sets
  one. Requires a GenAI application whose `question` takes `filter`.

### Bug Fixes

- An answer the model cut off at its maximum number of tokens is reported as
  `GenAiAnswerCutOffError` instead of a successful answer without sections.
- An index request the GenAI application refuses with `409`, because another
  run writes the same source, is sent again after 15, 30 and 60 seconds
  (`GENAI_BUSY_RETRIES`) instead of aborting the index run.
