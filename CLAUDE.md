# Jouw Schoolplein

The person working on this project is **not a programmer**. Explain what you change in plain language, avoid jargon, and never ask them to edit code or run commands themselves when you can do it.

## How it runs
- Plain PHP + MySQL on an aaPanel server. No build step, no Node.js, no Composer.
- Live at https://carinreilman.com/apps/jouwschoolplein/
- **Deploy = push to `main`.** A GitHub webhook (`webhook/deployer.php`) pulls the code onto the server and then runs the database migrations. Always commit and push when a change is ready.
- `nextjs/` is the original ChatGPT prototype (Next.js + Supabase), kept only as a design reference. It does not run.

## Files
- `db.php`: `db()` returns the shared PDO connection. Always use prepared statements for user input.
- `config.php`: DB password. Exists **only on the server**, gitignored (the repo is public). Never commit secrets.
- `install.php`: one-time setup (DB credentials + first admin). Locks itself once an admin exists.
- `dbtest.php`: shows connection status, tables and which migrations have run.
- `migrate.php` + `migrations/`: database changes, see below.

## Database changes: always through a migration
Never change the database by hand and never tell the user to run SQL. To change the database structure:

1. Add a **new** file in `migrations/` with the next number: `002_create_activities.sql`, `003_add_phone_to_users.sql`, …
2. Write MySQL in it. End every statement with `;` at the end of a line. Lines starting with `--` are comments.
3. Commit and push. The webhook runs the new file once and records it in the `schema_migrations` table.
4. Check https://carinreilman.com/apps/jouwschoolplein/dbtest.php. It should say "Database is up-to-date".

Rules:
- **Never edit or rename a migration that has already been pushed.** It has already run on the server and won't run again. To fix a mistake, add a new migration.
- Never delete data (`DROP TABLE`, `DROP COLUMN`, `DELETE`) without asking the user first and explaining what will be lost.
- Use `utf8mb4` for new tables and `ENGINE=InnoDB`.
- If a migration fails, it is not recorded, so it runs again on the next push. MySQL can't undo half a migration, so prefer one change per file. If a failed migration partly ran, make the file safe to rerun (`IF NOT EXISTS`) before pushing the fix.
- The deploy log is on the server at `webhook/deploy.log`.

## Product rules (from the prototype)
- One open square: activities are open to the whole community, not per school group.
- Say "oud-leerlingen", not "alumni".
- No WhatsApp groups, likes, followers or private DMs; conversation belongs to an activity.
- Children are managed by their parent/guardian.
