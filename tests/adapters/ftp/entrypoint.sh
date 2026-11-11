#!/bin/sh
# file generated with AI assistance: Claude Code - [2026-11-11 21:00:00 UTC]
# Starts one FTP daemon for the adapter probe. Creates the test user from
# FTP_USER/FTP_PASSWORD (./test-credentials.env) and a self-signed TEST-ONLY
# certificate, generated fresh at every start and never stored.
set -eu

daemon="${1:?usage: ftp-test-entrypoint vsftpd|proftpd|pure-ftpd}"
: "${FTP_USER:?FTP_USER missing, see test-credentials.env}"
: "${FTP_PASSWORD:?FTP_PASSWORD missing, see test-credentials.env}"
home="/srv/ftp/${FTP_USER}"

create_user() {
    mkdir -p /srv/ftp
    # a restarted container keeps /etc/passwd but gets a fresh tmpfs
    if ! id "$FTP_USER" >/dev/null 2>&1; then
        adduser -D -h "$home" -s /bin/sh "$FTP_USER"
        echo "${FTP_USER}:${FTP_PASSWORD}" | chpasswd
    fi
    mkdir -p "$home"
    chown "$FTP_USER:$FTP_USER" "$home"
}

create_test_certificate() {
    openssl req -x509 -newkey rsa:2048 -nodes -days 2 \
        -subj "/CN=$(hostname)/O=filefly adapter probe TEST ONLY" \
        -keyout /etc/ftp-test/key.pem -out /etc/ftp-test/cert.pem \
        2>/dev/null
    cat /etc/ftp-test/key.pem /etc/ftp-test/cert.pem \
        > /etc/ftp-test/combined.pem
    chmod 600 /etc/ftp-test/key.pem /etc/ftp-test/combined.pem
}

create_user
create_test_certificate

case "$daemon" in
    vsftpd)
        # inetd style: tcpsvd accepts, one vsftpd process per connection.
        # vsftpd's own standalone listener (listen=YES) died with SIGSEGV
        # after FTPS sessions on Alpine 3.24 (vsftpd 3.0.5, musl).
        exec tcpsvd -E 0.0.0.0 21 \
            vsftpd /etc/ftp-test/vsftpd.conf -olisten=NO
        ;;
    proftpd)
        mkdir -p /run/proftpd
        # PROFTPD_DEFINE (optional) selects an <IfDefine> block of the config
        exec proftpd --nodaemon --config /etc/ftp-test/proftpd.conf \
            ${PROFTPD_DEFINE:+-D "$PROFTPD_DEFINE"}
        ;;
    pure-ftpd)
        # -l unix: system users, -E: no anonymous, -A: chroot everyone,
        # -p: passive ports, -Y 1: TLS optional, -2: certificate file
        exec /opt/pure-ftpd/sbin/pure-ftpd -l unix -E -A -p 30000:30009 -Y 1 \
            -2 /etc/ftp-test/combined.pem
        ;;
    *)
        echo "unknown daemon: $daemon" >&2
        exit 64
        ;;
esac
