# file generated with AI assistance: Claude Code - [2026-11-11 21:00:00 UTC]
# One image with three FTP daemons (vsftpd, ProFTPD with mod_tls, Pure-FTPd).
# The compose service picks the daemon through its command, see
# ./entrypoint.sh. Test use only.
#
# Pure-FTPd is compiled from the release tarball: Alpine's package is built
# with --with-minimal, which drops NOOP, STAT, FEAT, HELP and more (all
# answered "500 Unknown command"), so it would not behave like the full
# builds that hosting servers run.

FROM alpine:3.24.4 AS pure-ftpd-build
ARG PURE_FTPD_VERSION=1.0.54
# sha256 of pure-ftpd-1.0.54.tar.gz (matches Alpine's sha512 for the same file)
ARG PURE_FTPD_SHA256=dc9140420ec44f7829579591ff378aa6396b4604b9c6aeae847368e0f35bd7b2
RUN apk add --no-cache build-base openssl-dev curl \
    && curl -fsSL -o /tmp/pure-ftpd.tar.gz \
        "https://download.pureftpd.org/pub/pure-ftpd/releases/pure-ftpd-${PURE_FTPD_VERSION}.tar.gz" \
    && echo "${PURE_FTPD_SHA256}  /tmp/pure-ftpd.tar.gz" | sha256sum -c - \
    && tar -xzf /tmp/pure-ftpd.tar.gz -C /tmp \
    && cd "/tmp/pure-ftpd-${PURE_FTPD_VERSION}" \
    && ./configure --prefix=/opt/pure-ftpd --with-tls --without-humor \
    && make -j"$(getconf _NPROCESSORS_ONLN)" \
    && make install

FROM alpine:3.24.4

RUN apk add --no-cache \
        busybox-extras \
        openssl \
        proftpd \
        proftpd-mod_tls \
        vsftpd

COPY --from=pure-ftpd-build /opt/pure-ftpd /opt/pure-ftpd
COPY vsftpd.conf proftpd.conf /etc/ftp-test/
COPY entrypoint.sh /usr/local/bin/ftp-test-entrypoint

ENTRYPOINT ["/usr/local/bin/ftp-test-entrypoint"]
