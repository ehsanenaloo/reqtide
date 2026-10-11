# Redis whose clock the tests control. The server runs under libfaketimeMT and reads
# the wall time from the file named by FAKETIME_TIMESTAMP_FILE on every call (no caching), so the clock is frozen at the
# value the test harness writes and moves forwards or backwards only when it rewrites the file. The scripts under test
# are the production scripts: nothing in them knows about the fake clock.
#
# The official entrypoint drops privileges to the "redis" user; libfaketime needs a writable semaphore, which worked only
# as root, so the entrypoint is bypassed and the server runs as root inside this test-only image.
ARG BASE_IMAGE
FROM ${BASE_IMAGE}

RUN apt-get update \
    && apt-get install -y --no-install-recommends libfaketime \
    && rm -rf /var/lib/apt/lists/* \
    && find /usr/lib -name 'libfaketimeMT.so.1' -print | head -1 > /faketime-path \
    && test -s /faketime-path

ENV FAKETIME_TIMESTAMP_FILE=/ft/time \
    FAKETIME_NO_CACHE=1

USER root
ENTRYPOINT ["/bin/sh", "-c", "[ -s /ft/time ] || date -u '+%Y-%m-%d %H:%M:%S' > /ft/time; exec env LD_PRELOAD=\"$(cat /faketime-path)\" redis-server \"$@\"", "redis-server"]
CMD ["--save", "", "--appendonly", "no", "--maxmemory-policy", "noeviction"]
