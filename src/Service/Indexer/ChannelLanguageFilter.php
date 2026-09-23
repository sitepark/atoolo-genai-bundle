<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer;

use Atoolo\Index\Service\Indexer\ResourceFilter;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceLanguage;

/**
 * Lets only resources in the language of the channel through, the
 * translations are left out of the GenAI index for now.
 *
 * A resource carries the language of its own `locale`, so one in the
 * channel language is not `ResourceLanguage::default()` but e.g. `de`.
 */
class ChannelLanguageFilter implements ResourceFilter
{
    public function __construct(
        private readonly ResourceFilter $filter,
        private readonly ResourceChannel $resourceChannel,
    ) {}

    public function accept(Resource $resource): bool
    {
        $channelLang = ResourceLanguage::of($this->resourceChannel->locale);
        return $resource->lang->code === $channelLang->code
            && $this->filter->accept($resource);
    }
}
