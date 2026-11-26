#!/bin/sh
# Replaces systemctl in the container of the "deployment" job, which has no systemd: the same commands as the
# installation procedure, applied directly to the services. A backup service runs the ExecStart line of its real
# unit file.
set -eu

action="$1"
shift

fpm_stop() {
    # By process: "php-fpm -t" removes the PID file of a running php-fpm.
    pkill -TERM -f 'php-fpm: master process' || true
    while pgrep -f 'php-fpm: master process' > /dev/null; do
        sleep 0.1
    done
}

fpm_start() {
    "php-fpm$1" --fpm-config "/etc/php/$1/fpm/php-fpm.conf"
}

nginx_stop() {
    # "nginx -t" leaves an empty PID file: only a non-empty file means nginx is running.
    if [ -s /run/nginx.pid ]; then
        nginx -s quit
        while [ -s /run/nginx.pid ]; do
            sleep 0.1
        done
    fi
}

case "$action" in
    daemon-reload) exit 0 ;;
    is-active)
        # php-fpm: from its process; a long-running service, never started here, counts as active.
        for unit in "$@"; do
            case "$unit" in
                --quiet) ;;
                php*-fpm) pgrep -f 'php-fpm: master process' > /dev/null ;;
                *.service) grep -q '^Restart=always' "/etc/systemd/system/$unit" ;;
                *) echo "systemctl de test : is-active $unit non géré." >&2 && exit 1 ;;
            esac
        done
        exit 0
        ;;
    enable)
        for unit in "$@"; do
            case "$unit" in
                --now) ;;
                *) test -f "/etc/systemd/system/$unit" ;;
            esac
        done
        exit 0
        ;;
esac

for unit in "$@"; do
    case "$unit" in
        php*-fpm)
            version="${unit#php}"
            version="${version%-fpm}"
            case "$action" in
                start) fpm_start "$version" ;;
                stop) fpm_stop ;;
                restart | reload)
                    fpm_stop
                    fpm_start "$version"
                    ;;
                *) echo "systemctl de test : $action $unit non géré." >&2 && exit 1 ;;
            esac
            ;;
        nginx)
            case "$action" in
                start) nginx ;;
                stop) nginx_stop ;;
                reload) nginx -s reload ;;
                restart)
                    nginx_stop
                    nginx
                    ;;
                *) echo "systemctl de test : $action $unit non géré." >&2 && exit 1 ;;
            esac
            ;;
        *@*.service)
            # Template instance: the file is "name@.service", %i becomes the instance name.
            test "$action" = start
            instance="${unit#*@}"
            instance="${instance%.service}"
            sh -c "$(sed -n 's/^ExecStart=//p' "/etc/systemd/system/${unit%%@*}@.service" | sed "s/%i/$instance/g")"
            ;;
        *.service)
            # Long-running service (Restart=always): never started here, it would block the job.
            if grep -q '^Restart=always' "/etc/systemd/system/$unit"; then
                echo "systemctl de test : $unit, service de longue durée, non lancé."
                continue
            fi
            test "$action" = start
            sh -c "$(sed -n 's/^ExecStart=//p' "/etc/systemd/system/$unit")"
            ;;
        *)
            echo "systemctl de test : unité inconnue : $unit." >&2
            exit 1
            ;;
    esac
done
