FROM nginx:alpine

# Copy custom Nginx configuration
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Copy frontend source files
COPY . /usr/share/nginx/html/

# Expose port 5500
EXPOSE 5500
