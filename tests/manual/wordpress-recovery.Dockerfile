FROM wordpress:php8.3-apache
# The native-backend fixture requires a dump executable inside the worker,
# not merely on the CI host. This image is never deployed to production.
RUN apt-get update \
    && apt-get install -y --no-install-recommends mariadb-client \
    && rm -rf /var/lib/apt/lists/*
