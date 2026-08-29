#!/bin/sh
set -eu

wait_seconds="${NVIDIA_RECOVERY_WAIT_SECONDS:-90}"
gpu_index="${NVIDIA_GPU_INDEX:-0}"
power_limit_w="${NVIDIA_POWER_LIMIT_W:-550}"

log() {
    printf '%s\n' "nvidia-gpu-recovery: $*"
}

if ! lspci -n -d 10de: 2>/dev/null | grep -q .; then
    log "no NVIDIA PCI device detected"
    exit 1
fi

if /usr/bin/nvidia-smi -L >/dev/null 2>&1; then
    log "driver is already healthy"
else
    kernel="$(uname -r)"
    if ! modinfo -k "$kernel" nvidia >/dev/null 2>&1; then
        log "NVIDIA module is missing for ${kernel}; rebuilding installed DKMS modules"
        /usr/sbin/dkms autoinstall -k "$kernel"
    fi

    modinfo -k "$kernel" nvidia >/dev/null
    log "loading NVIDIA modules for ${kernel}"
    modprobe_error=""
    if ! modprobe_error="$(/usr/sbin/modprobe nvidia 2>&1)"; then
        if printf '%s' "$modprobe_error" | grep -Fq "Key was rejected by service"; then
            log "Secure Boot rejected the DKMS module; enroll /var/lib/shim-signed/mok/MOK.der or install a matching distribution-signed module"
        fi
        printf '%s\n' "$modprobe_error" >&2
        exit 1
    fi
    /usr/sbin/modprobe nvidia_modeset
    /usr/sbin/modprobe nvidia_drm
    /usr/sbin/modprobe nvidia_uvm
    /usr/bin/udevadm settle --timeout=30 || true
    if [ -x /usr/bin/nvidia-modprobe ]; then
        /usr/bin/nvidia-modprobe -u -c=0
    fi

    elapsed=0
    while ! /usr/bin/nvidia-smi -L >/dev/null 2>&1; do
        if [ "$elapsed" -ge "$wait_seconds" ]; then
            log "driver modules loaded but nvidia-smi is still unavailable"
            exit 1
        fi
        sleep 2
        elapsed=$((elapsed + 2))
    done
    log "driver recovered"
fi

if systemctl cat nvidia-persistenced.service >/dev/null 2>&1; then
    systemctl reset-failed nvidia-persistenced.service || true
    systemctl restart nvidia-persistenced.service
fi

if systemctl cat nvidia-cdi-refresh.service >/dev/null 2>&1; then
    systemctl reset-failed nvidia-cdi-refresh.service || true
    systemctl restart nvidia-cdi-refresh.service
fi

/usr/bin/nvidia-smi -i "$gpu_index" --power-limit="$power_limit_w"
applied_power_limit="$(/usr/bin/nvidia-smi -i "$gpu_index" --query-gpu=power.limit --format=csv,noheader,nounits)"
log "GPU ${gpu_index} power limit restored to ${applied_power_limit} W"

/usr/bin/nvidia-smi -L
log "persistence and CDI refresh completed"
