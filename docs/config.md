# Configuration

Two independent pieces: loading a `.env` file into the real process
environment, and typed access to whatever's in it.

## `.env` loading

```{code-block} text
:caption: .env, at your project root

APP_ENV=production
DB_HOST=db.internal
DB_PORT=3306
DEBUG=false
```

A missing `.env` is not an error — loading it does nothing and startup
continues. A real environment variable that is already set always wins
over the file, so a checked-in `.env.example` copied to `.env` locally
cannot override a production secret set through Docker, systemd, or a
secrets manager. See {doc}`appendix-configuration` for exactly which
entry points load it, and when, relative to environment detection.

Both are read once, at boot: a persistent worker builds `Config` from
them when it starts and never rereads either afterward, so a changed
`.env` value or environment variable takes effect only once the worker
restarts — a new deploy, a supervisor restart, or equivalent.

```{note}
No `AppEnvironment` check gates this — `.env` loading runs in every
environment. Shared hosting and FPM-only deployments often give you file
access and no way to set a real process environment variable at all. For
that shape of deployment `.env` is the way to configure the application
in production, not a development-only convenience.
```

## Typed config access

```{code-block} php
use Kinetis\Config\Config;
use Kinetis\Http\Attributes\Get;

final readonly class OrderController
{
    public function __construct(
        private Config $config,
    ) {}

    #[Get('/orders')]
    public function index(): array
    {
        return [
            'host' => $this->config->string('DB_HOST', 'localhost'),
            'debug' => $this->config->bool('DEBUG', false),
        ];
    }
}
```

`Kinetis\Config\Config` is a snapshot of the environment taken once, not
live `getenv()` calls scattered through business logic. Environment
variables are worker-lifetime configuration, not per-request state.

| Method | Returns |
|---|---|
| `get(string $key, ?string $default = null): ?string` | The raw value, or `$default` when the key is absent |
| `string(string $key, string $default): string` | Same as `get()`, with a required (non-null) default |
| `int(string $key, int $default): int` | The value as `int`, or `$default` when unset or empty |
| `intOrNull(string $key): ?int` | The value as `int`, or `null` when unset or empty — no default to fall back to |
| `float(string $key, float $default): float` | The value as `float`, or `$default` when unset or empty |
| `bool(string $key, bool $default): bool` | The value as `bool`, or `$default` when unset or empty |
| `required(string $key): string` | The raw value, or throws `Kinetis\Config\Exception\MissingConfigException` when the key is absent |

The four parsing accessors — `int()`, `intOrNull()`, `float()`, `bool()`
— treat an explicitly empty value (`DB_PORT=`) the same as an unset one
and fall back to the default. That is the convention a named-connection
setting uses to turn itself off. Anything else that doesn't parse, or
doesn't fit, throws `Kinetis\Config\Exception\InvalidConfigValueException`
rather than taking whatever a lossy cast would produce.

`get()`, `string()` and `required()` do not parse, and so do not apply
that rule: they return an empty value as the empty string. `DB_PASSWORD=`
satisfies `required('DB_PASSWORD')` and yields `''`. Only an absent key
throws.

`int()`/`intOrNull()` accept an optional sign followed by decimal digits
only — leading zeroes (`"007"`) are fine, but a fraction, an exponent,
surrounding whitespace, an alternate base (`"0x1A"`), or a grouping
separator (`"1_000"`) all throw, as does anything outside the platform's
representable integer range (a huge digit string that would otherwise
saturate toward `PHP_INT_MAX`/`PHP_INT_MIN`).

`float()` accepts ordinary decimal notation (`"5"`, `"5."`, `".5"`,
`"5.5"`) with an optional scientific-notation exponent (`"5e3"`,
`"5.5e-3"`); a leading `+`, leading zeroes, and both a leading and a
trailing dot are accepted. Beyond syntax, two cases are checked rather
than left to a plain `(float)` cast: an exponent large enough to overflow
to infinity throws instead of becoming `INF`, and a nonzero value whose
magnitude underflows to exactly `0.0` (`"1e-400"`) throws too, since once
cast it is indistinguishable from a configured zero. A zero mantissa
(`"0e-400"`) returns `0.0`.

`bool()` accepts `"true"`/`"false"`, `"1"`/`"0"`, `"on"`/`"off"`, and
`"yes"`/`"no"` (case-insensitively) and rejects anything else, rather
than letting an unrecognized value like `"purple"` become `false`.

`Config` validates syntax and representable range. It has no idea whether
a key means a TCP port, a positive duration, or a ratio between 0 and 1.
That domain knowledge belongs to whichever factory or middleware reads
the key: `kinetis/database-bridge`'s `ConnectionFactory` rejects a
`DB_PORT` outside 1–65535,
`FormLimits` rejects a non-positive `MAX_BODY_SIZE`, `TracerFactory`
rejects an `OTEL_TRACES_SAMPLER_ARG` outside 0–1, and so on — each with
an `InvalidArgumentException` naming the key, rather than clamping into
range. {doc}`appendix-configuration` records each key's own bound.

`intOrNull()` exists for config that means something different when it is
absent than when it is zero — `DB_CONNECT_TIMEOUT` means "no timeout at
all" only when unset, not `0` standing in for it.

`required()` is for config with no sane default. A database password
absent from the environment fails at construction rather than proceeding
as an empty string and failing somewhere less obvious later:

```{code-block} php
$password = $this->config->required('DB_PASSWORD');
// throws Kinetis\Config\Exception\MissingConfigException if the key is absent
```

## Named connections

Redis, a SQL database, and a few other storage technologies can each be
configured more than once under a name, alongside the usual unnamed
**default** connection — see {doc}`appendix-configuration`'s "Named
connections" for the naming convention, every selector key it applies
to, and how to register one from `bootstrap.php`.

## Resolving `Config`

`Config` is injected automatically: `AppScope::boot()` registers one
shared instance — `Config::fromEnvironment()` — unless you've already
registered your own, and it resolves anywhere via constructor injection,
including through `RequestScope`, which delegates to that same
`AppScope`-registered instance (see {doc}`container`) the same as any
other service you never explicitly registered on `RequestScope` itself.

See {doc}`appendix-configuration` for overriding the default
registration and for why `.env`/`Config` sit outside the AOT cache, and
{doc}`bootstrapping` for registering an application service more
generally.

## See also

- {doc}`bootstrapping` — registering an application service or global
  middleware from `bootstrap.php`, and the package/application boot
  order.
- {doc}`container` — `AppScope`'s registration-lock discipline, and how
  `RequestScope` delegates to a `Config` it never explicitly registered
  itself.
- {doc}`appendix-configuration` — every key Kinetis and its packages read,
  grouped by subsystem, the named-connection naming algorithm, and why
  `.env` sits outside the AOT cache.
- {doc}`caching` — the AOT cache Kinetis builds for production.
- {doc}`appendix` — the `Kinetis\Config` namespace in the full system map.
