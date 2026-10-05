<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/lifecycle-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=lifecycle-for-laravel">
    <img src="https://raw.githubusercontent.com/roundly-consulting/lifecycle-for-laravel/main/art/hero.png" alt="Lifecycle for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/lifecycle-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/lifecycle-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/lifecycle-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/lifecycle-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/lifecycle-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/lifecycle-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=lifecycle-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Lifecycle for Laravel

Status lifecycles for any Eloquent model — orders, tickets, listings, subscriptions. Named
transitions behind one guard pipeline, race-free quotas, expiry and scheduled transitions,
rollbacks and an append-only history.

## Installation

Requires PHP 8.4, Laravel 12 or 13, and SQLite, PostgreSQL or MySQL/MariaDB.

```bash
composer require roundly-consulting/lifecycle-for-laravel
php artisan vendor:publish --tag="lifecycle-migrations"
php artisan migrate
```

If your models or users have UUID/ULID keys, set `LIFECYCLE_KEY_TYPE` /
`LIFECYCLE_ACTOR_KEY_TYPE` **before** migrating. States with a TTL need the sweep scheduled:
`Schedule::command('lifecycle:sweep')->everyMinute();`.

## Usage

Define the lifecycle once (`php artisan make:lifecycle ListingLifecycle` generates the class):

```php
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

final class ListingLifecycle extends LifecycleDefinition
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(ListingStatus::class)   // a backed enum: draft, active, closed, expired
            ->initial(ListingStatus::Draft);

        $lifecycle->state(ListingStatus::Active)
            ->ttl('30 days')->expiresVia('expire')
            ->quota(5, scope: 'user_id');           // at most 5 active listings per user

        $lifecycle->transition('publish')->from(ListingStatus::Draft)->to(ListingStatus::Active)->ability('publish');
        $lifecycle->transition('close')->from(ListingStatus::Active)->to(ListingStatus::Closed)->requiresReason();
        $lifecycle->transition('expire')->from(ListingStatus::Active)->to(ListingStatus::Expired)->systemOnly();
    }
}
```

Attach it to the model:

```php
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;

final class Listing extends Model implements LifecycleSubject
{
    use HasLifecycle;

    public function lifecycleDefinitions(): array
    {
        return ['status' => ListingLifecycle::class];
    }

    protected function casts(): array
    {
        return ['status' => ListingStatus::class];
    }
}
```

Then move it through its states:

```php
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;

Lifecycles::for($listing)->by($user)->apply('publish');      // draft → active, checked by the Gate

Lifecycles::for($listing)->can('close');                     // true
Lifecycles::for($listing)->because('Sold elsewhere')->apply('close');

Lifecycles::for($listing)->rollback();                       // undo the last transition
Lifecycles::for($listing)->history();                        // who did what, when and why
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/lifecycle-for-laravel](https://roundly-consulting.com/open-source/docs/lifecycle-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=lifecycle-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=lifecycle-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=lifecycle-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
