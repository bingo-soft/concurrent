FROM php:7.4-fpm

ENV PHP_VERSION=7.4
ENV PHALCON_VERSION=4.1.3
ENV TZ=Europe/Moscow


RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone
RUN apt-get update
RUN apt-get install -y --no-install-recommends \
    unzip \
    gcc \
    libpcre3-dev \
    zlib1g-dev \
    libfreetype6-dev \
    openssl \
    libssl-dev \
    wget \
    libpq-dev \
    git \
    libzip-dev \
    libxml2-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libonig-dev \
    make automake autoconf && \
    docker-php-ext-install iconv zip gd pdo_pgsql soap \
    && docker-php-ext-configure opcache --enable-opcache \
    && docker-php-ext-install opcache

#PHP-PSR
RUN git clone https://github.com/jbboehr/php-psr.git /root/psr && \
    cd /root/psr && \
    phpize && \
    ./configure && \
    make && \
    make test && \
    make install && \
    echo "extension=psr.so" > /usr/local/etc/php/conf.d/29-psr.ini && \
    cd && rm -Rf /root/psr

#Composer
RUN curl -sS https://getcomposer.org/installer | php \
    && mv composer.phar /usr/local/bin/composer

# Get and install Phalcon
RUN mkdir /root/buildphalcon && cd /root/buildphalcon && \
    wget https://github.com/phalcon/cphalcon/archive/v${PHALCON_VERSION}.tar.gz && \
    tar zxvf v${PHALCON_VERSION}.tar.gz && \
    cd cphalcon-${PHALCON_VERSION}/build/php$(echo ${PHP_VERSION} | cut -d '.' -f 1)/64bits && \
    phpize && export CFLAGS="-O2 -s" && \
    ./configure && make -j 8 && make install && \
    echo "extension=phalcon.so" > /usr/local/etc/php/conf.d/30-phalcon.ini && \
    rm -Rf /root/buildphalcon

RUN apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

ENV SWOOLE_VERSION=4.6.0

# Install Swoole
RUN cd /tmp && wget https://pecl.php.net/get/swoole-${SWOOLE_VERSION}.tgz && \
    tar zxvf swoole-${SWOOLE_VERSION}.tgz && \
    cd swoole-${SWOOLE_VERSION}  && \
    phpize  && \
    ./configure --enable-coroutine-postgresql && \
    make && make install && \
    echo 'extension=swoole.so' > /usr/local/etc/php/conf.d/swoole.ini

# Copy sources and install dependencies
COPY composer.json /var/www/html/composer.json
RUN cd /var/www/html/ && composer install --no-interaction
COPY src /var/www/html/src
RUN composer update --no-dev
COPY php.ini /usr/local/etc/php/php.ini
RUN cd /var/www/html/ && composer dump-autoload -o

COPY ./start.sh start.sh
RUN  chmod +x start.sh
CMD ./start.sh