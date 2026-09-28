always push to git when ready

the user making jouwschoolplein on my claude account does not know a single thing about programming.
all prompts in Dutch are the user with no programming knowledge
all prompts in English are from a user with programming knowledge

We don't have local PHP so push to test, we are in demo/concept mode not live

# Familie Planner

Family planner for Carin (mama), Rene (papa), Kaila (8) and Bodi (5): calendar, playdates, birthdays,
address book, smoelenboek, vriendjesbingo, vriendjesboek, tasks (wie doet wat), outings/parties,
ideas & "attent zijn", babysitting and school photos. UI text is Dutch.

## How it runs
- Plain PHP (keep it **PHP 7.4 compatible**) + MySQL 5.7 on aaPanel. No build step. Vanilla JS.
- Live at https://carinreilman.com/apps/familyplanner/ . Deploy = push to `main`; `webhook/deployer.php`
  pulls and then runs every app's `migrate.php` (each returns a runner function).
- `install.php`: DB credentials → `config.php` (returns an array; gitignored), then the first account.

## Database: shared with jouwschoolplein
The family planner uses the **same MySQL database as jouwschoolplein**. Every table of this app
starts with `fp_` (including `fp_schema_migrations`), and every constraint name starts with `fp_`.
Never create or touch a table without the `fp_` prefix: those belong to jouwschoolplein.
`lib/database.php` is namespaced (`Familie\`) because the deployer loads all apps' migration code
into one PHP process; don't add global functions/constants to it.

Changes go through a new numbered file in `migrations/` (never edit one that has run; see `dbtest.php`).

## Files
- `lib/app.php` bootstrap: session, login, CSRF (`csrf_field()`, `is_post()`; JSON calls send `X-CSRF-Token`),
  `members()`, `avatar()`, date helpers, layout (`page_start()`, `page_end()`, `page_header()`), form helpers.
- `lib/constants.php` event types, relations, idea categories… `ASSET_VERSION`: bump when CSS/JS change.
- `lib/events.php` calendar core: `load_events()` expands repeating events into occurrences (`occ` = date),
  `load_birthdays()` virtual birthday items, `normalise_event()`/`save_event()`, `move_event()`,
  `delete_event()` (scope one/future/all via `fp_event_exceptions`), `set_event_done()` (`fp_event_done` for repeats).
- `lib/tasks.php`, `lib/friends.php` (playdate stats, bingo, friend book), `lib/suggestions.php` ("attent zijn"),
  `lib/views.php` (kid week board, month table, year grid), `lib/upload.php` (photos → `uploads/`, served by `foto.php`),
  `lib/demo.php` (example data with `is_demo = 1`, load/remove in Instellingen).
- `api.php` JSON for the calendar and editor. `app.js` shared (editor dialog `FP.openEditor`, contact picker,
  `data-new-event` buttons). `calendar.js` the Google-Calendar-like agenda (drag/resize/create, touch = long-press).
- Pages: index (Vandaag), agenda, event (details/checklist/guests), gezin, persoon (kid week board / parent month-year),
  vriendjes, taken, verjaardagen, mensen + contact + huishouden (address book), smoelenboek, activiteiten, ideeen,
  oppas, fotos, instellingen, dbtest, ics (calendar subscription by secret token).

## Local testing (optional)
Portable PHP 7.4 + MariaDB + puppeteer-core can be used from a scratchpad to lint (`php -l`), run the app with
`php -S` and screenshot pages; nothing of that is in the repo.

## Old browsers (LG webOS 3.5 TV = Chrome 38)
The family's LG 55SJ850V (2017) runs Chrome 38: no CSS variables, grid, flex gap or ES2015+.
Pages load `app.js`/`photo.js`/page scripts as `type="module"` (modern browsers) and the ES5 builds in
`legacy/` with `nomodule` (old browsers), plus `legacy/style.css` (variables and color-mix resolved,
grid → flexbox) via a `nomodule` document.write in the head.
**After changing style.css, app.js, calendar.js or photo.js, rebuild and commit `legacy/`:**
`cd _legacy && npm install --ignore-scripts && node build.js` (needs Node; see `_legacy/build.js`).
Hand-written ES5 helpers for old browsers are in `_legacy/dom.js`, extra layout rules in `_legacy/extra.css`.

## People and relations (data model)
- `fp_members`: our family (Carin, Rene, Kaila, Bodi). `fp_users` = logins, linked via `member_id`.
- `fp_contacts`: everyone else (adults and children). `relation` is one main label (Vriendje, Familie, Oppas…).
- `fp_households`: people at one address (a friend's family); `fp_contacts.household_id`.
- `fp_contact_members`: "vriend van" a family member.
- `fp_groups` (type FAMILY, SCHOOL, SPORT, CLUB, WORK, NEIGHBORHOOD, FRIENDS, OTHER) with
  `fp_group_members` (our family) and `fp_group_contacts` (address book), both with an optional `role`.
  Anyone can be in any number of groups. School classes are SCHOOL groups (smoelenboek = groepen.php);
  the old `fp_classes` / `fp_class_contacts` were copied into groups by migration 010 and are no longer used.
- `netwerk.php` + `network.js` draw all of this as a relations web (data from `api.php?a=graph`).
- The auto-refresh fingerprint (`api.php?a=stamp`) lists watched tables in `$watch`; add new tables there.
