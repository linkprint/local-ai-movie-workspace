# Movie AI Workspace

**Turn your own AI server into a private film studio your whole team can walk
into from anywhere—write the script, board the scenes, generate the shots, and
manage the footage, end to end on your own machines, with nothing but a
browser.**

[中文](README.zh-CN.md) · [Español](README.es.md) · [日本語](README.ja.md)

![License: MIT](https://img.shields.io/badge/license-MIT-22c55e)
![Self-hosted](https://img.shields.io/badge/self--hosted-AI-7c3aed)
![Workspace](https://img.shields.io/badge/workspace-Codex%20%2B%20tmux-2563eb)
![Media](https://img.shields.io/badge/video-MiniMax%20H3-f97316)

> **Start here: give this repository to Codex.** After Codex reads this README,
> `AGENTS.md`, and the linked installation guide, it can inventory your actual
> AI servers and help you build the complete system described here—including
> the reservation website and admin console, isolated browser Workspaces,
> PostgreSQL/Redis control plane, per-user or company AI-plan sessions, local
> and external model routing, GPU Workers, MiniMax H3 workflows, security
> boundaries, and end-to-end acceptance tests. You provide the machines,
> network facts, accounts, and administrator access; the documentation gives
> Codex the architecture and executable contracts needed to adapt, install,
> verify, and later take over your deployment.

> **Use the AI plans your team already pays for before paying the image API
> again.** With five existing ChatGPT Pro 20x users and the documented workload
> of 10,000 background-art and storyboard generation/editing outputs per month,
> this architecture can avoid roughly **$410–$1,650/month**—or
> **$4,920–$19,800/year**—in GPT-Image-2 output API charges, plus the input-token
> charges that image edits would otherwise add. That estimate still excludes
> the text-model, agent-reasoning, tool-use, and long-context tokens that a
> fully API-driven CLI workflow would consume. Most importantly, every creator
> signs the CLI into their own ChatGPT account: OpenAI-side Codex activity is
> governed by that account and its data controls, while local history, project
> files, and credentials remain in the creator's isolated persistent Workspace.
> The assumptions, current official prices, plan limits, and net-cost caveat
> are shown below.

The server stays in your studio, your machine room, or a corner at home.
You—on set, at home, on a train—open a browser, log in, and your projects,
your AI, and your half-finished shots are exactly where you left them.

## For filmmakers: this is not another AI website

(Not technical? This section is all you need.)

If you make films with AI, you know the routine: the script lives in one chat
window, concept art comes from another site, video renders on a third
platform, and the assets end up scattered across cloud drives and chat
threads. Every platform bills separately, queues separately, and moderates
separately—and halfway through a render you get "content policy violation,"
when all you wrote was a villain's monologue.

Movie AI Workspace takes a different path: **instead of renting a desk on
someone else's platform, turn your own machine into the studio.** It is an
open-source project that turns one GPU server into a complete filmmaking
platform for you and your team—AI writing, image boards, video generation,
and asset management, all in one place, inside one project.

### One server becomes a whole studio

- **A writers' room that never closes.** The AI helps you break down scripts,
  punch up dialogue, keep continuity, and translate—on models you deploy
  yourself. Horror, crime, war, intimacy: the normal subjects of fiction do
  not get refused mid-scene.
- **A concept table where trial and error is free.** Concept art,
  storyboards, style exploration—iterate on local image models for as many
  rounds as you like without counting credits.
- **A stage that delivers shots.** Through fixed MiniMax H3 workflows:
  text-to-video, image-to-video, first/last frame, and reference-driven
  generation, with finished shots landing straight in the project's media
  library.
- **A media library organized by project.** One project per film: script,
  references, generations, and final footage together instead of scattered
  everywhere.

### A few machines, scheduled across the whole team

AI servers are expensive; you almost certainly do not have one per person.
The real problem was never "how many do we buy" but **how a handful of
machines keeps everyone working**.

Most teams handle it by shouting in a group chat—"anyone on box 2?"—followed
by the familiar story: two people start at once and blow out VRAM, someone's
job gets killed halfway, someone holds a machine while they go to lunch, and
most often, nobody notices a server sat idle all afternoon.

This system turns GPUs into a resource you book like a meeting room:

- **See what's free at a glance.** Browse each server's schedule by date: who
  has it, until when, which windows are open. If a machine is free right now,
  start immediately. Times render in each person's own timezone, so a
  distributed crew never does timezone math.
- **What you book is yours—nobody can bump you.** Two people cannot hold the
  same machine for the same window: a PostgreSQL exclusion constraint enforces
  it in the database, not as an application-layer gentlemen's agreement.
  Getting killed mid-job is structurally impossible.
- **Extend when you run long.** If the following window is still open, extend
  in place instead of dropping out and rebooking.
- **Idle capacity comes back automatically.** Idle workspaces stop on their
  own, and private-model grants are reclaimed when a reservation expires or
  its holder never shows. A machine does not burn a whole night because
  somebody forgot to log off.
- **Fence off maintenance in advance.** Driver upgrades, model swaps, service
  restarts: declare a maintenance window and that period stops accepting
  reservations. A node can also be set to *draining*—no new bookings, while
  running work finishes safely.
- **Schedule several machines as one pool.** Reservations are scoped to a
  specific compute node, so image work on one box and video work on another
  never collide.
- **Every action is on the record.** Key operations land in an audit trail, so
  scheduling and retrospectives do not depend on memory or chat history.

### The server stays in the rack; the studio travels with you

The system is designed for **remote use** from day one. The machine stays
put, and you can work from anywhere:

- **A browser is all you need.** Log in to the portal, book your slot, open
  your project, and your familiar workbench appears—laptop or tablet.
- **Disconnecting is not stopping.** Close the laptop and the server keeps
  working; log back in and you return to the same scene—even the
  conversation you were having with the AI is still there.
- **The work follows you instead of chaining you to a machine.** Queue up
  shots at the studio in the afternoon and review them from your couch at
  night; when an idea strikes on the road, connect and fix two lines of
  dialogue.

> Five p.m., at the studio: box 3 is free tonight, so you book eight to
> eleven, have the AI turn scene three's storyboard into a shot list, submit
> two video jobs, and close the laptop.
> Ten p.m., at home: open a browser—both shots are waiting in the media
> library. Pick one, have the AI check it against the delivery spec, and book
> tomorrow's window while you're there.
> Your laptop stayed shut. The server never stopped. The film kept moving.

### A few familiar headaches simply go away

- **No more pay-per-generation anxiety.** Dialogue passes, continuity
  checks, translations, concept frames—the operations you run hundreds of
  times a day execute on your own GPUs, so iteration stops burning credits.
- **No more ComfyUI setup hell.** A serious image pipeline—with LoRA and
  acceleration—used to mean nights on HuggingFace and Civitai hunting
  models, wiring nodes, matching versions, and debugging errors for hours.
  Here, Codex builds that complexity for you by following the guide, and it
  is sealed into administrator-pinned workflows: creators just call them and
  never touch a node graph.
- **No more wondering where your IP travels.** Unreleased scripts, story
  bibles, casting references, and rough cuts can stay on your own server end
  to end, touching no third-party platform.
- **No more one-size-fits-all moderation.** The rules still exist—but the
  production writes them: who can use what, when, and to generate what, all
  inside your own permissions and approvals. A generic platform filter is
  replaced by rules made for filmmaking.

### Honestly, though

This project was built by its author over five days of spare time, and it
shows: edges are unpolished, the documentation is still growing, and moving
it to different hardware means real porting work. It is not a finished
product, and it will not install itself.

What it did do is solve a real problem for our own small team—how a handful
of AI servers can serve everyone in an orderly way: no collisions, no idle
waste, and no need to be in the same room as the hardware. If your situation
looks like ours, this is at least a working starting point you can adapt.

### I'm not technical. How do I start?

Setting the system up takes a technical partner—or, as this project itself
recommends, an AI assistant such as Codex or Claude following the complete
guide in this repository. Once installed, creators only ever touch the
portal and the workbench in their browser. Hand the sections below to
whoever runs your deployment, and start shooting.

---

**The rest of this document is for engineers and operators. Book your own AI
servers, open a persistent Codex workspace, and turn private models into a
safe creative studio for the whole team.**

Movie AI Workspace is an open-source control plane for teams that own one or
more AI machines. It combines a reservation portal, isolated project
workspaces, persistent AI-plan identities, private language-model routing, and
fixed image/video workflows—without handing users a GPU host shell or your
provider keys.

Reserve a server, enter your project, reconnect to the same tmux session, ask
Codex to plan the work, and render MiniMax H3 video through the bounded
`movie-ai` CLI.

## Why people build this

- **Stop sharing GPU servers in chat.** A PostgreSQL exclusion constraint
  (`EXCLUDE USING gist` over `tstzrange`) makes per-node reservation windows
  non-overlapping in the database; maintenance windows, node draining, and
  reservation status decide who owns the execution window. Idle workspaces and
  expired or no-show model grants are reclaimed automatically.
- **Bring your own AI plan.** Keep personal Codex identities isolated, or offer
  an administrator-managed company identity to authorized operators.
- **Use private models from `/model`.** Route Qwen 3.8 27B and DeepSeek V4
  Flash deployment aliases through a reservation-bound Broker.
- **Make AI creative work reproducible.** Ship MiniMax H3 prompt/generation
  skills, fixed workflows, project media libraries, and artifact checks with
  the Workspace image.
- **Resume instead of restarting.** Browser terminal + tmux + persistent
  project/identity volumes makes handoff and reconnect predictable.
- **Scale deliberately.** Run the central Portal on one node today; §8 of the
  installation guide covers adding a compute Worker, and the multi-node data
  model (per-node exclusion constraint, node registration, health checks)
  ships as code.

## What is inside

| Layer | What it does |
| --- | --- |
| Portal | Laravel/Filament login, TOTP, reservations, projects, admin, media |
| Workspace | Hardened Codex terminal, tmux, persistent project and identity |
| Model router | Keeps hosted Codex traffic intact; routes approved private models |
| AI Broker | Enforces reservation grants and bounded language/media contracts |
| Media adapter | Connects approved MiniMax H3, image, and style workflows |
| Host control | Fixed GPU preflight and service actions over a Unix socket |
| Security | AppArmor, seccomp, internal networks, egress policy, no host shell |
| AI handoff | Root/runtime `AGENTS.md`, `CLAUDE.md`, admin skills, runbooks, tests |

## The workflow

```text
Reserve -> choose project -> choose personal/company AI identity
        -> enter browser tmux -> Codex/Claude plans
        -> private text model or movie-ai CLI
        -> fixed Broker workflow -> verified media in the project library
```

Text-model route:

```text
Codex -> loopback router -> reservation Broker -> Qwen/DeepSeek Unix socket
```

Video route:

```text
Codex -> $h3-video-generation -> movie-ai -> Broker -> adapter -> MiniMax H3
```

## Model options

- Hosted Codex models continue through the user's selected Codex identity.
- `qwen3.8-27b-uncensored` is a configurable private Qwen deployment alias.
- `deepseek-v4-flash-0731` is a configurable private/external DeepSeek alias.
- Local Z-Image-Turbo and Hunyuan image contracts are included.
- MiniMax H3 supports bounded T2VA, I2VA, FL2VA, L2VA, and native Ref2VA
  flows through administrator-owned workflows.

The repository does **not** distribute model weights. Names containing
“uncensored” describe operator-supplied endpoints; you are responsible for the
weights, licenses, safety policy, and lawful use.

## Why uncensored, self-deployed models matter for filmmaking

Codex becomes far more powerful when it is the **production brain** in front of
models and GPUs you control. Codex can break down a film, maintain the plan,
load the right skill, prepare shots, call image and MiniMax H3 workflows, and
check the resulting artifacts. From the same terminal, `/model` can move the
creative conversation to an operator-supplied `deepseek-v4-flash-0731`
uncensored endpoint or `qwen3.8-27b-uncensored`, without abandoning the project
or exposing a model port to the user.

That combination is unusually valuable for real film work:

- **Keep the writers' room moving.** Legitimate fiction routinely includes
  horror, crime, war, political satire, intimacy, villain dialogue, body
  transformation, and other mature material. A studio-controlled uncensored
  model is much less likely to interrupt a scene, sanitize the tone, or force
  the crew into endless prompt euphemisms halfway through production.
- **Keep unreleased IP inside the studio.** Scripts, story bibles, casting
  references, storyboards, rough cuts, and client concepts can stay on your
  own network. Team members reach the model through the reservation-bound
  Broker instead of receiving provider keys or raw LAN endpoints.
- **Direct the model like part of the crew.** The operator chooses weights,
  quantization, context length, system prompt, LoRA, sampling, and upgrade
  timing. A production can optimize one model for screenplay and continuity
  work, another for shot reasoning, and still use hosted Codex for planning,
  coding, tool use, and handoff.
- **Make iteration cheap and repeatable.** Owned GPUs turn another rewrite,
  prompt pass, translation, shot variation, or continuity check into capacity
  planning rather than another per-token approval. Persistent projects, tmux,
  skills, fixed workflows, seeds, and artifact checks preserve how a result
  was made.
- **Go from conversation to finished media.** This is not a chat UI wrapped
  around a model. The same governed session can inspect project media, invoke
  a film-specific skill, submit a bounded H3 job, and return the verified video
  to the project library.

For a filmmaker, the breakthrough is not merely that an uncensored model will
answer. It is that the same private creative brain can remain attached to the
same project, tools, references, skills, and human approvals from treatment to
final shot. **Uncensored does not mean ungoverned:** the studio replaces a
third-party platform's generic filters with its own lawful production policy,
access control, reservation grants, and Broker-enforced execution boundaries.

## Use each creator's ChatGPT plan for image generation and editing

Every signed-in creator can keep a separate ChatGPT/Codex identity inside an
isolated Workspace. When that user's plan and Codex surface provide the image
tool, Codex can use `gpt-image-2` to **generate and edit** background concept
art, environment studies, character sheets, and storyboard frames through the
user's existing plan allowance. The studio does not need to place one shared
OpenAI API key in every Workspace or send every visual iteration to a separately
metered API account.

The more important ownership benefit is that each CLI runs under its creator's
own ChatGPT sign-in instead of a pooled studio API identity. Content processed
by OpenAI is associated with that user's Codex access and governed by that
account's terms and data controls; local Codex history, project files, and
credentials persist in that user's isolated Workspace storage. A company-plan
identity remains a separate, explicitly assigned option. OpenAI also notes that
[local workflows run on the user's device and ChatGPT data controls apply to
content processed through Codex](https://help.openai.com/en/articles/11369540-using-codex-with-chatgpt).

This distinction is deliberate, not accounting sleight of hand. OpenAI states
that [ChatGPT subscriptions and API billing are separate](https://help.openai.com/en/articles/9039756),
so this project does not turn a subscription into API credits. It preserves the
user's own signed-in plan path and uses that path when the required image tool is
available; an API fallback is still billed as API usage, and every account
remains subject to its current plan limits, terms, and abuse guardrails.

**A transparent five-person studio example (prices checked 2026-08-28):** assume
all five creators already pay for the [$200/month ChatGPT Pro 20x tier](https://help.openai.com/en/articles/9793128)
and each
performs 100 one-output image generations or edits on 20 working days. That is
`5 × 100 × 20 = 10,000` image outputs per month. The
[official GPT-Image-2 cost table](https://developers.openai.com/api/docs/guides/image-generation#token-usage-and-costs)
lists a 1536×1024 output at $0.041 in Medium quality and $0.165 in High quality:

| Same monthly workload through the API | Output-only API cost avoided |
| --- | ---: |
| 10,000 Medium landscape generations/edits | **about $410/month** or $4,920/year |
| 10,000 High landscape generations/edits | **about $1,650/month** or $19,800/year |

Image edits also incur image-input and text-input tokens, so the API alternative
can cost more than these output-only figures. The estimate deliberately omits
the API tokens for text conversations, agent reasoning, tool calls, retrieval,
and long project context that the same production workflow would otherwise
consume, so the complete avoided API bill may be higher. The five existing
subscriptions cost $1,000/month in total; that amount is not subtracted here
because the scenario says the team already owns them. If the plans are
purchased solely for this deployment, subtract their cost when calculating net
savings. “20x” is a plan tier—not a guarantee of 20 times a fixed number of
images. Actual savings equal the work that fits within the team's existing plan
allowances multiplied by the then-current API price.

## An opinionated reference implementation, not a universal appliance

This project was built over five days of spare time, out of personal interest
and around a specific set of real AI servers the author owns—and it did solve
that small team's server-sharing problem first. It is an engineering
reference with working contracts—not a commercial installer that has been
abstracted around every possible rack, GPU, hypervisor, storage backend,
firewall, model server, or company identity system.

The documentation therefore favors an explicit, auditable reference topology
over imaginary portability. Example service units, socket paths, Compose
networks, node roles, GPU transitions, and operational assumptions describe
the system this project was developed against. They have **not** been optimized
for every server layout, and copying them unchanged onto different hardware is
not a deployment strategy.

If you want to use the project, bring Codex to the porting work. Ask it to read
`AGENTS.md` and the installation guide, inventory your actual machines, and
produce a deliberate mapping for:

- Portal, Workspace, Broker, adapter, Worker, and model-server placement;
- GPU types, model runtimes, VRAM transitions, storage, and persistent volumes;
- LAN CIDRs, DNS, TLS, firewall rules, Unix sockets, SSH tunnels, and egress;
- systemd/Compose ownership, user identities, secrets, backups, and recovery;
- local model aliases, API compatibility, context limits, and acceptance tests.

A useful first instruction is:

```text
Read AGENTS.md and docs/AI_INSTALL_AND_OPERATIONS_GUIDE.md. Inventory my real
servers before editing anything. Map this reference architecture onto my GPU,
network, storage, model endpoints, and identity boundaries. Do not copy example
addresses or service assumptions blindly. Preserve the security contracts, then
propose the smallest configuration changes and a staged verification plan.
```

The reusable product here is the architecture, its security boundaries, and
the AI handoff method. The exact deployment values are examples. Rough edges
and environment-specific work remain, and each operator is responsible for
validating the resulting installation on their own infrastructure.

## Turn this reference into your own internal AI platform

Yes—this repository can be adapted into a customer-specific system with the
same class of capabilities: a reservation website and admin console, isolated
per-user workspaces, project media libraries, shared company-plan or personal
Codex/Claude sessions, local and external model routing, and MiniMax H3
production workflows. Codex or another engineering agent can use the repository
as an executable specification instead of rebuilding those contracts from
scratch.

A customer deployment can cover the complete path:

- inventory the real servers, GPUs, VRAM, networks, storage, model runtimes,
  identity boundaries, and operational constraints;
- place and configure the Portal, Gateway, PostgreSQL, Redis, Manager, Router,
  Broker, adapters, model services, and one or more compute Workers;
- connect local models, OpenAI-compatible external endpoints, ComfyUI custom
  nodes, MiniMax H3, and additional administrator-approved media workflows;
- configure GPU reservations, node-specific secrets, model manifests, personal
  accounts, company AI plans, tmux sessions, Skills, and AI handoff files;
- establish DNS, TLS, SMTP, firewall and egress rules, backups, recovery, and
  least-privilege service ownership; and
- deliver customer-specific `AGENTS.md`, `CLAUDE.md`, server context, runbooks,
  and acceptance tests so another Codex or Claude session can take over safely.

The customer or operator must provide an accurate hardware and network
inventory, the intended model/runtime list, domain and identity requirements,
team and reservation policy, storage expectations, and an approved way to
perform administrative installation. API keys and passwords do **not** need to
be pasted into an AI conversation: generate or install them directly on the
target machines through ignored secret files or the customer's secret manager.

The current implementation is optimized around x86_64 Linux, Docker Compose,
systemd, PostgreSQL, Redis, and LAN-connected AI servers. ARM, Kubernetes,
cloud GPU fleets, multiple VLANs, zero-trust overlays, SSO, or different model
servers are valid targets, but require an explicit port rather than mechanical
replacement of the sample topology. A deployment is complete only after a real
user can book an eligible GPU node, enter a correctly isolated Workspace,
invoke the selected local or external model, run an approved media job, and
retrieve the verified artifact—not merely after containers report healthy.

## Quick start

The secure installation has host-level prerequisites; it is intentionally not
a one-line privileged container.

```bash
git clone https://github.com/linkprint/local-ai-movie-workspace.git movie-ai
cd movie-ai
sh ops/bootstrap.sh
```

Then configure the ignored `.env` and `env/laravel.env`, install the narrow
AppArmor/host-control/model-socket pieces, and start the locked Compose stack:

```bash
docker compose build
docker compose --profile workspace-build build movie-workspace-image
docker compose up -d movie-postgres movie-redis
docker compose run --rm --no-deps movie-web php artisan migrate --force
docker compose run --rm --no-deps movie-web php artisan db:seed --force
docker compose up -d
docker compose exec movie-web php artisan movie:create-admin \
  --name="Initial Administrator" --email="admin@example.com" --timezone="UTC"
```

Configure a real SMTP transport before the final command. It sends a one-time
password setup link; no default or plaintext password is created or emailed.

The migrations carry the complete PostgreSQL schema, including constraints,
indexes, triggers, and extensions. The public seeder restores only sanitized
compute-node templates and the company-Codex lease singleton; it never creates
users, reservations, projects, sessions, jobs, audit events, or media records.

Follow the complete
[AI installation, operations, and handoff guide](docs/AI_INSTALL_AND_OPERATIONS_GUIDE.md).
It covers local models, remote AI servers, hosted-provider bridges, MiniMax H3,
Codex/company identities, tmux, skill discovery, backups, testing, and public
release preparation.

## Workspace skills are not an afterthought

Administrator skills are baked into `/etc/codex/skills`, the official Codex
admin skill scope. Startup runs:

```bash
movie-ai skills verify
```

It fails closed when required `SKILL.md` metadata or read-only permissions are
wrong. After entering a new Workspace, `/skills` is the acceptance check and
critical media workflows use explicit `$skill-name` invocation.

## Security model

The security goal is practical blast-radius control: let creators finish real
work without turning every browser session into an administrator foothold.

- **Least-privilege Workspaces.** Containers run as an unprivileged user with a
  read-only root filesystem, dropped Linux capabilities, `no-new-privileges`,
  tmpfs scratch space, seccomp, AppArmor, internal networks, and controlled
  egress. Broad `privileged`, `SYS_ADMIN`, and `seccomp=unconfined` shortcuts are
  outside the supported design.
- **Capabilities instead of a host shell.** Workspaces do not receive Docker,
  SSH, systemd, the host-control socket, direct LAN model ports, or arbitrary
  ComfyUI workflow access. Reviewed host actions use narrow Unix-socket
  contracts; media jobs use the bounded `movie-ai` CLI and Broker schemas.
- **A reservation is also an authorization boundary.** Signed, short-lived
  grants bind the user, project, compute node, reservation, and expiry. The
  Broker owns job state and revocation, while PostgreSQL exclusion constraints
  prevent overlapping ownership. Expiry, no-show, cancellation, or abandonment
  removes the corresponding model grant.
- **Keyless creative sessions.** Provider credentials remain in root-readable
  secret files, a secret manager, or an administrator-owned adapter. Model
  endpoints reach the Broker through fixed sockets; a Workspace receives only
  the reservation-scoped API and never needs a shared provider key.
- **Identity and project isolation.** Personal and company AI identities use
  separate volumes, and each user/project keeps separate persistent state and
  media. Collaboration does not require copying credentials or another
  creator's CLI history.
- **Topology stays behind the control plane.** User-facing APIs do not return
  node IPs, internal Broker URLs, health internals, or HMAC secrets. Controlled
  egress reduces the value of a compromised Workspace as a network pivot.
- **Authentication and evidence.** The Portal supports roles and TOTP; critical
  reservation, administration, and runtime actions are recorded for review.
- **Safe publication.** No provider key, private key, password, `auth.json`,
  user media, production database record, or database export belongs in Git.
  The public release uses sanitized bootstrap data and an automated tree/history
  scanner before publication.

This is a security-oriented reference architecture with automated gates, not a
claim of formal certification or an independent penetration test. The Docker
host and administrators remain trust anchors; each deployment must validate
TLS, firewall and egress policy, secret rotation, patching, backups, recovery,
and the behavior of every external model provider on its real infrastructure.

## Current release boundary

The central Portal and single execution-node path are implemented. The
multi-node data model, node registration, and health checks ship as code, and
§8 of the installation guide covers adding a Worker—but the Worker routing and
failure gates have not been through real multi-machine acceptance, so we do not
advertise production multi-node scheduling. We prefer an honest boundary over a
magical architecture diagram.

## Develop with an AI agent

Codex reads [AGENTS.md](AGENTS.md); Claude Code is directed by
[CLAUDE.md](CLAUDE.md). Both point to the canonical runbook and enforce the
Portal -> Manager -> Broker -> adapter boundary.

Start a new agent with:

```text
Read AGENTS.md and docs/AI_INSTALL_AND_OPERATIONS_GUIDE.md completely. Run git
status --short, preserve existing changes, trace the real request path, and run
focused tests plus the public release scan. Never inspect or print secrets.
```

## Validate

```bash
python3 -m unittest discover -s ops/tests -p 'test_*.py'
sh ops/tests/gate4-static.sh
python3 ops/tests/public_release_scan.py --tree
```

Portal tests run from `app/` with the locked Composer and npm dependencies.

## Repository map

- `app/` — Portal, reservations, projects, media libraries, admin, tests
- `images/workspace/` — Codex, tmux, router, `movie-ai`, policies, skills
- `images/control/` — Manager, Broker, terminal router, media adapter
- `host-control/` — fixed GPU/service control plane
- `gateway/`, `egress/`, `security/` — ingress and isolation
- `ops/` — bootstrap, systemd, tunnels, firewall, tests, release tooling
- `reference-workflows/` — administrator-owned workflow references
- `docs/` — installation, architecture, operations, and AI handoff guide

## Contributing

Issues and focused pull requests are welcome. Please preserve the security
boundaries, add tests for every contract change, keep production evidence out
of Git, and do not submit model weights or credentials.

## License

MIT. See [LICENSE](LICENSE).
