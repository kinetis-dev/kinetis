# Changelog

Notable changes to `kinetis/neuron`, newest first.

## 1.3.3

### Fixed

- `NoWorkerUnsafeNeuronRule` no longer aborts PHPStan with an internal error on `new` or a static call naming a built-in class whose constructor has more than one signature, such as `new \ReflectionMethod($class, $method)`.
