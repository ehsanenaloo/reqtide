<div align="center" dir="rtl">

[English](../README.md) | **فارسی**

<a href="https://enaloo.com/apps/reqtide/docs/fa/"><img src="assets/logo.svg" width="112" height="112" alt="لوگوی Reqtide"></a>

# Reqtide PHP

**تلاش مجدد، محدودسازی نرخ، مدارشکن و مهلت اجرا در یک پایپ‌لاین؛ قابل تست با زمان مجازی.**

مجموعه‌ابزاری برای افزایش تاب‌آوری برنامه‌های PHP در ارتباط با APIهای ناپایدار.
بستهٔ اصلی هیچ وابستگی‌ای در زمان اجرا ندارد. پروژه با نسخه‌های 0.x در مرحلهٔ بتا قرار دارد و API آن ممکن است بین نسخه‌های فرعی تغییر کند.

[![User guide](https://img.shields.io/badge/User%20guide-Open%20the%20docs-0f766e?logo=readthedocs&logoColor=white&style=for-the-badge)](https://enaloo.com/apps/reqtide/docs/fa/)
[![Examples](https://img.shields.io/badge/Examples-Run%20in%20Docker-2496ED?logo=docker&logoColor=white&style=for-the-badge)](../examples)

[![License: MIT](https://img.shields.io/badge/license-MIT-green)](../LICENSE)
![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)
![Core runtime dependencies: none](https://img.shields.io/badge/core%20runtime%20dependencies-none-lightgrey)
![Status: beta](https://img.shields.io/badge/status-beta-yellow)
[![Docs](https://img.shields.io/badge/docs-guides-0f766e)](https://enaloo.com/apps/reqtide/docs/fa/)

</div>

<div dir="rtl">

## چرا Reqtide ساخته شد؟

یک جا حلقهٔ تلاش مجدد داریم، جای دیگر شمارندهٔ درخواست‌ها و جایی هم متغیری که از دسترس خارج شدن سرویس را مشخص می‌کند. این کدها به‌مرور در برنامه پراکنده می‌شوند و هماهنگ نگه‌داشتنشان دشوار می‌شود: کدام محدودیت باید اول بررسی شود و چه زمانی باید عملیات را متوقف کرد؟ برای تست این رفتارها هم معمولاً باید زمان تأخیر بین تلاش‌ها واقعاً سپری شود.

Reqtide همهٔ این تصمیم‌ها را در یک پایپ‌لاین و با ترتیب مشخص مدیریت می‌کند. شما مهلت کل عملیات، تعداد و سهمیهٔ تلاش‌های مجدد، محدودیت نرخ درخواست‌ها، مدارشکن، سقف اجرای هم‌زمان (Bulkhead) و در صورت نیاز، راهکار جایگزین (Fallback) را تعیین می‌کنید. خطاها در قالب نتایجی با نوع مشخص گزارش می‌شوند تا بتوانید بر اساس نوع آن‌ها تصمیم بگیرید. همهٔ مقادیر عددی را خودتان تعیین می‌کنید؛ Reqtide برای آن‌ها مقدار پیش‌فرضی ندارد. امکان تزریق منبع زمان هم وجود دارد؛ بنابراین تست تأخیر نمایی بین تلاش‌ها می‌تواند در چند میکروثانیه تمام شود.

Reqtide این سیاست‌ها را هنگام اجرای یک `callable` اعمال می‌کند. این کتابخانه، کلاینت HTTP، صف پردازش یا ابزار محافظت در لبهٔ شبکه نیست. در بستهٔ اصلی، وضعیت تنها در حافظهٔ همان فرایند PHP نگهداری می‌شود. جزئیات در [محدودیت‌ها و تضمین‌های کتابخانه](https://enaloo.com/apps/reqtide/docs/fa/limits/) آمده است.

---

## نصب

| روش نصب یا محیط اجرا | وضعیت |
| --- | --- |
| Packagist (`composer require enaxon/reqtide`) | از نسخهٔ 0.1.0 در دسترس است |
| PHP 8.3، 8.4 و 8.5 | در محیط خط فرمان تست شده است |
| PHP-FPM | در CI سه پیکربندی تست می‌شود، برای PHP 8.3 و 8.4 و 8.5، هر کدام با nginx، شش worker و Redis 8.2.10. Apache و دیگر وب‌سرورها تست نشده‌اند. |

```shell
composer require enaxon/reqtide
composer require enaxon/reqtide-redis   # optional: state shared by processes
composer require enaxon/reqtide-guzzle  # optional: Guzzle integration
```

هر سه بسته هم‌زمان و با شماره‌نسخهٔ یکسان منتشر می‌شوند. بسته‌های Redis و Guzzle دقیقاً به همان نسخه از بستهٔ اصلی نیاز دارند. برای اجرای مثال‌ها بدون نصب PHP روی دستگاه، مخزن را کلون کنید و از Docker استفاده کنید. مراحل در [راهنمای نصب](https://enaloo.com/apps/reqtide/docs/fa/install/) آمده است.

کد زیر را در پروژه‌ای که بستهٔ اصلی در آن نصب شده، با نام `demo.php` ذخیره کنید. این کد همان مثال [`examples/06-getting-started.php`](../examples/06-getting-started.php) است.

```php
<?php

declare(strict_types=1);

namespace Reqtide\Examples\GettingStarted;

use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Ratio;

require __DIR__ . '/vendor/autoload.php';

/** The failure we treat as transient. Anything else is a plain failure and is never retried. */
final class UpstreamTimeout extends \RuntimeException {}

// Build the pipeline once and reuse it. Every number is yours: Reqtide has no defaults.
$pipeline = PipelineBuilder::profile('payments', 'v1')
    ->partition('shop', 'prod', 'payments-api')
    // One attempt may take at most 2 s (checked by you, not enforced inside your code: "Cooperative").
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
    // At most 3 attempts in total (the first call counts), exponential backoff from 10 ms up to 100 ms.
    ->retry(3, BackoffMode::Exponential, Duration::milliseconds(10), Duration::milliseconds(100), JitterMode::Full)
    // Retries draw from a budget so a failing dependency is not hammered: 10 tokens, 10 more per minute.
    ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(10))
    // Token bucket: 5 tokens, refilled 5 per second. Every dispatch, retries included, costs 1 token.
    ->rateLimit(PipelineBuilder::tokenBucket(5, 5, Duration::seconds(1)))
    // Judge the last 10 completed calls; from 4 samples on, open at >= 1/2 failures, for 30 s, then allow 1 probe.
    ->circuitBreaker(PipelineBuilder::lastCalls(10), 4, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))
    // Only UpstreamTimeout may be retried.
    ->compileInMemory(ExceptionClassifier::create()->retryOn(UpstreamTimeout::class));

// The protected call. Here it fails twice with a timeout and then succeeds.
$calls = 0;
$callApi = static function (AttemptContext $attempt) use (&$calls): string {
    ++$calls;
    if ($calls < 3) {
        throw new UpstreamTimeout('upstream timed out');
    }

    return "ok on attempt {$attempt->dispatchedAttemptNumber}";
};

// One context per operation. Its deadline covers all attempts and waits together. forReadOnlyCallable() is YOUR
// statement that running this callable again is safe; Reqtide cannot check it, and refuses to retry without it.
$context = $pipeline->newContext(
    Duration::seconds(30),
    OperationSafety::replayableRead(ReplaySafetyContract::forReadOnlyCallable('get-payment', Duration::seconds(3600))),
);

echo $pipeline->execute($callApi, $context), "\n";
echo "invocations: {$calls}\n";
```

خروجی:

```text-output
ok on attempt 3
invocations: 3
```

با فراخوانی `forReadOnlyCallable()`، صریحاً اعلام می‌کنید که اجرای دوبارهٔ این `callable` بی‌خطر است. Reqtide خودش چنین فرضی نمی‌کند و بدون این اعلام، عملیات را تکرار نخواهد کرد. توضیح خط‌به‌خط این مثال را در [راهنمای شروع](https://enaloo.com/apps/reqtide/docs/fa/getting-started/) بخوانید.

---

## قابلیت‌ها

| قابلیت | توضیح |
| --- | --- |
| **مهلت کل عملیات** | یک مهلت مشترک برای تمام تلاش‌ها، تأخیرها و اجرای راهکار جایگزین. اگر زمان باقی‌مانده برای تلاش مجدد کافی نباشد، آن تلاش آغاز نمی‌شود. |
| **تلاش مجدد** | تأخیر ثابت یا نمایی بین تلاش‌ها (Backoff)، نوسان تصادفی اختیاری در تأخیر (Jitter)، رعایت `Retry-After` به‌عنوان حداقل زمان انتظار، و سهمیه‌ای برای محدودکردن ترافیک ناشی از تلاش‌های مجدد. |
| **محدودسازی نرخ** | الگوریتم سطل توکن (Token Bucket)، پنجرهٔ ثابت یا شمارندهٔ تقریبی با پنجرهٔ لغزان؛ همگی با استفاده از محاسبات صحیح. |
| **مدارشکن** | ارزیابی فراخوانی‌ها در پنجره‌ای بر اساس تعداد یا زمان، تعیین حداقل تعداد نمونه و اجرای فراخوانی‌های آزمایشی در حالت نیمه‌باز. |
| **محدودسازی هم‌زمانی و دورهٔ انتظار** | تعیین سقف اجرای هم‌زمان با مجوزهای مدت‌دار (Lease)، و اعمال دورهٔ انتظار مشترک (Cooldown) پس از دریافت `Retry-After`. |
| **راهکار جایگزین** | برای هر عملیات جداگانه فعال می‌شود و هنگام شکست آن به کار می‌رود. در صورت تمایل، می‌توانید آن را برای زمانی هم فعال کنید که اجرای عملیات رد می‌شود. |
| **خطاها و رویدادهای دارای نوع مشخص** | کد دلیل (`ReasonCode`)، شیء `Problem` با ساختار RFC 9457 و بدون اطلاعات حساس، رویدادهای دارای نوع مشخص و متد `explain()`. |
| **ابزارهای تست** | ساعت مجازی، امکان تعریف رفتار هر تلاش از قبل، تولید اعداد تصادفی با مقدار اولیهٔ مشخص (Seed) و ثبت رویدادها؛ همگی در همان بسته. |

همهٔ این قابلیت‌ها در بستهٔ اصلی وجود دارند و با نگهداری وضعیت در حافظه تست شده‌اند. با این حال، صرفِ گذراندن این تست‌ها به‌معنای آمادگی کتابخانه برای استفاده در محیط عملیاتی نیست.

سه بستهٔ Composer با شماره‌نسخهٔ یکسان ارائه می‌شوند:

| بسته | کاربرد | وضعیت |
| --- | --- | --- |
| `enaxon/reqtide` | پایپ‌لاین، سیاست‌ها، نتیجه‌های دارای نوع مشخص و ابزارهای تست؛ بدون وابستگی در زمان اجرا. | بتا |
| [`enaxon/reqtide-redis`](../packages/redis/README.md) | اشتراک وضعیت محدودسازی نرخ، مدارشکن، سقف هم‌زمانی، سهمیهٔ تلاش مجدد و دورهٔ انتظار میان فرایندها، با Redis 7.0 یا بالاتر. | بتا؛ در خط فرمان با PHP 8.3 تا 8.5 و در پیکربندی‌های PHP-FPM تست شده است. Sentinel، Cluster و Valkey پشتیبانی نمی‌شوند. |
| [`enaxon/reqtide-guzzle`](../packages/guzzle/README.md) | اعمال سیاست‌های پایپ‌لاین بر درخواست‌های Guzzle 7 از طریق `ReqtideClient`، با قواعد صریح برای مجاز بودن ارسال مجدد درخواست. | بتا؛ در خط فرمان با PHP 8.3 تا 8.5 و handlerهای cURL تست شده است. در PHP-FPM تست نشده و از PSR-18 پشتیبانی نمی‌کند. |

---

## نمونه‌های کاربرد

نمونه‌های زیر از اسکریپت‌های موجود در مخزن گرفته شده‌اند و خروجی ثبت‌شدهٔ هرکدام نیز در ادامه آمده است.

### توقف فراخوانی‌های یک سرویس ازکارافتاده

پس از چهار فراخوانی ناموفق، مدار باز می‌شود. فراخوانی پنجم بدون اجرای کد شما رد می‌شود. استثنای حاصل، نوع مشخصی دارد و دلیل رد شدن را در قالبی ارائه می‌کند که برنامه می‌تواند آن را پردازش کند. این نمونه از [`examples/02-failure-demo.php`](../examples/02-failure-demo.php) است.

```php
// Judge the last 4 completed calls, but only once 4 samples exist; open when at least half of them failed, stay
// open for 30 s. The permit lease (10 s) must exceed the attempt budget plus 1 s. The default classifier counts
// every exception as a circuit failure and retries none.
$pipeline = $base()
    ->circuitBreaker(PipelineBuilder::lastCalls(4), 4, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))
    ->compileInMemory(ExceptionClassifier::create());

$invocations = 0;
$broken = static function (AttemptContext $attempt) use (&$invocations): string {
    ++$invocations;

    return upstreamIsDown();
};

say('-- circuit breaker');
for ($i = 1; $i <= 4; ++$i) {
    try {
        $pipeline->execute($broken, $context($pipeline));
        check(false, 'a broken callable must not succeed');
    } catch (PermanentError $e) {
        // Closed circuit: your exception comes through untouched.
        say("call {$i}: PermanentError ({$e->getMessage()})");
    }
}
check($invocations === 4, 'the callable ran for each of the four closed-circuit calls');
```

```text-output
-- circuit breaker
call 1: PermanentError (upstream is down)
call 2: PermanentError (upstream is down)
call 3: PermanentError (upstream is down)
call 4: PermanentError (upstream is down)
call 5: Enaxon\Reqtide\Exception\AdmissionRejectedException
```

### تست بدون انتظار واقعی

بستهٔ اصلی ساعت مجازی و ثبت‌کنندهٔ رویداد دارد و به شما اجازه می‌دهد رفتار هر تلاش را از قبل تعیین کنید. به این ترتیب، برای تست تأخیرهای ۱۰۰ و ۲۰۰ میلی‌ثانیه‌ای لازم نیست واقعاً این مدت منتظر بمانید. این نمونه از [`examples/08-testing.php`](../examples/08-testing.php) است.

```php
echo "-- backoff without sleeping\n";
[$pipeline, $clock, $scheduler, $runtime, $events] = rig(false);
$runtime->enqueue(
    DispatchScript::throwing(new UpstreamTimeout('503')),
    DispatchScript::throwing(new UpstreamTimeout('503')),
    DispatchScript::returning('ok'),
);
$start = $clock->peekMonotonic();
$result = $pipeline->execute($unused, context($pipeline, $clock));
$delays = array_map(static fn(Duration $d): int => intdiv($d->nanoseconds, 1_000_000), $scheduler->requestedDelays());

expect($result === 'ok', 'the third dispatch succeeds');
expect($runtime->dispatchCount() === 3, 'exactly three dispatches');
expect($delays === [100, 200], 'exponential backoff waited 100 ms, then 200 ms');
```

```text-output
-- backoff without sleeping
ok: the third dispatch succeeds
ok: exactly three dispatches
ok: exponential backoff waited 100 ms, then 200 ms
```

### اعمال محدودیت مشترک میان فرایندها

با `enaxon/reqtide-redis`، دو پایپ‌لاین مستقل از یک سطل توکن مشترک استفاده می‌کنند. در این مثال، از مجموع پنج فراخوانی، فقط سه فراخوانی نخست مجوز اجرا می‌گیرند؛ تفاوتی ندارد از کدام پایپ‌لاین ارسال شده باشند. کد از [`packages/redis/examples/01-shared-rate-limit.php`](../packages/redis/examples/01-shared-rate-limit.php) است. اسکریپت `tools/run-redis-examples.sh` نیز یک سرور Redis را در Docker اجرا می‌کند.

```PHP
/** What each "process" does: build the same pipeline on the Redis environment. */
$build = static function () use ($registry) {
    $builder = PipelineBuilder::profile('shared-limit', 'v1')
        ->partition('example', 'local', 'reports-api')
        ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
        // Three tokens, one refilled per hour: three calls for the life of this script, across both pipelines.
        ->rateLimit(PipelineBuilder::tokenBucket(3, 1, Duration::seconds(3600)));

    return $builder->compile(RedisEnvironment::for($builder->definition(), ExceptionClassifier::create(), connection(), $registry));
};

$pipelines = ['A' => $build(), 'B' => $build()];
$sequence = ['A', 'B', 'A', 'B', 'A'];
```

```console
call 1 via A: served
call 2 via B: served
call 3 via A: served
call 4 via B: refused (rate.exhausted)
call 5 via A: refused (rate.exhausted)
served by A: 2, served by B: 1
```

اگر Redis در دسترس نباشد، فراخوانی اجرا نمی‌شود و استثنای `StateUnavailableException` دریافت می‌کنید.

### افزایش تاب‌آوری درخواست‌های Guzzle

در `enaxon/reqtide-guzzle`، شما مشخص می‌کنید ارسال مجدد کدام درخواست‌ها مجاز است. کد HTTP 503 نیز به‌عنوان پاسخ برگردانده می‌شود؛ یعنی پس از آخرین تلاش، همان پاسخ را دریافت می‌کنید. در این مثال از سرور تست محلی مخزن استفاده شده است. کد در [`packages/guzzle/examples/01-read-with-retry.php`](../packages/guzzle/examples/01-read-with-retry.php) قرار دارد.

```PHP
$client = ReqtideClient::inMemory(
    PipelineBuilder::profile('orders-api', 'v1')
        ->partition('shop', 'prod', 'orders-api')
        // The transport enforces this timeout on every attempt.
        ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
        // Up to three attempts, 20 ms apart, and a budget so that a failing server is not hammered.
        ->retry(3, BackoffMode::Constant, Duration::milliseconds(20), Duration::seconds(1), JitterMode::None)
        ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(12)),
);

$read = static fn(string $path): HttpOperation => HttpOperation::read(
    new Request('GET', $server->baseUri . $path),
    'orders.read',
    Duration::seconds(60),
    HttpOutcomeClassifier::starter()->retryableOutcomeCodes(),
);

$response = $client->send($read('/status/503'), Duration::seconds(10));
echo 'failing server: status ', $response->getStatusCode(), ', requests at the server: ', $server->count('/status/503'), "\n";
```

```console
failing server: status 503, requests at the server: 3
```

---

## راهنمای کامل

راهنمای کامل در [enaloo.com](https://enaloo.com/apps/reqtide/docs/fa/) است: ۱۶ صفحه با جست‌وجو و پوستهٔ روشن و تیره.

| موضوع | صفحه‌های مرتبط |
| --- | --- |
| شروع کار | [نصب](https://enaloo.com/apps/reqtide/docs/fa/install/) · [راهنمای شروع](https://enaloo.com/apps/reqtide/docs/fa/getting-started/) |
| مفاهیم اصلی | [مفاهیم](https://enaloo.com/apps/reqtide/docs/fa/concepts/) · [انتخاب سیاست‌ها](https://enaloo.com/apps/reqtide/docs/fa/choosing-policies/) |
| سیاست‌های تاب‌آوری | [تلاش مجدد](https://enaloo.com/apps/reqtide/docs/fa/retry-and-budget/) · [محدودسازی نرخ](https://enaloo.com/apps/reqtide/docs/fa/rate-limiting/) · [مدارشکن](https://enaloo.com/apps/reqtide/docs/fa/circuit-breaker/) · [محدودسازی هم‌زمانی و دورهٔ انتظار](https://enaloo.com/apps/reqtide/docs/fa/bulkhead-and-cooldown/) · [راهکار جایگزین](https://enaloo.com/apps/reqtide/docs/fa/fallback/) |
| خطاها و تست‌ها | [مدیریت خطا](https://enaloo.com/apps/reqtide/docs/fa/error-handling/) · [تست](https://enaloo.com/apps/reqtide/docs/fa/testing/) |
| مراجع | [مرجع API](../src) · [محدودیت‌ها](https://enaloo.com/apps/reqtide/docs/fa/limits/) · [مقایسه](https://enaloo.com/apps/reqtide/docs/fa/comparison/) · [پرسش‌های متداول](https://enaloo.com/apps/reqtide/docs/fa/faq/) |

---

## مدیریت خطاها

Reqtide استثناهای کد شما را تغییر نمی‌دهد و آن‌ها را در استثنای دیگری نمی‌پیچد؛ کد فراخواننده همان استثنای اصلی را دریافت می‌کند. اما اگر خود Reqtide تصمیمی بگیرد، مثلاً اجرای عملیات را رد کند، آن را با استثنایی از نوع مشخص گزارش می‌دهد. این استثنا یک کد ثابت برای مشخص‌کردن علت و یک شیء `Problem` دارد که می‌توانید آن را در لاگ ثبت کنید یا به‌عنوان پاسخ برگردانید. نمونهٔ زیر از [`examples/07-error-handling.php`](../examples/07-error-handling.php) است.

```php
// 3. Your own exceptions are never wrapped. They come out exactly as your callable threw them.
$failing = $base()->compileInMemory(ExceptionClassifier::create());
try {
    $failing->execute(static fn(): string => throw new UpstreamTimeout('upstream timed out'), $failing->newContext(Duration::seconds(30)));
} catch (UpstreamTimeout $e) {
    echo 'business exception: ', $e::class, ' (', $e->getMessage(), ")\n";
}
```

```text-output
business exception: Reqtide\Examples\ErrorHandling\UpstreamTimeout (upstream timed out)
```

منطق مدیریت خطا را بر اساس `ReasonCode` و `retryability` بنویسید، نه متن پیام خطا. جزئیات در [راهنمای مدیریت خطا](https://enaloo.com/apps/reqtide/docs/fa/error-handling/) آمده است.

---

## بررسی‌ها و تست‌های انجام‌شده

موارد زیر به‌صورت محلی، در کانتینرهای Docker و از طریق خط فرمان اجرا شده‌اند:

- **بستهٔ اصلی:** تست‌های واحد، تحلیل ایستا با بالاترین سطح PHPStan و بررسی رعایت استاندارد کدنویسی، روی PHP 8.3، 8.4 و 8.5 با موفقیت اجرا شده‌اند.
- **مثال‌ها و مستندات:** همهٔ مثال‌ها اجرا می‌شوند و خروجی آن‌ها با فایل خروجی ثبت‌شده مقایسه می‌شود. تمام بلوک‌های کد و خروجی در این README نیز با همان فایل‌ها تطبیق داده می‌شوند.
- **بستهٔ Redis:** مجموعه‌تست‌ها روی Redis 7.4.11 و 8.2.10، با TLS و ساعت قابل‌کنترل سرور اجرا می‌شوند.
- **بستهٔ Guzzle:** تست‌ها با Guzzle 7.15.5 و handlerهای cURL اجرا شده‌اند. برای ارزیابی سناریوهای چندفرایندی و شرایط خطا، تعداد درخواست‌های دریافتی سرور تست بررسی می‌شود. خروجی‌های بسته نیز بررسی شده‌اند تا مشخص شود مقادیر محرمانه‌ای که به‌صورت تصادفی برای تست تولید شده‌اند در آن‌ها نشت کرده‌اند یا نه؛ هیچ موردی پیدا نشده است.

### نکاتی که باید بدانید

- سری نسخه‌های 0.x در مرحلهٔ بتا است. API ممکن است در نسخه‌های فرعی تغییر کند و تمام تغییرات ناسازگار در فهرست تغییرات ثبت می‌شوند. CI با هر push در GitHub Actions اجرا می‌شود و PHP 8.3، 8.4 و 8.5، مجموعه‌تست‌های Redis، پیکربندی PHP-FPM، سنجش پوشش تست و تست جهش را در بر می‌گیرد. نتایج در زبانهٔ Actions مخزن قابل مشاهده‌اند.
- برای PHP-FPM سه پیکربندی در CI تست می‌شود، برای PHP 8.3 و 8.4 و 8.5، هر کدام با nginx، شش worker و Redis 8.2.10. کلاینت Guzzle در FPM، همچنین Apache و دیگر وب‌سرورها تست نشده‌اند. Redis Sentinel، Redis Cluster، Valkey، Guzzle 8 و PSR-18 پشتیبانی نمی‌شوند.
- بستهٔ اصلی، وضعیت را در حافظهٔ یک فرایند نگه می‌دارد. در PHP-FPM، هر درخواست با وضعیت تازه آغاز می‌شود.
- اگر اجرای callback شروع شده باشد، Reqtide آن را متوقف نمی‌کند؛ مهلت عملیات فقط بین مراحل بررسی می‌شود.
- اجرای دقیقاً یک‌بارهٔ عملیات تضمین نمی‌شود. همچنین اگر فراخوانی پیش از آغاز اجرا لغو شود، سهم مصرف‌شده از محدودیت نرخ بازگردانده نمی‌شود.
- امنیت پروژه تاکنون به‌صورت مستقل بازبینی نشده است.

فهرست کامل در [محدودیت‌ها و تضمین‌های کتابخانه](https://enaloo.com/apps/reqtide/docs/fa/limits/) آمده است.

---

## پیش‌نیازها

- PHP نسخهٔ **8.3** یا بالاتر، با معماری ۶۴ بیتی، و Composer 2. تست‌های خط فرمان روی PHP 8.3، 8.4 و 8.5 اجرا شده‌اند.
- بستهٔ اصلی به هیچ بستهٔ دیگری در Composer وابسته نیست.
- بستهٔ `enaxon/reqtide-redis` به `predis/predis` نسخهٔ 3.7، افزونهٔ `ext-openssl` و سرور Redis 7.0 یا بالاتر با تنظیم `maxmemory-policy noeviction` نیاز دارد.
- بستهٔ `enaxon/reqtide-guzzle` به `guzzlehttp/guzzle` نسخهٔ 7.15.5 یا نسخه‌های جدیدتر از سری 7.x، و افزونهٔ `ext-curl` نیاز دارد.

---

## پرسش‌های متداول

<details>
<summary><b>آیا بسته در Packagist منتشر شده است؟</b></summary>

بله. با دستور `composer require enaxon/reqtide` می‌توانید آن را نصب کنید. جزئیات در [راهنمای نصب](https://enaloo.com/apps/reqtide/docs/fa/install/) آمده است.
</details>

<details>
<summary><b>آیا درخواست POST هم دوباره ارسال می‌شود؟</b></summary>

فقط زمانی که شما صریحاً بی‌خطر بودن تکرار فراخوانی را اعلام کرده باشید، سهمیهٔ تلاش مجدد تعریف شده باشد و کلاس استثنا در فهرست استثناهای مجاز برای تلاش مجدد قرار داشته باشد.
</details>

---

## مشارکت در پروژه

از گزارش باگ، اصلاح مستندات و پول‌ریکوئست‌های کوچک استقبال می‌کنیم. پیش از شروع، [راهنمای مشارکت](CONTRIBUTING.md) را بخوانید؛ روند کار با Docker و استاندارد کدنویسی در آن توضیح داده شده است.

- **گزارش باگ:** از فرم گزارش باگ استفاده کنید و اسکریپت کوتاهی برای بازتولید مشکل ارائه دهید.
- **دریافت راهنمایی:** به [SUPPORT.md](SUPPORT.md) مراجعه کنید.

آسیب‌پذیری‌های امنیتی را به‌صورت خصوصی و طبق [SECURITY.md](SECURITY.md) گزارش دهید. لطفاً [آیین‌نامهٔ رفتاری پروژه](CODE_OF_CONDUCT.md) را نیز رعایت کنید.

### مشارکت‌کنندگان

از همهٔ کسانی که در این پروژه مشارکت کرده‌اند سپاسگزاریم. پس از ادغام اولین مشارکت شما در پروژه، نامتان در این بخش قرار می‌گیرد.

تغییرات اخیر را در [CHANGELOG.md](../CHANGELOG.md) ببینید.

---

## مجوز

[MIT](../LICENSE). حق نشر © ۲۰۲۶ Ehsan Enaloo.

## دربارهٔ پروژه

Reqtide PHP را [Ehsan Enaloo](https://github.com/ehsanenaloo) توسعه داده است. این مجموعه‌ابزار برای افزایش تاب‌آوری برنامه‌های PHP، امکاناتی مانند تعیین مهلت اجرا، تلاش مجدد، محدودسازی نرخ، مدارشکن و محدودسازی اجرای هم‌زمان را فراهم می‌کند و بسته‌هایی برای نگهداری وضعیت در Redis و یکپارچه‌سازی با Guzzle دارد.

برچسب‌ها: `php` `resilience` `retry` `rate-limit` `circuit-breaker` `bulkhead` `guzzle` `redis` `api` `http`

</div>
