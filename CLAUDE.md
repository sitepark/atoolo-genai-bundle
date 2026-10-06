# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Atoolo GenAI Bundle** is a Symfony bundle that indexes resources of
`atoolo/resource-bundle` into an external GenAI application - embedding plus
vector database - and asks that application questions. The GenAI technology
itself is not part of this bundle, just as the Solr server is not part of the
search-bundle.

The bundle has two areas:

- **Indexer** — the GenAI index as a target of `atoolo/index-bundle`
- **Assistant** — asking the GenAI application questions

## Common Commands

```bash
composer install
composer analyse          # phplint, phpstan level 9, php-cs-fixer, compatibility
composer fix              # auto-fix code style
composer test             # phpunit with coverage
./tools/phpunit.phar -c phpunit.xml --no-coverage --filter SomeTest
```

## Architecture

The indexer core - the `Indexer` interface, the CMS side configuration, the
enricher mechanics, status, abortion, the console commands and the scheduler -
comes from `atoolo/index-bundle`. Nothing of that is copied here. This bundle
only provides the target implementation, the document, its enricher and the
assistant.

### Indexer (`src/Service/Indexer/`)

- `GenAiDocument` — the payload. Its property names **are** the JSON keys, so
  the mapping lives nowhere else. `jsonSerialize()` drops null fields and
  empty lists and formats the date as `DATE_ATOM`. The application knows two
  kinds of document and picks them by `type`: an `article` carries the
  sections it is made of, a `media` the text the CMS extracted from a binary
  asset. Both live in this one class, because the enricher receives the
  document from the factory before it has seen the resource and may only fill
  it in, never exchange it - the port types `enrichDocument()` as returning
  what it was given.
- `Dto\Indexer\` — the parts of a document: `Category` (a tree, through
  `parent`), `TextSection` (`headline` + `html`), `LinkSection`
  (`headline` + `Link[]`).
- `GenAiDocument::contentHash()` — makes a full run a sync. Every document
  carries a `hash` of its payload without the `processId`; the application
  skips the embedding of a document whose hash it already holds and only
  takes over the new `processId`, so the purge at the end of the run keeps
  it. The hash is taken from the finished document, not from the
  `generated` of the resource, because the enricher pulls in data the
  resource does not change with: the inherited kicker, category titles, the
  `serverName`, the keywords of other enrichers, the mapping itself.
- `GenAiDocumentFactory` — feeds both the `HttpIndexUpdater` and the document
  dumper, so a dump and an index run always produce the same document. It
  sets the `channel`, which is no property of the resource.
- `HttpIndexService` / `HttpIndexUpdater` — the `IndexService` and
  `IndexUpdater` ports of the index-bundle. The updater buffers a chunk and
  sends one bulk request; an empty bulk sends nothing. The application
  reports only how many documents and chunks it wrote and how many it left
  unchanged, so a document counts as rejected when it is missing from both
  counts.
- `ContentBeyondTitle` — a document whose text, apart from the words of
  `title`, `headline` and `kicker`, has fewer than 20 letters or digits
  (a job offer with "51 Jugendamt", a test article with "fsdfdfdf") answers
  no question. The text is the `intro`, the section headlines and the HTML
  of the text sections, with the alt text of images; link sections do not
  count, a medium counts its `rawText`. The rule mirrors `ContentBeyondTitle`
  of the application, which skips such a document as a safety net, and must
  never be stricter. The `HttpIndexUpdater` - the first to see the finished
  document, with what the enrichers of other bundles added - does not send
  it but logs it and deletes its id, so an article that became empty leaves
  the index with an incremental update, not only with the purge of the next
  full run. A filtered document counts neither as accepted nor as rejected.
  The dumper still shows every document.
- `SiteKit\DefaultGenAiDocumentEnricher` — maps a SiteKit resource onto the
  document. Unlike the Solr enricher it does not flatten the resource into one
  string: it walks the content tree and turns every block that carries text or
  links into a section, keeping the editor's HTML, because the application
  converts that markup block by block and chunks along it. The contact point
  is not part of that tree but answers the questions people ask most, so it
  becomes a section of its own - its facts in one list, which the application
  treats as a single block and therefore never tears apart. The opening
  hours of the contact point are a section of their own, one list of days
  per week block, followed by the editor's additional text. Plain text of the
  CMS is escaped on its way into the HTML. An article carries `kicker` and
  `intro` next to its `headline`: the kicker is the one of the teaser or the
  resource, otherwise inherited from the nearest navigation ancestor that has
  one, as the `ResourceKickerResolver` of the graphql-search-bundle does; the
  intro is `metadata.intro`, falling back to `metadata.description`, escaped
  into a paragraph, because the application reads it as HTML. Every
  document carries the `keywords` the text may not contain: `metadata.keywords`
  and `metadata.boostKeywords`, added through `GenAiDocument::addKeywords()`,
  which other enrichers - the synonyms of the citygov-bundle - use as well;
  the application embeds them with every chunk of the document. The url
  of the document is made absolute with `https://` and the `serverName` of the
  `ResourceChannel`, because the application links the sources of an answer; a
  url that already names a host is kept.
- `SiteKit\ContactPointSections` — renders a SiteKit contact point: its
  facts, led by the name of the organisation, in one list, the notices as
  paragraphs, the opening hours as a section of their own. The default
  enricher uses it for `metadata.contactPoint`, the event enricher for the
  contact points inside an event.
- `SiteKit\EventGenAiDocumentEnricher` — adds the facts of an
  `eventsCalendar-event`, which are not part of its text: the dates of
  `metadata.scheduling` as one list "Termine" - the days of a date over
  several days joined again, a status other than `available` named, at most
  15 dates and then the last one - and the `eventsCalendar.contactSection`s
  of the content (venue, ticket agency, organizers), whose contact points
  become sections of their own and whose categories are added to the
  document. An event has no `metadata.contactPoint`. The `date` of an event
  stays unset: its dates are text, not the age of the document. It runs
  after the default enricher (priority 50), so its sections follow the
  description; a project adds its own event data after it (priority < 50).

**The index is the channel.** The application separates its indices by the
`channel`, the way Solr does by its cores. Every document, delete and purge
carries it, and it is the index name: `getIndex()`, the `searchIndex` of the
`ResourceChannel`, the same for every language. Within a channel the content
is told apart by the `source`. The application lists no channels to an
indexer, so `getManagedIndices()` answers with that one name.

**Known gaps of the remote API.** The document has no place for the access
groups of a resource, so the GenAI index knows no access rights - an answer
may be built from protected content. It has no `language` either, although
the question does. Both are left out rather than smuggled in; they belong in
the API.

**Only the channel language.** For now the translations are not indexed.
`ChannelLanguageFilter` wraps the `ResourceFilter` of the index-bundle and
rejects every resource whose language is not the `locale` of the
`ResourceChannel`; the solr indexer keeps the unwrapped filter.

**Id and source.** The indexer reads the same resources as the solr
indexer, so its documents carry the same source, `internal`. What sets it
apart is its id `genai` (see `AbstractIndexer` of the index-bundle): it
selects the indexer on the console (`--indexer genai`) and in the schedule,
keys its status and names its configuration `configs/indexer/genai.php`, so
it can be enabled separately from the solr indexer (`internal.php`). The
progress state and the dumper are given the id as well.

### HTTP contract (`src/Service/GenAiHttpClient.php`)

The client prepends nothing but `{GENAI_URL}`, because the application serves
its parts under different roots; the caller names the full path. `X-API-Key`
is only sent when `GENAI_API_KEY` is set - an empty key disables the indexing
API on the other side. Every failure becomes a `GenAiRequestException`, so no
transport detail leaks upwards.

| Purpose | Request |
|---|---|
| health | `GET /actuator/health` (`{"status":"UP"}`) |
| bulk update | `POST /api/index/documents`, body is a bare list of documents, answers `{documents, chunks, unchanged}` |
| delete by id | `POST /api/index/documents/delete` `{channel, source, ids}` |
| purge by process id | `POST /api/index/purge` `{channel, source, keepProcessId}` |
| ask | GraphQL `POST /graphql`, query `question(query!, language!, channel!, categoryIds)` |
| feedback | GraphQL `POST /graphql`, mutation `answerFeedback(feedbackToken!, feedback)` |

The application lets one request at a time write a source and refuses an
index request with `409` once it waited `GENAI_INDEX_LOCK_TIMEOUT` for
another. `GenAiHttpClient::request()` sends a request under `api/index/`
again after each pause of `GENAI_BUSY_RETRIES` and only then throws, with a
message naming the other index run; other paths and statuses are never
repeated. Tests inject the `sleep` callable, so they never wait.

There is no commit; documents are searchable as soon as the bulk request
returns. A document requires its `channel` - at most 64 letters, digits,
`.`, `_` or `-`. Its `hash` is expected to let the application skip an
unchanged document; `unchanged` in the answer counts those, and the
indexer shows the number in its status line. Until the
application compares it, it is ignored and every document is embedded.

The contract is the one the application actually serves; `/v3/api-docs` of a
running instance is its OpenAPI description, `/graphql` answers an
introspection. `GenAiHttpClient::graphql()` sends an operation and returns its
`data`; the `errors` GraphQL reports with status 200 become a
`GenAiRequestException` as well. A GraphQL operation carries the client ip of
the current request in `X-Forwarded-For`, so the application can limit
requests per ip: only `Request::getClientIp()`, which honours the trusted
proxies, never the chain the caller sent along, which could be forged. Without
a request - on the console - the header is left out; the REST calls of the
indexer never carry it.

### Assistant (`src/Assistant.php`, `src/Service/Assistant/`)

`Assistant::ask(Question): QuestionResult` and
`Assistant::feedback(feedbackToken, feedback): bool`, implemented by
`HttpAssistant`. The result is modelled as the application delivers it,
"errors as data": `question` returns the union `QuestionResult` and
`HttpAssistant` maps it by its `__typename`. Every result carries an `id`, a
`feedbackToken` and the `duration`, because every one is stored and can be
rated; only an `Answer` is cached by the application.

- `Answer` - the `sections`, an `AnswerTextSection` with `html` or an
  `AnswerLinksSection` with `links`, each with `headline` and `sources`; a
  section of another type is skipped. The prefix keeps them apart from
  `Dto\Indexer\TextSection` and `LinkSection`.
- `NoDocumentsError` - the search found no chunk similar enough, the model
  was not asked.
- `NoMatchingDocumentsError` - the model found none of the chunks to answer
  the question; the only result with `hints` (text sections) and
  `suggestedQuestions` (at most three), both possibly empty.
- `AnswerCutOffError` - the model reached `answer.maxTokens` of the channel;
  the incomplete answer is discarded.
- `UnansweredError` - any other or missing `__typename`, with the
  `typeName` of the application. The application may add errors, so an
  unknown one is a result, never an exception.

The DTOs in `Dto\Assistant\` mirror the public types of the application one
to one. The channel is the `searchIndex`
of the `ResourceChannel`; a question without a language is asked in the one
of the channel, because the application requires it. Reading the feedback
back is not public in the application, so it is not offered; a frontend keeps
what it has set.

**Feedback only with the token.** The application binds the feedback to the
user who asked: the feedback takes no answer id but only the
`feedbackToken` of the answer, from which the application finds the answer.
It works for 15 minutes by default and as often as wanted; the token lives in
the memory of the application only. After that, after a restart, with an
unknown token or for an answer whose content was deleted, the feedback
returns `false`. An answer
without a token (`null`) cannot be rated. The token is a secret of the user
who asked: the bundle passes it on, never stores it (no session, cache or
database) and never puts it into a log, an exception or an error message.

**Through GraphQL, not passed through.** `GraphQL\Assistant` adds
`genAiQuestion(query!, lang, categoryIds): GenAiQuestionResult!` and the
mutation `genAiAnswerFeedback(feedbackToken!, feedback): Boolean!` to the
atoolo schema; the types are in `config/graphql/types`, prefixed with
`GenAi`. They follow the atoolo convention "errors as data" as well: the
union `GenAiQuestionResult` of `GenAiAnswer`, `GenAiNoDocumentsError`,
`GenAiNoMatchingDocumentsError`, `GenAiAnswerCutOffError` and
`GenAiUnansweredError`, all implementing the interface
`GenAiAnsweredQuestion { id, feedbackToken, duration }`; the sections
implement `GenAiAnswerSection { headline, sources }` as `GenAiTextSection`
and `GenAiLinksSection`. `GenAiUnansweredError` is part of the union so
that an error the bundle does not know yet still has a type. The types are
YAML, so overblog cannot map them by their PHP class: the `resolveType` of
the union and both interfaces calls the public service
`atoolo_genai.graphql.type_resolver` (`GraphQL\TypeResolver`), which maps
by `instanceof`. A type only an interface leads to - `GenAiLinksSection`
is named by no field - would be missing from the schema, so
`config/graphql.yaml` lists the section types in `definitions.schema.types`,
which overblog merges with those of the other bundles. The request of the caller is never handed on as it is: the client
may carry an API key that grants far more than the public fields, and the
channel is not the caller's choice, so `HttpAssistant` sends fixed
operations of its own.
`config/graphql.yaml` is only loaded when the overblog bundle is registered.

**Errors keep their classification.** The application refuses a question
with a GraphQL error whose `extensions.classification` says why:
`BAD_REQUEST` (a question over 1000 characters, a language that is no ISO
639 code, more than 20 categories, a channel without documents, more than
one question in an operation) or `TOO_MANY_REQUESTS` (the limit per client
or in total). `GenAiGraphQlException` keeps the classification and message of
the first error, `HttpAssistant` turns these two into an `AssistantException`
of that `AssistantErrorType` with the application's message; everything else
is an `INTERNAL_ERROR`. `GraphQL\AssistantError` is the field error: a
`UserError` - so overblog neither hides its message nor rethrows it, as the
graphql-search-bundle sets `rethrow_internal_exceptions` - that provides
`extensions.classification`, the convention of the atoolo GraphQL API. An
error for the caller carries no previous exception, because overblog's error
logger would log it; an `INTERNAL_ERROR` gets a general message, since its
own names the address of the application, and keeps the cause as previous
so that it is logged.
Its fields attach to the attribute-defined `RootQuery`/`RootMutation` of the
graphql-search-bundle.

From the console: `genai:ask <question> [--lang] [--category ...]`.

## Configuration

The connection is held as its parts, the way the search-bundle holds the Solr
connection, so that each one can be set on its own:

| Variable | Default |
|---|---|
| `GENAI_SCHEME` | `http` |
| `GENAI_HOST` | `localhost` |
| `GENAI_PORT` | `8385` |
| `GENAI_PATH` | *(empty)* |
| `GENAI_API_KEY` | *(empty, no key is sent)* |
| `GENAI_IDLE_TIMEOUT` | `300` |
| `GENAI_BUSY_RETRIES` | `15,30,60` *(seconds between the attempts of an index request refused with 409, empty disables)* |

Without any of them the bundle talks to `http://localhost:8385`.
`GENAI_IDLE_TIMEOUT` is the seconds the client waits for the next byte of an
answer - the `timeout` of the Symfony http client, not the duration of a
request. The application answers a bulk only once it has embedded every
changed document of the chunk and sends nothing before, so the default is
generous.
`Service\EnvVarLoader` takes a `GENAI_URL` apart into scheme, host, port and
path, so an environment that knows the application as one address can set
that instead - the same way `SOLR_URL` works in the search-bundle.
`atoolo_genai.connection.url` is assembled from the parts and is what
`GenAiHttpClient` is built with.

Scheduling through the index-bundle:
`atoolo_index.indexer.schedules: { genai: '0 3 * * *' }`.

## Conventions

- PHPStan level 9, no baseline
- PER-CS code style
- JSON keys are camelCase, as the remote API spells them
- Tests mirror `src/` under `test/`, PHPUnit 10 attributes; HTTP is tested
  with `MockHttpClient`/`MockResponse`, never against a real endpoint
