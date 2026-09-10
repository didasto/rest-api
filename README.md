# didasto/rest-api

[![Tests](https://github.com/didasto/rest-api/actions/workflows/tests.yml/badge.svg)](https://github.com/didasto/rest-api/actions/workflows/tests.yml)
[![Latest release](https://img.shields.io/github/v/release/didasto/rest-api?sort=semver)](https://github.com/didasto/rest-api/releases)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Model backed and hand written REST APIs for Laravel, with an OpenAPI
document generated from your request classes and filters you declare
instead of implement.

**Documentation: [English](README.EN.md) · [Deutsch](README.DE.md)**

Requires PHP 8.3 or newer and Laravel 12 or 13.

```bash
composer require didasto/rest-api
```

Nothing in this package is `final` and nothing is `private`, so every step
of every request can be replaced on its own. The extension points have a
section of their own in both documents.
