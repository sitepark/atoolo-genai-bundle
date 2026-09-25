<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Exception;

/**
 * Why a question could not be asked or a feedback not be given. The case
 * names are the `extensions.classification` of the GraphQL errors, those of
 * the GenAI application as well as the one atoolo uses for system errors.
 */
enum AssistantErrorType: string
{
    /**
     * The question, its language or its categories are not accepted, e.g.
     * too long; the message says why. Also a channel without documents.
     */
    case BAD_REQUEST = 'BAD_REQUEST';
    /**
     * The client, or all clients together, asked too many questions in the
     * last minute; it may ask again later.
     */
    case TOO_MANY_REQUESTS = 'TOO_MANY_REQUESTS';
    /** Anything else: the application is not reachable or failed. */
    case INTERNAL_ERROR = 'INTERNAL_ERROR';
}
