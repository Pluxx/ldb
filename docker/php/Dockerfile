ARG PHP_VERSION=7.4
FROM php:${PHP_VERSION}-apache

# php:7.4 images are Debian bullseye (EOL): use the archive mirror there; no-op on newer bases.
RUN if grep -q bullseye /etc/os-release; then \
      sed -i -e 's|deb.debian.org/debian-security|archive.debian.org/debian-security|g' -e 's|deb.debian.org/debian|archive.debian.org/debian|g' /etc/apt/sources.list; \
      echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99archive; \
    fi \
 && apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libzip-dev libbz2-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql zip bz2 \
 && rm -rf /var/lib/apt/lists/*
# pdo_sqlite, mbstring, zlib, iconv, openssl, json are built into the official image.

# SQL Server support (optional, keeps the default image light):  --build-arg WITH_SQLSRV=1
# Installs Microsoft ODBC Driver 18 + pecl sqlsrv/pdo_sqlsrv (preferred driver) and FreeTDS pdo_dblib (alternative).
# pdo_sqlsrv 5.10.1 supports PHP 7.4/8.0; newer PHP gets the latest release from PECL.
ARG WITH_SQLSRV=0
RUN if [ "$WITH_SQLSRV" = "1" ]; then \
      set -eux; \
      if grep -q bullseye /etc/os-release; then \
        sed -i -e 's|deb.debian.org/debian-security|archive.debian.org/debian-security|g' -e 's|deb.debian.org/debian|archive.debian.org/debian|g' /etc/apt/sources.list; \
      fi; \
      apt-get update; \
      apt-get install -y --no-install-recommends curl ca-certificates unixodbc-dev freetds-dev libsybdb5 $PHPIZE_DEPS; \
      . /etc/os-release; \
      curl -fsSL -o /tmp/packages-microsoft-prod.deb "https://packages.microsoft.com/config/debian/${VERSION_ID}/packages-microsoft-prod.deb"; \
      dpkg -i /tmp/packages-microsoft-prod.deb; rm /tmp/packages-microsoft-prod.deb; \
      apt-get update; \
      ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18; \
      case "$PHP_VERSION" in \
        7.4*|8.0*) SQLSRV_VER=-5.10.1 ;; \
        *) SQLSRV_VER= ;; \
      esac; \
      pecl install sqlsrv${SQLSRV_VER} pdo_sqlsrv${SQLSRV_VER}; \
      docker-php-ext-enable sqlsrv pdo_sqlsrv; \
      docker-php-ext-configure pdo_dblib --with-libdir="lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH)"; \
      docker-php-ext-install -j"$(nproc)" pdo_dblib; \
      rm -rf /var/lib/apt/lists/* /tmp/pear; \
    fi

RUN a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN { \
      echo 'upload_max_filesize=256M'; \
      echo 'post_max_size=256M'; \
      echo 'memory_limit=256M'; \
      echo 'expose_php=Off'; \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/dev/stderr'; \
    } > /usr/local/etc/php/conf.d/ldb.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
