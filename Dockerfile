FROM php:8.2-apache

# Enable Apache rewrite module
RUN a2enmod rewrite

# Install Java Runtime (Debian default) and MySQL PHP extensions
RUN apt-get update && apt-get install -y default-jre && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy all website files into Apache web root
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Start Java Discord bot in background and launch Apache in foreground
CMD ["sh", "-c", "java -jar /var/www/html/PointTracker.jar & apache2-foreground"]