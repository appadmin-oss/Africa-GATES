# Attendee on Google Cloud — setup runbook

Standing up the meeting bot that Africa GATES interviews (and Afrovanguard meetings) talk
to. End to end this is about **90 minutes**, most of it waiting for a Docker build.

Everything here is checked against the Attendee source at commit `77e990ed`, not against
its marketing. Where the obvious path is wrong, it says so.

---

## 0. Before you start

You need:

- A Google Cloud project with billing enabled, and `gcloud` installed and logged in.
- A **domain you control** — a real hostname with a real certificate. Not optional:
  Attendee requires HTTPS for webhooks by default, and Africa GATES refuses to register a
  callback over plain HTTP.
- An **OpenAI API key** (transcription, and the bot's voice).

Three decisions already made for you, with reasons:

| Decision | Why |
|---|---|
| One Compute Engine VM, Docker Compose | Cloud Run **services** cannot hold a bot — see §11, which works through the request-driven model properly, including the one Cloud Run product that *is* a genuine fit. GKE is what Attendee's own manifests target and is right above ~5 concurrent interviews; below that it is a cluster to operate for no gain. |
| `LAUNCH_BOT_METHOD` left **unset** | The non-obvious one. Unset means bots run as Celery tasks inside the worker. `docker-compose-multi-host` needs a *second* VM consuming a `bot_launcher_vm` queue, and `kubernetes` needs a cluster. Setting either on a single box gives you bots that never launch. |
| Google Cloud Storage via its S3-compatible API | Attendee only speaks S3 or Azure. GCS's XML API is S3-compatible with HMAC keys, so you keep recordings in GCP and never open an AWS account. |

### Sizing

Each bot is a headless Chrome with audio. Budget **~2 vCPU and 4 GB per concurrent bot**,
plus ~2 vCPU / 4 GB for web, worker, Postgres and Redis.

| Concurrent interviews | Machine type | Rough on-demand |
|---|---|---|
| 1–2 | `e2-standard-4` (4 vCPU, 16 GB) | ~$100/mo |
| 3–4 | `e2-standard-8` (8 vCPU, 32 GB) | ~$200/mo |

Check live pricing before committing — those are order-of-magnitude. **Interviews are
seasonal: see §9 for stopping the VM between judging rounds, which is the real saving.**

---

## 1. The VM

Pick a region near your panellists — `europe-west1` and `europe-west2` are the usual
lowest-latency choices for West Africa; `africa-south1` (Johannesburg) is closer for
Southern Africa. Latency here affects the bot's audio, not just page loads.

```bash
export PROJECT_ID="your-project-id"
export REGION="europe-west1"
export ZONE="europe-west1-b"

gcloud config set project "$PROJECT_ID"
gcloud services enable compute.googleapis.com storage.googleapis.com

# A static IP first, so DNS never has to change.
gcloud compute addresses create attendee-ip --region="$REGION"
export ATTENDEE_IP=$(gcloud compute addresses describe attendee-ip \
  --region="$REGION" --format='value(address)')
echo "Point your DNS A record at: $ATTENDEE_IP"

gcloud compute instances create attendee \
  --zone="$ZONE" \
  --machine-type=e2-standard-4 \
  --image-family=ubuntu-2404-lts --image-project=ubuntu-os-cloud \
  --boot-disk-size=100GB --boot-disk-type=pd-balanced \
  --address="$ATTENDEE_IP" \
  --tags=attendee
```

**Create the DNS record now** (`meetbot.your-domain.org` → `$ATTENDEE_IP`) so it has
propagated by the time Caddy asks for a certificate in §5.

### Firewall

Only 443 from the internet. Postgres and Redis stay on the Docker network and must never
be reachable from outside.

```bash
gcloud compute firewall-rules create attendee-https \
  --allow=tcp:443 --target-tags=attendee \
  --source-ranges=0.0.0.0/0 --description="Attendee HTTPS"

# Port 80 only so Caddy can complete the ACME HTTP-01 challenge.
gcloud compute firewall-rules create attendee-http-acme \
  --allow=tcp:80 --target-tags=attendee --source-ranges=0.0.0.0/0
```

Do **not** open 8000. The app is only ever reached through Caddy.

SSH in with `gcloud compute ssh attendee --zone="$ZONE"` — everything from §3 on runs on
the VM.

---

## 2. Storage bucket (recordings)

Attendee writes recordings to S3-compatible storage. GCS does this via HMAC keys.

Run these **locally**, not on the VM:

```bash
export BUCKET="africa-gates-attendee-recordings"   # must be globally unique

gcloud storage buckets create "gs://$BUCKET" \
  --location="$REGION" --uniform-bucket-level-access

# Recordings are personal data. Do not keep them forever — set this to whatever
# your appeal window actually is.
cat > /tmp/lifecycle.json <<'EOF'
{"rule":[{"action":{"type":"Delete"},"condition":{"age":180}}]}
EOF
gcloud storage buckets update "gs://$BUCKET" --lifecycle-file=/tmp/lifecycle.json

# A service account that can only touch this bucket.
gcloud iam service-accounts create attendee-storage \
  --display-name="Attendee recording storage"

export SA="attendee-storage@${PROJECT_ID}.iam.gserviceaccount.com"
gcloud storage buckets add-iam-policy-binding "gs://$BUCKET" \
  --member="serviceAccount:$SA" --role=roles/storage.objectAdmin

# HMAC keys = the S3-compatible credential pair.
gcloud storage hmac create "$SA"
```

That last command prints an **access ID** and a **secret**. The secret is shown once —
copy both now. They become `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`.

---

## 3. Docker

On the VM:

```bash
sudo apt-get update && sudo apt-get install -y ca-certificates curl git
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io \
  docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker $USER && newgrp docker
```

```bash
git clone https://github.com/attendee-labs/attendee.git ~/attendee
cd ~/attendee
docker compose -f dev.docker-compose.yaml build    # ~5–10 min. Only the build.
```

---

## 4. Configuration

Generate the two secrets Attendee requires (`DJANGO_SECRET_KEY` and
`CREDENTIALS_ENCRYPTION_KEY` — the Fernet key that encrypts stored provider credentials):

```bash
cd ~/attendee
docker compose -f dev.docker-compose.yaml run --rm attendee-app-local \
  python init_env.py > .env.generated
grep -E 'DJANGO_SECRET_KEY|CREDENTIALS_ENCRYPTION_KEY' .env.generated
```

Now write the real `.env`. **Back up `CREDENTIALS_ENCRYPTION_KEY` somewhere safe** — lose
it and every stored credential in the instance is unrecoverable.

```bash
cd ~/attendee && nano .env
```

```dotenv
# ── identity ──────────────────────────────────────────────────────────────
DJANGO_SECRET_KEY=<from .env.generated>
CREDENTIALS_ENCRYPTION_KEY=<from .env.generated>
SITE_DOMAIN=meetbot.your-domain.org
TIME_ZONE=Africa/Lagos

# ── database (the container in this compose file) ─────────────────────────
POSTGRES_HOST=postgres
POSTGRES_PORT=5432
POSTGRES_DB=attendee
POSTGRES_USER=attendee
POSTGRES_PASSWORD=<a long random password>
# The container speaks plaintext on a private Docker network. Leaving this
# at its default of true makes the app refuse to connect at all.
POSTGRES_SSL_REQUIRE=false

# ── redis ─────────────────────────────────────────────────────────────────
REDIS_URL=redis://redis:6379/0
DISABLE_REDIS_SSL=true

# ── recordings, in GCS over its S3-compatible API ─────────────────────────
STORAGE_PROTOCOL=s3
AWS_ENDPOINT_URL=https://storage.googleapis.com
AWS_ACCESS_KEY_ID=<HMAC access ID from §2>
AWS_SECRET_ACCESS_KEY=<HMAC secret from §2>
AWS_DEFAULT_REGION=auto
AWS_RECORDING_STORAGE_BUCKET_NAME=africa-gates-attendee-recordings

# ── bots ──────────────────────────────────────────────────────────────────
# DELIBERATELY UNSET: LAUNCH_BOT_METHOD
# Unset = bots run as Celery tasks in the worker on this VM, which is what a
# single-VM deployment wants. Setting it to 'kubernetes' or
# 'docker-compose-multi-host' means bots are dispatched to infrastructure that
# does not exist here, and they silently never launch.

# Chrome cannot use its sandbox inside this container without extra seccomp
# wiring. The VM is single-tenant and reachable only on 443.
ENABLE_CHROME_SANDBOX=false

# Transcripts are the nominee's own words. Keep them out of the log files.
MASK_TRANSCRIPT_IN_LOGS=true

# ── access ────────────────────────────────────────────────────────────────
# Turn this on AFTER you create your account in §6. Until then you cannot sign up.
DISABLE_SIGNUP=false

# No SMTP configured. The sign-up confirmation link goes to the logs instead —
# see §6. Set up real email later if you want error reports.
DISABLE_EMAIL=true

ATTENDEE_LOG_LEVEL=INFO
```

### The production compose file

The shipped `dev.docker-compose.yaml` mounts your source over the image and runs Django's
dev server. Write a production one beside it:

```bash
cd ~/attendee && nano docker-compose.prod.yaml
```

```yaml
# Attendee, production, single VM.
#
# Three app services off one image, plus Postgres and Redis. The `app` and
# `scheduler` services override the entrypoint because it starts PulseAudio,
# which only the worker needs — the worker is where the browser bots run.
x-app: &app
  build: ./
  env_file: .env
  networks: [attendee_network]
  restart: unless-stopped
  depends_on: [postgres, redis]

services:
  app:
    <<: *app
    entrypoint: []
    command: >
      sh -c "python manage.py migrate --noinput &&
             python manage.py collectstatic --noinput &&
             gunicorn attendee.wsgi --bind 0.0.0.0:8000 --workers 3 --timeout 120"
    ports: ["127.0.0.1:8000:8000"]   # localhost only; Caddy fronts it

  worker:
    <<: *app
    # No entrypoint override: this one needs PulseAudio, because this is where
    # the bots actually run.
    #
    # CONCURRENCY IS YOUR BOT LIMIT. Each concurrent bot is a headless Chrome
    # at roughly 2 vCPU / 4 GB. On an e2-standard-4, 2 is the honest ceiling.
    command: celery -A attendee worker -l INFO --concurrency=2

  scheduler:
    <<: *app
    entrypoint: []
    command: python manage.py run_scheduler

  postgres:
    image: postgres:15.3-alpine
    env_file: .env
    environment:
      PGDATA: /data/postgres
    volumes: [postgres:/data/postgres]
    networks: [attendee_network]
    restart: unless-stopped

  redis:
    image: redis:7-alpine
    volumes: [redis:/data/redis]
    networks: [attendee_network]
    restart: unless-stopped

networks:
  attendee_network: {driver: bridge}

volumes:
  postgres:
  redis:
```

> `postgres` reads `POSTGRES_DB` / `POSTGRES_USER` / `POSTGRES_PASSWORD` from the same
> `.env`, so the database it creates and the credentials the app uses cannot drift apart.

---

## 5. TLS

Caddy gets and renews the certificate on its own.

```bash
mkdir -p ~/caddy && cd ~/caddy && nano Caddyfile
```

```
meetbot.your-domain.org {
    reverse_proxy 127.0.0.1:8000
}
```

```bash
docker run -d --name caddy --restart unless-stopped --network host \
  -v ~/caddy/Caddyfile:/etc/caddy/Caddyfile:ro \
  -v caddy_data:/data -v caddy_config:/config \
  caddy:2
```

`--network host` is what lets Caddy reach the app on `127.0.0.1:8000` while binding 80 and
443 itself. Confirm the certificate before going further:

```bash
docker logs caddy | tail -20      # look for "certificate obtained successfully"
```

If it fails, DNS has not propagated or port 80 is closed. Fix that before continuing —
nothing downstream works without a valid certificate.

---

## 6. First run

```bash
cd ~/attendee
docker compose -f docker-compose.prod.yaml up -d
docker compose -f docker-compose.prod.yaml logs -f app
```

The `app` service runs migrations itself on start. Wait for gunicorn to report it is
listening, then open `https://meetbot.your-domain.org` and create your account.

Email is disabled, so the confirmation link is written to the logs:

```bash
docker compose -f docker-compose.prod.yaml logs app | grep confirm-email
```

Open that URL, then **close the door behind you**:

```bash
sed -i 's/^DISABLE_SIGNUP=false/DISABLE_SIGNUP=true/' ~/attendee/.env
docker compose -f docker-compose.prod.yaml up -d --force-recreate app
```

---

## 7. Inside Attendee

In the web UI:

1. **Create a project** — one per consumer. Use two: `Africa GATES` and `Afrovanguard`.
   They get separate API keys and separate transcript stores on the same box.
2. **Copy each project's API key.** Shown once.
3. **Credentials → OpenAI** — paste your OpenAI key. This is what transcribes.
   You do **not** need Google Cloud TTS credentials: the bot's voice is synthesised on the
   Africa GATES side and posted as MP3, so Attendee's Google-only `/speech` endpoint is
   never used.
4. **Webhook** (optional, only speeds up `auto` mode):
   - URL — `https://your-africa-gates-domain/api/v1/interview/bot/webhook`
   - Header — `X-Attendee-Secret: <your ATTENDEE_WEBHOOK_SECRET>`

---

## 8. Point Africa GATES at it

In the Africa GATES `.env` on cPanel:

```dotenv
ATTENDEE_API_KEY=<the Africa GATES project key>
ATTENDEE_BASE_URL=https://meetbot.your-domain.org
ATTENDEE_BOT_NAME=Africa GATES Interview Assistant
ATTENDEE_STT_MODEL=gpt-4o-transcribe
INTERVIEW_TTS_MODEL=gpt-4o-mini-tts
INTERVIEW_TTS_VOICE=alloy
ATTENDEE_WEBHOOK_SECRET=<openssl rand -hex 32 — same value as §7.4>
OPENAI_API_KEY=<already set for other features>
```

Then in the console: **Admin → Interviews → any sitting**. The bot panel should say a bot
is configured and **not** warn about the hosted instance. If it warns, `ATTENDEE_BASE_URL`
did not take.

For Afrovanguard, the same instance with its own project key:

```dotenv
AV_MEET_BOT_PROVIDER=attendee
AV_ATTENDEE_API_KEY=<the Afrovanguard project key>
AV_ATTENDEE_BASE_URL=https://meetbot.your-domain.org
```

### Smoke test — do this before a real interview

1. Start a Google Meet from your own account.
2. Create a test sitting in Africa GATES, set the meet URL, and give consent on the
   nominee link.
3. Press **Send the bot now**.
4. Admit it when it asks. The console should move to *The bot is in the call* within a
   minute or so.
5. Say a few sentences, including a name the recogniser has to work for.
6. End the call. Within a couple of cron ticks the captured text appears in the buffer and
   the recording link appears.

If the bot never joins, `docker compose -f docker-compose.prod.yaml logs worker` on the VM
is the place to look.

---

## 9. Cost control

**Stop the VM between judging rounds.** This is the biggest lever by a distance — a box
that runs three weeks a year should not be billed for twelve months.

```bash
gcloud compute instances stop  attendee --zone="$ZONE"   # billing stops (disk still charged)
gcloud compute instances start attendee --zone="$ZONE"   # static IP is retained
```

Docker services come back on their own (`restart: unless-stopped`). Confirm the smoke test
passes after each restart before the first sitting of the round.

Other levers:

- **Spot VMs** are 60–90% cheaper, and can be reclaimed with 30 seconds' notice. A bot
  evicted mid-interview loses that recording — right for a pilot, wrong for finals week.
- **Committed use discounts** only pay off if the VM runs year-round. If you are stopping
  it between rounds, they do not.
- **Bucket lifecycle** is already set to 180 days in §2. Recordings are the bulk of your
  storage bill and the bulk of your data-protection exposure.

---

## 10. Keeping it healthy

```bash
# Update Attendee
cd ~/attendee && git pull
docker compose -f docker-compose.prod.yaml build
docker compose -f docker-compose.prod.yaml up -d

# Database backup — do this before every update, and weekly during a round.
docker compose -f docker-compose.prod.yaml exec -T postgres \
  pg_dump -U attendee attendee | gzip > ~/attendee-$(date +%F).sql.gz
gcloud storage cp ~/attendee-$(date +%F).sql.gz "gs://$BUCKET/backups/"

# Disk fills with images and stopped containers over time.
docker system prune -af --filter "until=168h"
```

Worth setting up once: a GCP **uptime check** against `https://meetbot.your-domain.org`
with an email alert. A bot host that quietly died between rounds is discovered, otherwise,
at the start of an interview.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| Bots never launch; no error | `LAUNCH_BOT_METHOD` is set. Unset it — see §4. |
| App will not start, Postgres connection error | `POSTGRES_SSL_REQUIRE` left at its `true` default against the plaintext container. |
| Caddy will not get a certificate | DNS not propagated, or port 80 closed. |
| Bot joins but records nothing | No OpenAI credential on the *project* (§7.3). It is per-project, not the `.env` key. |
| Recording link 404s | Bucket name or HMAC key wrong, or the lifecycle rule already deleted it. |
| Bot joins, transcript arrives, never speaks | Expected unless the sitting's voice mode is on and Africa GATES has `OPENAI_API_KEY`. |
| Everything slows down with 3+ interviews | Worker `--concurrency` exceeds what the machine can carry. Raise the machine or lower the concurrency. |
| Credentials unreadable after a rebuild | `CREDENTIALS_ENCRYPTION_KEY` changed. Restore the original; there is no recovery without it. |

---

## 11. Cloud Run — the honest version

"Serverless, scale to zero, no VM to patch" is the right instinct for a workload that runs
three weeks a year. It is worth being precise about which half of it works, because Cloud
Run is two products and they behave completely differently here.

### Cloud Run **services**: fine for the web app, wrong for the bots

A Cloud Run *service* is request-driven, and that breaks the worker in two independent
ways:

**CPU allocation.** Under the default request-based billing, "CPU is only allocated during
request processing". A bot does not run inside a request — it runs inside a Celery task
pulled off Redis. So the instance is throttled the moment the HTTP request that
(hypothetically) started it returns, and the bot freezes mid-sentence in a nominee's
interview.

You can switch to **instance-based billing**, which allocates CPU for the whole container
lifecycle and is explicitly the option Google names for background work. That fixes the
throttling and runs straight into the second problem.

**Instance lifetime.** Google's own wording: *"idle instances, including those kept warm
using minimum instances, can be shut down at any time."* An instance with no in-flight
requests is idle by Cloud Run's definition even while your bot is thirty minutes into
recording a judging interview. There is no contract that keeps it alive, and losing it
loses the recording — the single worst failure this system has.

Two smaller nails: autoscaling is driven by *request* volume, which for a queue worker is
permanently zero, so it will never scale up when three interviews start at once; and a
service instance caps at **8 vCPU / 32 GiB**, which is a hard ceiling of roughly four
concurrent bots per instance and no way to add a fifth.

So the worker cannot go on a Cloud Run service. **The `app` service can** — it is an
ordinary stateless Django app and a genuinely good fit. Whether that is worth doing is §11.3.

### 11.2 Cloud Run **jobs**: the one that actually fits

Cloud Run *jobs* are not request-driven at all. They run to completion, and the task
timeout goes up to **168 hours** — against an interview that lasts forty minutes. One task
per bot, scale to zero between them, pay for the exact seconds a bot is in a call.

For a seasonal workload this is, on paper, the best-fit GCP-native design there is. No
idle VM between judging rounds, no concurrency ceiling to size in advance, no capacity
planning at all.

**The catch is that Attendee cannot do it today.** `LAUNCH_BOT_METHOD` accepts exactly two
values — `kubernetes` (creates pods) and `docker-compose-multi-host` (queues to a
`bot_launcher_vm` worker running ephemeral Docker containers) — plus unset, which runs the
bot inline in the Celery worker. There is no Cloud Run Jobs launcher.

Writing one is a real but bounded piece of work: `bots/launch_bot_utils.py` is where the
branch lives, and the closest model is `run_bot_in_ephemeral_container_task`. A third
branch would call the Cloud Run Admin API to execute a job with the bot id as an env
override. Perhaps 150 lines plus IAM. If interviews become year-round, or you ever need
more than four at once, **this is the upgrade path — not GKE.**

Until then, the VM is what runs today without forking Attendee.

### 11.3 What "optimised" actually means here

Ranked by how much they change the outcome. Note that the top two are not infrastructure
choices at all.

1. **Worker concurrency, matched to the machine.** `--concurrency=2` on an `e2-standard-4`
   is the honest ceiling. Set it to 4 on that machine and all four interviews degrade —
   choppy audio, dropped words, a worse transcript for everyone — rather than the fourth
   one queueing. This is the single biggest quality lever and it costs nothing.
2. **Region.** Put the VM near the panellists, not near you. Bot audio crosses this link
   in real time, so latency here shows up as a worse recording, unlike a web app where it
   shows up as a slower page. `europe-west1`/`europe-west2` for West Africa,
   `africa-south1` for Southern Africa.
3. **Stop the VM between rounds** (§9). For a three-week judging season this is roughly a
   90% saving and it beats every architectural cleverness above.
4. **Persistent disk type.** `pd-balanced` as specified. Bots write recording chunks
   continuously; `pd-standard` is cheaper and its IOPS become the bottleneck under three
   concurrent bots.
5. **Do not move Postgres and Redis to managed services yet.** Cloud SQL plus a Memorystore
   instance is roughly $50/month before any compute, for a database holding transcripts and
   job state on a box you can snapshot. It is the right call when this stops being
   seasonal, and an expensive reflex before then.

**The Cloud Run service for the web app** (§11.1) is worth it only if you have already
moved to Cloud SQL and Memorystore — a Cloud Run service cannot reach Postgres inside your
VM's Docker network. So it is part of the "this is year-round now" package, not a quick
win. In that world the shape is: Cloud Run service for `app`, Cloud Run jobs for bots,
Cloud SQL, Memorystore, and no VM at all. That is the destination. It is not step one.

---

## What this deliberately does not do

- **No Kubernetes.** Correct above ~5 concurrent interviews, and a cluster to run below it.
  If you outgrow this VM, read §11.2 first — Cloud Run jobs is the cheaper destination for
  a seasonal workload, and GKE only wins if you are running bots continuously.
- **No managed Cloud SQL / Memorystore.** Roughly doubles the monthly cost for a workload
  where the database holds transcripts and job state, and the VM's disk is snapshot-able.
  Worth revisiting if this ever stops being seasonal.
- **No Google Cloud TTS.** The voice is OpenAI's, synthesised on the Africa GATES side and
  handed to Attendee as MP3 bytes. Attendee's own `/speech` supports Google TTS and nothing
  else, and using it would mean a second vendor and a service-account JSON on this host.
- **No public port but 443.** Postgres, Redis and the app itself are unreachable from
  outside the VM.
