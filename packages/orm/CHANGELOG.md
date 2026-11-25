# Changelog

Notable changes to `kinetis/orm`, newest first.

## 1.16.0

### Added

- `OrmFactory::jsonDepth()` and `EntityManager::jsonDepth()` return the connection's JSON depth: the most levels of nested arrays and objects a JSON property holds, as the server stores them.

### Fixed

- A JSON property's value nested deeper than the server stores is refused with `MappingException` from `persist()` or `flush()`, naming the entity class, the property and the limit, before any SQL. It no longer reaches the server and fails there with a driver error.

### Changed

- The JSON depth is the connection's server's instead of 511 levels everywhere: 100 on MySQL, 31 on MariaDB before 12.2, and 2499 on MariaDB 12.2 and later and on PostgreSQL. `OrmFactory::create()` reads it once from the link through `Kinetis\Persistence\Contract\ReportsJsonDepth`, refuses a link that does not implement it with `InvalidArgumentException`, and on a MySQL-family link opens a connection when none is open yet. Loading decodes at that depth, so a stored document nested deeper throws `MappingException` on load.

## 1.15.0

### Added

- A property declared `stdClass` or `?stdClass` maps a JSON column that holds a JSON object, recorded in the metadata with the type `jsonObject`. A property declared `array|stdClass` or `array|stdClass|null` maps a JSON column that holds a JSON object or array, recorded with the type `jsonObjectOrList`.
- Such a property loads its column with every JSON object a `stdClass` and every JSON array a list at any depth, so `{}` and `[]` stay distinct through a load, an untouched flush and a write. It admits exact `stdClass` instances and lists of the JSON leaves, nested at most 511 levels deep; a `stdClass` subclass, any other object, an array with keys other than `0` to `n-1`, and a property name starting with a NUL byte are refused before SQL.

### Changed

- The `MetadataRegistry` type vocabulary gains `jsonObject` and `jsonObjectOrList`. An `array` or `?array` property keeps the type `json` and its existing loading, admitted values and messages.
