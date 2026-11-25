# Changelog

Notable changes to `kinetis/framework`, newest first.

## 1.32.0

### Added

- `#[ObjectMap(keepObjects: true)]` keeps the JSON shape inside an object map: the parameter is still an array keyed by the object's member names, and every nested JSON object becomes a `stdClass` and every JSON array a list at any depth, so `json_encode()` writes `{}` and `[]` back as they arrived. The parameter's constraints read the same value. Without the option, nested objects stay plain arrays, and the JSON Schema is unchanged either way.

### Changed

- `Hydrator::objectMap()` returns the parameter's `ObjectMap` attribute, or `null` without one, instead of a `bool`.
- `Hydrator::resolveObjectMap()` takes `bool $keepObjects` before `$constraints`.
- A `HydrationPlanParameter` carries `keepObjects`. A compiled artifact from an earlier version fails validation and is recompiled.

## 1.31.0

### Added

- `#[FileMediaType(array $types)]` validates an uploaded file against exact `type/subtype` media types detected from its content by libmagic. A mismatch reports `file_media_type` with the declared `choices` and the detected `media_type`. The client's media type and filename are not consulted, and the stream is left rewound.

### Changed

- `kinetis/framework` requires `ext-fileinfo`.

## 1.30.0

### Added

- `kinetis <command> --help` and `kinetis <command> -h` print the command's usage and description to STDOUT and exit `0` without building configuration, running the bootstrap chain, or calling the command.
- `kinetis --help`, `kinetis -h` and `kinetis help` print the usage line and the command list to STDOUT and exit `0`. `help` applies only while no discovered command is named `help`.
- `TestApplication::command()` returns the command's help for the same flags instead of running it.

### Changed

- A command no longer receives a `--help` or `-h` argument; the console consumes it.
