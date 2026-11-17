FROM mlabfactory/php8-apache:v1.5.0
COPY . /var/www/workdir
COPY bin/apache/default.conf /etc/apache2/sites-available/budgetcontrol.cloud.conf
WORKDIR /var/www/workdir
RUN composer install --no-dev --optimize-autoloader && mkdir -p storage/logs && touch .env
EXPOSE 80
CMD ["apache2-foreground"]
