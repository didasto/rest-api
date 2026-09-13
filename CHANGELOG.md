# Changelog

All notable changes to this package are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
the versioning follows [SemVer](https://semver.org/).

The release pipeline reads the `[Unreleased]` section below and derives the
bump from it: `### Removed` or the word BREAKING means major, `### Added` or
`### Changed` means minor, anything else means patch. An explicit marker in
the heading, such as `## [Unreleased] [minor]`, always wins.

## [Unreleased]

## [1.1.0] - 2026-09-13
### Added

- **Generators.** `php artisan make:rest-api` writes a controller and the
  request classes that belong to it, `php artisan make:rest-job` writes a
  whole job API - controller, request, input object and a first job.
  `--only`, `--except` and `--read-only` use the same words as the
  attribute, `--model` resolves a model the way Laravel's own generators
  do, and without a model a hand written API is written instead.
- **Publishable stubs.** `vendor:publish --tag=rest-api-stubs` copies the
  templates to `stubs/rest-api`, where a project can change them. A
  published stub always wins over the one of the package.
- Validation rules are drafted from the table columns - but only when the
  table really exists. Filters are only suggested with `--filters`: which
  fields may be filtered on is a decision, not a schema detail.
- `illuminate/console`, `illuminate/filesystem` and `laravel/prompts` are
  now declared. They were used by the prune command already and only
  worked because a full Laravel application ships them anyway.

## [1.0.0] - 2026-09-10
### Removed

- Support for Laravel 11. The package now requires Laravel 12 or 13, which
  is what the test matrix covers.

### Fixed

- `config/rest-api.php` declared a `fields` query key that nothing read. The
  key is gone; sparse fieldsets are a feature to decide on separately, not
  something the config should imply.
- The release script derived the next version from the git tags alone. In a
  repository whose history was imported without its tags that restarts the
  numbering at `0.0.1` and quietly undoes a release that is already
  published - which is exactly what produced `v0.1.0` for a package whose
  CHANGELOG said `1.0.0`. It now takes the higher of the newest tag and the
  newest released section of this file.

### Added

- Both READMEs state the requirements: PHP 8.3 or newer, Laravel 12 or 13.

## [0.1.0] - 2026-09-09
First stable release.

### Added

- **Model resources.** `#[RestResource(model: …)]` on a controller extending
  `RestController` registers `index`, `show`, `store`, `update` and
  `destroy`. `only` and `except` switch individual actions on or off.
  `update` answers to both `PUT` and `PATCH` with one set of rules.
- **Job APIs.** `#[RestJob(key: …)]` on a controller extending
  `JobController` registers a `POST` that starts a run and returns its id, a
  `GET` that reports the progress, and an optional `DELETE` that cancels it.
  The counters are read live from Laravel's batch, so the jobs report
  nothing themselves, and `total` may grow while the run is going.
- **Typed job payloads.** `JobData` carries the input of a run, `JobResult`
  is filled in by the chain. `updateResult()` writes under a row lock, so
  jobs running at the same time cannot overwrite each other.
- **Declarative filters.** Request classes declare which filters a listing
  accepts. Single operators (`EqualsFilter`, `LikeFilter`, `BetweenFilter`
  and friends) or whole groups (`IdFilter`, `StringFilter`, `NumericFilter`,
  `DateFilter`, `BooleanFilter`). Unknown fields, operators and sort columns
  are rejected with 422 and a message naming what was allowed.
- **An OpenAPI document** at a configurable route, generated from the
  registered routes. Request bodies come from the validation rules, response
  schemas from the table columns of the model, and filters appear as one
  `deepObject` parameter per field. Which routes need a token is derived
  from their middleware.
- **`rest-api:prune-jobs`** removes finished runs.

### Notes

- Every class is extensible: nothing is `final`, no method or property is
  `private`. See the "Extension points" section of the README.
- Responses are flat JSON. Pagination is reported in the `X-Total-Count`,
  `X-Page`, `X-Per-Page`, `X-Last-Page` and `Link` headers.

