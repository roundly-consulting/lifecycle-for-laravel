<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Readme;

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;

/**
 * The README's quick-start definition, verbatim.
 */
final class ListingLifecycle extends LifecycleDefinition
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(ListingStatus::class)
            ->initial(ListingStatus::Draft)
            ->terminal(ListingStatus::Archived);

        $lifecycle->state(ListingStatus::Active)
            ->ttl('30 days')->grace('3 days')->warnBefore('7 days', '1 day')
            ->expiresVia('expire')
            ->quota(5, scope: 'user_id')
            ->stamps('published_at');

        $lifecycle->transition('publish')
            ->from(ListingStatus::Draft)->to(ListingStatus::Active)
            ->ability('publish')
            ->allowSystem();

        $lifecycle->transition('close')
            ->from(ListingStatus::Active)->to(ListingStatus::Closed)
            ->rules(['note' => 'nullable|string|max:500']);

        $lifecycle->transition('reopen')
            ->from(ListingStatus::Closed)->to(ListingStatus::Active)
            ->maxOccurrences(3)->cooldown('1 hour')->requiresReason()
            ->rules(['note' => 'nullable|string|max:500']);

        $lifecycle->transition('expire')
            ->from(ListingStatus::Active)->to(ListingStatus::Expired)
            ->systemOnly();

        $lifecycle->transition('reactivate')
            ->from(ListingStatus::Expired)->to(ListingStatus::Active);

        $lifecycle->transition('archive')
            ->from('*')->to(ListingStatus::Archived)
            ->irreversible();
    }
}
