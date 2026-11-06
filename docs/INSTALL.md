# Installation & Setup Guide

This project consists of two main components:

1. **AI Server:** The AI processing backend, deployed on a VPS using Docker.
2. **WordPress Plugin:** The WordPress plugin installed on your website to communicate with the AI server.

First, deploy the AI server, then install and connect the WordPress plugin.

---

# Part 1: AI Server Installation

## 1. Prerequisites

Before starting, make sure you have the following:

- A VPS running Ubuntu 22.04 or later.
- SSH access to the server with `sudo` privileges.
- A domain or subdomain for the AI server (e.g. `ai.example.com`).
- A valid OpenAI API Key or an API-compatible service.
- The public URL of your WordPress website (e.g. `https://example.com`).

### Configure DNS

In your domain provider's DNS management panel, create an A record:

| Type | Name | Value |
|---|---|---|
| A | `ai` | `SERVER_IP` |

Replace `SERVER_IP` with your VPS public IP address.

After creating the record, verify DNS propagation:

```bash
nslookup ai.example.com
```

The output should display your server's IP address.

> The domain must point to the correct server IP before proceeding with HTTPS certificate issuance.

## 2. Connect to Your VPS

Open a terminal on your local machine and connect to the server:

```bash
ssh root@SERVER_IP
```

If you are using another user account:

```bash
ssh username@SERVER_IP
```

Update the system packages:

```bash
sudo apt update && sudo apt upgrade -y
```

## 3. Install Docker

Run the following command to install Docker:

```bash
curl -fsSL https://get.docker.com | sh
```

Enable and start the Docker service:

```bash
sudo systemctl enable --now docker
```

Verify that Docker and Docker Compose are installed:

```bash
docker --version
docker compose version
```

Both commands should display their installed versions.

## 4. Open Required Ports

The following ports must be accessible from the internet:

| Port | Protocol | Purpose |
|---|---|---|
| 80 | TCP | HTTP requests and SSL certificate issuance |
| 443 | TCP | Secure HTTPS communication |

If you are using UFW, run:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
```

Enable the firewall:

```bash
sudo ufw enable
```

Check its status:

```bash
sudo ufw status
```

If your VPS provider has an additional firewall in its control panel, make sure these ports are also allowed there.

> Make sure SSH access is allowed before enabling UFW to avoid losing your connection to the server.

## 5. Upload the Project to the Server

Upload the `ai-server` directory to your VPS.

For example, run the following command from your local machine:

```bash
scp -r ./ai-server root@SERVER_IP:/opt/ai-server
```

Then navigate to the project directory:

```bash
cd /opt/ai-server
```

Verify that the project files have been uploaded correctly:

```bash
ls -la
```

## 6. Generate Environment Configuration

Run the initialization script:

```bash
bash deploy/init-env.sh
```

The script will ask you to provide the following information:

- AI server domain (e.g. `ai.example.com`).
- WordPress website URL (e.g. `https://example.com`).
- OpenAI API Key.

All other required configuration values will be generated and configured automatically.

At the end, the script will display two values:

```text
PWC_API_URL
PWC_API_SECRET
```

**Save both values. You will need them when configuring the WordPress plugin in Part 2.**

The `.env` file will also be created in the root directory of `ai-server`.

> The `.env` file contains sensitive information. Never upload it to GitHub or share it with unauthorized users.

## 7. Start the AI Server

Build and start the services using Docker Compose:

```bash
docker compose up -d --build
```

During the first execution, Docker will download the required images, build the project, and start the services.

Check the status of all services:

```bash
docker compose ps
```

All services should be in the `running` or `healthy` state.

## 8. Verify Server Health

To check whether the API is running correctly, execute:

```bash
curl https://ai.example.com/health
```

If everything is configured correctly, the response should look similar to:

```json
{
  "status": "ok"
}
```

You can also inspect the API service logs:

```bash
docker compose logs -f api
```

Press `Ctrl + C` to exit the live log output. This will not stop the service.

> SSL certificate issuance may take a few seconds during the first startup. If the certificate cannot be issued, check your DNS configuration and make sure ports 80 and 443 are accessible.

Once this step is complete, your AI server is ready to connect to the WordPress plugin.

---

# Part 2: WordPress Plugin Installation

## 1. Install the Plugin

Log in to your WordPress dashboard.

Navigate to:

`Plugins → Add New Plugin → Upload Plugin`

Select the following file:

```text
release/persian-wp-chatbot-1.0.0.zip
```

Then:

1. Click **Install Now**.
2. Wait for the installation to complete.
3. Click **Activate Plugin**.

After activation, the Persian AI Assistant management section will become available in your WordPress dashboard.

## 2. Configure Server Connection

To establish a secure connection, you must add the connection credentials generated during Step 6 of the server installation to your WordPress `wp-config.php` file.

The `wp-config.php` file is located in the root directory of your WordPress installation.

Add the following lines before this statement:

```php
/* That's all, stop editing! Happy publishing. */
```

Configuration:

```php
define( 'PWC_API_URL', 'https://ai.example.com' );
define( 'PWC_API_SECRET', 'YOUR_API_SECRET' );
```

Replace the example values with your actual server credentials:

- `PWC_API_URL`: The AI server URL generated during server initialization.
- `PWC_API_SECRET`: The secret key generated by the server initialization script.

Example:

```php
define( 'PWC_API_URL', 'https://ai.example.com' );
define( 'PWC_API_SECRET', 'your-generated-secret' );

/* That's all, stop editing! Happy publishing. */
```

> The `PWC_API_SECRET` value must exactly match `API_SHARED_SECRET` in the server's `.env` file. Make sure no extra spaces or characters are introduced when copying it.

## 3. Verify the WordPress Connection

After saving the changes to `wp-config.php`:

1. Open your WordPress dashboard.
2. Navigate to the Persian AI Assistant settings.
3. Check the server connection status.

If the configuration is correct, the connection status should display:

**Connected**

At this point, the WordPress plugin has successfully established communication with the AI server.

## 4. Train the AI Using Website Content

After establishing the connection, you need to provide the website's information sources to the RAG system.

From the plugin's Knowledge Base management section:

1. Select the content sources you want to use, such as products, posts, and pages.
2. Extract the selected content and add it to the knowledge base.
3. Wait for the processing and indexing operations to complete.
4. Verify the processing status of the selected sources.

If you have additional documents, you can also upload them through the knowledge source management section.

## 5. Activate the Chatbot

After preparing the knowledge base:

1. Open the plugin settings.
2. Customize the chat widget's appearance and configuration.
3. Configure required features such as file and voice message uploads.
4. Enable the chatbot.
5. Open your website and verify that the chat widget is displayed correctly.

Visitors can now interact with the AI assistant through the website's chat widget.

---

# Part 3: Server Management

All commands below must be executed inside the `ai-server` directory:

```bash
cd /opt/ai-server
```

| Operation | Command |
|---|---|
| Check service status | `docker compose ps` |
| View all service logs | `docker compose logs -f` |
| View API logs | `docker compose logs -f api` |
| Restart all services | `docker compose restart` |
| Stop services | `docker compose down` |
| Rebuild and restart the project | `docker compose up -d --build` |
| Monitor resource usage | `docker stats` |

**Note:** `docker compose down` stops and removes the containers and networks managed by Compose, but does not remove named volumes. Avoid using the `-v` option unless you intentionally want to delete persistent data.

## Updating the Server

If you replace the project files or download a newer version, navigate to the project directory:

```bash
cd /opt/ai-server
```

Then rebuild and restart the services:

```bash
docker compose up -d --build
```

This command rebuilds the required images and starts the services using the updated project files.

---

# Part 4: Troubleshooting

### `https_required` or HTTP 400 Error

The server URL must use HTTPS and a valid domain name.

Correct:

```text
https://ai.example.com
```

Incorrect:

```text
http://ai.example.com
http://SERVER_IP
```

### SSL Certificate Issuance Failed

Check the following:

- The domain's DNS record points to the correct server IP.
- Ports 80 and 443 are accessible.
- The server firewall or VPS provider firewall is not blocking incoming requests.
- The domain is publicly accessible.

### HTTP 401 Error When Connecting the Plugin

This error is usually related to authentication.

Verify the following values:

```text
wp-config.php:
PWC_API_SECRET
```

```text
ai-server/.env:
API_SHARED_SECRET
```

Both values must match exactly.

### Server Fails to Start

Check the status of the services:

```bash
docker compose ps
```

Then inspect the API logs:

```bash
docker compose logs --tail=100 api
```

If a configuration error exists, the error message will usually identify the problematic value in the `.env` file.

### Connection Works but the Chatbot Does Not Respond

Check the following:

- API connectivity to the AI provider and the validity of the API Key.
- API service logs.
- Whether the knowledge base sources have been successfully processed.
- Plugin request errors in the WordPress dashboard.
- Whether the WordPress REST API is accessible.

To monitor the API logs in real time:

```bash
docker compose logs -f api
```

---

# Security Recommendations

- Never publish the `.env` file on GitHub or any public repository.
- Never share your AI provider API Key or `API_SHARED_SECRET` with unauthorized users.
- Do not hardcode sensitive credentials directly into the project's source files.
- Always use HTTPS for communication between WordPress and the AI server.
- Regularly back up server data and persistent Docker volumes.

---

**Installation completed successfully!** Your Persian AI Assistant is now ready to respond to your website visitors.
