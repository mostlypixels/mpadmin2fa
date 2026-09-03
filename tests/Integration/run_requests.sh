#!/usr/bin/env bash
set -euo pipefail

: "${MP2FA_PS_ROOT:?Point MP2FA_PS_ROOT at a disposable installed shop}"
[[ "${MP2FA_INTEGRATION:-}" == 1 ]] || { echo 'Set MP2FA_INTEGRATION=1'; exit 1; }
module_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
runtime="$(mktemp -d /tmp/mp2fa-requests.XXXXXX)"
chmod 700 "$runtime"
mkdir "$runtime/php-sessions"
chmod 700 "$runtime/php-sessions"
export MP2FA_REQUEST_RUNTIME="$runtime"
# A CLI install may leave the separately compiled web/dev container stale.
php "$MP2FA_PS_ROOT/bin/console" cache:clear --env=dev --no-warmup
php "$MP2FA_PS_ROOT/bin/console" cache:clear --env=prod --no-warmup
apache_pid=''
cleanup() {
  if [[ -n "$apache_pid" ]]; then kill "$apache_pid" 2>/dev/null || true; wait "$apache_pid" 2>/dev/null || true; fi
  echo "Request test logs: $runtime"
}
trap cleanup EXIT

openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost \
  -addext subjectAltName=DNS:localhost -keyout "$runtime/server.key" -out "$runtime/server.crt" 2>/dev/null
php_module="${MP2FA_APACHE_PHP_MODULE:-$(find /usr/lib/apache2/modules -maxdepth 1 -name 'libphp*.so' | head -n1)}"
[[ -f "$php_module" ]] || { echo 'Apache PHP module is required'; exit 1; }
php_module_id=php_module
[[ "$php_module" == *php7* ]] && php_module_id=php7_module
cat > "$runtime/apache.conf" <<EOF
ServerRoot "$runtime"
ServerName localhost
PidFile "$runtime/apache.pid"
Listen 127.0.0.1:8443
Listen 127.0.0.1:8080
LoadModule mpm_prefork_module /usr/lib/apache2/modules/mod_mpm_prefork.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
LoadModule authz_host_module /usr/lib/apache2/modules/mod_authz_host.so
LoadModule dir_module /usr/lib/apache2/modules/mod_dir.so
LoadModule mime_module /usr/lib/apache2/modules/mod_mime.so
LoadModule rewrite_module /usr/lib/apache2/modules/mod_rewrite.so
LoadModule env_module /usr/lib/apache2/modules/mod_env.so
LoadModule setenvif_module /usr/lib/apache2/modules/mod_setenvif.so
LoadModule ssl_module /usr/lib/apache2/modules/mod_ssl.so
LoadModule $php_module_id "$php_module"
TypesConfig /etc/mime.types
ErrorLog "$runtime/apache-error.log"
LogLevel warn
DocumentRoot "$MP2FA_PS_ROOT"
DirectoryIndex index.php
<Directory "$MP2FA_PS_ROOT">
  Require all granted
  AllowOverride All
  php_admin_value session.save_path "$runtime/php-sessions"
</Directory>
<FilesMatch "\\.php$">
  SetHandler application/x-httpd-php
</FilesMatch>
<VirtualHost 127.0.0.1:8443>
  SSLEngine on
  SSLCertificateFile "$runtime/server.crt"
  SSLCertificateKeyFile "$runtime/server.key"
</VirtualHost>
EOF
setsid /usr/sbin/apache2 -f "$runtime/apache.conf" -DFOREGROUND > "$runtime/apache-output.log" 2>&1 &
apache_pid=$!
for attempt in $(seq 1 30); do
  if curl --silent --cacert "$runtime/server.crt" https://localhost:8443/robots.txt > /dev/null; then break; fi
  kill -0 "$apache_pid" 2>/dev/null || { cat "$runtime/apache-output.log"; exit 1; }
  sleep 1
done
php "$module_root/tests/Integration/request_checks.php"
