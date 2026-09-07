# Changelog

## [Unreleased]

### Added
- Antwort-Schemas werden aus den Tabellenspalten des Models abgeleitet, wenn
  keine Store- oder Update-Request existiert. Casts, versteckte Felder und
  Enums werden beruecksichtigt; abschaltbar ueber `openapi.schema_from_model`.

### Fixed
- Ein leeres `properties` wurde als `[]` statt `{}` ausgegeben - damit war das
  Dokument nach OpenAPI ungueltig.
- `requestBody` wurde auch dann gesetzt, wenn es keine Request-Klasse und damit
  nichts zu beschreiben gab.

## [0.9.0] - 2026-09-07
### Added
- Initial Package setup and functions

