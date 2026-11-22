# Changelog

Notable changes to `kinetis/framework`, newest first.

## 1.30.0

### Added

- `kinetis <command> --help` and `kinetis <command> -h` print the command's usage and description to STDOUT and exit `0` without building configuration, running the bootstrap chain, or calling the command.
- `kinetis --help`, `kinetis -h` and `kinetis help` print the usage line and the command list to STDOUT and exit `0`. `help` applies only while no discovered command is named `help`.
- `TestApplication::command()` returns the command's help for the same flags instead of running it.

### Changed

- A command no longer receives a `--help` or `-h` argument; the console consumes it.
