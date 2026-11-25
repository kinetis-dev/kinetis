# Changelog

Notable changes to `kinetis/orm`, newest first.

## 1.16.0

### Fixed

- A JSON property's value nests at most 31 arrays or objects, down from 511, because MariaDB's `JSON` column refuses 32 and MySQL's refuses 101. A deeper value throws `MappingException` from `persist()` or `flush()`, naming the entity class, the property and the 31-level limit, before any SQL; it no longer reaches the server and fails with a driver error on the MySQL family while PostgreSQL stores it.
- Behavior change: loading decodes JSON text with `json_decode()` at depth 32, so a stored document nested deeper than 31 levels, written by other means, throws `MappingException` on load.

## 1.15.0

### Added

- A property declared `stdClass` or `?stdClass` maps a JSON column that holds a JSON object, recorded in the metadata with the type `jsonObject`. A property declared `array|stdClass` or `array|stdClass|null` maps a JSON column that holds a JSON object or array, recorded with the type `jsonObjectOrList`.
- Such a property loads its column with every JSON object a `stdClass` and every JSON array a list at any depth, so `{}` and `[]` stay distinct through a load, an untouched flush and a write. It admits exact `stdClass` instances and lists of the JSON leaves, nested at most 511 levels deep; a `stdClass` subclass, any other object, an array with keys other than `0` to `n-1`, and a property name starting with a NUL byte are refused before SQL.

### Changed

- The `MetadataRegistry` type vocabulary gains `jsonObject` and `jsonObjectOrList`. An `array` or `?array` property keeps the type `json` and its existing loading, admitted values and messages.
