![phpstan](https://img.shields.io/badge/PHPStan-level%209-brightgreen)
[![php](https://img.shields.io/badge/PHP-8.1-yellow)](## "is no longer checked automatically")
![php](https://img.shields.io/badge/PHP-8.2-blue)
![php](https://img.shields.io/badge/PHP-8.3-blue)
![php](https://img.shields.io/badge/PHP-8.4-blue)

# Atoolo GenAI bundle

Indexes [resources](https://github.com/sitepark/atoolo-resource-bundle) into an
external GenAI application - embedding and vector database - and asks that
application questions. The GenAI technology itself is not part of this bundle,
just as the Solr server is not part of the search-bundle.

The indexer core comes from
[atoolo/index-bundle](https://github.com/sitepark/atoolo-index-bundle); this
bundle only provides the target implementation, the document, its enricher and
the assistant.

[Documentation](https://sitepark.github.io/atoolo-docs/develop/bundles/genai/)

## Asking through GraphQL

The bundle adds a question and the feedback on its answer to the atoolo
GraphQL schema:

```graphql
query {
  genAiQuestion(query: "Wann hat das Bürgerbüro geöffnet?") {
    __typename
    ... on GenAiAnsweredQuestion { id feedbackToken }
    ... on GenAiAnswer {
      sections {
        __typename
        headline
        sources { kind url title }
        ... on GenAiTextSection { html }
        ... on GenAiLinksSection { links { url label } }
      }
    }
    ... on GenAiNoMatchingDocumentsError {
      hints { headline html sources { kind url title } }
      suggestedQuestions
    }
  }
}
```

The result is a `GenAiAnswer` or an error that says why the question was not
answered: `GenAiNoDocumentsError` (no resource was similar enough),
`GenAiNoMatchingDocumentsError` (none of the resources found answers the
question; with hints how to ask more precisely and suggested questions),
`GenAiAnswerCutOffError` (the answer became too long and was discarded) or
`GenAiUnansweredError` (an error of the GenAI application this version of the
bundle does not know yet). Every result can be rated with its
`feedbackToken`. A question that cannot be asked at all is a GraphQL error in
`errors` with `extensions.classification`: `BAD_REQUEST` (e.g. longer than
1000 characters without the whitespace around it; refused by the bundle
before it asks), `TOO_MANY_REQUESTS` (too many questions now, ask again
later), `SERVICE_UNAVAILABLE` (the application answers no questions at the
moment, e.g. an admin switched them off; ask again later) or
`INTERNAL_ERROR` (the application failed or is not reachable). An HTTP 413,
429 or 503 of the application or a proxy in front of it counts as
`BAD_REQUEST`, `TOO_MANY_REQUESTS` or `SERVICE_UNAVAILABLE`.

```graphql
mutation {
  genAiAnswerFeedback(feedbackToken: "t-1", feedback: GOOD)
}
```

The feedback takes the `feedbackToken` the answer came with, no answer id; the
GenAI application finds the answer from the token. It is valid for 15 minutes
by default; within that time the feedback can be set, changed or withdrawn
(`feedback: null`) as often as wanted, afterwards the mutation returns
`false`. An answer without a token cannot be rated. Keep the token in
the memory of the page only, never in `localStorage` or the URL.

## Glossary and knowledge entries of the configuration

The CMS keeps a glossary and knowledge entries as configuration of the
resource channel, in `configs/genai/glossary/*.php` and
`configs/genai/knowledge/*.php`. The GenAI indexer sends them to the GenAI
application with the internal resources: a full run sends all of them, a
reported change of one of the files sends that file again, and a removed one is
deleted by the id of its resource. The CMS reports a change of a file as one
of the publication type `CONFIG`, which needs IES 3.27 with the ies-webnode
admin module; an older CMS reports the publication of a configuration as a
removal.

```php
// configs/genai/glossary/<name>.php: one glossary
<?php return [
    'id' => 40540,                     // id of the CMS resource
    'title' => 'Stadtteile',           // optional, else the file name
    'definitions' => [
        ['terms' => ['OB'], 'definition' => 'Oberbürgermeister'],
        ['terms' => ['perso', 'persi'], 'definition' => 'Personalausweis'],
    ],
];

// configs/genai/knowledge/<name>.php: one knowledge entry
<?php return [
    'id' => 40541,
    'headline' => 'Stuttgart 21 – Aktueller Stand',
    'keywords' => ['S21'],             // optional
    'text' => ['modelType' => 'html.richText', 'text' => '<p>…</p>'],
];
```

The glossaries of a channel together are the glossary the application
normalizes and searches its questions with: a question with a term is
searched with what the pages call it. A term must contain neither a comma nor
an equals sign. Only the German files are read; a file without `id`, or
without entries or text, is skipped with a warning in the log.

## Busy index

The GenAI application lets one request at a time write a source. A request
that waits longer than the application's `GENAI_INDEX_LOCK_TIMEOUT` - for
instance an incremental update after a publish while a bulk of a full run is
embedding - is refused with `409`. The bundle then sends the index request
again after a pause, by default after 15, 30 and 60 seconds, and only fails
once every attempt was refused. Other requests and other statuses are never
repeated.

`GENAI_BUSY_RETRIES` sets the pauses in seconds, comma separated
(`15,30,60`); the number of pauses is the number of retries, an empty value
disables the retry. The lock timeout of the application must stay below
`GENAI_IDLE_TIMEOUT` (300 seconds by default), so that the application
answers with `409` before the bundle gives up the connection.
