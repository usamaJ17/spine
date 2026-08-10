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

Everything stays in one folder — the database included. No extra folder to
create, nothing outside `public_html`.

1. **Push the repo.** `.env`, `data/*.sqlite*`, `data/secret.key` and
   `data/.installed` are git-ignored, so they never leave your machine.
   `data/.htaccess` **is** tracked and must stay that way — it's what blocks
   the database from the web.

2. **hPanel → Advanced → Git.** Connect the repo, branch `main`, install path
   `public_html`. Deploy. (Hostinger's Git deploy runs a `pull`, not a clean
   wipe, so it never touches files that aren't tracked in the repo — like
   `data/spine.sqlite` once setup.php creates it.)

3. **hPanel → PHP Configuration.** PHP 8.1 or newer. Confirm `pdo_sqlite` is on
   — it is by default on Hostinger.

4. **Create `.env` in `public_html`** (File Manager → New File):

   ```ini
   APP_NAME="Spine"
   APP_SECRET=paste_64_random_hex_characters_here
   ```

   `DB_DRIVER` and `DB_PATH` can be left out entirely — SQLite in `data/` is
   already the default. Generate the secret with
   `php -r "echo bin2hex(random_bytes(32));"`. Setting it here (rather than
   letting PHP generate one) means a future redeploy can't sign everyone out.

5. **`chmod 775 public_html/data`** so PHP can create the database file and
   the secret key.

6. Open `https://yourdomain.com/setup.php`, set your admin password, copy the
   links.

7. **Delete `setup.php` from the repository** and push. Deleting it only on
   the server does nothing; the next deploy restores it.

8. Open `/admin.php` and read the banner at the top. You want **no red
   warning** about the database being downloadable — that's a live check from
   your own browser, not a guess. If it's red, your host is ignoring
   `.htaccess` and that needs fixing before you send anyone a link.

### Redeploying later

`.env` and the database are both git-ignored, so pushing new code never
touches either. `lib/seed.php` only ever runs during setup, so nothing gets
re-seeded.

### Backups

Nothing backs up a SQLite file automatically. It's one file, so it's easy —
grab it over SFTP or File Manager occasionally, or on a schedule if you have
shell access:

```bash
cp public_html/data/spine.sqlite ~/backups/spine-$(date +%F).sqlite
```

## Sending the links

`/admin.php` → **Send on WhatsApp** next to each person. It pre-writes the
invitation, including the ask that actually gets people to engage:

> Your profile is already filled in from the session notes — please fix
> anything I got wrong, and add one thing you noticed about someone else.

Send them **privately**, one per person. A link in the group chat signs
everyone in as the same person.

## The Round

One question, thought or idea holds the floor at a time. Anyone can put one up
when the floor is free; everyone gets an email; it closes and the next person
can post.

**You cannot read anyone else's answer to the open round until you have written
your own.** Otherwise the first answer anchors everybody. Once it moves to
history it is readable by everyone — and still answerable, so whoever was slow
can add theirs late.

It leaves the floor whichever happens first:

- enough answers land (default 6), or
- it has held the floor long enough (default 4 days)

Three days after that (default day 7) everyone gets a digest email with the
question and every answer in full — the extra gap is what gives late answers
time to arrive.

The author can close early, and after 10 days anyone can, so a stalled round
never blocks the circle forever. All three numbers are in `/admin.php`.

### The daily job

`cron.php` does the closing and the digests. In hPanel → **Advanced → Cron
Jobs**, once a day:

```bash
curl -s "https://yourdomain.com/cron.php?key=YOUR_CRON_KEY"
```

Set `CRON_KEY` in `.env` first — without it the URL returns 404 to everyone.
Running it as PHP directly needs no key, since that is not a web request:

```bash
/usr/bin/php /home/uXXXXXXXX/public_html/cron.php
```

The same schedule also runs whenever anyone opens the app, so a cron job that
is never set up or quietly stops only makes things late — it never stalls the
circle.

## Email notifications

When someone creates a spark, the other person gets an email. Off unless
`MAIL_HOST` is set in `.env` — see the MAIL block in `.env.example`.
Hostinger: `smtp.hostinger.com`, port 465, full mailbox address as the username.

`/admin.php` has a **Send a test email** button. Use it before trusting any of
this; it reports the actual SMTP error if something is wrong.

Sending is best-effort and never blocks a spark: if the mail server is down, has
no address for that person, or they have muted notifications, the spark is still
created and the API just reports `notified: false`.

### Addresses

Addresses live in the database only — never in a tracked file, because this repo
may be public. Nobody but the person themselves and the admin can see an address;
`person_public()` has no email field, so the API cannot leak them.

Three ways to set them:

- **Bulk, from the browser** — `/admin.php` → *Import addresses in bulk*. Paste a
  To: line straight out of your email client. It shows you every match before
  saving anything.
- **Bulk, from the shell** — `php tools/emails.php import addresses.txt` to
  preview, then add `--apply`. Also `list`, `set "Full Name" a@b.com`, and
  `clear "Full Name"`.
- **One at a time** — the Edit form for each person in `/admin.php`.

Matching uses the display name when there is one, and otherwise guesses from the
local part of the address (`ghafoorilyas1@` → Ilyas Ghafoor). It refuses to guess
when two people score equally, so an address is never assigned to the wrong
person silently.

Anyone can change their own address and mute notifications from **Edit your
header** on their profile.

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
