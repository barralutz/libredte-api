FROM php:7.4-fpm

# Instalar dependencias
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    libxml2-dev \
    libssl-dev \
    git \
    curl \
    && docker-php-ext-install \
    zip \
    soap \
    xml \
    && docker-php-ext-enable \
    soap

# Instalar OpenSSL para PHP 7.4
RUN apt-get install -y openssl

# Instalar Composer
COPY --from=composer:2.0 /usr/bin/composer /usr/bin/composer

# Configurar PHP para producción
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini \
    && echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini \
    && echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini \
    && echo "opcache.interned_strings_buffer=8" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini \
    && echo "opcache.max_accelerated_files=4000" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini \
    && echo "memory_limit=256M" > /usr/local/etc/php/conf.d/memory-limit.ini

# Configuración optimizada para entornos con recursos limitados
COPY ./docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Configurar directorio de trabajo
WORKDIR /var/www/api

# Copiar la aplicación
COPY ./api /var/www/api

# Crear el directorio de almacenamiento si no existe
RUN mkdir -p /var/www/api/storage
# Establecer permisos
RUN chown -R www-data:www-data /var/www/api \
    && chmod -R 775 /var/www/api/storage

# Instalar dependencias con Composer
RUN cd /var/www/api && composer install --no-dev --optimize-autoloader --no-interaction

# Copiar el script de entrada
COPY docker/php/entrypoint.sh /entrypoint.sh

# Darle permisos de ejecución (así no necesitas chmod manual)
RUN chmod +x /entrypoint.sh

# Usar el script como punto de entrada
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]

