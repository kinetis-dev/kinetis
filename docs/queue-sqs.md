# Queue (SQS)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/queue-sqs
```
````

Adds Amazon SQS as a backend for {doc}`queue`. Switching to it changes
configuration, not application code.

```{code-block} text
QUEUE_CONNECTION=sqs
QUEUE_SQS_REGION=us-east-1
```

```{code-block} sh
vendor/bin/kinetis queue:work --queue=high,default
```

Every SQS request, including a worker waiting for its next job, suspends
the calling Fiber instead of blocking the process. Credential files are
the exception — see [Credentials](#credentials).

## Configuring

`QUEUE_SQS_REGION` is required. Four settings are optional:

```{code-block} text
QUEUE_SQS_QUEUE_PREFIX=myapp-
QUEUE_SQS_ENDPOINT=http://localstack:4566
QUEUE_SQS_PLAINTEXT=true
QUEUE_SQS_TIMEOUT=30
```

`QUEUE_SQS_QUEUE_PREFIX` is prepended to every queue name, so staging and
production can share one AWS account without both using `default`.

`QUEUE_SQS_ENDPOINT` points at an SQS-compatible service such as
LocalStack. It is one origin — a scheme, a host and an optional port,
with no userinfo, path, query or fragment — and anything else is refused
when the client is built. Unset, the destination is AsyncAws's regional
endpoint table, and an `AWS_ENDPOINT_URL` set in the environment for
another tool is refused rather than redirecting this application's
signed requests.

`QUEUE_SQS_PLAINTEXT=true` allows an `http://` endpoint. Plain HTTP
between containers on one Compose network is ordinary; a public
plain-HTTP endpoint would carry credentials and job payloads unencrypted,
and nothing in a hostname tells the two apart, so the decision is
explicit.

`QUEUE_SQS_TIMEOUT` (seconds, default `30`, any positive value) bounds
each SQS request and each credential lookup on its own — not one deadline
across a `pop()` that makes several requests. Keep it above five seconds:
a worker's long poll holds a request open for up to that long, and a
shorter budget aborts an idle poll as a failure. A request is one
attempt, with no retry and no redirect followed.

## Credentials

Credentials resolve through AsyncAws's standard chain, in its standard
order: environment variables (including the STS assume-role that
`AWS_ROLE_ARN` selects), web identity, the shared credentials and config
files, ECS or EKS pod identity, then IMDS. Nothing is configured in
Kinetis.

Every provider that calls AWS uses the same non-blocking transport as the
client, so an assume-role or IMDS lookup suspends the calling Fiber. The
shared credentials file, the shared config file and any web-identity or
pod-identity token file are read with blocking calls, on first
resolution and on each refresh.

Resolved credentials are reused until they expire. An expired answer is
skipped for the next provider, and a lookup that resolved nothing is not
remembered, so a role, container credential endpoint or token file that
appears after a worker starts is picked up by the next queue operation.

## Create your queues ahead of time

Each queue name you push to — `default`, `high` — must already exist as
a standard SQS queue of that name, with `QUEUE_SQS_QUEUE_PREFIX`
prepended when set. **This package never creates a queue.** Create each
one with Terraform, CloudFormation, the AWS CLI or the console before
pushing or popping. FIFO queues (names ending in `.fifo`) are not
supported.

Set each queue's visibility timeout above your slowest job. A message a
worker has not settled by then is delivered again — which is also how a
worker that died mid-job has its message redelivered.

## Delivery caveats

- SQS can deliver a message more than once on its own, even to a second
  worker while the first still holds it within the visibility timeout,
  so handlers must be idempotent.
- A job's attempt count is SQS's `ApproximateReceiveCount`, which AWS
  documents as approximate.
- A settlement that arrives after the visibility timeout is not reported
  as a lost delivery: if SQS rejects it, its error stops the worker, and
  if SQS accepts it, nothing reports it.
- A worker watching several queues checks each once, then long-polls
  only the highest-priority queue for up to five seconds, so a job
  arriving on a lower-priority queue can wait that long.

[SQS mechanisms](appendix-queue.md#sqs) maps each queue operation to its
SQS call.

## Emptying a queue

`SqsQueue` does not declare `ClearableQueueInterface`, so it has no
`clear()`, and `kinetis queue:clear` names the backend and exits 1.
SQS's `PurgeQueue` also deletes messages workers hold in flight and
messages sent during the up-to-60-second purge, and reports no count, so
this backend never calls it. Empty a queue the way you created it —
`aws sqs purge-queue`, or recreating the queue — accepting those
semantics explicitly.

## Delays and retries

SQS delays a message by at most 900 seconds (15 minutes). A longer
`delaySeconds` makes `push()` throw before anything is sent. Retries
follow {doc}`queue`: `maxAttempts`, `QUEUE_MAX_ATTEMPTS` and
`QUEUE_RETRY_BASE_DELAY_SECONDS`. A delayed retry is the message's own
visibility timeout: `ChangeMessageVisibility`'s request field accepts up
to 43200 seconds (12 hours), wider than the send-time delay, and a
larger value throws before anything is sent.

Being within that field's range is not the same as being accepted. SQS
refuses a timeout longer than the time left in that received message's
own 12-hour maximum and does not recalculate down to it, so a long
backoff late in a message's life can be rejected. That is service state
this package cannot see, so it makes no local guess: SQS's error
propagates and the delivery stays unsettled, the same as any other
settlement failure here.

## Named connections

```{code-block} text
QUEUE_CONNECTION_NAME=reports
QUEUE_REPORTS_SQS_REGION=eu-west-1
QUEUE_REPORTS_SQS_QUEUE_PREFIX=myapp-reports-
```

`QUEUE_CONNECTION_NAME` picks which scoped block of `QUEUE_SQS_*` keys a
worker reads, and `default`, or leaving it unset, reads the plain keys.
See {doc}`config`.

## See also

- {doc}`queue` — jobs, workers, retries and delivery guarantees.
- {doc}`appendix-queue` — the SQS mechanisms and delivery contracts.
- {doc}`config` — named connections and every `QUEUE_SQS_*` key.
