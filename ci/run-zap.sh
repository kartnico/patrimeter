#!/bin/sh
# Authenticated ZAP baseline: passive scan of the application's pages, explored with a real session. The report
# goes into reports/zap.json; the security gate decides.
# Without submitting forms: the robot must neither sign out nor delete data.
# --autooff: the "Automation Framework", active by default, ignores spider.postform=false. ZAP says it might remove
# this option: check again when changing version.
set -eu
cd "$(dirname "$0")/.."

sh ci/start-app.sh --background
. ci/app-env.sh
cookie="$($PHP tests/dast/session-cookie.php "$DEFAULT_URI" e2e-01@example.test)"

mkdir -p reports /zap/wrk
chmod 777 /zap/wrk
status=0
zap-baseline.py --autooff -t "$DEFAULT_URI/" -m 2 -I -J zap.json -z "\
-config spider.postform=false \
-config spider.processform=false \
-config replacer.full_list(0).description=session \
-config replacer.full_list(0).enabled=true \
-config replacer.full_list(0).matchtype=REQ_HEADER \
-config replacer.full_list(0).matchstr=Cookie \
-config replacer.full_list(0).regex=false \
-config replacer.full_list(0).replacement=$cookie" || status=$?
cp /zap/wrk/zap.json reports/zap.json
# 0: nothing; 1 and 2: alerts, which the gate judges; beyond: ZAP failure.
test "$status" -le 2
