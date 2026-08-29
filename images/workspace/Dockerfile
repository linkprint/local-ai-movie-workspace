FROM debian@sha256:abd67ffcfa541b485a3dff59865ab629aa048a6c613e639d36e7456b0b229241

ARG TTYD_VERSION=1.7.7
ARG TTYD_SHA256=8a217c968aba172e0dbf3f34447218dc015bc4d5e59bf51db2f2cd12b7be4f55

ENV DEBIAN_FRONTEND=noninteractive \
    CODEX_HOME=/home/codex/.codex \
    HOME=/home/codex \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bubblewrap \
        ca-certificates \
        curl \
        ffmpeg \
        git \
        jq \
        less \
        nodejs \
        npm \
        procps \
        python3 \
        tmux \
    && rm -rf /var/lib/apt/lists/*

COPY images/workspace/package.json images/workspace/package-lock.json /opt/movie-workspace/
RUN cd /opt/movie-workspace \
    && npm ci --omit=dev \
    && npm cache clean --force \
    && ln -s /opt/movie-workspace/node_modules/.bin/codex /usr/local/bin/codex

RUN curl --fail --show-error --silent --location \
        "https://github.com/tsl0922/ttyd/releases/download/${TTYD_VERSION}/ttyd.x86_64" \
        --output /usr/local/bin/ttyd \
    && printf '%s  %s\n' "${TTYD_SHA256}" /usr/local/bin/ttyd | sha256sum --check --strict \
    && chmod 0755 /usr/local/bin/ttyd

RUN groupadd --gid 10001 codex \
    && useradd --uid 10001 --gid 10001 --create-home --shell /bin/bash codex \
    && install -d -o root -g root -m 0755 /etc/codex /etc/codex/hooks /etc/codex/skills /usr/local/share/movie-workspace \
    && install -d -o codex -g codex -m 0700 /home/codex/.codex \
    && install -d -o codex -g codex -m 0750 /workspace /outputs /run/movie/deadline /run/movie/ai-grant \
    && install -d -o codex -g codex -m 0700 /home/codex/.local/state/movie-workspace

COPY --chmod=0755 images/workspace/supervisor.py /usr/local/bin/movie-workspace-supervisor
COPY --chmod=0755 images/workspace/company_codex_session.py /usr/local/bin/movie-company-codex-session
COPY --chmod=0755 images/workspace/personal_codex_session.py /usr/local/bin/movie-personal-codex-session
COPY --chmod=0755 images/workspace/codex_session_index.py /usr/local/bin/movie-codex-session-index
COPY --chmod=0755 images/workspace/codex_model_router.py /usr/local/bin/movie-codex-model-router
COPY --chmod=0755 images/workspace/movie-ai.py /usr/local/bin/movie-ai
COPY --chmod=0644 images/workspace/AGENTS.md /usr/local/share/movie-workspace/AGENTS.md
COPY --chmod=0644 images/workspace/SERVER_CONTEXT.md /usr/local/share/movie-workspace/SERVER_CONTEXT.md
COPY images/workspace/admin-skills/h3-prompt-writing /etc/codex/skills/h3-prompt-writing
COPY images/workspace/admin-skills/h3-video-generation /etc/codex/skills/h3-video-generation
COPY images/workspace/admin-skills/h3-editorial-fashion-motion /etc/codex/skills/h3-editorial-fashion-motion
COPY images/workspace/admin-skills/h3-surreal-miniature-absurdism /etc/codex/skills/h3-surreal-miniature-absurdism
COPY images/workspace/admin-skills/h3-chibi-live-action-sticker /etc/codex/skills/h3-chibi-live-action-sticker
COPY images/workspace/admin-skills/h3-creature-motion-replacement /etc/codex/skills/h3-creature-motion-replacement
COPY images/workspace/admin-skills/h3-multiverse-portal-ensemble /etc/codex/skills/h3-multiverse-portal-ensemble
COPY images/workspace/admin-skills/h3-deadpan-mockumentary-interview /etc/codex/skills/h3-deadpan-mockumentary-interview
COPY images/workspace/admin-skills/h3-soft-body-physics-comedy /etc/codex/skills/h3-soft-body-physics-comedy
COPY images/workspace/admin-skills/h3-retro-pixel-sprite-loop /etc/codex/skills/h3-retro-pixel-sprite-loop
COPY images/workspace/admin-skills/h3-japanese-craft-commercial /etc/codex/skills/h3-japanese-craft-commercial
COPY images/workspace/admin-skills/h3-micro-fpv-impossible-one-take /etc/codex/skills/h3-micro-fpv-impossible-one-take
COPY images/workspace/admin-skills/h3-occlusion-orbit-ensemble /etc/codex/skills/h3-occlusion-orbit-ensemble
COPY images/workspace/admin-skills/h3-character-intro-motion-card /etc/codex/skills/h3-character-intro-motion-card
COPY images/workspace/admin-skills/h3-ancient-title-sequence /etc/codex/skills/h3-ancient-title-sequence
COPY images/workspace/admin-skills/h3-interactive-creature-encyclopedia /etc/codex/skills/h3-interactive-creature-encyclopedia
COPY images/workspace/admin-skills/h3-anime-character-showcase-pv /etc/codex/skills/h3-anime-character-showcase-pv
COPY images/workspace/admin-skills/h3-material-carving-asmr /etc/codex/skills/h3-material-carving-asmr
COPY images/workspace/admin-skills/h3-pop-art-split-screen-motion /etc/codex/skills/h3-pop-art-split-screen-motion
COPY images/workspace/admin-skills/h3-dark-sci-fi-motion-poster /etc/codex/skills/h3-dark-sci-fi-motion-poster
COPY images/workspace/admin-skills/h3-asymmetric-speed-duo-choreography /etc/codex/skills/h3-asymmetric-speed-duo-choreography
COPY images/workspace/admin-skills/h3-layered-windsurfing-fashion-mv /etc/codex/skills/h3-layered-windsurfing-fashion-mv
COPY images/workspace/admin-skills/h3-water-obstacle-variety-show /etc/codex/skills/h3-water-obstacle-variety-show
COPY images/workspace/admin-skills/h3-two-part-character-reveal /etc/codex/skills/h3-two-part-character-reveal
COPY images/workspace/admin-skills/h3-first-person-finger-controlled-dance /etc/codex/skills/h3-first-person-finger-controlled-dance
COPY images/workspace/admin-skills/z-image-turbo-generation /etc/codex/skills/z-image-turbo-generation
COPY images/workspace/admin-skills/hunyuan-image-generation /etc/codex/skills/hunyuan-image-generation
COPY images/workspace/admin-skills/image-style /etc/codex/skills/image-style
COPY --chmod=0755 images/workspace/hooks/enforce_image_urls.py /etc/codex/hooks/enforce_image_urls.py
COPY --chmod=0644 images/workspace/managed_config.toml /etc/codex/managed_config.toml
COPY --chmod=0644 images/workspace/requirements.toml /etc/codex/requirements.toml
COPY --chmod=0644 images/workspace/config.toml /usr/local/share/movie-workspace/config.toml
COPY --chmod=0644 images/workspace/movie.config.toml /usr/local/share/movie-workspace/movie.config.toml
COPY --chmod=0644 images/workspace/tmux.conf /usr/local/share/movie-workspace/tmux.conf

RUN find /etc/codex/skills -type d -exec chmod 0755 {} + \
    && find /etc/codex/skills -type f -exec chmod 0644 {} + \
    && chown -R root:root /etc/codex/skills

USER 10001:10001
WORKDIR /workspace
EXPOSE 7681
ENTRYPOINT ["/usr/local/bin/movie-workspace-supervisor"]
