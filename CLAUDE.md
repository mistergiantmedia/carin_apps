# Jouw Schoolplein

The person working on this project is **not a programmer**. Explain what you change in plain language, avoid jargon, and never ask them to edit code or run commands themselves when you can do it.

## How it runs
- Plain PHP + MySQL on an aaPanel server. No build step, no Node.js, no Composer.
- Live at https://carinreilman.com/apps/jouwschoolplein/
- **Deploy = push to `main`.** A GitHub webhook (`webhook/deployer.php`) pulls the code onto the server and then runs the database migrations. Always commit and push when a change is ready.
- `nextjs/` is the original ChatGPT prototype (Next.js + Supabase), kept only as a design reference. It does not run.

## Files
- `lib/app.php`: loaded by every page. Session, login (`require_login()`), CSRF (`csrf_field()` in every form, `is_post()` to handle it), current square (`current_school()`), permissions (`is_school_admin()`), Dutch date formatting, and the layout (`page_start()` / `page_end()`). Escape all output with `e()`.
- Pages: `index.php` (the square, tabs Plein/Agenda/Community/Meehelpen), `activiteit.php` (details, join with children, conversation), `activiteit-bewerken.php` (new/edit), `profiel.php` (details, children, password, delete account), `beheer.php` (approve activities, square settings, members, new squares), `register.php`, `login.php`, `logout.php`.
- `style.css`: all styling. Colours are the variables at the top.
- `db.php`: `db()` returns the shared PDO connection. Always use prepared statements for user input.
- `config.php`: DB password. Exists **only on the server**, gitignored (the repo is public). Never commit secrets.
- `install.php`: one-time setup (DB credentials + first admin). Locks itself once an admin exists.
- `dbtest.php`: shows connection status, tables and which migrations have run (site admins only).
- `migrate.php` + `migrations/`: database changes, see below.

## Database changes: always through a migration
Never change the database by hand and never tell the user to run SQL. To change the database structure:

1. Add a **new** file in `migrations/` with the next number: `002_create_activities.sql`, `003_add_phone_to_users.sql`, …
2. Write MySQL in it. End every statement with `;` at the end of a line. Lines starting with `--` are comments.
3. Commit and push. The webhook runs the new file once and records it in the `schema_migrations` table.
4. Check https://carinreilman.com/apps/jouwschoolplein/dbtest.php. It should say "Database is up-to-date".

Rules:
- **Never edit or rename a migration that has run successfully** (listed on `dbtest.php`). It won't run again. To change something, add a new migration.
- Never delete data (`DROP TABLE`, `DROP COLUMN`, `DELETE`) without asking the user first and explaining what will be lost.
- Use `utf8mb4` for new tables and `ENGINE=InnoDB`.
- The server runs **MySQL 5.7**: no MySQL 8-only features (no `ADD COLUMN IF NOT EXISTS`, CTEs, window functions, or enforced `CHECK`).
- If a migration fails, it is not recorded, so it runs again on the next push. MySQL can't undo half a migration, so prefer one change per file. A failed migration blocks all later ones, so fix *that* file (it's the one exception to the edit rule). Statements before the failing one did run, so remove them or make them safe to repeat (`CREATE TABLE IF NOT EXISTS`) before pushing.
- The deploy log is on the server at `webhook/deploy.log`.

## How the app works
- Several squares (`schools`) can exist. Each has its own settings, edited in `beheer.php`: who may post activities (everyone / after approval / admins only) and who may register (anyone / only with the school code).
- `users.role = 'ADMIN'` is the site-wide beheerder (manages every square); `school_memberships.role = 'ADMIN'` is beheerder of one square.
- Parents add their children once in their profile and tick which children come along to an activity.
- Only the organiser and beheerders see who joins; everyone else sees totals.

## Product rules (from the prototype)
- One open square: activities are open to the whole community, not per school group.
- Say "oud-leerlingen", not "alumni".
- No WhatsApp groups, likes, followers or private DMs; conversation belongs to an activity.
- Children are managed by their parent/guardian.
