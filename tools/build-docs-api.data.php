<?php

declare(strict_types=1);

/**
 * Hand-written part of the generated API reference: which types are listed, in which group, and what they are
 * for. Used by tools/build-docs-api.php. Signatures are never written here; they come from src/.
 *
 * Keys of `classes` are names below Enaxon\Reqtide. A class value is `[description, methods]`, where `methods` maps a
 * method (or `case`/property) name to a one-line meaning. A listed method that no longer exists stops the build.
 *
 * Descriptions are HTML (they may contain <code>); keep them short and true.
 */

return [
    [
        'id' => 'build',
        'title' => 'Build a pipeline',
        'intro' => 'Everything you need at start-up. <code>PipelineBuilder</code> assembles and validates the configuration, <code>compileInMemory()</code> wires process-local state, and the result is a <code>CompiledPipeline</code> you reuse. Every number is yours: the builder has no defaults.',
        'classes' => [
            'Pipeline\\PipelineBuilder' => ['Fluent, immutable builder: every call returns a new builder. <code>profile()</code>, <code>partition()</code> and <code>attemptTimeout()</code> are required; a policy exists only if you call its method. A bad value raises <code>InvalidConfigurationException</code> naming the option.', [
                'profile' => 'Names and versions the configuration. Change the version when settings change; shared state is keyed by it.',
                'partition' => 'Whose state this is: application, environment, subject and an optional tenant. One pipeline serves exactly one partition.',
                'attemptTimeout' => 'The longest one attempt may take. Use <code>Cooperative</code> with the synchronous runtime.',
                'retry' => 'Up to <code>maxAttempts</code> attempts, the first included. More than one needs <code>retryBudget()</code> and a replay-safety assertion on every call.',
                'retryBudget' => 'A token pool that pays for retries so a failing dependency is not hammered.',
                'rateLimit' => 'Charges <code>cost</code> units per dispatch, retries included. Takes the result of <code>tokenBucket()</code>, <code>fixedWindow()</code> or <code>slidingWindow()</code>.',
                'circuitBreaker' => 'Judges a window of completed calls, opens at a failure share, probes after the open duration.',
                'bulkhead' => 'At most <code>capacity</code> operations in flight; <code>lease</code> bounds a slot whose owner vanished.',
                'cooldown' => 'A shared pause after a server-stated wait. Needs a classifier that reports waits.',
                'fallbackScope' => 'Whether a fallback may also replace admission refusals.',
                'tokenBucket' => 'Rate algorithm: capacity, refill tokens per interval.',
                'fixedWindow' => 'Rate algorithm: capacity per window, aligned to the epoch or to the first hit.',
                'slidingWindow' => 'Rate algorithm: an approximate sliding counter.',
                'lastCalls' => 'Circuit window over the last N completed calls.',
                'timeWindow' => 'Circuit window over a duration, kept in buckets.',
                'definition' => 'Validates the composition and returns the immutable <code>PipelineDefinition</code>.',
                'compile' => 'Compiles against a hand-built <code>RuntimeEnvironment</code>, for example with test helpers.',
                'compileInMemory' => 'Compiles with the real clock, sleeping delays and process-local memory backends. There is no default classifier.',
            ]],
            'Pipeline\\CompiledPipeline' => ['The reusable pipeline. It holds the state that makes limits work, so build it once and share it. It has no per-operation state.', [
                'newContext' => 'One context per operation: the total deadline, the replay-safety statement, an optional fallback, cancellation and an operation id.',
                'execute' => 'Runs a callable through the pipeline. Returns your value, throws your own exception unchanged, or throws a Reqtide refusal.',
                'executeDetailed' => 'Same run, but returns an <code>ExecutionCompletion</code> instead of throwing for business or policy outcomes.',
                'executeOperation' => 'Like <code>execute()</code> for an <code>AttemptOperation</code> object instead of a callable.',
                'executeOperationDetailed' => 'Like <code>executeDetailed()</code> for an <code>AttemptOperation</code>.',
                'explain' => 'Describes the configured policies and the runtime capabilities. No I/O and no state change.',
            ]],
            'Pipeline\\InMemoryEnvironment' => ['Wires the runtime for one process: system clock, a scheduler that really sleeps, secure randomness and a memory backend for each configured policy. This is what <code>compileInMemory()</code> uses.', []],
            'Pipeline\\PipelineExplanation' => ['What <code>explain()</code> returns: profile, enabled policies and capability notes such as <code>physical_stop=not_guaranteed</code>.', []],
            'Value\\Duration' => ['A non-negative integer count of nanoseconds. Arithmetic is checked: overflow throws and never wraps.', []],
            'Value\\Ratio' => ['A reduced fraction in (0, 1], compared with integers. Used for the circuit failure threshold.', []],
        ],
    ],
    [
        'id' => 'run',
        'title' => 'Run an operation',
        'intro' => 'Per-operation objects. A context carries the total deadline and your statement about repeating the call; your callable receives an <code>AttemptContext</code>.',
        'classes' => [
            'Execution\\ExecutionContext' => ['Immutable facts of one logical operation. Build it with <code>CompiledPipeline::newContext()</code>. Do not share one between operations.', []],
            'Execution\\AttemptContext' => ['Passed to your callable on every attempt: operation id, attempt id, the attempt number, the deadline, the cancellation and the attempt timeout.', []],
            'Execution\\OperationSafety' => ['Your statement about repeating a call. <code>unsafe()</code> is the default and forbids retries; the other two allow them.', [
                'unsafe' => 'The default. A pipeline that allows more than one attempt refuses to run.',
                'replayableRead' => 'You assert that running the callable again is safe.',
                'providerIdempotentMutation' => 'You assert that the provider deduplicates repeated mutations.',
            ]],
            'Execution\\ReplaySafetyContract' => ['The written assertion behind <code>OperationSafety</code>. Reqtide never verifies it.', [
                'forReadOnlyCallable' => 'Builds the contract for a repeatable read. Your call is the assertion; it is not checked.',
            ]],
            'Execution\\OperationSafetyKind' => ['The kind of safety an operation carries.', []],
            'Fallback\\FallbackOperation' => ['A fallback you attach to one operation through <code>newContext()</code>. It receives the original outcome.', []],
            'Fallback\\FallbackScope' => ['What a fallback may replace. The default replaces business outcomes only.', []],
        ],
    ],
    [
        'id' => 'classify',
        'title' => 'Decide what an outcome means',
        'intro' => 'Policies act on the classification of each attempt, and you decide what a failure is. <code>ExceptionClassifier</code> is the ready-made classifier; implement <code>OutcomeClassifier</code> for anything it cannot express.',
        'classes' => [
            'Outcome\\ExceptionClassifier' => ['Classifier driven by lists of exception classes. Conservative on purpose: an exception nobody listed is not retried and not replaced by a fallback, but it counts as a circuit failure. Matching is <code>instanceof</code>. Every method returns a new instance.', [
                'create' => 'An empty classifier.',
                'retryOn' => 'Exception classes that may be retried.',
                'countAsCircuitFailure' => 'Narrows circuit failures to these classes (and retryable ones). Without it every exception that is not ignored counts.',
                'ignoreForCircuit' => 'Exception classes that say nothing about the dependency, such as validation errors. Ignore wins over a failure list.',
                'fallbackOn' => 'Exception classes a fallback may replace.',
                'retryAfterFrom' => 'Extracts a server-stated wait (a <code>Duration</code> or <code>null</code>) from an exception.',
                'unacceptableValuesWhen' => 'Marks a returned value as a failure: a circuit failure, never retried, never replaced.',
                'classify' => 'The <code>OutcomeClassifier</code> contract.',
            ]],
            'Outcome\\OutcomeClassifier' => ['The contract a classifier implements. Write your own when results must be retried or classified in ways the exception lists cannot say.', []],
            'Outcome\\Outcome' => ['What one attempt produced: a returned value or a thrown exception.', []],
            'Outcome\\OutcomeClassification' => ['The classifier\'s verdict on one outcome: retry eligibility, circuit verdict, fallback eligibility, acceptability, a reason code and an optional wait lower bound.', []],
            'Outcome\\OutcomeKind' => ['Whether an outcome is a value or an exception.', []],
            'Outcome\\RetryEligibility' => ['Whether an outcome may be retried.', []],
            'Outcome\\FallbackEligibility' => ['Whether a fallback may replace an outcome.', []],
            'Outcome\\CircuitVerdict' => ['How an outcome counts for the circuit breaker. Neutral outcomes neither open nor heal it.', []],
            'Outcome\\Acceptability' => ['Whether an outcome is acceptable to the caller.', []],
        ],
    ],
    [
        'id' => 'options',
        'title' => 'Policy options',
        'intro' => 'Enums and option objects the builder takes or returns. You normally reach them through <code>PipelineBuilder</code> and rarely construct them yourself.',
        'classes' => [
            'Policy\\BackoffMode' => ['Retry delay growth: constant or exponential.', []],
            'Policy\\JitterMode' => ['<code>Full</code> draws each delay uniformly from zero to the computed delay.', []],
            'Policy\\AttemptTimeoutRequirement' => ['<code>Cooperative</code> is the honest choice for the synchronous runtime: your code must respect the time. <code>TransportEnforced</code> needs a runtime that declares it.', []],
            'Policy\\FixedWindowAnchor' => ['Where fixed windows start: on multiples of the window length, or at the first hit.', []],
            'Policy\\TokenBucketOptions' => ['Token-bucket settings returned by <code>PipelineBuilder::tokenBucket()</code>.', []],
            'Policy\\FixedWindowOptions' => ['Fixed-window settings returned by <code>PipelineBuilder::fixedWindow()</code>.', []],
            'Policy\\SlidingCounterOptions' => ['Sliding-counter settings returned by <code>PipelineBuilder::slidingWindow()</code>.', []],
            'Policy\\CountCircuitWindow' => ['Circuit window over the last N completed calls.', []],
            'Policy\\TimeCircuitWindow' => ['Circuit window over a duration, kept in buckets.', []],
        ],
    ],
    [
        'id' => 'results',
        'title' => 'Results of an operation',
        'intro' => '<code>execute()</code> returns your value or throws. <code>executeDetailed()</code> returns the same facts as data in an <code>ExecutionCompletion</code>.',
        'classes' => [
            'Completion\\ExecutionCompletion' => ['Disposition, terminal outcome, per-attempt records, bounded diagnostics and a summary of counts.', [
                'problem' => 'The redacted <code>Problem</code> for this completion, or <code>null</code> when there is nothing for Reqtide to report.',
            ]],
            'Completion\\CompletionDisposition' => ['How an operation ended.', []],
            'Completion\\ExecutionSummary' => ['Counts for one operation: dispatches, retries, outcomes, fallbacks, elapsed time and whether accounting is complete.', []],
            'Completion\\AttemptRecord' => ['One physical attempt: its outcome, classification and circuit verdict.', []],
            'Completion\\Diagnostic' => ['A bounded note about a stage and reason, such as <code>retry / attempts_exhausted</code>.', []],
        ],
    ],
    [
        'id' => 'errors',
        'title' => 'Errors and reasons',
        'intro' => 'Your own exceptions come out unwrapped. A Reqtide decision is a typed exception that implements <code>ProblemCarrier</code>. Branch on <code>reason()</code> and <code>problem()</code>, never on message text.',
        'classes' => [
            'Error\\Problem' => ['Immutable, redaction-safe report shaped after RFC 9457 problem details. It is built from code metadata only: no message, URL, header or credential can enter it. Member names and order are API; <code>title</code> and <code>detail</code> wording is not.', []],
            'Error\\ProblemCarrier' => ['Implemented by every exception that describes a Reqtide decision.', []],
            'Exception\\ReqtideException' => ['Marker for every exception raised by Reqtide itself. Your own exceptions are never wrapped in it.', []],
            'Error\\ReasonCode' => ['The registry of stable reason codes. Keep a <code>default</code> branch when you <code>match</code> over it: new cases may be added.', []],
            'Error\\Retryability' => ['What a caller should do with a decision: wait, retry, fix the setup or reconcile.', []],
            'Error\\Category' => ['The kind of decision a code belongs to.', []],
            'Error\\Severity' => ['Advisory severity, using PSR-3 level names as plain strings.', []],
            'Error\\Stage' => ['The lifecycle stage a diagnostic or problem is reported at. Append-only.', []],
            'Exception\\PolicyTerminationException' => ['Base of the exceptions by which a policy ended the operation.', []],
            'Exception\\AdmissionRejectedException' => ['A circuit, bulkhead or rate limit refused the call. Your callable did not run.', []],
            'Exception\\AdmissionDeferredException' => ['A refusal that carries a positive wait, for example a cooldown or a <code>Retry-After</code> that cannot be honored.', []],
            'Exception\\ImpossibleAdmissionException' => ['The request can never be admitted, for example a rate cost above the capacity.', []],
            'Exception\\DeadlineExceededException' => ['The total deadline passed. A late value is replaced by this.', []],
            'Exception\\OperationCancelledException' => ['The operation was cancelled through its cancellation.', []],
            'Exception\\FallbackFailedException' => ['The fallback itself threw. <code>getPrevious()</code> is the fallback exception.', []],
            'Exception\\InvalidConfigurationException' => ['A value, option or combination is invalid. Raised before anything is dispatched.', []],
            'Exception\\CapabilityMismatchException' => ['A configured behavior needs a capability the selected runtime, scheduler or backend does not declare.', []],
            'Exception\\InfrastructureException' => ['Base of clock and state-backend failures. They are never hidden as success.', []],
            'Exception\\StateUnavailableException' => ['A state backend could not be reached. The protected call does not run.', []],
            'Exception\\StateCapacityExceededException' => ['A memory backend reached its ceiling and rejected a new partition.', []],
            'Exception\\CorruptStateException' => ['A state backend found corrupt state.', []],
            'Exception\\IndeterminateStateException' => ['The result of a state-backend operation is indeterminate.', []],
            'Exception\\ClockFailureException' => ['A clock could not be read.', []],
            'Exception\\ClassificationFailedException' => ['The classifier itself threw.', []],
            'Exception\\PreparationFailedException' => ['Attempt preparation or replay verification failed. Nothing was dispatched.', []],
        ],
    ],
    [
        'id' => 'events',
        'title' => 'Events',
        'intro' => 'Core emits its own typed events and has no telemetry-library dependency. Pass an <code>EventSink</code> to <code>compileInMemory()</code>. A failing sink never replaces your result.',
        'classes' => [
            'Telemetry\\EventSink' => ['Receives every event. Implement it to log or count.', []],
            'Telemetry\\Event' => ['Base contract of all events: each names its operation and profile version. <code>EventSeverity::of()</code> gives an event a severity.', []],
            'Telemetry\\RetryScheduled' => ['A retry was scheduled after a delay.', []],
            'Telemetry\\AdmissionRejected' => ['A policy refused an attempt. <code>policyKind</code> and <code>reasonCode</code> say which.', []],
            'Telemetry\\AttemptCompleted' => ['An attempt settled, with its circuit verdict.', []],
            'Telemetry\\OperationCompleted' => ['The operation ended, with its disposition.', []],
            'Telemetry\\CircuitTransitioned' => ['The circuit changed state.', []],
            'Telemetry\\BackendErrorObserved' => ['A state backend reported an error.', []],
            'Telemetry\\AccountingDegraded' => ['A bookkeeping step after the call failed or was stale. <code>reasonCode</code> says which.', []],
            'Telemetry\\NullEventSink' => ['The default sink: discards every event.', []],
            'Telemetry\\EventSeverity' => ['Severity of an event, with PSR-3 level names.', []],
        ],
    ],
    [
        'id' => 'testing',
        'title' => 'Test helpers',
        'intro' => 'In the <code>Enaxon\\Reqtide\\Testing</code> namespace, shipped in the same package. For tests only: never call them from production code. See <a href="testing.html">Testing with the virtual clock</a>.',
        'classes' => [
            'Testing\\VirtualClock' => ['A clock that moves only when you move it. Starts at a fixed date so a test gives the same answer every day.', []],
            'Testing\\VirtualDelayScheduler' => ['Advances a <code>VirtualClock</code> instead of sleeping, and records every requested delay.', []],
            'Testing\\ControlledRuntimeAdapter' => ['A synchronous runtime driven by scripts: return a value, throw, advance the clock or request cancellation per dispatch.', []],
            'Testing\\DispatchScript' => ['One scripted dispatch.', []],
            'Testing\\RecordingEventSink' => ['Stores every event for assertions.', []],
            'Testing\\SeededRandomSource' => ['Seeded randomness: same seed, same sequence.', []],
            'Testing\\ScriptedRandomSource' => ['Returns a queued list of integers and fails loudly when it runs out.', []],
        ],
    ],
    [
        'id' => 'redis-package',
        'title' => 'Redis package: set up shared state',
        'intro' => 'These classes belong to the separate package <code>enaxon/reqtide-redis</code>, namespace <code>Enaxon\\Reqtide\\Redis</code>. It is not released and not on Packagist. See <a href="redis.html">Shared state with Redis</a>. The host creates the registry and the ACL users. The application only builds the environment.',
        'classes' => [
            'Redis\\RedisEnvironment' => ['The shared-state counterpart of <code>InMemoryEnvironment</code>. It builds a <code>RuntimeEnvironment</code> whose policies live in one Redis registry, for exactly the policies the definition configures. Creating it opens no connection.', [
                'for' => 'Returns the environment to pass to <code>PipelineBuilder::compile()</code>. The clock of every decision is the Redis server clock.',
                'explain' => 'Adds the facts of the shared state to the usual explanation: state scope, I/O timeout, TLS, registry identity and ceilings. No I/O, and no host, password, prefix or secret in the output.',
            ]],
            'Redis\\RedisRegistry' => ['The immutable identity of one registry, the isolation unit of shared state: a key prefix, an identity, two ceilings and a secret of 32 bytes or more. The object creates nothing in Redis.', []],
            'Redis\\RedisRegistryAdmin' => ['The only way to create, check or retire a registry. The host calls it with its administration connection. The application never does.', [
                'initialize' => 'Creates the registry, or confirms an identical one. A different identity or different ceilings are refused. Refused on a server older than Redis 7.0 and on a prefix that still holds leftovers of a retired registry.',
                'verify' => 'Checks presence, identity, schema and counters. Writes nothing.',
                'retire' => 'Deletes the registry metadata. Partition and record keys stay until <code>purgeRetired()</code> removes them.',
                'purgeRetired' => 'Removes the keys a retired registry left behind. Refuses to run while the registry exists. Returns the number of keys removed.',
                'inspectServer' => 'Reads <code>maxmemory-policy</code> and <code>maxmemory</code>. Needs <code>CONFIG GET</code> and <code>INFO</code>. Refuses a server older than Redis 7.0.',
            ]],
            'Redis\\RedisConnectionOptions' => ['Immutable options for one direct Redis connection. Both timeouts are required. There is no retry option and no way to turn off certificate or host name checks. The password never appears in <code>var_dump</code> or in an exception message.', []],
            'Redis\\RedisAcl' => ['The Redis ACL rules the library needs, and no more. Print them and pass them to <code>ACL SETUSER</code>.', [
                'execution' => 'Rules for the application user: the shipped scripts and their commands, only on keys under the registry prefix.',
                'administration' => 'The same rules plus <code>SCAN</code>, <code>UNLINK</code>, <code>CONFIG GET</code> and <code>INFO</code>, for the host.',
            ]],
            'Redis\\RegistryStatus' => ['What a registry administration call found or did: whether it created the registry, the authority watermark, and the counts against the ceilings.', []],
            'Redis\\ServerPolicyReport' => ['What the server does when it runs out of memory. <code>protectsState()</code> is true for <code>noeviction</code> or no memory limit, false for a policy that can delete keys, and null when the policy could not be read.', []],
        ],
    ],
    [
        'id' => 'redis-operate',
        'title' => 'Redis package: operate',
        'intro' => 'Run these from a worker, a scheduler or an operator command, with a connection that has the administration rules. The library never calls them for you.',
        'classes' => [
            'Redis\\RedisRegistryMaintenance' => ['Bounded cleanup of a registry. One step looks at no more than 128 candidates and no more than 128 finished records. Nothing runs it for you.', [
                'step' => 'Runs one step and returns what it removed and whether <code>more</code> may be found. Call again until <code>more</code> is false.',
            ]],
            'Redis\\RedisCircuitAdmin' => ['Operator actions on circuit breaker partitions. A reset is never called by the library.', [
                'reset' => 'Closes the circuit, discards its history and makes every active permit stale. Returns the new generation, or null when the partition does not exist.',
            ]],
        ],
    ],
    [
        'id' => 'redis-backends',
        'title' => 'Redis package: backends',
        'intro' => 'One backend for each policy. <code>RedisEnvironment::for()</code> builds them for you. Create one yourself only to pass it to <code>ReqtideClient::withBackends()</code>. The third constructor argument is a seam for tests: leave it out. Each backend sends one server-side script call for each step, and a command is never sent twice.',
        'classes' => [
            'Redis\\RedisRateLimitBackend' => ['Shared token bucket, fixed window and sliding counter.', []],
            'Redis\\RedisCircuitBackend' => ['Shared circuit breaker. Open and half-open state, active permits and count history are never reclaimed or evicted.', []],
            'Redis\\RedisConcurrencyBackend' => ['Shared bulkhead with leases. Active owners are never evicted.', []],
            'Redis\\RedisRetryBudgetBackend' => ['Shared retry budget. A balance is never reclaimed or evicted.', []],
            'Redis\\RedisCooldownBackend' => ['Shared cooldown. An extension never shortens an existing pause.', []],
        ],
    ],
    [
        'id' => 'guzzle-package',
        'title' => 'Guzzle package',
        'intro' => 'These classes belong to the separate package <code>enaxon/reqtide-guzzle</code>, namespace <code>Enaxon\\Reqtide\\Guzzle</code>. It is not released and not on Packagist. See <a href="guzzle.html">Guzzle requests with Reqtide</a>. A 4xx or 5xx status is a response, never an exception.',
        'classes' => [
            'Guzzle\\ReqtideClient' => ['The entry point. It owns its cURL handlers and runs each operation, with all its attempts, through one compiled pipeline.', [
                'inMemory' => 'A client with process-local state.',
                'withBackends' => 'A client whose state lives in the backends you pass, for example the Redis backends. Pass a backend for every policy the pipeline configures.',
                'uri' => 'Resolves a reference against the base URI (RFC 3986). The base URI is not a security boundary: an absolute reference replaces the host.',
                'send' => 'Runs the operation and returns the response, or throws the failure of the operation. The timeout covers every attempt and every wait.',
                'sendDetailed' => 'Returns an <code>ExecutionCompletion</code> with the summary of attempts and decisions instead of throwing.',
                'sendAsync' => 'Returns a lazy promise. The operation runs when you call <code>wait()</code>, one operation at a time.',
                'explain' => 'Describes the compiled pipeline of the synchronous runtime, or of the promise runtime with true. No transfer, no state change.',
            ]],
            'Guzzle\\GuzzleConfiguration' => ['The options of the client: the classifier, a short list of Guzzle client defaults and an optional base URI. Any other client default is refused by name.', []],
            'Guzzle\\HttpOperation' => ['One logical HTTP operation: an absolute request and your explicit statement of whether and how it may be sent again. Nothing is guessed from the method or a header.', [
                'read' => 'For GET and HEAD. You state that repeating the request has no side effect, for how long, and which outcomes may be retried.',
                'idempotentMutation' => 'For POST, PUT, PATCH or DELETE that the server deduplicates by an identity. The same body and the same identity are sent on every attempt, and the identity is checked before each one.',
                'single' => 'Sent once, never repeated. Core refuses it in a pipeline that retries more than once.',
                'metadata' => 'The bounded facts passed to the pipeline: the safety statement and the descriptor attributes.',
            ]],
            'Guzzle\\HttpOutcomeClassifier' => ['Decides which statuses and cURL errors are retried, count against a circuit, may fall back or set a cooldown. Every method returns a new instance.', [
                'starter' => 'A documented starting set. It is not a recommendation for every API.',
                'empty' => 'Retries and falls back on nothing and reads no <code>Retry-After</code>.',
                'retryableOutcomeCodes' => 'The reason codes that can be retried. Pass them to <code>read()</code> and <code>idempotentMutation()</code>. An empty list never retries.',
            ]],
            'Guzzle\\RetryAfter' => ['A strict reader of <code>Retry-After</code>: seconds or an HTTP date. A value that cannot be read gives no wait.', [
                'read' => 'Reads the header lines against the given wall time. The greatest valid delay wins.',
            ]],
            'Guzzle\\RetryAfterReading' => ['What the <code>Retry-After</code> lines of one response say. A null delay means no lower bound, never zero.', []],
        ],
    ],
];
