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
  the mapping lives nowhere else. `getFields()` drops null fields, formats
  dates as `DATE_ATOM`, sends `meta` only when filled, and adds a
  `content_hash` (`sha256:<hash of title, description and content>`) so the
  GenAI application can skip documents whose indexed content did not change.
- `GenAiDocumentFactory` — feeds both the `HttpIndexUpdater` and the document
  dumper, so a dump and an index run always produce the same document.
- `HttpIndexService` / `HttpIndexUpdater` — the `IndexService` and
  `IndexUpdater` ports of the index-bundle. The updater buffers a chunk and
  sends one bulk request; an empty bulk sends nothing.
- `SiteKit\DefaultGenAiDocumentEnricher` — derived from the schema 2.x
  enricher of the search-bundle with semantic field names instead of `sp_*`.
  Everything that only serves Solr's ranking or sorting is left out.

**One index per channel.** Embedding models are multilingual, so documents
carry `language` and `locale` instead of being spread over language specific
indices; `getIndex()` returns the same name for every language.

The indexer runs under the source `genai` and is configured by its own
`configs/indexer/genai.php`, so it can be enabled separately from the solr
indexer (`internal.php`).

### HTTP contract (`src/Service/GenAiHttpClient.php`)

All endpoints live under `{GENAI_URL}/api/v1`. `Authorization: Bearer` is only
sent when `GENAI_API_KEY` is set. Every failure becomes a
`GenAiRequestException`, so no transport detail leaks upwards.

| Purpose | Request |
|---|---|
| health | `GET /health` |
| managed indices | `GET /indices` |
| bulk update | `PUT /indices/{index}/documents` |
| delete by id | `POST /indices/{index}/documents/delete` |
| cleanup by process id | `POST /indices/{index}/documents/cleanup` |
| commit | `POST /indices/{index}/commit` |
| ask | `POST /indices/{index}/ask` |

The counterpart does not exist yet, so this bundle defines the contract.

### Assistant (`src/Assistant.php`, `src/Service/Assistant/`)

`Assistant::ask(Question): Answer`, implemented by `HttpAssistant`, reachable
from the console via `genai:ask <question> [--lang]`.

## Configuration

Environment variables: `GENAI_URL`, `GENAI_API_KEY`, `GENAI_TIMEOUT`.
Scheduling through the index-bundle:
`atoolo_index.indexer.schedules: { genai: '0 3 * * *' }`.

## Conventions

- PHPStan level 9, no baseline
- PER-CS code style
- JSON keys are snake_case
- Tests mirror `src/` under `test/`, PHPUnit 10 attributes; HTTP is tested
  with `MockHttpClient`/`MockResponse`, never against a real endpoint
