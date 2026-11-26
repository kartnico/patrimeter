#!/bin/sh
# Patrimeter console in the container, as the application's user:
#   docker compose exec app patrimeter-console app:user:create you@example.org
exec runuser -u www-data -- php /srv/patrimeter/bin/console "$@"
