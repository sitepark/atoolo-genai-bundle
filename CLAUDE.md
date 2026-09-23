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
- `GenAiDocumentFactory` — feeds both the `HttpIndexUpdater` and the document
  dumper, so a dump and an index run always produce the same document.
- `HttpIndexService` / `HttpIndexUpdater` — the `IndexService` and
  `IndexUpdater` ports of the index-bundle. The updater buffers a chunk and
  sends one bulk request; an empty bulk sends nothing. The application
  reports only how many documents and chunks it wrote, so a document counts
  as rejected when it is missing from that count.
- `SiteKit\DefaultGenAiDocumentEnricher` — maps a SiteKit resource onto the
  document. Unlike the Solr enricher it does not flatten the resource into one
  string: it walks the content tree and turns every block that carries text or
  links into a section, keeping the editor's HTML, because the application
  converts that markup block by block and chunks along it. The contact point
  is not part of that tree but answers the questions people ask most, so it
  becomes a section of its own - its facts in one list, which the application
  treats as a single block and therefore never tears apart. Plain text of the
  CMS is escaped on its way into the HTML. The url of the document is made
  absolute with `https://` and the `serverName` of the `ResourceChannel`,
  because the application links the sources of an answer; a url that already
  names a host is kept.

**No indices, one source.** The application separates the content of the CMS
instances by the `source` every document carries; it knows no indices.
`getIndex()` therefore never reaches the application, it only names what the
indexer reports its progress under, and `getManagedIndices()` answers with
that one name.

**Known gaps of the remote API.** The document has no place for the access
groups of a resource, so the GenAI index knows no access rights - an answer
may be built from protected content. It has no `language` either, although
the question does. Both are left out rather than smuggled in; they belong in
the API.

The indexer runs under the source `genai` and is configured by its own
`configs/indexer/genai.php`, so it can be enabled separately from the solr
indexer (`internal.php`).

### HTTP contract (`src/Service/GenAiHttpClient.php`)

The client prepends nothing but `{GENAI_URL}`, because the application serves
its parts under different roots; the caller names the full path. `X-API-Key`
is only sent when `GENAI_API_KEY` is set - an empty key disables the indexing
API on the other side. Every failure becomes a `GenAiRequestException`, so no
transport detail leaks upwards.

| Purpose | Request |
|---|---|
| health | `GET /actuator/health` (`{"status":"UP"}`) |
| bulk update | `POST /api/index/documents`, body is a bare list of documents, answers `{documents, chunks}` |
| delete by id | `POST /api/index/documents/delete` `{source, ids}` |
| purge by process id | `POST /api/index/purge` `{source, keepProcessId}` |
| ask | GraphQL `POST /graphql`, query `question(systemPrompt!, userPrompt!, query!, language!, categoryIds)` |

There is no commit; documents are searchable as soon as the bulk request
returns. There is no endpoint that lists indices either.

The contract is the one the application actually serves; `/v3/api-docs` of a
running instance is its OpenAPI description, `/graphql` answers an
introspection. **The assistant does not speak it yet** - it still sends the
REST call this bundle invented before the application existed, and is to be
moved to GraphQL in a step of its own.

### Assistant (`src/Assistant.php`, `src/Service/Assistant/`)

`Assistant::ask(Question): Answer`, implemented by `HttpAssistant`, reachable
from the console via `genai:ask <question> [--lang]`.

## Configuration

The connection is held as its parts, the way the search-bundle holds the Solr
connection, so that each one can be set on its own:

| Variable | Default |
|---|---|
| `GENAI_SCHEME` | `http` |
| `GENAI_HOST` | `localhost` |
| `GENAI_PORT` | `8080` |
| `GENAI_PATH` | *(empty)* |
| `GENAI_API_KEY` | *(empty, no key is sent)* |
| `GENAI_TIMEOUT` | `30` |

Without any of them the bundle talks to `http://localhost:8080`.
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
