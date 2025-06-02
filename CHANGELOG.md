# Changelog

## [Unreleased]

### Changed

- Requires `innmind/foundation:~1.0`
- `Innmind\S3\Bucket::upload()` now returns an `Innmind\Immutable\Attempt<Innmind\Immutable\SideEffect>`
- `Innmind\S3\Bucket::delete()` now returns an `Innmind\Immutable\Attempt<Innmind\Immutable\SideEffect>`
- `Innmind\S3\Format\*` classes have grouped into the enum `Innmind\S3\Format\Amazon`
- `Innmind\S3\Bucket` is now a final class
- `Innmind\S3\Filesystem\Adapter` has been renamed `Innmind\S3\Filesystem`

### Removed

- `Innmind\S3\Exception\*` classes

### Fixed

- PHP `8.4` deprecations

## 4.1.4 - 2024-11-11

### Fixed

- Uploading a file and then a directory with the same name didn't remove the file.
- The list of files inside a directory was kept in memory.

## 4.1.3 - 2024-10-26

### Fixed

- Listing files inside an empty folder was throwing an error

## 4.1.2 - 2024-06-17

### Fixed

- Continuation token used when paginating a listed wasn't properly encoded

## 4.1.1 - 2024-06-14

### Fixed

- Listing elements inside a path is no longer limited to 1000 elements, it now follows the pagination

## 4.1.0 - 2024-03-23

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
