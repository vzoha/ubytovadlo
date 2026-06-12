FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libxml2-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype-dev \
        libwebp-dev \
        tesseract-ocr \
        tesseract-ocr-eng \
        imagemagick \
        curl \
        ca-certificates \
    && curl -fsSL -o /usr/share/tesseract-ocr/5/tessdata/mrz.traineddata \
        https://raw.githubusercontent.com/DoubangoTelecom/tesseractMRZ/master/tessdata_best/mrz.traineddata \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        intl \
        zip \
        opcache \
        gd \
        bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/memory.ini \
    && echo "date.timezone = Europe/Prague" >> /usr/local/etc/php/conf.d/memory.ini

WORKDIR /app
