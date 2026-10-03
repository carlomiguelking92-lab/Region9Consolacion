FROM php:8.2-apache

# Enable Apache rewrite module for .htaccess
RUN a2enmod rewrite

# Copy all website files into Apache web root
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html