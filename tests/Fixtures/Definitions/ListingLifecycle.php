<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions;

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;

final class ListingLifecycle extends LifecycleDefinition
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(ListingStatus::class)
            ->initial(ListingStatus::Draft)
            ->terminal(ListingStatus::Archived);

        $lifecycle->state(ListingStatus::Active)
            ->ttl('30 days')
            ->grace('3 days')
            ->warnBefore('1 day', '7 days')
            ->expiresVia('expire')
            ->stamps('published_at');

        $lifecycle->transition('publish')
            ->from(ListingStatus::Draft)
            ->to(ListingStatus::Active);

        $lifecycle->transition('close')
            ->from(ListingStatus::Active)
            ->to(ListingStatus::Closed);

        $lifecycle->transition('reopen')
            ->from(ListingStatus::Closed)
            ->to(ListingStatus::Active)
            ->maxOccurrences(3);

        $lifecycle->transition('expire')
            ->from(ListingStatus::Active)
            ->to(ListingStatus::Expired)
            ->systemOnly();

        $lifecycle->transition('reactivate')
            ->from(ListingStatus::Expired)
            ->to(ListingStatus::Active);

        $lifecycle->transition('archive')
            ->from('*')
            ->to(ListingStatus::Archived)
            ->irreversible();
    }
}
