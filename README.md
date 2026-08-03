# Spine

A small private web app for one circle of people: everyone has a profile,
other people add what they see in you, and any shared topic can become a
**spark** — a tiny public promise that two people will actually talk.

No accounts, no passwords. Each person gets a private link that signs them in
forever on that device.

---

## Requirements

- PHP 8.0 or newer
- `pdo_sqlite` (the default — nothing to install)
- Apache or LiteSpeed with `.htaccess`, or nginx with the rules below

## Configuration

Everything lives in a **`.env`** file next to `index.php`. It is git-ignored and
blocked from the web, so nothing server-specific or secret reaches the
repository. `lib/config.php` holds the defaults and is not meant to be edited.

```bash
cp .env.example .env
```

| Key | Meaning |
|---|---|
| `APP_NAME`, `TAGLINE` | Shown in the header and shared messages |
| `DB_DRIVER` | `sqlite` (default) or `mysql` |
| `DB_PATH` | Where the SQLite file lives. Relative paths resolve against the app folder |
| `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` | Only for `DB_DRIVER=mysql` |
| `BASE_URL` | Only if magic links come out with the wrong host |
| `COOKIE_DAYS` | How long someone stays signed in on a device |

## Install locally

```bash
cp .env.example .env
php -S localhost:8000
```

Open `http://localhost:8000/setup.php`, set an admin password, done. The 8
profiles from the July session are created automatically.

---

## Deploying to Hostinger from GitHub

The one thing that matters: **`DB_PATH` must point outside `public_html`.**

Your SQLite file would otherwise sit inside the folder git deploys into, where
a clean checkout can delete it and only `.htaccess` keeps it private. Moved one
level up, it has no URL at all and no deployment can touch it. Same database,
same simplicity — just kept somewhere git and the web server can't reach.

1. **Push the repo.** `.env`, `data/*.sqlite*`, `data/secret.key` and
   `data/.installed` are git-ignored. `data/.htaccess` **is** tracked and must
   stay that way.

2. **hPanel → Advanced → Git.** Connect the repo, branch `main`, install path
   `public_html`. Deploy.

3. **hPanel → PHP Configuration.** PHP 8.1 or newer. Confirm `pdo_sqlite` is on
   — it is by default on Hostinger.

4. **Make the data folder**, next to `public_html`, not inside it. In File
   Manager go up one level from `public_html` and create `spine-data`. Over SSH:

   ```bash
   mkdir -p ~/spine-data && chmod 755 ~/spine-data
   ```

5. **Create `.env` in `public_html`** (File Manager → New File). Use your real
   home path — hPanel shows it, it looks like `/home/u123456789`:

   ```ini
   APP_NAME="Spine"
   DB_DRIVER=sqlite
   DB_PATH=/home/u123456789/spine-data/spine.sqlite
   APP_SECRET=paste_64_random_hex_characters_here
   ```

   Generate the secret with `php -r "echo bin2hex(random_bytes(32));"`, or any
   long random string. Setting it here means a deploy that replaces the whole
   folder cannot sign everyone out.

6. **`chmod 775 public_html/data`** — only needed if you skipped `APP_SECRET`
   above, in which case PHP has to write `data/secret.key` itself.

7. Open `https://yourdomain.com/setup.php`, set your admin password, copy the
   links. The page confirms which database file it is using — check it says your
   `spine-data` path.

8. **Delete `setup.php` from the repository** and push. Deleting it only on the
   server does nothing; the next deploy restores it.

9. Open `/admin.php` and read the banners at the top. You want a green
   *"Database is stored outside the web root"* and **no red ones**. That is a
   live check from your browser, not a guess.

### Redeploying later

Your database is outside the deploy folder and `.env` is not in git, so pushing
new code cannot touch either. `lib/seed.php` only ever runs during setup, so
nothing is re-seeded.

### Backups

Nothing backs up a SQLite file automatically. It is one file, so it is easy:

```bash
cp ~/spine-data/spine.sqlite ~/backups/spine-$(date +%F).sqlite
```

Worth doing before each deploy, and worth a weekly cron once people have put
real things in there.

## Sending the links

`/admin.php` → **Send on WhatsApp** next to each person. It pre-writes the
invitation, including the ask that actually gets people to engage:

> Your profile is already filled in from the session notes — please fix
> anything I got wrong, and add one thing you noticed about someone else.

Send them **privately**, one per person. A link in the group chat signs
everyone in as the same person.

## The explainer page

`#/how` — what the platform is for, the four profile boxes, sparks, projects,
and the two practical notes (no password, everything is public to the group).

It opens **automatically on someone's first visit**, then never again. After
that it lives behind the **?** in the top right and a link at the bottom of the
home screen. The "seen it" flag is per browser (`localStorage`), so nobody who
switches device gets nagged twice on the same one.

Edit the copy in `viewHow()` in `assets/app.js`.

## Admin

`/admin.php`, password from setup.

| Action | What it does |
|---|---|
| **Edit** | Name, emoji, headline, city, order, hide from the app |
| **New link** | Fresh URL. The old one dies immediately and their devices sign out |
| **Sign out their devices** | Same URL, but every signed-in browser is kicked |
| **Delete** | Removes the person and everything they added |
| **Change admin password** | — |
| **Clear sparks & activity** | Wipes the feed, keeps profiles |

## How identity works

- Each person has a secret token; their link is `/?u=TOKEN`.
- Opening it sets a signed cookie (`id.epoch.hmac`) for a year.
- "Switch person" sets that cookie server-side without revealing anyone's token.
- Admin bumps `cookie_epoch` to invalidate a person's devices, or mints a new
  token to kill the old URL.

There is **no real authentication** — anyone with the site URL can act as
anyone via "switch person". That is deliberate: it keeps the app frictionless
for a small trusted group. Everything is public and attributed by name, which
is what keeps people honest. Reconsider this above ~30 people.

Because of that: the site is `noindex`, and **no phone numbers or email
addresses are stored**. Keep contact details in WhatsApp.

## Layout

```
.env             your settings — git-ignored, blocked from the web
.env.example     the template, committed
index.php        the app (single page, hash routing)
api.php          JSON API — every action lands here as ?a=<action>
admin.php        admin console
setup.php        one-time installer (delete from the repo after use)
assets/          app.css, app.js, admin.js — no frameworks, no build step
lib/             config, db, helpers, model, seed data
data/            secret key, plus the SQLite file if DB_PATH is left at default
```

Assets are cache-busted by file mtime, so edits show up immediately.

### nginx

`.htaccess` does nothing on nginx. Add this to the server block instead:

```nginx
location ~ ^/(data|lib)/  { deny all; return 404; }
location ~ /\.env         { deny all; return 404; }
```

## Editing the seeded people

`lib/seed.php` only runs once, at install. After that, change people in
`/admin.php` and their tags inside the app.

## What is deliberately not here

No in-app chat (the group already has WhatsApp — the app feeds it), no
notifications, no accounts, no matching AI. Sparks are found with plain SQL
set intersections in `lib/model.php`.
