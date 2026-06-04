# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial architecture and documentation scaffold (no implementation yet).
- Three-tier documentation: getting started, guide, internals.

### Changed
- Upgraded the `phpunit/phpunit` development dependency to `^13.0`. Connection test doubles configured without expectations now use `createStub()` / `getStubBuilder()` instead of mock objects, as required by PHPUnit 13.

[Unreleased]: https://github.com/Th3Mouk/materialized-view/commits/main
