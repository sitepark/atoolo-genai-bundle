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
