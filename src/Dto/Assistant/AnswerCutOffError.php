<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * The model reached the maximum number of tokens of the channel
 * (`answer.maxTokens`, finish reason `LENGTH` or `MODEL_LENGTH`). The
 * incomplete answer is discarded.
 *
 * @codeCoverageIgnore
 */
class AnswerCutOffError extends QuestionResult {}
