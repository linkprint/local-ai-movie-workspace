# Movie AI Workspace contributor guide

## Mission

This repository turns one or more privately operated AI servers into a
reservation-based creative workspace for a team. The Portal owns identity,
reservations, projects, workspaces, and media. Codex or Claude Code runs inside
the selected project through a persistent tmux session. Language and media
models remain behind the reservation-bound Broker.

## Read before changing code

1. Read `README.md` and `docs/AI_INSTALL_AND_OPERATIONS_GUIDE.md`.
2. Run `git status --short` and preserve every existing user change.
3. Read the closest nested `AGENTS.md`; `images/workspace/AGENTS.md` is the
   runtime policy embedded into each Workspace image.
4. Trace Portal -> Manager -> Broker -> adapter/Unix socket before changing an
   authorization, reservation, model, or media path.

## Security boundaries

- Never read, print, copy, commit, or share `.env`, Docker secret files,
  private keys, model-provider tokens, cookies, device codes, `auth.json`, user
  media, production database records, or runtime volumes. Database migrations
  and the deterministic sanitized system-configuration seeder are the only
  database material permitted in Git.
- Keep examples on `movie.example.com` and the sample physical LAN
  `192.168.88.x`. The `172.30.x` ranges are isolated Docker bridge networks,
  not physical server addresses.
- Workspaces never receive Docker, SSH, systemd, host shell, arbitrary
  ComfyUI workflow, or direct model-server access.
- Personal and company Codex identities use separate persistent volumes.
  Never implement collaboration by copying an identity file between users.
- Treat model names ending in `uncensored` as operator-supplied deployment
  aliases. The repository ships routing and policy, not model weights.

## Skills and agent handoff

- Administrator skills are built from `images/workspace/admin-skills/` into
  `/etc/codex/skills`, Codex's admin skill scope.
- Workspace startup must fail closed if `movie-ai skills verify` cannot validate
  required `SKILL.md` metadata and read-only permissions.
- Use explicit `$skill-name` invocation for safety-critical media workflows.
  `/skills` is the human acceptance check after entering a Workspace.
- Durable repository knowledge belongs here or in the runtime `AGENTS.md`;
  detailed runbooks belong in `docs/AI_INSTALL_AND_OPERATIONS_GUIDE.md`.

## Development and verification

- Portal: run PHP tests from `app/` in the documented container/runtime.
- Control plane and Workspace: run `sh ops/tests/gate4-static.sh` plus focused
  Python tests under `ops/tests/`.
- Public release: run `python3 ops/tests/public_release_scan.py --tree`. Run
  `--history` only in the new public snapshot repository; the private source
  repository intentionally retains its old private history.
- Do not claim a model or media workflow works from configuration alone. A real
  acceptance requires an approved bounded request and artifact verification.

## Commit discipline

Keep secrets and deployment evidence out of Git. Preserve unrelated work,
update tests with behavior changes, and report source validation separately
from deployment or live GPU evidence.
