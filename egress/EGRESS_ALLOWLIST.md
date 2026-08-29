# Codex egress allowlist evidence

This list is enforced by `images/egress/openai-bootstrap-domains.txt`. It never
uses `*` or `.openai.com`/`.chatgpt.com` suffix wildcards.

| Domain | Purpose | Evidence | Status |
| --- | --- | --- | --- |
| `auth.openai.com` | ChatGPT OAuth/device authorization | OpenAI authentication documentation identifies ChatGPT browser/device login; validate in Spike B deny logs | bootstrap |
| `chatgpt.com` | ChatGPT account/device confirmation | OpenAI device-auth flow; validate in Spike B deny logs | bootstrap |
| `api.openai.com` | Codex model API | Codex CLI service endpoint; validate with one Spike B conversation | bootstrap |
| `ab.chatgpt.com` | Codex feature/config bootstrap | Conservative exact bootstrap host; retain only if observed | provisional |
| `ios.chat.openai.com` | Codex ChatGPT backend | Conservative exact bootstrap host; retain only if observed | provisional |

Every added domain must include a Squid denial timestamp and a successful
retest. Remove provisional domains that are not observed during Spike B.
