FROM php:8.3-apache

# Instala o driver PDO para MySQL.
RUN docker-php-ext-install pdo_mysql

# Habilita o mod_rewrite do Apache.
RUN a2enmod rewrite

# Define public/ como diretório público da aplicação.
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' \
    /etc/apache2/sites-available/000-default.conf

# Permite regras .htaccess no diretório público.
RUN printf '<Directory /var/www/html/public>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' \
    > /etc/apache2/conf-available/public-directory.conf \
    && a2enconf public-directory

# Define o nome global do servidor para evitar avisos do Apache.
RUN echo 'ServerName localhost' \
    > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# Disponibiliza o Composer.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copia o código da aplicação.
COPY . .

# Instala as dependências de produção conforme o composer.lock.
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

# Verifica a configuração do Apache e se o processo principal está ativo.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD apache2ctl -t && grep -aq apache2 /proc/1/cmdline

EXPOSE 80

CMD ["apache2-foreground"]
