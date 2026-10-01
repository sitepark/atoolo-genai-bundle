<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * The search found no chunk in the channel, restricted to the category ids
 * of the question. The similarity search found none with a similarity of at
 * least `answer.similarityThreshold`, and the full text search found none
 * with words of the question and a similarity of at least
 * `answer.fullTextSimilarityThreshold`, if the channel uses it
 * (`answer.fullTextTopK` > 0). The model was not asked.
 *
 * @codeCoverageIgnore
 */
class NoDocumentsError extends QuestionResult {}
