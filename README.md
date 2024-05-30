### Architecture Documentation for URL Redirection Node

#### Overview
The current architecture is designed to handle a significant deployment of SSL certificates, aiming to support up to 50,000 SSL certificates per server. The system is composed of several components working together to provide efficient URL redirection with HTTPS support, caching, and log management.

#### Components

1. **Swoole**:
   - A PHP-based URL redirection server.
   - Utilizes MongoDB for storing and retrieving links.
   
2. **Varnish**:
   - Provides a caching layer for the Swoole service.
   
3. **Traefik**:
   - Handles loading of SSL certificates.
   - Manages HTTPS requests and forwards them to Varnish.
   
4. **Filebeat**:
   - Collects logs from Varnish.
   - Sends logs to a log server for traffic analysis.

#### File Structure

The directory `certs/` contains the SSL certificate and key files. For security purposes, domain names are desensitized in this documentation.

```plaintext
certs/
├── abc.example.com.crt
├── abc.example.com.key
├── def.example.com.crt
├── def.example.com.key
├── ghi.example.org.crt
├── ghi.example.org.key
...
```

#### Current Metrics

- **Number of SSL Certificates**: 10,000
- **HTTPS Traffic**: 100 requests per second
- **HTTP Traffic**: 100 requests per second
- **Traefik Memory Usage**: 2 GB
- **Varnish Memory Usage**: 200 M
- **Swoole Memory Usage**: 50 M

#### Detailed Component Description

1. **Swoole**:
   - Handles incoming URL redirection requests.
   - Written in PHP for asynchronous and high-performance request processing.
   - Connects to MongoDB to fetch and store redirection links.

2. **Varnish**:
   - Acts as a reverse proxy cache.
   - Reduces the load on Swoole by caching frequent requests.
   - Configured to work seamlessly with Traefik to serve cached content efficiently.

3. **Traefik**:
   - Configured to load a large number of SSL certificates.
   - Uses dynamic configuration to manage certificates for multiple domains.
   - Forwards incoming HTTPS traffic to Varnish for caching.
   - Monitors performance and ensures SSL termination.

4. **Filebeat**:
   - Deployed as a lightweight shipper for forwarding and centralizing log data.
   - Collects logs from Varnish.
   - Sends logs to a centralized log server for further analysis and monitoring.

#### Traefik Configuration

The Traefik configuration is optimized for handling numerous SSL certificates, with specific settings to manage memory usage efficiently while ensuring reliable SSL termination and traffic forwarding.

### Future Enhancements

#### Scalability
- **Horizontal Scaling**: Redesign the architecture to support horizontal scaling by adding more servers and distributing the load effectively.

#### Resource Efficiency
- **Code Optimization**: Refactor code to enhance performance, reducing CPU and memory usage.
- **Efficient Resource Management**: Utilize lightweight containers and optimize data handling to minimize resource consumption.

