# Changelog

Notable changes to `kinetis/queue-failed-jobs`, newest first.

## 1.1.0

### Added

- `FailedJobRetrier`, bound in the application scope: `retry()`,
  `retryMany()` and `retryMatching()` retry failed jobs from application
  code and return a `RetryResult` per record, with a `RetryStatus` of
  `Retried`, `NotFound` or `Failed`. `queue:retry` runs through it.

### Changed

- A retry that fails because the job's constructor rejected the stored
  arguments is reported by the constructor exception's class rather than
  its message, which can quote an argument.
