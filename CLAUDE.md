# Claude Code project context

Read and follow `AGENTS.md` before making changes. Then read the closest nested
`AGENTS.md` for the directory you are editing and
`docs/AI_INSTALL_AND_OPERATIONS_GUIDE.md` for architecture and runbooks.

The core invariant is Portal -> reservation -> Workspace Manager -> AI Broker
-> approved model or media adapter. Do not bypass the Broker, expose secrets,
copy AI account credentials, or give a Workspace direct host/model/ComfyUI
access. Preserve existing changes and run the verification commands listed in
`AGENTS.md` before handing work back.
