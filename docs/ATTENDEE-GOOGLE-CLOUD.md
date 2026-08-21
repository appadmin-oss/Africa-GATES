# Attendee on Google Cloud — a deployment guide

[Attendee](https://github.com/attendee-labs/attendee) is the meeting bot both this
platform and its sibling talk to: it joins a Google Meet, Zoom or Teams call as a
participant, records it, transcribes it, and will play audio back into the room.
Neither app can host it — both are PHP-FPM on cPanel with no long-running process —
so Attendee runs on its own host and the apps are HTTP clients.

This document is how you build that host on Google Cloud. It is written against
Attendee **v1.64.1** (verified by reading the repository, not the marketing page).

Both apps can point at **one** instance. Give each its own Attendee *project* and
its own API key — credentials and bots are scoped per project, so a leaked key from
one app cannot read the other's transcripts.

---

## 1. What you are actually deploying

Attendee is a Django app in a single Docker image, plus Postgres, Redis, and an
object-storage bucket. From that one image you run **three** long-lived processes:

| Process | Command | What it does |
|---|---|---|
| **web** | `gunicorn attendee.wsgi` | The dashboard and the REST API your apps call |
| **worker** | `celery -A attendee worker` | Runs the bots. This is where headless Chrome, PulseAudio and ffmpeg live |
| **scheduler** | `python manage.py run_scheduler` | A 60-second loop: promotes `join_at` bots, syncs calendars, refreshes Zoom tokens |

A fourth, the *webpage streamer*, is only needed for the voice-agent feature that
streams an arbitrary web page into a call. Neither app uses it. Skip it.

### Five facts that constrain every choice below

1. **amd64 only.** The Dockerfile pins `FROM --platform=linux/amd64` and
   `requirements.txt` pins `zoom-meeting-sdk==0.0.27`, an x86 wheel. Do **not**
   pick an Arm machine type (T2A, C4A/Axion). E2, N2, N4, C3, C4 are fine.
2. **There is no published image.** The repo's CI builds the image with
   `push: false`, and the `nduncanattendee/attendee` default in the code is the
   maintainer's own Docker Hub namespace, not a supported release channel. **You
   build the image yourself.** Plan for that.
3. **The worker is fat and long-lived.** One bot is one headless Chrome (or the
   native Zoom SDK) holding a media session for the length of the meeting.
   Upstream's own Kubernetes path requests **4 vCPU, 4 GiB RAM and 10 GiB of
   ephemeral disk _per bot_** (`BOT_CPU_REQUEST` defaults to `4`). Treat that as
   the honest upper bound and 2 vCPU / 2 GiB as the practical floor for one
   Google Meet bot with recording.
4. **Object storage is S3-shaped.** Recordings go through
   `storages.backends.s3.S3Storage` and boto3 presigned GETs. There is no GCS
   backend — but `AWS_ENDPOINT_URL` is honoured, and that is the hook that makes
   Google Cloud Storage work (§5).
5. **Transcription is not free by default, and there is no bundled Whisper.**
   Attendee either scrapes the meeting platform's own closed captions (genuinely
   free, lower quality) or sends per-speaker audio to a third-party provider —
   Deepgram, OpenAI, Gladia, AssemblyAI, ElevenLabs, Sarvam or Kyutai — all
   metered by that provider. What self-hosting removes is Attendee's own
   per-meeting-hour bill, not the recogniser's.

---

## 2. Which Google Cloud shape

| Shape | Verdict |
|---|---|
| **One Compute Engine VM running Docker Compose** | **Do this.** One host, one compose file, a handful of concurrent bots, ~$110–150/month all in. |
| **GKE** | The scale path, and what upstream's own `attendee.settings.production-gke` and `LAUNCH_BOT_METHOD=kubernetes` target: one pod per bot, autoscaled. Worth it above roughly ten concurrent bots. See §12. |
| **Cloud Run** | **No.** Nothing about a bot is request-shaped. It is a process that must hold a media session for an hour with 2–4 vCPU pinned, spawn Chrome and PulseAudio, and take work from a Celery queue rather than an HTTP request. You would be fighting the platform's every assumption to get a worse result. |

The rest of this guide builds the VM.

---

## 3. Names, project and network

Set these once; every later command uses them.

```bash
export PROJECT=your-gcp-project
export REGION=europe-west1          # pick the region closest to your meetings
export ZONE=${REGION}-b
export DOMAIN=meetbot.your-domain.org
export BUCKET=${PROJECT}-attendee-recordings

gcloud config set project "$PROJECT"

gcloud services enable \
  compute.googleapis.com \
  sqladmin.googleapis.com \
  storage.googleapis.com
```

A **static external IP** and a DNS record, because Caddy will get a TLS
certificate for `$DOMAIN` and your cPanel apps will call it by name:

```bash
gcloud compute addresses create attendee-ip --region="$REGION"
gcloud compute addresses describe attendee-ip --region="$REGION" --format='value(address)'
```

Point an **A record** for `$DOMAIN` at that address before you start the stack —
certificate issuance depends on it resolving.

**Firewall.** Inbound: 443 for the API and dashboard, and 80 as well, because
Caddy's default certificate flow uses the HTTP-01 challenge on port 80. (If you
must keep 80 closed, configure Caddy for TLS-ALPN or DNS-01 instead.)

```bash
gcloud compute firewall-rules create attendee-web \
  --allow=tcp:80,tcp:443 --target-tags=attendee --source-ranges=0.0.0.0/0
```

**Outbound matters more than it looks.** Google Meet and Zoom media is WebRTC over
UDP, and a bot opens a lot of UDP flows. Google Cloud allows all egress by
default, so a VM with an external IP just works. If you later move the VM behind
Cloud NAT with no external IP, raise the port allocation — the default minimum of
64 ports per VM will starve WebRTC:

```bash
# only if you go private + Cloud NAT
gcloud compute routers nats update <nat> --router=<router> --region="$REGION" \
  --min-ports-per-vm=1024
```

---

## 4. The VM

```bash
gcloud compute instances create attendee \
  --zone="$ZONE" \
  --machine-type=e2-standard-4 \
  --boot-disk-size=100GB --boot-disk-type=pd-balanced \
  --image-family=ubuntu-2204-lts --image-project=ubuntu-os-cloud \
  --address=attendee-ip \
  --tags=attendee \
  --scopes=https://www.googleapis.com/auth/cloud-platform \
  --metadata=startup-script='#!/bin/bash
set -e
apt-get update
apt-get install -y ca-certificates curl git
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
  > /etc/apt/sources.list.d/docker.list
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
'
```

**Sizing.** `e2-standard-4` (4 vCPU / 16 GB) comfortably runs **1–2 concurrent
bots** with recording. Go to `e2-standard-8` for 3–4. The 100 GB disk is not
generous — the image alone is several GB once Chrome, OpenCV, ffmpeg and GStreamer
are in it, and each bot buffers its recording locally before upload.

**Why not a smaller machine:** Chrome rendering a Meet call plus ffmpeg encoding
is genuinely CPU-bound. Under-provision it and the bot joins, records, and
produces stuttering audio the recogniser then mistranscribes — a failure that
looks like a transcription-quality problem and is not.

Grant the VM's service account access to Cloud SQL, so the proxy in §6 can
authenticate without a key file:

```bash
PROJECT_NUM=$(gcloud projects describe "$PROJECT" --format='value(projectNumber)')
gcloud projects add-iam-policy-binding "$PROJECT" \
  --member="serviceAccount:${PROJECT_NUM}-compute@developer.gserviceaccount.com" \
  --role=roles/cloudsql.client
```

---

## 5. Storage — a GCS bucket over the S3 API

Attendee speaks S3. Google Cloud Storage has an S3-compatible XML API and HMAC
credentials, and Attendee exposes `AWS_ENDPOINT_URL`, so the two meet without a
fork or a MinIO sidecar.

```bash
gcloud storage buckets create "gs://${BUCKET}" \
  --location="$REGION" --uniform-bucket-level-access

gcloud iam service-accounts create attendee-storage \
  --display-name="Attendee recordings"

gcloud storage buckets add-iam-policy-binding "gs://${BUCKET}" \
  --member="serviceAccount:attendee-storage@${PROJECT}.iam.gserviceaccount.com" \
  --role=roles/storage.objectAdmin

# The HMAC pair becomes AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY
gcloud storage hmac create "attendee-storage@${PROJECT}.iam.gserviceaccount.com"
```

Save both values from that last command — the secret is shown once.

**A retention rule, not an afterthought.** These objects are recordings of real
people in mentorship sessions and judging interviews. Keeping them forever is a
cost problem and a data-protection problem. Ninety days:

```bash
cat > /tmp/lifecycle.json <<'JSON'
{"rule":[{"action":{"type":"Delete"},"condition":{"age":90}}]}
JSON
gcloud storage buckets update "gs://${BUCKET}" --lifecycle-file=/tmp/lifecycle.json
```

Set the retention window to whatever your consent language actually promises.

---

## 6. Postgres — Cloud SQL

The data here is judging transcripts and mentorship records. Run it somewhere that
takes backups without being asked.

```bash
gcloud sql instances create attendee-db \
  --database-version=POSTGRES_15 \
  --tier=db-custom-1-3840 \
  --region="$REGION" \
  --storage-auto-increase \
  --backup-start-time=02:00 \
  --enable-point-in-time-recovery

gcloud sql databases create attendee --instance=attendee-db
gcloud sql users create attendee --instance=attendee-db --password='<a-strong-password>'

gcloud sql instances describe attendee-db --format='value(connectionName)'
# -> project:region:attendee-db  — you need this for the compose file
```

`db-custom-1-3840` is 1 vCPU / 3.75 GB. `db-g1-small` is cheaper and fine for a
pilot, but it is shared-core and carries no SLA.

*Cheaper alternative:* a `postgres:15-alpine` container in the compose file with a
named volume, plus a nightly `pg_dump` to the bucket. It saves roughly $25/month
and costs you point-in-time recovery. Only take that trade knowingly.

---

## 7. The deployment

SSH in (`gcloud compute ssh attendee --zone="$ZONE"`) and lay it out:

```bash
sudo usermod -aG docker "$USER" && exec newgrp docker
mkdir -p ~/attendee-deploy && cd ~/attendee-deploy
git clone https://github.com/attendee-labs/attendee.git
cd attendee && git checkout v1.64.1 && cd ..    # pin a release; never track main
```

### `~/attendee-deploy/compose.yaml`

```yaml
name: attendee

x-app: &app
  build:
    context: ./attendee
  image: attendee:v1.64.1
  env_file: [./.env]
  restart: unless-stopped
  depends_on: [redis, cloudsql]

services:
  # Cloud SQL Auth Proxy. Uses the VM's service account — no key file on disk.
  cloudsql:
    image: gcr.io/cloud-sql-connectors/cloud-sql-proxy:2.14.1
    command: ["--address=0.0.0.0", "--port=5432", "PROJECT:REGION:attendee-db"]
    restart: unless-stopped

  redis:
    image: redis:7-alpine
    command: ["redis-server", "--appendonly", "yes"]
    volumes: ["redis:/data"]
    restart: unless-stopped

  app:
    <<: *app
    # The image's entrypoint starts PulseAudio, which the web process does not
    # need. Upstream's own dev compose clears it the same way.
    entrypoint: []
    command: >
      bash -lc "python manage.py collectstatic --noinput &&
                exec gunicorn attendee.wsgi --bind 0.0.0.0:8000 --workers 3 --timeout 120"
    expose: ["8000"]

  worker:
    <<: *app
    # Keeps the image entrypoint on purpose: the bots need PulseAudio.
    command: ["celery", "-A", "attendee", "worker", "-l", "INFO", "--concurrency=2"]
    # Docker's default 64 MB /dev/shm crashes Chrome under load.
    shm_size: "2gb"

  scheduler:
    <<: *app
    entrypoint: []
    command: ["python", "manage.py", "run_scheduler"]

  caddy:
    image: caddy:2-alpine
    ports: ["80:80", "443:443"]
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
    restart: unless-stopped
    depends_on: [app]

volumes:
  redis:
  caddy_data:
  caddy_config:
```

Replace `PROJECT:REGION:attendee-db` with the connection name from §6.

`--concurrency=2` is the concurrent-bot ceiling. Attendee sets
`CELERY_WORKER_MAX_TASKS_PER_CHILD = 1` for you when bots run in the worker, so
each child process is recycled after every bot — a deliberate guard against a
segfault in the Zoom SDK taking the worker down with it.

### `~/attendee-deploy/Caddyfile`

```
meetbot.your-domain.org {
    reverse_proxy app:8000
}
```

Caddy gets and renews the certificate itself and sets `X-Forwarded-Proto`, which
is what Django's `SECURE_PROXY_SSL_HEADER` reads in the production settings.

### `~/attendee-deploy/.env`

Generate the two secrets with the tool in the repo:

```bash
docker compose build app
docker compose run --rm --entrypoint "" app python init_env.py | head -2
```

Then write `.env` — `chmod 600 .env`:

```ini
DJANGO_SETTINGS_MODULE=attendee.settings.production

# From init_env.py above. CREDENTIALS_ENCRYPTION_KEY encrypts every API key you
# store in the dashboard: lose it and those credentials are unrecoverable.
DJANGO_SECRET_KEY=<generated>
CREDENTIALS_ENCRYPTION_KEY=<generated>

# Site identity
SITE_DOMAIN=meetbot.your-domain.org
ALLOWED_HOSTS=meetbot.your-domain.org
CSRF_TRUSTED_ORIGINS=https://meetbot.your-domain.org
DJANGO_SSL_REQUIRE=true
TIME_ZONE=Africa/Lagos

# Database, through the proxy. The proxy already encrypts the hop, so the second
# TLS layer is off deliberately.
POSTGRES_HOST=cloudsql
POSTGRES_PORT=5432
POSTGRES_DB=attendee
POSTGRES_USER=attendee
POSTGRES_PASSWORD=<from step 6>
POSTGRES_SSL_REQUIRE=false

REDIS_URL=redis://redis:6379/0

# Google Cloud Storage over its S3-compatible XML API
STORAGE_PROTOCOL=s3
AWS_ENDPOINT_URL=https://storage.googleapis.com
AWS_ACCESS_KEY_ID=<HMAC access id, starts GOOG1E>
AWS_SECRET_ACCESS_KEY=<HMAC secret>
AWS_DEFAULT_REGION=europe-west1
AWS_RECORDING_STORAGE_BUCKET_NAME=<your bucket>

# Chrome's own sandbox needs syscalls Docker's default seccomp profile blocks.
# Upstream's dev compose disables it for the same reason.
ENABLE_CHROME_SANDBOX=false

# No SMTP yet: the signup confirmation link is printed to the web logs instead.
DISABLE_EMAIL=true

ATTENDEE_LOG_LEVEL=INFO
# Interview and mentorship transcripts should not sit in plaintext in the logs.
MASK_TRANSCRIPT_IN_LOGS=true
```

If storage rejects your signature with `SignatureDoesNotMatch`, set
`AWS_DEFAULT_REGION=auto` — GCS accepts both, and which one it wants depends on
how the bucket was created.

---

## 8. Bring it up

```bash
cd ~/attendee-deploy
docker compose build                                             # ~10-20 min: Chrome, OpenCV, GStreamer
docker compose run --rm --entrypoint "" app python manage.py migrate
docker compose up -d
docker compose ps
```

`https://meetbot.your-domain.org/health/` should return 200.

### Your account

Attendee requires a verified email address, and you have no SMTP configured — so
the confirmation link goes to the logs:

1. Open `https://meetbot.your-domain.org/accounts/signup/` and sign up.
2. `docker compose logs app | grep -i 'confirm-email'`
3. Open that URL in your browser.

**Then close the door.** This instance is on the public internet; leaving signup
open means anyone who finds it can create an account:

```bash
echo 'DISABLE_SIGNUP=true' >> .env
docker compose up -d app worker scheduler
```

### Housekeeping crons

Two of these are not optional. Without the first, a bot whose container dies stays
`in_call` forever and the polling loops in both apps wait on a meeting that ended.
Without the second, raw PCM audio chunks accumulate in Postgres until the disk
fills.

```bash
crontab -e
```

```cron
*/5 * * * * cd /home/YOU/attendee-deploy && docker compose run --rm --entrypoint "" app python manage.py clean_up_bots_with_heartbeat_timeout_or_that_never_launched >> /var/log/attendee-cron.log 2>&1
17 3 * * *  cd /home/YOU/attendee-deploy && docker compose run --rm --entrypoint "" app python manage.py clear_old_audio_chunks >> /var/log/attendee-cron.log 2>&1
```

For a data-retention sweep there is `cleanup_old_data --days N --tables ...`. It
deletes rows. Run it with `--dry-run` first, every time.

---

## 9. Credentials inside Attendee

In the dashboard, **Settings → Credentials**. These are encrypted at rest with
`CREDENTIALS_ENCRYPTION_KEY` and are *per project*:

| Credential | Needed for |
|---|---|
| **OpenAI** | `gpt-4o-transcribe` — the recogniser the interview flow asks for, because it can be primed with a nominee's name and Google Meet's cannot |
| **Deepgram** | Cheaper and faster than OpenAI, $200 free credit for new accounts. Worth having as the fallback |
| **Zoom OAuth** | Only if bots must join Zoom. Client ID and secret from a Zoom Marketplace "General App" with the Meeting SDK toggle on |

Then **Settings → API Keys → Create**. That token is what goes into each app's
env. It is shown once.

**Two things that will bite you in production, neither of them Google Cloud's
fault:**

- **Google Meet admission.** By default a bot joins anonymously — the equivalent
  of an incognito window. The host must admit it from the waiting room, and some
  Meet configurations refuse anonymous participants outright. For unattended
  interviews you need either a human to admit the bot or a *signed-in bot*: a paid
  Google Workspace account on a domain you own, with Attendee configured as its
  SAML IdP (`docs/signed_in_bots.md` upstream). Plan this before the first real
  sitting, not during it.
- **Zoom app approval.** An unapproved Zoom app can only join meetings hosted by
  the same Zoom account that owns it. External meetings need Zoom's review.

---

## 10. Wiring the two apps

Both point at the same instance over HTTPS with a bearer token. Neither needs an
inbound callback — every read is pollable by design, because a cPanel host cannot
be relied on to receive a webhook.

**Africa GATES** (`.env`):

| Variable | Value |
|---|---|
| `ATTENDEE_API_KEY` | the key from §9 |
| `ATTENDEE_BASE_URL` | `https://meetbot.your-domain.org` — the origin only; `AttendeeBot::base()` appends `/api/v1` |
| `ATTENDEE_BOT_NAME` | `Africa GATES Interview Assistant` |
| `ATTENDEE_STT_MODEL` | `gpt-4o-transcribe` — requires the OpenAI credential above |
| `ATTENDEE_WEBHOOK_SECRET` | optional; only makes `auto` mode faster. `openssl rand -hex 32`, then add the webhook in the dashboard with header `X-Attendee-Secret` |

**Afrovanguard** (`.env`):

| Variable | Value |
|---|---|
| `AV_ATTENDEE_API_KEY` | the key from §9 (a *different* project's key) |
| `AV_ATTENDEE_BASE_URL` | `https://meetbot.your-domain.org` |
| `AV_ATTENDEE_BOT_NAME` | `Afrovanguard Notetaker` |

Leaving either `*_BASE_URL` blank falls back to `app.attendee.dev`, the vendor's
metered hosted service — which is the one outcome self-hosting exists to avoid.
Both apps print which instance is answering on their admin screens; check it after
you deploy.

*One quirk worth knowing:* `lib/AttendeeBot.php` sends a `webhook_url` field when
creating a bot. Attendee's API has no such field — it takes
`webhooks: [{url, triggers}]` — and DRF drops unknown keys silently, so nothing
breaks and nothing is registered. The cron polling path is what actually delivers
transcripts, exactly as that file's own comments say. Don't wait for a callback
that was never subscribed.

---

## 11. Verify end to end

```bash
KEY=<your api key>
BASE=https://meetbot.your-domain.org

# Start a real Meet call, then send the bot in
curl -sS -X POST "$BASE/api/v1/bots" \
  -H "Authorization: Token $KEY" -H 'Content-Type: application/json' \
  -d '{"meeting_url":"https://meet.google.com/abc-defg-hij","bot_name":"Test Bot"}'
# -> {"id":"bot_...","state":"joining",...}

# Admit it in the meeting, talk for a minute, then leave
curl -sS "$BASE/api/v1/bots/bot_xxx" -H "Authorization: Token $KEY"
# -> state: ended, transcription_state: complete

curl -sS "$BASE/api/v1/bots/bot_xxx/transcript" -H "Authorization: Token $KEY"
```

If the transcript comes back with speakers and text, and the recording plays from
the dashboard, the storage path and the media path are both correct. Watch it work
once before you point a real interview at it.

---

## 12. When one VM is not enough

At roughly ten concurrent bots, move to GKE — it is what upstream runs. The
mechanism is already in the code: set `LAUNCH_BOT_METHOD=kubernetes` and
`DJANGO_SETTINGS_MODULE=attendee.settings.production-gke`, and the app stops
running bots in the worker and starts creating **one pod per bot** through the
Kubernetes API.

What that needs:

- A **Standard** cluster with a dedicated node pool, in preference to Autopilot.
  Bot pods request 4 vCPU / 4 GiB / 10 GiB ephemeral storage each; Autopilot caps
  local ephemeral storage at 10 GiB and restricts the `Unconfined` seccomp profile
  that Chrome's own sandbox needs. Both are workable, neither is friction-free.
- The image in **Artifact Registry**, built by Cloud Build. Raise the build
  timeout — this image does not build in ten minutes:
  `gcloud builds submit --timeout=3600s --machine-type=e2-highcpu-8`.
- `CUBER_RELEASE_VERSION` (required — the pod creator refuses to start without
  it) and `BOT_POD_IMAGE` pointing at the registry path. With Workload Identity
  on the node pool, set `DISABLE_BOT_POD_IMAGE_PULL_SECRET=true` and skip the
  `regcred` secret entirely.
- A ConfigMap named `env` and a Secret named `app-secrets` — the bot pod inherits
  its whole environment from those two, by name
  (`BOT_POD_CONFIG_MAP_NAME` / `BOT_POD_SECRETS_NAME` to override).
- **RBAC**: the app's service account needs `create`, `get`, `delete` on pods and
  `list` on events in the bot namespace (default `attendee`).

Upstream ships no manifests or Helm chart. You write them.

---

## 13. Running it

**Upgrades.** Pin a tag, read the release notes, migrate before restarting:

```bash
cd ~/attendee-deploy/attendee && git fetch --tags && git checkout vX.Y.Z && cd ..
docker compose build
docker compose run --rm --entrypoint "" app python manage.py migrate
docker compose up -d
```

**Logs.** `docker compose logs -f worker` is where a bot's life story is. To get
them into Cloud Logging, install the Ops Agent on the VM.

**Backups.** Cloud SQL handles the database. The bucket holds recordings under a
lifecycle rule. What is *not* backed up anywhere is `~/attendee-deploy/.env` —
and `CREDENTIALS_ENCRYPTION_KEY` is unrecoverable if you lose it, taking every
stored API key with it. Put a copy in Secret Manager:

```bash
gcloud secrets create attendee-env --data-file=/dev/stdin < ~/attendee-deploy/.env
```

**Rough monthly cost** (region-dependent — price it in the calculator before you
commit):

| Item | Approx |
|---|---|
| `e2-standard-4`, always on, sustained-use discount applied | ~$100 |
| Cloud SQL `db-custom-1-3840` + backups | ~$30 |
| 100 GB pd-balanced | ~$10 |
| Static IP, storage, egress | ~$5–15 |
| **Total** | **~$145–155/month** |

Plus whatever your recogniser charges. A committed-use discount takes 20–40% off
the VM if you know you are keeping it.

---

## 14. Troubleshooting

| Symptom | Cause |
|---|---|
| Bot never joins; worker logs show a Chrome crash | `/dev/shm` too small — confirm `shm_size: "2gb"` on the worker |
| `Chrome failed to start: sandbox` | `ENABLE_CHROME_SANDBOX` must be `false` under Docker's default seccomp profile |
| Recording upload fails, `SignatureDoesNotMatch` | GCS HMAC signing region — try `AWS_DEFAULT_REGION=auto` |
| `DisallowedHost` in the web logs | `ALLOWED_HOSTS` doesn't contain `$DOMAIN` |
| CSRF failure on dashboard login | `CSRF_TRUSTED_ORIGINS` needs the full `https://` origin |
| Bot stuck in `joining` on Google Meet | Nobody admitted it. See the signed-in-bot note in §9 |
| Bot joins Zoom then sits in `Joined - Not Recording` | The Zoom host account hasn't granted external participants recording privileges |
| Bot stuck `in_call` after the meeting ended | The heartbeat cleanup cron from §8 isn't running |
| `CUBER_RELEASE_VERSION environment variable is required` | You set `LAUNCH_BOT_METHOD=kubernetes` without it. On a single VM, leave that variable unset |
| Image won't build / crashes on start | Arm machine type. Attendee is amd64 only |

---

## Sources

Everything above was verified against `attendee-labs/attendee` at `v1.64.1`:
`Dockerfile`, `entrypoint.sh`, `dev.docker-compose.yaml`,
`attendee/settings/{base,production,production-gke,db}.py`,
`bots/bot_pod_creator/bot_pod_creator.py`, `bots/launch_bot_utils.py`,
`bots/storage.py`, `bots/management/commands/`, `docs/environment-variables.md`,
`docs/transcription.md`, `docs/signed_in_bots.md`, `docs/openapi.yml`.
