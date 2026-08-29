FROM debian@sha256:abd67ffcfa541b485a3dff59865ab629aa048a6c613e639d36e7456b0b229241 AS manager

ENV DEBIAN_FRONTEND=noninteractive PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates python3 \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --uid 10002 --gid nogroup --home-dir /nonexistent --shell /usr/sbin/nologin movie-manager \
    && install -d -o 10002 -g nogroup -m 0700 /var/lib/movie-manager \
    && install -d -o root -g root -m 0755 /usr/local/share/movie-workspace /run/movie-h3-control
COPY --chmod=0755 images/control/manager.py /usr/local/bin/movie-workspace-manager
COPY --chmod=0644 security/seccomp/workspace.json /usr/local/share/movie-workspace/seccomp.json
USER 10002:65534
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/movie-workspace-manager"]

FROM debian@sha256:abd67ffcfa541b485a3dff59865ab629aa048a6c613e639d36e7456b0b229241 AS terminal-router

ENV DEBIAN_FRONTEND=noninteractive PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates python3 \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --uid 10005 --gid nogroup --home-dir /nonexistent --shell /usr/sbin/nologin movie-router
COPY --chmod=0755 images/control/terminal_router.py /usr/local/bin/movie-terminal-router
USER 10005:65534
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/movie-terminal-router"]

FROM debian@sha256:abd67ffcfa541b485a3dff59865ab629aa048a6c613e639d36e7456b0b229241 AS ai-router

ENV DEBIAN_FRONTEND=noninteractive PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates python3 \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --uid 10006 --gid nogroup --home-dir /nonexistent --shell /usr/sbin/nologin movie-ai-router \
    && install -d -o 10006 -g nogroup -m 0700 /var/lib/movie-ai-router
COPY --chmod=0755 images/control/ai_router.py /usr/local/bin/movie-ai-router
USER 10006:65534
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/movie-ai-router"]

FROM debian@sha256:abd67ffcfa541b485a3dff59865ab629aa048a6c613e639d36e7456b0b229241 AS broker

ENV DEBIAN_FRONTEND=noninteractive PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates ffmpeg python3 \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --uid 10003 --gid nogroup --home-dir /nonexistent --shell /usr/sbin/nologin movie-broker \
    && install -d -o 10003 -g nogroup -m 0700 /var/lib/movie-broker \
    && install -d -o 10003 -g nogroup -m 0755 /var/lib/movie-style-demos
COPY --chmod=0755 images/control/broker.py /usr/local/bin/movie-ai-broker
COPY --chmod=0644 images/control/h3-style-skills.txt /usr/local/bin/h3-style-skills.txt
USER 10003:65534
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/movie-ai-broker"]

FROM debian@sha256:abd67ffcfa541b485a3dff59865ab629aa048a6c613e639d36e7456b0b229241 AS h3-adapter

ENV DEBIAN_FRONTEND=noninteractive PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates python3 \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --uid 10004 --gid nogroup --home-dir /nonexistent --shell /usr/sbin/nologin movie-h3-adapter
COPY --chmod=0755 images/control/h3_adapter.py /usr/local/bin/movie-h3-adapter
USER 10004:65534
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/movie-h3-adapter"]

FROM debian@sha256:abd67ffcfa541b485a3dff59865ab629aa048a6c613e639d36e7456b0b229241 AS node-control

ENV DEBIAN_FRONTEND=noninteractive PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates python3 \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --uid 10007 --gid nogroup --home-dir /nonexistent --shell /usr/sbin/nologin movie-node-control
COPY --chmod=0755 images/control/node_control.py /usr/local/bin/movie-node-control
USER 10007:65534
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/movie-node-control"]
