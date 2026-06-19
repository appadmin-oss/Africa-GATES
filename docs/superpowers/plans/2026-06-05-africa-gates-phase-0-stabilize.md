# Africa GATES — Phase 0 "Stabilize" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Africa GATES *true and safe* — fix the security and correctness defects, remove fabricated content, and prove it with a SQLite-backed PHPUnit suite — with **no new frontend build tooling**.

**Architecture:** Stay on PHP 8.1 / Slim 4 / Twig 3 / Eloquent query-builder / MySQL (prod) + SQLite (local/test). Introduce a `tests/` harness that boots an in-memory SQLite DB from the existing `database/sqlite-*.sql` files. Extract the CPI scoring math into a pure, unit-testable `CpiService`. Add small testable seams (`Support\Session`) rather than scattering logic. All changes target the existing files; no React/Vite yet (motion-token file is seeded inert for later phases).

**Tech Stack:** PHP 8.1, Slim 4, Twig 3, illuminate/database 10, PHPUnit 10, SQLite.

**Source spec:** `docs/superpowers/specs/2026-06-05-africa-gates-enterprise-upgrade-design.md` (Phase 0 = §9). This plan covers **Phase 0 only**.

**Hard constraints (from spec §3):** shared-hosting prod; no Node/Redis/containers in prod; truth over theater; adaptive UX. Phase 0 is pre-CI — tests run **locally** (CI arrives in Phase 1).

---

## Decisions to confirm during execution (spec §12)

Two items are explicitly "decide and document." Recommended defaults are baked into the tasks; confirm or override when you reach them:

1. **RBAC scope (Task B6):** default = two roles (`superadmin`, `editor`); superadmin-only for admin-account management, settings, judge management, and award-cycle advancement; `editor`+ for content. Alternative: all admins super-equivalent (then B6 becomes a documented no-op).
2. **`voter_country` (Task C3):** default = rename the column to `nominee_country` (it has always stored the nominee's country, which is genuinely useful) via an idempotent migration. Alternative: drop it if unused.

---

## File map

**Create:**
- `phpunit.xml.dist` — test config
- `tests/bootstrap.php` — autoload + env
- `tests/TestCase.php` — base case: fresh in-memory SQLite + schema + minimal seed per test
- `tests/Unit/VoteServiceTest.php`
- `tests/Unit/CpiServiceTest.php`
- `tests/Unit/CacheServiceTest.php`
- `tests/Unit/SessionTest.php`
- `tests/Unit/CsrfMiddlewareTest.php`
- `tests/Unit/RateLimitGuardTest.php` (magic-link + login throttle)
- `tests/Unit/RoleMiddlewareTest.php`
- `tests/Unit/TwigEscapingTest.php`
- `tests/Unit/StatsServiceTest.php`
- `src/Services/CpiService.php` — extracted pure CPI math
- `src/Support/Session.php` — session-id rotation seam
- `src/Admin/Middleware/RoleMiddleware.php` — RBAC guard
- `src/Services/StatsService.php` — real site counts (replaces hardcoded numbers)
- `public/assets/css/tokens.motion.css` — seeded motion tokens (inert, for Phase 2)
- `resources/motion/motion.ts` — seeded motion-token stub (inert, for Phase 2)
- `database/migrations/2026_06_05_rename_voter_country.sql` (+ sqlite variant) — if C3 default chosen

**Modify:**
- `composer.json` — add `require-dev` phpunit
- `src/Admin/Services/AuthService.php` — session rotation + login throttle + timing equalization
- `src/Admin/Controllers/AuthController.php` — magic-link rate limit + rotation on consume
- `src/Judge/Controllers/AuthController.php` — rotation on verify
- `src/Middleware/CsrfMiddleware.php` — narrow exemption + Origin check
- `src/Services/CacheService.php` — working tag invalidation
- `src/Controllers/ApiController.php` — tag the cached reads; newsletter handling
- `src/Controllers/HomeController.php` — pass real stats / honest data
- `src/Console/Commands/CpiRecomputeCommand.php` — use `CpiService` + per-category max
- `config/container.php` — wire `StatsService`, `RateLimitService` into `AuthService`, `RoleMiddleware`
- `src/routes.php` — apply `RoleMiddleware`; newsletter route (implement or removed)
- `templates/pages/home.twig` — remove fabricated ticker/rating/testimonials/spotlight
- `templates/layout/nav.twig` — real/removed counts; Atlas relabel; Nigeria-now copy
- `templates/layout/gates.twig` — Atlas relabel; `JSON_HEX_TAG`
- `templates/judge/ballot.twig`, `templates/admin/dashboard.twig` — `JSON_HEX_TAG`
- `public/assets/js/main.js` — Atlas honest copy; newsletter wiring/removal; ticker source
- `src/Middleware/SecurityHeadersMiddleware.php` + `public/.htaccess` — resolve `X-XSS-Protection`

**Delete:**
- `africa-gates-voting-and-nomination-claude-eager-wozniak-QLdKH/` (duplicate copy)
- `africa-gates-voting-and-nomination-claude-eager-wozniak-QLdKH.zip`

---

# Workstream A — Test harness bootstrap

### Task A1: PHPUnit + SQLite test harness

**Files:**
- Modify: `composer.json`
- Create: `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/TestCase.php`

- [ ] **Step 1: Add PHPUnit dev dependency**

Run:
```bash
composer require --dev phpunit/phpunit ^10
```
Expected: `phpunit/phpunit` appears under `require-dev` in `composer.json`; `vendor/bin/phpunit` exists.

- [ ] **Step 2: Add a `test` script + autoload-dev to `composer.json`**

Add to `composer.json`:
```json
"autoload-dev": { "psr-4": { "Tests\\": "tests/" } },
"scripts": { "test": "phpunit" }
```
Run `composer dump-autoload`.

- [ ] **Step 3: Create `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="tests/bootstrap.php"
         colors="true" cacheDirectory=".phpunit.cache">
  <testsuites>
    <testsuite name="unit">
      <directory>tests/Unit</directory>
    </testsuite>
  </testsuites>
  <source>
    <include><directory suffix=".php">src</directory></include>
  </source>
</phpunit>
```

- [ ] **Step 4: Create `tests/bootstrap.php`**

```php
<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_DRIVER'] = 'sqlite';
```

- [ ] **Step 5: Create `tests/TestCase.php`** (fresh in-memory DB per test, loaded from the real sqlite schema files)

```php
<?php
declare(strict_types=1);
namespace Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected Capsule $db;

    protected function setUp(): void
    {
        parent::setUp();
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $this->db = $capsule;

        $pdo = Capsule::connection()->getPdo();
        foreach ([
            'sqlite-schema.sql', 'sqlite-admin-schema.sql', 'sqlite-community-schema.sql',
        ] as $file) {
            $sql = file_get_contents(__DIR__ . '/../database/' . $file);
            // sqlite PDO executes multi-statement scripts via exec()
            $pdo->exec($sql);
        }
    }
}
```

- [ ] **Step 6: Smoke-test the harness**

Create `tests/Unit/HarnessSmokeTest.php`:
```php
<?php
declare(strict_types=1);
namespace Tests\Unit;
use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

class HarnessSmokeTest extends TestCase
{
    public function test_tables_exist(): void
    {
        DB::table('gates_profiles')->insert(['slug'=>'x','display_name'=>'X','email'=>'x@x.io','country_code'=>'NG','status'=>'approved']);
        $this->assertSame(1, DB::table('gates_profiles')->count());
    }
}
```
Run: `vendor/bin/phpunit --filter HarnessSmokeTest`
Expected: PASS. If a sqlite schema file fails to load via `exec()`, split on `;` and run statements individually inside `TestCase` (note this in code).

- [ ] **Step 7: Commit**
```bash
git add composer.json composer.lock phpunit.xml.dist tests/
git commit -m "test: bootstrap SQLite-backed PHPUnit harness"
```

---

### Task A2: Characterization tests for `VoteService`

Pin the *current correct* behavior of the vote core before anything else changes.

**Files:**
- Create: `tests/Unit/VoteServiceTest.php`

- [ ] **Step 1: Write characterization tests**

```php
<?php
declare(strict_types=1);
namespace Tests\Unit;
use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\VoteService;

class VoteServiceTest extends TestCase
{
    private function seedNominee(int $id=1, int $cat=10, string $cc='NG'): void {
        DB::table('gates_nominees')->insert(['id'=>$id,'category_id'=>$cat,'name'=>'Nom','country_code'=>$cc,'status'=>'approved','vote_count'=>0]);
    }
    private function seedOtp(string $email, string $code, string $purpose='vote', int $minutes=10): void {
        DB::table('gates_otp_tokens')->insert([
            'email_hash'=>hash('sha256',strtolower($email)),'token_hash'=>hash('sha256',$code),
            'purpose'=>$purpose,'nominee_id'=>1,'award_id'=>0,'attempts'=>0,'is_used'=>0,
            'expires_at'=>Carbon::now()->addMinutes($minutes)->toDateTimeString(),'created_at'=>Carbon::now()->toDateTimeString(),
        ]);
    }

    public function test_happy_path_records_vote_and_consumes_otp(): void {
        $this->seedNominee(); $this->seedOtp('v@x.io','123456');
        $r = (new VoteService())->castVote('v@x.io','123456',1,0,'1.2.3.4');
        $this->assertTrue($r['success']);
        $this->assertSame(1, DB::table('gates_votes')->count());
        $this->assertSame(1, (int)DB::table('gates_otp_tokens')->value('is_used'));
        $this->assertSame(1, (int)DB::table('gates_nominees')->where('id',1)->value('vote_count'));
    }

    public function test_wrong_code_does_not_consume_token(): void {
        $this->seedNominee(); $this->seedOtp('v@x.io','123456');
        $r = (new VoteService())->castVote('v@x.io','000000',1,0,'');
        $this->assertFalse($r['success']);
        $this->assertSame('INVALID_OTP', $r['code']);
        $this->assertSame(0, (int)DB::table('gates_otp_tokens')->value('is_used'));
    }

    public function test_expired_token_rejected(): void {
        $this->seedNominee(); $this->seedOtp('v@x.io','123456','vote',-1);
        $r = (new VoteService())->castVote('v@x.io','123456',1,0,'');
        $this->assertFalse($r['success']);
    }

    public function test_duplicate_vote_blocked(): void {
        $this->seedNominee(); $this->seedOtp('v@x.io','123456');
        (new VoteService())->castVote('v@x.io','123456',1,0,'');
        $this->seedOtp('v@x.io','654321');
        $r = (new VoteService())->castVote('v@x.io','654321',1,0,'');
        $this->assertFalse($r['success']);
        $this->assertSame('ALREADY_VOTED', $r['code']);
    }

    public function test_attempt_cap_burns_token_after_five(): void {
        $this->seedNominee(); $this->seedOtp('v@x.io','123456');
        $svc = new VoteService();
        for ($i=0;$i<5;$i++) { $svc->castVote('v@x.io','000000',1,0,''); }
        $r = $svc->castVote('v@x.io','123456',1,0,'');
        $this->assertFalse($r['success']);
        $this->assertSame('TOO_MANY_ATTEMPTS', $r['code']);
    }
}
```

- [ ] **Step 2: Run — expect PASS (characterization of existing code)**

Run: `vendor/bin/phpunit --filter VoteServiceTest`
Expected: PASS. If `lockForUpdate()` errors on SQLite, it is a documented no-op there — confirm it does not throw; if it does, the test reveals an environment quirk to note (do not change VoteService).

- [ ] **Step 3: Commit**
```bash
git add tests/Unit/VoteServiceTest.php
git commit -m "test: characterize VoteService current behavior"
```

---

### Task A3: Extract `CpiService` + characterize unchanged CPI math

Refactor the scoring math out of the console command into a pure class so it can be tested and so the per-category fix (C1) is isolated. **No behavior change in this task** (still global-max), only extraction — characterization tests must pass against extracted code.

**Files:**
- Create: `src/Services/CpiService.php`, `tests/Unit/CpiServiceTest.php`
- Modify: `src/Console/Commands/CpiRecomputeCommand.php`

- [ ] **Step 1: Create `CpiService` (pure math, no DB)**

```php
<?php
declare(strict_types=1);
namespace AfricaGates\Services;

class CpiService
{
    public const TIERS = [['diamond',850],['platinum',650],['gold',450],['silver',250],['bronze',100],['unranked',0]];
    private const VERIFY = ['none'=>0,'basic'=>40,'verified'=>75,'premium'=>100];

    /** Final 0..1000 nominee score from a normalized public part and judge avg (0..10|null). */
    public function nomineeScore(int $voteCount, int $cohortMaxVotes, ?float $judgeAvg0to10): int
    {
        $max = max(1, $cohortMaxVotes);
        $publicPart = min(1.0, $voteCount / $max);
        $judgeNorm  = $judgeAvg0to10 !== null ? $judgeAvg0to10 / 10.0 : 0.0;
        return (int) round((0.45 * $publicPart + 0.55 * $judgeNorm) * 1000);
    }

    /** @param int[] $linkedFinals */
    public function profileRollup(array $linkedFinals): ?int
    {
        if (!$linkedFinals) return null;
        return (int) round(array_sum($linkedFinals) / count($linkedFinals));
    }

    public function baselineScore(?string $verificationTier, float $completenessPct, int $viewCount): int
    {
        $score = 0.50 * ((self::VERIFY[$verificationTier ?? 'none'] ?? 0) / 100)
               + 0.30 * ($completenessPct / 100)
               + 0.20 * min(1.0, $viewCount / 5000);
        return (int) round($score * 1000);
    }

    public function tierFor(int $score): string
    {
        foreach (self::TIERS as [$name,$min]) { if ($score >= $min) return $name; }
        return 'unranked';
    }
}
```

- [ ] **Step 2: Characterization tests (current math)**

```php
<?php
declare(strict_types=1);
namespace Tests\Unit;
use Tests\TestCase;
use AfricaGates\Services\CpiService;

class CpiServiceTest extends TestCase
{
    public function test_tier_thresholds(): void {
        $s = new CpiService();
        $this->assertSame('diamond', $s->tierFor(900));
        $this->assertSame('gold', $s->tierFor(450));
        $this->assertSame('unranked', $s->tierFor(0));
    }
    public function test_nominee_score_and_rollup(): void {
        $s = new CpiService();
        $this->assertSame(round((0.45*1.0+0.55*0.8)*1000), (float)$s->nomineeScore(10,10,8.0));
        $this->assertSame(500, $s->profileRollup([400,600]));
        $this->assertNull($s->profileRollup([]));
    }
}
```

- [ ] **Step 3: Rewire `CpiRecomputeCommand` to use `CpiService`** (still global max for now — extraction only)

In `execute()`, replace the inline math with calls to a `new CpiService()`: `$cpi->nomineeScore((int)$n->vote_count, $maxVotes, $judgeAverages[$n->id] ?? null)`, `$cpi->profileRollup($linked)`, `$cpi->baselineScore($p->verification_tier,(float)$p->completeness_pct,(int)$p->view_count)`, `$cpi->tierFor($final)`. Keep `$maxVotes = max(1,(int)DB::table('gates_nominees')->max('vote_count'))` for now.

- [ ] **Step 4: Run CPI tests + full suite**

Run: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Commit**
```bash
git add src/Services/CpiService.php tests/Unit/CpiServiceTest.php src/Console/Commands/CpiRecomputeCommand.php
git commit -m "refactor: extract pure CpiService from recompute command (no behavior change)"
```

---

# Workstream C — Correctness fixes

### Task C1: CPI per-category vote normalization (the fairness bug)

**Files:**
- Modify: `src/Console/Commands/CpiRecomputeCommand.php`
- Test: `tests/Unit/CpiServiceTest.php` (+ a command-level integration test)

- [ ] **Step 1: Write the failing intent test** (per-category, in `CpiServiceTest`)

```php
public function test_normalization_is_per_cohort_not_global(): void {
    $s = new CpiService();
    // Same raw votes (5), different cohort maxes -> different public parts.
    $lowCohort  = $s->nomineeScore(5, 5,  null);   // 5/5  = 1.0 public
    $highCohort = $s->nomineeScore(5, 50, null);   // 5/50 = 0.1 public
    $this->assertGreaterThan($highCohort, $lowCohort);
}
```
Run: `vendor/bin/phpunit --filter test_normalization_is_per_cohort_not_global`
Expected: PASS (the pure method already supports a cohort max — the *bug lives in the command passing a global max*). This test locks the contract; the command integration test below is the one that drives the fix.

- [ ] **Step 2: Write a failing command-level test**

Create `tests/Unit/CpiRecomputeTest.php` that seeds two categories with different vote distributions, runs the command, and asserts a top nominee in a *small* category can out-rank-by-public-share a mid nominee in a *huge* category. Use Symfony `CommandTester`. Expected initially: FAIL (global max conflates cohorts).

- [ ] **Step 3: Fix the command — compute per-category max**

Replace the single `$maxVotes` with a per-category map:
```php
$catMax = DB::table('gates_nominees')
    ->selectRaw('category_id, MAX(vote_count) as m')->groupBy('category_id')
    ->pluck('m','category_id')->all();
// per nominee:
$cohortMax = (int)($catMax[$n->category_id] ?? 1);
$final = $cpi->nomineeScore((int)$n->vote_count, $cohortMax, $judgeAverages[$n->id] ?? null);
```
Update the docblock to state per-category normalization (it now matches the code).

- [ ] **Step 4: Run — expect PASS**; then full suite PASS.

- [ ] **Step 5: Commit**
```bash
git add src/Console/Commands/CpiRecomputeCommand.php tests/Unit/CpiServiceTest.php tests/Unit/CpiRecomputeTest.php
git commit -m "fix: normalize CPI community votes per category, not globally"
```

---

### Task C2: CacheService tag invalidation actually works

**Files:**
- Modify: `src/Services/CacheService.php`, `src/Controllers/ApiController.php`, `src/Controllers/HomeController.php`
- Test: `tests/Unit/CacheServiceTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);
namespace Tests\Unit;
use Tests\TestCase;
use AfricaGates\Services\CacheService;

class CacheServiceTest extends TestCase
{
    public function test_forget_by_tag_purges_only_matching_entries(): void {
        $c = new CacheService();
        $c->remember('api:lb:20', 300, fn()=>['x'=>1], ['leaderboard']);
        $c->remember('api:reg:1',  300, fn()=>['y'=>2], ['registry']);
        $c->forgetByTag('leaderboard');
        $this->assertNull($c->get('api:lb:20'));
        $this->assertNotNull($c->get('api:reg:1'));
    }
}
```
Run: expect FAIL (`remember()` has no `$tags` param yet / tags never written).

- [ ] **Step 2: Implement tag writing** in `CacheService`

```php
public function remember(string $key, int $ttl, callable $cb, array $tags = []): mixed {
    try { $r=DB::table('gates_cache')->where('cache_key',$key)->where('expires_at','>',Carbon::now())->first(); if($r) return json_decode($r->payload,true); } catch(\Throwable) {}
    $v=$cb();
    try {
        DB::table('gates_cache')->updateOrInsert(['cache_key'=>$key],[
            'payload'=>json_encode($v),
            'tags'=>$tags ? implode(',', $tags) : null,
            'expires_at'=>Carbon::now()->addSeconds($ttl)->toDateTimeString(),
            'created_at'=>Carbon::now()->toDateTimeString(),
        ]);
    } catch(\Throwable) {}
    return $v;
}
```
(`forgetByTag` already does `where('tags','LIKE',"%$tag%")` — it will now match.)

- [ ] **Step 3: Tag the relevant reads** so invalidation has something to hit:
  - `ApiController::leaderboard` remember → `['leaderboard']`
  - `ApiController::dashboard` remember → `['leaderboard']`
  - `ApiController::registry` remember → `['registry']`
  - `HomeController` `home:lb`/`home:ticker`/`home:spotlight` → `['leaderboard']`; `home:stats` → `['leaderboard','registry']`

- [ ] **Step 4: Run — expect PASS**; full suite PASS.

- [ ] **Step 5: Commit**
```bash
git add src/Services/CacheService.php src/Controllers/ApiController.php src/Controllers/HomeController.php tests/Unit/CacheServiceTest.php
git commit -m "fix: make CacheService tag invalidation work (write + match tags)"
```

---

### Task C3: `voter_country` mislabel

**Files:**
- Modify: `src/Services/VoteService.php`; (default) `database/schema.sql`, `database/sqlite-schema.sql`, + a migration file
- Test: `tests/Unit/VoteServiceTest.php`

- [ ] **Step 1: Confirm usage**
Run: `grep -rn "voter_country" src/` — verify it is **written but never read**. Record the finding in the commit message.

- [ ] **Step 2: Decision** (default = rename to `nominee_country`). If dropping instead, adapt the steps.

- [ ] **Step 3: Failing test** (asserts the honest column)
```php
public function test_vote_stores_nominee_country_not_voter_country(): void {
    $this->seedNominee(1,10,'GH'); $this->seedOtp('v@x.io','123456');
    (new VoteService())->castVote('v@x.io','123456',1,0,'1.2.3.4');
    $this->assertSame('GH', DB::table('gates_votes')->value('nominee_country'));
}
```
(Requires the test schema to have `nominee_country` — update `database/sqlite-schema.sql` in step 4.)

- [ ] **Step 4: Rename in schema files + migration**
Update `gates_votes` column `voter_country` → `nominee_country` in `database/schema.sql` and `database/sqlite-schema.sql`. Add `database/migrations/2026_06_05_rename_voter_country.sql` (MySQL: `ALTER TABLE gates_votes CHANGE voter_country nominee_country VARCHAR(2) NULL;`) and a sqlite note (sqlite needs table rebuild; document the local-reset path since this is demo data).

- [ ] **Step 5: Update `VoteService`** insert key `voter_country` → `nominee_country`.

- [ ] **Step 6: Run — expect PASS**; full suite PASS.

- [ ] **Step 7: Commit**
```bash
git add -A
git commit -m "fix: rename mislabeled votes.voter_country -> nominee_country"
```

---

# Workstream B — Security fixes

### Task B1: Session-id rotation on every privilege transition

**Files:**
- Create: `src/Support/Session.php`, `tests/Unit/SessionTest.php`
- Modify: `src/Admin/Services/AuthService.php`, `src/Admin/Controllers/AuthController.php`, `src/Judge/Controllers/AuthController.php`

- [ ] **Step 1: Create the seam**
```php
<?php
declare(strict_types=1);
namespace AfricaGates\Support;
final class Session {
    /** Rotate the session id, preserving data, to defeat fixation. No-op if no active session. */
    public static function rotate(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
```

- [ ] **Step 2: Failing test**
```php
<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use AfricaGates\Support\Session;

class SessionTest extends TestCase
{
    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_rotate_changes_session_id(): void {
        if (PHP_SAPI === 'cli') { ini_set('session.save_handler','files'); ini_set('session.save_path', sys_get_temp_dir()); }
        @session_start();
        $before = session_id();
        $_SESSION['admin_id'] = 7;
        Session::rotate();
        $this->assertNotSame($before, session_id());
        $this->assertSame(7, $_SESSION['admin_id']); // data preserved
    }
}
```
Run: expect FAIL (class not yet wired / asserts) → after step 1 it passes; this test mainly guards the seam. If the CLI session emits "headers already sent", keep `@runInSeparateProcess` and the ini_set guard; if still flaky, mark the assertion of id-change as the behavioral contract and document that full HTTP verification is manual.

- [ ] **Step 3: Call `Session::rotate()` at all three sites**
  - `AuthService::startSession()` — first line.
  - `AuthController::magicConsume()` — immediately before `startSession` (startSession will rotate; ensure single rotation).
  - Judge `AuthController::loginVerify()` — before setting `$_SESSION['judge_id']`.

- [ ] **Step 4: Run suite PASS.**

- [ ] **Step 5: Manual verification** (record in commit): log in as admin via password and via magic link, and as judge via OTP; confirm the `PHPSESSID` cookie value changes across the login boundary (browser devtools).

- [ ] **Step 6: Commit**
```bash
git add src/Support/Session.php tests/Unit/SessionTest.php src/Admin/Services/AuthService.php src/Admin/Controllers/AuthController.php src/Judge/Controllers/AuthController.php
git commit -m "fix: regenerate session id on admin+judge login (session fixation)"
```

---

### Task B2: Narrow `/api/` CSRF exemption + Origin check

**Files:**
- Modify: `src/Middleware/CsrfMiddleware.php`
- Test: `tests/Unit/CsrfMiddlewareTest.php`

- [ ] **Step 1: Failing tests**
```php
<?php
declare(strict_types=1);
namespace Tests\Unit;
use Tests\TestCase;
use AfricaGates\Middleware\CsrfMiddleware;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ServerRequestInterface as Req;
use Psr\Http\Message\ResponseInterface as Res;

class CsrfMiddlewareTest extends TestCase
{
    private function handler(): Handler {
        return new class implements Handler { public function handle(Req $r): Res { return new Response(200); } };
    }
    private function req(string $method,string $path,array $headers=[]): Req {
        $r = (new ServerRequestFactory())->createServerRequest($method,'https://afg.local'.$path);
        foreach ($headers as $k=>$v) $r = $r->withHeader($k,$v);
        return $r;
    }

    public function test_otp_gated_route_is_exempt(): void {
        $_ENV['APP_URL']='https://afg.local'; $_SESSION['csrf_token']='t';
        $res = (new CsrfMiddleware())(($this->req('POST','/api/vote')), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }
    public function test_other_api_route_requires_same_origin(): void {
        $_ENV['APP_URL']='https://afg.local'; $_SESSION['csrf_token']='t';
        $bad  = (new CsrfMiddleware())($this->req('POST','/api/register',['Origin'=>'https://evil.com']), $this->handler());
        $this->assertSame(403, $bad->getStatusCode());
        $ok   = (new CsrfMiddleware())($this->req('POST','/api/register',['Origin'=>'https://afg.local']), $this->handler());
        $this->assertSame(200, $ok->getStatusCode());
    }
}
```
Run: expect FAIL.

- [ ] **Step 2: Implement** — change `EXEMPT` to the OTP-gated set, add an Origin/Referer allowlist for the rest:
```php
private const CSRF_EXEMPT_OTP = ['/api/vote', '/api/otp/request'];

public function __invoke(Request $req, Handler $handler): Response {
    if (!in_array($req->getMethod(), self::MUTATING, true)) return $handler->handle($req);
    $path = $req->getUri()->getPath();

    if (in_array($path, self::CSRF_EXEMPT_OTP, true)) return $handler->handle($req); // protected by email OTP

    if (str_starts_with($path, '/api/')) {                 // other API writes: same-origin required
        if ($this->sameOrigin($req)) return $handler->handle($req);
        return $this->deny('Cross-origin request blocked.');
    }
    // non-API mutating routes: token required (unchanged)
    $token = $req->getHeaderLine('X-CSRF-Token') ?: (((array)$req->getParsedBody())['_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) return $this->deny('CSRF validation failed.');
    return $handler->handle($req);
}

private function sameOrigin(Request $req): bool {
    $appHost = parse_url((string)($_ENV['APP_URL'] ?? ''), PHP_URL_HOST);
    foreach (['Origin','Referer'] as $h) {
        $v = $req->getHeaderLine($h);
        if ($v !== '') return parse_url($v, PHP_URL_HOST) === $appHost;
    }
    return false; // no Origin/Referer on a cross-site POST is treated as untrusted
}
private function deny(string $msg): Response {
    $res = new \Slim\Psr7\Response(403);
    $res->getBody()->write(json_encode(['success'=>false,'message'=>$msg]));
    return $res->withHeader('Content-Type','application/json');
}
```
Note: same-origin browser `fetch` always sends `Origin` on POST, so legitimate first-party calls pass.

- [ ] **Step 3: Run — expect PASS**; full suite PASS.
- [ ] **Step 4: Commit**
```bash
git add src/Middleware/CsrfMiddleware.php tests/Unit/CsrfMiddlewareTest.php
git commit -m "fix: scope /api CSRF exemption to OTP routes; same-origin check for the rest"
```

---

### Task B3: Rate-limit the admin magic-link request

**Files:**
- Modify: `config/container.php` (inject `RateLimitService` into `AuthController`), `src/Admin/Controllers/AuthController.php`
- Test: `tests/Unit/RateLimitGuardTest.php`

- [ ] **Step 1: Failing test** — call the rate-limit guard logic directly (extract a tiny guard or test via `RateLimitService`):
```php
public function test_magic_link_throttled_per_ip(): void {
    $rl = new \AfricaGates\Services\RateLimitService();
    $ip = hash('sha256','1.2.3.4');
    for ($i=0;$i<5;$i++) $this->assertTrue($rl->check($ip,'admin_magic_req',5,3600));
    $this->assertFalse($rl->check($ip,'admin_magic_req',5,3600)); // 6th blocked
}
```
(Use the `Tests\TestCase` base so `gates_rate_limits` exists.)

- [ ] **Step 2: Implement** — in `AuthController::magicRequest`, before creating the link, add per-IP (5/hour) and per-email (3/hour) checks via injected `RateLimitService`; on limit, set the same non-revealing notice and redirect. Wire `RateLimitService` in `container.php` for `AdminAuthController`.

- [ ] **Step 3: Run — expect PASS**; suite PASS.
- [ ] **Step 4: Commit**
```bash
git add config/container.php src/Admin/Controllers/AuthController.php tests/Unit/RateLimitGuardTest.php
git commit -m "fix: rate-limit admin magic-link requests (anti mail-bomb)"
```

---

### Task B4: `JSON_HEX_TAG` on every `json_encode|raw`

**Files:**
- Modify: `templates/judge/ballot.twig`, `templates/admin/dashboard.twig`, `templates/layout/gates.twig`
- Test: `tests/Unit/TwigEscapingTest.php`

- [ ] **Step 1: Failing test** — render the filter pattern and assert `</script>` is escaped:
```php
public function test_json_encode_escapes_script_tags(): void {
    $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
        't' => "{{ v|json_encode(constant('JSON_HEX_TAG') b-or constant('JSON_HEX_APOS'))|raw }}",
    ]));
    $out = $twig->render('t', ['v' => ['name' => '</script><script>alert(1)</script>']]);
    $this->assertStringNotContainsString('</script>', $out);
}
```
Run: expect PASS for the *pattern* (this test pins the technique). 

- [ ] **Step 2: Apply the flag at each site**, e.g.:
```twig
{{ ballot.categories|json_encode(constant('JSON_HEX_TAG') b-or constant('JSON_HEX_APOS') b-or constant('JSON_HEX_QUOT') b-or constant('JSON_HEX_AMP'))|raw }}
```
Apply to: `ballot.criteria`, `ballot.categories` (ballot.twig:18); `vote_series`, `region_dist`, `tier_dist` (dashboard.twig); and the `structured_data` site (gates.twig:74).

- [ ] **Step 3: Manual verification** — render the judge ballot with a nominee whose name contains `</script>` (seed locally) and confirm no breakout.
- [ ] **Step 4: Commit**
```bash
git add templates/judge/ballot.twig templates/admin/dashboard.twig templates/layout/gates.twig tests/Unit/TwigEscapingTest.php
git commit -m "fix: JSON_HEX_TAG on embedded json_encode to prevent </script> breakout"
```

---

### Task B5: Per-IP admin login throttle + timing equalization

**Files:**
- Modify: `config/container.php`, `src/Admin/Services/AuthService.php`
- Test: extend `tests/Unit/RateLimitGuardTest.php`

- [ ] **Step 1: Failing test** — `attemptLogin` returns null after N failed IP attempts even with the correct password; and an unknown email runs a verify to equalize timing (assert it still returns null and increments the IP counter).

- [ ] **Step 2: Implement** — inject `RateLimitService` into `AuthService`; at the top of `attemptLogin`, check per-IP (e.g. 10/hour) → return null if exceeded. When `findByEmail` returns null, call `password_verify($password, '$2y$10$'.str_repeat('x',53))` (a fixed dummy hash) before returning, to equalize timing. Wire constructor in `container.php`.

- [ ] **Step 3: Run — expect PASS**; suite PASS.
- [ ] **Step 4: Commit**
```bash
git add config/container.php src/Admin/Services/AuthService.php tests/Unit/RateLimitGuardTest.php
git commit -m "harden: per-IP admin login throttle + constant-time unknown-email path"
```

---

### Task B6: Minimal RBAC (`superadmin` vs `editor`)

**Confirm decision first (see top).** Default below.

**Files:**
- Create: `src/Admin/Middleware/RoleMiddleware.php`, `tests/Unit/RoleMiddlewareTest.php`
- Modify: `src/routes.php`

- [ ] **Step 1: Failing test** — middleware allows when `$_SESSION['admin_role']` is in the allowed set; 403/redirect otherwise.

- [ ] **Step 2: Implement `RoleMiddleware`** (constructor takes allowed roles; reads `$_SESSION['admin_role']`; HTML → redirect to `/admin/dashboard` with flash, JSON → 403).

- [ ] **Step 3: Apply** to superadmin-only route groups in `src/routes.php`: `/admin/admins*`, `/admin/settings*`, `/admin/judges*`, and the cycle-advance route. Leave existing inline checks (belt-and-braces) or remove the now-redundant ones — document the choice.

- [ ] **Step 4: Run — expect PASS**; suite PASS.
- [ ] **Step 5: Commit**
```bash
git add src/Admin/Middleware/RoleMiddleware.php tests/Unit/RoleMiddlewareTest.php src/routes.php
git commit -m "feat: role-based access control for sensitive admin routes"
```

---

# Workstream D — Content-integrity overhaul

> These are mostly template/JS/copy edits. Verification is by rendering, not unit tests, except the real-stats service (D1).

### Task D1: Real `StatsService` (replace hardcoded numbers)

**Files:**
- Create: `src/Services/StatsService.php`, `tests/Unit/StatsServiceTest.php`
- Modify: `config/container.php`, `src/Controllers/HomeController.php`

- [ ] **Step 1: Failing test** — `StatsService::summary()` returns real counts from the DB (profiles approved, total votes, nations-with-profiles, published legacy events) and a `nations_live` count.
- [ ] **Step 2: Implement** the service (cached via `CacheService` with `['leaderboard','registry']` tags).
- [ ] **Step 3: Wire** into `HomeController` and expose to templates as `site_stats`.
- [ ] **Step 4: Run — expect PASS**; suite PASS.
- [ ] **Step 5: Commit** `feat: real site statistics service`.

### Task D2: Remove fabricated homepage content

**Files:** `templates/pages/home.twig`, `public/assets/js/main.js`

- [ ] Remove the fake **`atlasTicker`** (or repoint it to a real recent-activity feed from `CommunityService::activityFeed` if available; otherwise delete the "Live" element entirely — do not fake it).
- [ ] Remove the hardcoded **4.8★ / 329 reviews** trust bar and the **testimonials** array (or replace with a clearly-labeled "Sample" block gated by `APP_ENV=demo`).
- [ ] Replace the hardcoded **spotlight** people with real `spotlight_profiles` (already computed by the controller) + honest empty state when none.
- [ ] Replace inline stat literals with `site_stats` from D1.
- [ ] **Verify:** load `/` with an empty DB → no fictional people, honest empty states; with seed → real data.
- [ ] **Commit** `fix: remove fabricated homepage content; wire real data + honest empty states`.

### Task D3: Relabel "Atlas" honestly

**Files:** `templates/layout/gates.twig`, `templates/layout/nav.twig`, `public/assets/js/main.js`

- [ ] Remove "**AI · trained on every cycle**" and "**Cited from the public CPI. Verified daily.**" Replace with honest framing, e.g. "**GATES Guide** — quick answers about CPI, voting & nominations" (a scripted help bot).
- [ ] Remove the fabricated data line in the canned responder (`Amara Okonkwo, CPI 972…`); replace country answers with a link to the real leaderboard rather than invented stats.
- [ ] **Commit** `fix: relabel Atlas as a scripted guide; remove fabricated AI claims/data`.

### Task D4: Newsletter endpoint — implement or remove

**Files:** `src/routes.php`, `public/assets/js/main.js` (+ `src/Controllers/*` if implementing)

- [ ] **Decision:** implement a minimal `POST /api/newsletter/subscribe` (store email hash + raw in a `gates_newsletter` table, rate-limited, same-origin) **or** remove the form + fetch. Default: **implement minimal** (it's small and demonstrates closing the loop).
- [ ] If implementing: add table to schema + sqlite schema, controller method, route, and a unit test for the store/validation path.
- [ ] **Commit** `feat: implement newsletter subscribe endpoint` (or `chore: remove phantom newsletter form`).

### Task D5: `APP_ENV=demo` gating + labeling

**Files:** `templates/layout/*`, any template still using sample data, `.env.example`

- [ ] Add a Twig global `is_demo = (APP_ENV == 'demo')`.
- [ ] Any remaining sample/demo content (e.g. the vote-page `awards_fallback`) renders **only** when `is_demo`, with a visible "Demonstration data" badge; in non-demo with empty DB, show honest empty states.
- [ ] Document `APP_ENV=demo` in `.env.example`.
- [ ] **Verify** all three: `production` + empty DB (honest empties), `demo` (labeled samples), seeded (real data).
- [ ] **Commit** `feat: gate + label demo data behind APP_ENV=demo`.

### Task D6: Nigeria-now / 54-dream copy + real nav counts

**Files:** `templates/layout/nav.twig`, relevant page heroes

- [ ] Replace hardcoded "**1,247+ verified profiles**", "**24 categories**", "**seven editions**" with `site_stats` values **or** vision-framed copy ("Live in Nigeria · building toward 54 nations").
- [ ] Ensure no surface claims continent-wide live data.
- [ ] **Commit** `fix: honest Nigeria-now / 54-dream framing; real or removed counts`.

---

# Workstream E — Repo hygiene

### Task E1: Delete the duplicate copy + zip

- [ ] Run:
```bash
git rm -r --cached --ignore-unmatch "africa-gates-voting-and-nomination-claude-eager-wozniak-QLdKH"
rm -rf "africa-gates-voting-and-nomination-claude-eager-wozniak-QLdKH" "africa-gates-voting-and-nomination-claude-eager-wozniak-QLdKH.zip"
```
(`.gitignore` already excludes both from earlier work.)
- [ ] **Verify:** `git status` clean of those paths; app still runs.
- [ ] **Commit** `chore: remove duplicate app snapshot + archive from tree`.

### Task E2: Seed inert motion tokens (for Phase 2)

**Files:** Create `public/assets/css/tokens.motion.css`, `resources/motion/motion.ts`

- [ ] `tokens.motion.css`: the motion custom properties from spec §5 (`--motion-fast/base/slow`, easings, `--motion-rise`, `--motion-stagger`). Link it from `gates.twig` after `main.css` (harmless, additive).
- [ ] `resources/motion/motion.ts`: exported `transition` + `variants` (`rise`, `stagger`, `fadeIn`) stub with a header comment "consumed by Phase 2 islands; not yet bundled."
- [ ] **Commit** `chore: seed motion design tokens for later phases (inert)`.

### Task E3: Resolve `X-XSS-Protection` header conflict

**Files:** `public/.htaccess`, `src/Middleware/SecurityHeadersMiddleware.php`

- [ ] Make both emit `X-XSS-Protection: 0` (modern guidance; CSP supersedes). Update `.htaccess` `Header always set X-XSS-Protection "0"`.
- [ ] **Commit** `chore: resolve conflicting X-XSS-Protection header`.

---

## Final verification (Phase 0 done)

- [ ] `vendor/bin/phpunit` — all green.
- [ ] Manual: session id rotates on all 3 logins; magic-link throttled; `/api/register` blocked cross-origin, allowed same-origin; `/api/vote` still works; leaderboard cache purges after a vote; CPI recompute uses per-category max; no fabricated "live" content anywhere; demo data labeled+gated; duplicate copy gone.
- [ ] Update `README.md` "Stack/Run" if any command changed (e.g. `composer test`).
- [ ] Tag: `git tag phase-0-stabilize`.
