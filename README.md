# Symfony Application with FrankenPHP

This Symfony application is configured to run with FrankenPHP via Docker.

This will not have a UI to start, but will use YAML config files to set up.

## Requirements

- Docker
- Docker Compose

## Getting Started

1. Clone this repository
2. Start the Docker containers:

```bash
docker compose up -d
```

3. Access the application at http://localhost

## Docker Setup

This application uses Docker Compose with the following services:

- **php**: FrankenPHP service that runs the Symfony application
- **database**: PostgreSQL database service

## Environment Variables

You can customize the environment by setting these variables in a `.env` file:

- `POSTGRES_DB`: Database name (default: app)
- `POSTGRES_USER`: Database user (default: app)
- `POSTGRES_PASSWORD`: Database password (default: !ChangeMe!)
- `POSTGRES_VERSION`: PostgreSQL version (default: 16)

## Development Workflow

### Accessing Logs

```bash
docker compose logs -f php
```

### Running Symfony Commands

```bash
docker compose exec php php bin/console cache:clear
```

### Database Access

```bash
docker compose exec database psql -U app -d app
```

## Production Deployment

For production deployment, consider:

1. Setting secure passwords in environment variables
2. Enabling HTTPS by modifying the Caddyfile
3. Setting APP_ENV=prod and APP_DEBUG=0 in the environment

## FrankenPHP Configuration

FrankenPHP configuration is stored in `frankenphp/Caddyfile`. You can modify this file to change web server settings.

## Troubleshooting

- If you encounter permission issues, make sure the application files are readable by the web server
- For database connection issues, check that the DATABASE_URL environment variable is correctly set
