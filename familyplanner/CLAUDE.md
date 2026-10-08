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
  oppas, fotos, instellingen, dbtest, ics (calendar subscription by secret token), google-auth + google-callback.

## Google Agenda koppeling (lib/gcal.php)
- Per account (Instellingen → 📆 Google Agenda): OAuth via google-auth.php → google-callback.php, scope
  `calendar.app.created` (only calendars we made). We create a calendar "Familie Planner" (or "· Kaila" when the
  account chose one member) in their Google account. Tokens + sync state are columns on fp_users (google_*).
- The OAuth client (client id/secret) is app-wide: shared table `fp_app_settings`, set by site admins in beheer.php
  (which also shows the redirect URI for the Google Cloud Console). Consent screen must be "In production",
  otherwise refresh tokens die after 7 days.
- Push = diff: `gcal_wanted()` builds every occurrence (−7 days … +12 months, same text as ics.php via
  `event_feed_text()`) + birthdays, keyed e12 / e12-2026-10-08 / bm3-2026-05-01; `fp_gcal_events` (per family,
  per user) holds what was sent (google_id, payload hash, times). Only differences go to Google, soonest first,
  max GCAL_BUDGET seconds per run; the rest next run (fp_users.google_pending). No save path needs a hook.
- Pull = Google sync token (`gcal_take_over()`): a planner event moved/deleted in Google → `move_event()` /
  `delete_event()` with scope 'one'; title/place/notes changed in Google (compared with sent_summary/
  sent_location/sent_description, migration 024) → `gcal_apply_text()` (a leading emoji becomes the event emoji;
  an occurrence of a repeating event is detached first). The row is re-keyed to the (detached) event. Birthdays are
  put back. Our own pushes come back in the pull too and are recognised by unchanged times and text.
- Events made in Google (no row, no `extendedProperties.private.fp` which we put on everything we push) are
  imported by `gcal_import()`: type OTHER, member = the account's google_member_id. A Google series is mapped by
  `gcal_rules()` (DAILY, WEEKDAYS, WEEKLY/BIWEEKLY incl. several BYDAYs = one event per day, MONTHLY, YEARLY,
  UNTIL/COUNT) and then deleted in Google (the planner pushes its own occurrences); unsupported rules stay in
  Google only (error shown in Instellingen).
- `gcal_schedule()` (require_login + api.php) syncs after the response (`fastcgi_finish_request`): always after a
  POST, otherwise when an account is due (GCAL_THROTTLE). MySQL GET_LOCK per account. Calendar deleted in Google
  = disconnected. fp_gcal_events is deliberately not in the api stamp `$watch` list.

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
- Regular week of a contact (`lib/week.php`): `fp_contact_week` (fixed activities per ISO weekday: BSO, sport,
  club…) and `fp_contact_days` (YES/NO can play). Used on contact.php, vriendjes.php, clubjes.php and for the
  availability warnings in the event editor (`api.php?a=availability`). Our own children's clubs are not stored
  separately: they are their repeating SPORT/ACTIVITY/OTHER events (`member_clubs()`).
- `netwerk.php` + `network.js` draw all of this as a relations web (data from `api.php?a=graph`).
- The auto-refresh fingerprint (`api.php?a=stamp`) lists watched tables in `$watch`; add new tables there.

## Days off (lib/freedays.php, vrijedagen.php)
- Event types STUDYDAY, STUDYPM (afternoon off, start time = when school ends) and SCHOOLHOLIDAY (for the children).
  Repeating SCHOOL events are dropped on those days and on official public holidays, and end early on a
  study afternoon (`apply_school_free()` in lib/events.php).
- Public holidays and fun days are computed (`load_feasts()`, Easter algorithm), not stored.
- School holidays can be imported per region (`fp_settings.school_region`) from the Rijksoverheid open data API.
- The children's school is Pieterskerkhof (Utrecht, regio Midden); migration 014 holds its 2026-2027 calendar
  (school runs 8:30-14:00). Add a new migration for the next school year from `fp_settings.school_url`.

## Several families (lib/database.php FamilyPDO, lib/families.php)
- `fp_families` (PENDING → ACTIVE after Carin/René approve in beheer.php; REJECTED/BLOCKED) and
  `fp_users.family_id` / `is_admin`. Registration: aanmelden.php. Login by e-mail; by first name only for family 1.
- Family 1 uses the original fp_ tables; family N gets empty copies fp{N}_… on approval (cloned from family 1's
  structure). All page SQL keeps writing fp_…: FamilyPDO rewrites it to the logged-in family (use_family()).
  Shared tables (never rewritten): fp_users, fp_families, fp_schema_migrations.
- Migrations: schema statements on family tables are repeated for every approved family automatically;
  data statements (INSERT/UPDATE) only touch family 1.
- Never query fp_users without `family_id` when listing accounts. foto.php only serves photos referenced by the
  current family's tables.

### Friend families (lib/friendfam.php, vriendgezinnen.php)
- Shared tables (never prefix-rewritten, listed in SHARED_TABLES + the rewrite_sql regex): fp_family_links
  (friendship, family_a < family_b, PENDING/ACCEPTED), fp_family_shares (owner_family → friend_family: PROFILE,
  BIRTHDAYS, CLUBS, PLAYDATES, EVENTS), fp_contact_links (our contact ↔ member in the friend family).
  Per family: fp_event_shares (events explicitly shared, needs EVENTS) and fp_contact_week.source = 'LINK'.
- Nothing is shared by default. Reading another family's tables only via with_family($fid, fn), and only after
  checking shares_from($fid, current_family_id()). Never call members()/member() inside with_family (static cache).
- sync_links() (from require_login, every 15 min per session) copies photo/birthday/clubs of linked members into
  our contacts. shared_events() returns friend playdates with our linked kids + shared events, read-only
  (id 'x{fid}-{id}', shared=true, owner_family); api events, index, gezin, persoon and kid boards include them
  (with_shared_events, event_link). Calendar: readOnly → click only, own popover.
- Photos may be referenced by several families: delete_photo() skips files other families still use.
- Family photo: fp_families.photo (set in Instellingen or at registration). family_avatar() renders it;
  foto.php serves it to that family, families with a friendship/invitation link, and site admins.
- Gezinnen (lib/gezinnen.php): fp_households is "Gezin" in the UI (one family at one address, photo,
  linked_family → friend family). Groups of type FAMILY are "Grote familie" (grandparents etc.). FAMILY groups
  without any of our members were merged into gezinnen once per planner family (merge_family_groups, setting
  gezinnen_v1). Don't reintroduce "huishouden/adressen" wording.
- Weather (lib/weather.php): Open-Meteo (no key), place = setting 'city', geocode + forecast cached in
  fp_settings (value is TEXT since migration 020). weather_forecast() never throws.
