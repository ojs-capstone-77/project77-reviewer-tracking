# OJS 3.4.x local development

## Start

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

## Access

- OJS: `http://localhost:8080`
- phpMyAdmin: `http://localhost:8081`
- Mailpit: `http://localhost:8025`

## Database

- Host: `127.0.0.1`
- Port: `3306`
- User: `pkp`
- Password: `changeMePlease`
- Database: `pkp`

## First run

If the app container still restarts after a clean rebuild, remove any old volumes and recreate:

```bash
docker compose down -v
docker compose up -d --build
```

## Debugging

Use VS Code launch configuration **Listen for PHP Xdebug**.
Trigger with `?XDEBUG_TRIGGER=1`.
