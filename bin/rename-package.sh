#!/usr/bin/env bash
# Rename this template into a new roundly-consulting *-for-laravel package.
#
# Usage:
#   bash bin/rename-package.sh Credits
#   bash bin/rename-package.sh Auth Authentication
#
# The first argument is the StudlyCase domain name (no "-for-laravel" suffix). It derives
# the package's identity:
#   repo/composer name  credits-for-laravel      (kebab-case + -for-laravel)
#   namespace           RoundlyConsulting\Credits
#
# The optional second argument is the StudlyCase public API name; it defaults to the domain.
# It names everything a host application sees by name:
#   service provider    CreditsServiceProvider
#   public API          Facades\Credits (+ its global alias) -> CreditsManager,
#                       Testing\CreditsFake, Actions\ExampleCreditsAction
#                       (+ DataTransferObjects\ExampleCreditsData)
#   config handle/file  config/credits.php, config('credits.*')
#   env var prefix      CREDITS_
#
# Pass it when the domain would shadow something Laravel already owns -- a core facade/alias
# (Auth, Cache, Http, Log, Mail, ...) or a config file the framework ships (database, services,
# ...). The script refuses those instead of scaffolding a package that is broken on its first
# run: `Auth Authentication` gives namespace RoundlyConsulting\Auth with an Authentication
# facade, AuthenticationServiceProvider, config/authentication.php and AUTHENTICATION_ env vars.
#
# Run this once, right after creating a new repo from this template and cloning it.
set -euo pipefail

usage='Usage: bash bin/rename-package.sh <StudlyCaseDomain> [<StudlyCaseApiName>]'

if [[ $# -lt 1 || $# -gt 2 ]]; then
    echo "$usage" >&2
    exit 1
fi

domain="$1"
name="${2:-$1}"

for value in "$domain" "$name"; do
    if ! [[ "$value" =~ ^[A-Z][A-Za-z0-9]*$ ]]; then
        echo "Names must be StudlyCase, e.g. Credits or MediaLibrary (got: $value)" >&2
        echo "$usage" >&2
        exit 1
    fi
done

# StudlyCase -> kebab-case: insert a dash before an uppercase letter that follows a
# lowercase/digit, then lowercase everything (MediaLibrary -> media-library).
to_kebab() {
    echo "$1" | sed -E 's/([a-z0-9])([A-Z])/\1-\2/g' | tr '[:upper:]' '[:lower:]'
}
to_lower() {
    echo "$1" | tr '[:upper:]' '[:lower:]'
}

kebab="$(to_kebab "$domain")"
name_kebab="$(to_kebab "$name")"
name_snake="$(echo "$name_kebab" | tr '-' '_')"
name_upper="$(echo "$name_kebab" | tr '[:lower:]-' '[:upper:]_')"
name_camel="$(to_lower "${name:0:1}")${name:1}"
# Human title for the README H1 and the hero (MediaLibrary -> Media Library for Laravel).
title="$(echo "$domain" | sed -E 's/([a-z0-9])([A-Z])/\1 \2/g') for Laravel"

# Refuse before touching anything: a rejected run must leave the tree -- and this script --
# exactly as they were, so the user can simply re-run with a corrected argument.
reject() {
    echo "$1" >&2
    if [[ "$name" == "$domain" ]]; then
        echo "Keep ${domain} as the domain and give the public API its own name:" >&2
    else
        echo "Pick another public API name:" >&2
    fi
    echo "  bash bin/rename-package.sh ${domain} <ApiName>    (e.g. Auth Authentication)" >&2
    exit 1
}

case "$domain" in
    *ForLaravel)
        echo "Pass the domain without the -for-laravel suffix (got ${domain}, use ${domain%ForLaravel})." >&2
        exit 1
        ;;
esac

# The package name must not be the template itself or a Tier-0 package it depends on: the
# root package would require-dev itself and share that dependency's namespace.
case "$kebab" in
    package-template | package-toolkit | testing)
        echo "${domain} would name the package roundly-consulting/${kebab}-for-laravel, which is" \
            "$( [[ "$kebab" == package-template ]] && echo "this template itself" || echo "a Tier-0 dependency every package requires")." \
            "Pick another domain." >&2
        exit 1
        ;;
esac

if [[ "$name" == PackageTemplate ]]; then
    echo "PackageTemplate is the template's own name (package-template-for-laravel) -- renaming to it changes nothing." >&2
    exit 1
fi

# The provider would be `class PackageServiceProvider extends PackageServiceProvider` -- its
# own name collides with the toolkit base class it imports, a fatal error on the first boot.
if [[ "$name" == Package ]]; then
    reject "Package would generate PackageServiceProvider, which collides with the base class it extends (RoundlyConsulting\\PackageToolkit\\PackageServiceProvider)."
fi

# The facade class is named exactly after the API name, and PHP reserves these as class names.
php_reserved=' abstract and array as break callable case catch class clone const continue declare default die do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile eval exit extends false final finally float fn for foreach function global goto if implements include instanceof insteadof int interface isset iterable list match mixed namespace never new null object or parent print private protected public readonly require return self static string switch throw trait true try unset use var void while xor yield bool '
case "$php_reserved" in
    *" $(to_lower "$name") "*)
        reject "${name} is a PHP reserved word, so the facade class \`final class ${name} extends Facade\` is a parse error."
        ;;
esac

# Every facade class in Illuminate\Support\Facades plus every default global alias, Laravel 12
# and 13 combined. The script runs before `composer install`, so it cannot ask Laravel; the
# template's self-test pins this list against the framework each CI leg installs. PHP class
# names are case-insensitive, so the comparison is too (`Url` shadows `URL`).
laravel_facades=' app arr artisan auth benchmark blade broadcast bus cache cloud concurrency config context cookie crypt date db eloquent event exceptions facade file gate hash http image js lang log mail maintenancemode notification number paralleltesting password pipeline process queue ratelimiter redirect redis request response route schedule schema session storage str uri url validator view vite '
case "$laravel_facades" in
    *" $(to_lower "$name") "*)
        reject "${name} is a Laravel core facade name (a class in Illuminate\\Support\\Facades or a default global alias, compared case-insensitively like PHP class names): the package's facade and its alias would shadow Laravel's, and the renamed package's own FacadeTest fails."
        ;;
esac

# Every config file the framework ships, Laravel 12 and 13 combined. mergeConfigFrom() would
# merge the package's keys into the framework's own config, and its publish tag would target
# the host's config file of the same name.
laravel_configs=' app auth broadcasting cache concurrency cors database filesystems hashing images logging mail queue services session view '
case "$laravel_configs" in
    *" ${name_kebab} "*)
        reject "${name} would give the package the config handle '${name_kebab}', which Laravel ships itself (config/${name_kebab}.php): mergeConfigFrom() would merge the package's keys into the framework's config."
        ;;
esac

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

if [[ "$name" == "$domain" ]]; then
    echo "Renaming template -> ${domain} (${kebab}-for-laravel)"
else
    echo "Renaming template -> ${domain} (${kebab}-for-laravel), public API ${name}"
fi

# The two seds disagree about -i: GNU takes no backup suffix, BSD/macOS requires an explicit
# (empty) one. Passing '' to GNU sed makes it read '' as a filename and exit 2, so detect once
# rather than guess -- only GNU sed answers --version.
if sed --version >/dev/null 2>&1; then
    sed_inplace() {
        sed -i "$@"
    }
else
    sed_inplace() {
        sed -i '' "$@"
    }
fi

# The substitutions, in order. The namespace and the package name carry the DOMAIN; every
# other occurrence is a name a host application sees (class, facade alias, config handle, env
# prefix, variable) and carries the API NAME. Both halves are identical when no API name is
# given. The namespace runs first so `RoundlyConsulting\PackageTemplate\PackageTemplateManager`
# becomes `RoundlyConsulting\Auth\AuthenticationManager`; `\\+` matches the JSON-escaped form
# in composer.json too. Every casing the template uses has a rule -- camelCase included, or the
# README's `$packageTemplate` survives -- and the leftover check below is case-insensitive, so
# a casing without a rule fails the run instead of shipping.
rewrites=(
    -e "s/RoundlyConsulting(\\\\+)PackageTemplate([^A-Za-z0-9_])/RoundlyConsulting\\1${domain}\\2/g"
    -e "s/RoundlyConsulting(\\\\+)PackageTemplate\$/RoundlyConsulting\\1${domain}/"
    -e "s/package-template-for-laravel/${kebab}-for-laravel/g"
    -e "s/PackageTemplate/${name}/g"
    -e "s/packageTemplate/${name_camel}/g"
    -e "s/package-template/${name_kebab}/g"
    -e "s/package_template/${name_snake}/g"
    -e "s/PACKAGE_TEMPLATE/${name_upper}/g"
)

# What the leftover check hunts for: the template's name in ANY casing or separator.
leftover='package[-_]?template'

# Renaming a class can change where its `use` line sorts: `PackageTemplateManager` sorts after
# `DataTransferObjects\...`, `CreditsManager` before it. Left alone, a freshly renamed package
# fails `pint --test` (and the code-style workflow) before its author has written a line.
# Re-sort each contiguous block of top-level `use Foo\Bar;` lines the way Pint's
# `ordered_imports` does: case-insensitive, with `\` compared as a space. Plain POSIX awk
# (insertion sort, no asort/pipes) so GNU, BSD and mawk agree; LC_ALL=C makes `>` bytewise.
sort_imports() {
    local tmp
    tmp="$(mktemp)"
    LC_ALL=C awk '
        function flush(   i, j, line, k) {
            for (i = 2; i <= n; i++) {
                line = buf[i]; k = key[i]
                for (j = i - 1; j > 0 && key[j] > k; j--) {
                    buf[j + 1] = buf[j]; key[j + 1] = key[j]
                }
                buf[j + 1] = line; key[j + 1] = k
            }
            for (i = 1; i <= n; i++) print buf[i]
            n = 0
        }
        /^use [A-Za-z0-9_\\]+( as [A-Za-z0-9_]+)?;$/ {
            k = tolower($0); sub(/^use /, "", k); sub(/;$/, "", k); gsub(/\\/, " ", k)
            n++; buf[n] = $0; key[n] = k
            next
        }
        { flush(); print }
        END { flush() }
    ' "$1" > "$tmp"
    cat "$tmp" > "$1"
    rm -f "$tmp"
}

# Walk the tree with find -print0 and test each file with a plain `grep -qE`. Listing the
# files with `grep -rlZ` instead is a GNU-ism: BSD/macOS grep reads -Z as "decompress" and
# ugrep treats it differently again, so the list came back newline-separated, `read -d ''`
# hit EOF before any NUL, and the loop ran zero times -- files renamed, contents untouched,
# exit 0. find -print0 and grep -qE behave the same on GNU, BSD and ugrep, and the NUL
# separator still keeps a path containing a space in one piece.
find . \( -name .git -o -name vendor -o -name node_modules \) -prune -o -type f \
    \( -name '*.php' -o -name '*.json' -o -name '*.md' -o -name '*.yml' \
    -o -name '*.neon' -o -name '*.dist' \) -print0 |
    while IFS= read -r -d '' f; do
        grep -qiE "$leftover" "$f" || continue
        sed_inplace -E "${rewrites[@]}" "$f"
        # An if, not `[[ ]] && ...`: a false test as the loop's last command fails the
        # pipeline under pipefail and set -e kills the run.
        if [[ "$f" == *.php ]]; then
            sort_imports "$f"
        fi
    done

# Rename every file whose NAME carries a token, not a hand-kept list: the service provider,
# config file, manager, facade, fake, example action and DTO today, and whatever the template
# grows next. A hard-coded list went stale the moment a new token-named file was added, and
# the content check below cannot see a file name. Only the casings the rewrite has a rule for
# are selected -- any other would `git mv` a file onto itself and abort half-way; the leftover
# check reports those instead.
find . \( -name .git -o -name vendor -o -name node_modules \) -prune -o -type f \
    \( -name '*PackageTemplate*' -o -name '*packageTemplate*' -o -name '*package-template*' \
    -o -name '*package_template*' -o -name '*PACKAGE_TEMPLATE*' \) -print0 |
    while IFS= read -r -d '' f; do
        renamed="$(basename "$f" | sed -E "${rewrites[@]}")"
        git mv "$f" "$(dirname "$f")/${renamed}"
    done

# Last act: delete the template's own scaffolding. This script is the one file the rewrite
# above cannot reach -- its substitution patterns ARE the tokens, so it is outside the
# rewrite's file filter, and leaving it behind leaves PackageTemplate/package-template/
# PACKAGE_TEMPLATE in a package that is supposed to be clean of them. The self-test only
# tests this script, so it goes with it.
#
# -f because git rm refuses a file that differs from HEAD, and the sed pass above just
# rewrote the test file. Both are removals staged against a committed HEAD, so `git restore
# --staged --worktree` brings them back if you need to look at them.
#
# Deleting the running script is safe: bash keeps its own file descriptor, so the rest of
# this file still executes.
git rm -qf bin/rename-package.sh tests/Feature/RenamePackageScriptTest.php

# Never report success over a half-renamed tree: the rewrite above once did nothing on macOS
# and still exited 0. Check every file type (not just the rewrite's own filter) the same
# portable way, and fail loudly if any template token survived -- in a file's contents or
# in its name, in any casing: a case-sensitive list only finds the casings somebody thought
# of, and the README's camelCase `$packageTemplate` slipped past one.
leftovers="$(
    find . \( -name .git -o -name vendor -o -name node_modules \) -prune -o -type f \
        -exec grep -liE "$leftover" {} + || true
    find . \( -name .git -o -name vendor -o -name node_modules \) -prune -o \
        \( -iname '*packagetemplate*' -o -iname '*package-template*' -o -iname '*package_template*' \) -print
)"
if [[ -n "$leftovers" ]]; then
    echo "Rename incomplete -- template tokens remain in:" >&2
    echo "$leftovers" >&2
    exit 1
fi

cat <<EOF

Done: ${kebab}-for-laravel, namespace RoundlyConsulting\\${domain}.
Public API ${name}: facade + global alias ${name}, ${name}Manager, ${name}Fake,
${name}ServiceProvider, config/${name_kebab}.php, ${name_upper}_* env vars.

Removed the template's own scaffolding, staged as deletions:
  bin/rename-package.sh (this script) and tests/Feature/RenamePackageScriptTest.php.
Commit them with the rest of the rename — keeping either one leaves template tokens or a
test of a script that no longer exists in ${kebab}-for-laravel.

Remaining manual steps:
  1. Rename the repo itself on GitHub (and this local directory) to ${kebab}-for-laravel.
  2. Edit composer.json: write a real "description" and "keywords", then copy the
     description to GitHub: gh repo edit --description "<composer description>"
  3. Rewrite README.md. The README H1 is the human title, never the
     slug: "# ${title}" (fix acronym casing, e.g. QR, JWT). Keep the
     <!-- roundly-badges --> row at the top and the <!-- roundly-support --> section
     right before "## License" (both already point at ${kebab}-for-laravel).
     CHANGELOG.md is ready: its "## Unreleased" is the 1.0.0 draft — write
     "Initial public release." + an "### Added" summary there.
  4. Add the hero banner (laravel-package-hero-image agent -> art/hero.png) at the very top
     of README.md, above the badges, inside <!-- roundly-hero:start --> ... <!-- roundly-hero:end -->.
  5. composer install, then run the quality gate:
       composer format && composer test && composer analyse && composer audit
  6. Confirm nothing template-shaped survived, in any casing — this must print nothing:
       grep -rliE 'package[-_]?template' --exclude-dir=.git --exclude-dir=vendor .
  7. Replace the placeholder public API (src/Actions/Example${name}Action.php, its DTO,
     ${name}Manager::example(), the ${name} facade docblock and ${name}Fake) with the
     real first verb — keep tests/Feature/FacadeTest.php's pins green as you go. Enable
     ArchPresets::modelsGoThroughTheFacade in tests/ArchTest.php once a model or trait
     exists. A package with no host-facing stateful behaviour deletes the facade scaffold.
EOF
