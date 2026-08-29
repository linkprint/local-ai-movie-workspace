FROM debian@sha256:abd67ffcfa541b485a3dff59865ab629aa048a6c613e639d36e7456b0b229241

ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates squid \
    && rm -rf /var/lib/apt/lists/* \
    && squid -z -f /etc/squid/squid.conf || true

COPY --chmod=0644 images/egress/squid.conf /etc/squid/squid.conf
COPY --chmod=0644 images/egress/openai-bootstrap-domains.txt /etc/squid/openai-bootstrap-domains.txt

USER 13:13
EXPOSE 3128
ENTRYPOINT ["/usr/sbin/squid", "-N", "-f", "/etc/squid/squid.conf"]
