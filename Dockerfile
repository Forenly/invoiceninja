FROM invoiceninja/invoiceninja:5

# Switch to root to install packages
USER root

# Install nginx
RUN apk add --no-cache nginx

# Create nginx directories
RUN mkdir -p /run/nginx /var/log/nginx

# Copy nginx configuration
COPY nginx.conf /etc/nginx/nginx.conf
COPY default.conf /etc/nginx/http.d/default.conf

# Create startup script
COPY start.sh /start.sh
RUN chmod +x /start.sh

# Expose port 8080 for Cloud Run
EXPOSE 8080

# Use our startup script
CMD ["/start.sh"]
