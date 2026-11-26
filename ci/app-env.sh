# Environment of the application for the end-to-end, security and load tests: production mode (as on a server),
# dedicated database and throwaway keys drawn at first use.
# To include with ". ci/app-env.sh" from the project's root.
E2E_DIR="$PWD/var/e2e"
mkdir -p "$E2E_DIR"
PHP="${PHP:-php}"
if [ ! -f "$E2E_DIR/secrets" ]; then
    {
        echo "APP_SECRET=$($PHP -r 'echo bin2hex(random_bytes(32));')"
        echo "AUTH_SECRET_KEY=$($PHP -r 'echo base64_encode(random_bytes(32));')"
    } > "$E2E_DIR/secrets"
fi
set -a
. "$E2E_DIR/secrets"
APP_ENV=prod
APP_DEBUG=0
APP_PORT="${APP_PORT:-8765}"
DEFAULT_URI="http://localhost:$APP_PORT"
DATABASE_URL="sqlite:///$E2E_DIR/patrimeter.sqlite"
SMTP_HOST="${SMTP_HOST:-localhost}"
SMTP_PORT="${SMTP_PORT:-1025}"
SMTP_USER=
SMTP_PASSWORD=
SMTP_ENCRYPTION=none
MAIL_FROM=patrimeter@example.test
REGISTRATION_ENABLED=true
# Off by default: the end-to-end flows and the screenshots go through the demonstration.
DEMO_MODE=true
API_ENABLED=true
MCP_ENABLED=true
set +a
