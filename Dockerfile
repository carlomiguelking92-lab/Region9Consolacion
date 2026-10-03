FROM php:8.2-apache

# Enable Apache rewrite module
RUN a2enmod rewrite

# Install MySQL PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy all website files into Apache web root
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html