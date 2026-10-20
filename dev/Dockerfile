FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libfreetype6-dev libzip-dev \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install mysqli gd exif zip \
 && rm -rf /var/lib/apt/lists/*
RUN printf "display_errors=Off\nlog_errors=On\nerror_log=/dev/stderr\n" > /usr/local/etc/php/conf.d/zz-dev.ini
