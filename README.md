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
    id
    feedbackToken
    error
    sections { type headline html links { url label } sources { url title } }
  }
}
```

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
