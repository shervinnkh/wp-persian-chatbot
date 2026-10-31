#!/usr/bin/env bash
# Lock the VPS down so only the WordPress host can reach the API (HTTPS), plus SSH from your IP.
#
#   sudo ./firewall-ufw.sh <WORDPRESS_HOST_IP> <YOUR_ADMIN_IP_FOR_SSH>
#
# Notes
#  - Both arguments are required on purpose: a wrong/missing SSH rule would lock you out.
#  - Qdrant (6333/6334) and the app (8000) are NOT opened. Keep them bound to 127.0.0.1.
#  - Let's Encrypt HTTP-01 needs port 80 from anywhere: open it only while issuing/renewing
#    (sudo ufw allow 80/tcp ... sudo ufw delete allow 80/tcp) or use a DNS-01 challenge.
#  - Only valid if the WordPress host has a fixed outbound IP. On shared hosting it may change
#    or be shared; HMAC auth stays the main protection either way.
set -euo pipefail

HOST_IP="${1:?usage: $0 <WORDPRESS_HOST_IP> <YOUR_ADMIN_IP_FOR_SSH>}"
ADMIN_IP="${2:?usage: $0 <WORDPRESS_HOST_IP> <YOUR_ADMIN_IP_FOR_SSH>}"

ufw default deny incoming
ufw default allow outgoing
ufw allow from "$ADMIN_IP" to any port 22 proto tcp
ufw allow from "$HOST_IP" to any port 443 proto tcp
ufw --force enable
ufw status verbose
