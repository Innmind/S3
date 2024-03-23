# Changelog

## [Unreleased]

### Added

- Support for `innmind/operating:~5.0`

### Changed

- Delete calls via the filesystem adapter are done concurrently when possible

### Fixed

- Empty directories are now listed
- Uploading documents with special characters
- Deleting entire directories
- Over-fetching directories content even when not used
- Over-fetching files content even when not used

## 4.0.0 - 2023-11-01

### Changed

- Requires `innmind/http-transport:~7.0`
- Requires `innmind/http:~7.0`
- Requires `innmind/filesystem:~7.1`
- Requires `innmind/operating-system:~4.0`

### Fixed

- Removed files in directories were not removed from S3

## 3.2.1 - 2023-09-23

### Fixed

- Upload and removals not being executed with the deferred execution inside `innmind/http-transport`

## 3.2.0 - 2023-09-23

### Added

- Support for `innmind/immutable:~5.0`

### Removed

- Support for PHP `8.1`

## 3.1.0 - 2023-01-29

### Added

- Support for `innmind/http:~6.0`

### Removed

- Direct dependency to `innmind/stream`
