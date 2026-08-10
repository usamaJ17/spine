/* Spine — admin console. */

(() => {
'use strict';

const app = document.getElementById('app');
let D = { people: [], stats: {} };
let busy = false;

const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function toast(m) {
  document.querySelectorAll('.toast').forEach(t => t.remove());
  const el = document.createElement('div');
  el.className = 'toast'; el.textContent = m;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 2600);
}

async function api(action, data) {
  const opts = data
    ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Spine': '1' }, body: JSON.stringify(data) }
    : {};
  const res  = await fetch(`api.php?a=${action}`, opts);
  const json = await res.json();
  if (!json.ok) throw new Error(json.error || 'Failed');
  return json;
}

async function guard(fn) {
  if (busy) return;
  busy = true;
  try { await fn(); } catch (e) { toast(e.message); } finally { busy = false; }
}

function modal(html) {
  close();
  const w = document.createElement('div');
  w.className = 'scrim';
  w.innerHTML = `<div class="sheet">${html}</div>`;
  w.addEventListener('click', e => { if (e.target === w) close(); });
  document.body.appendChild(w);
  const f = w.querySelector('[autofocus]');
  if (f) setTimeout(() => f.focus(), 60);
}
function close() { document.querySelectorAll('.scrim').forEach(e => e.remove()); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

/**
 * Confirm the host actually honours .htaccess. If the SQLite file can be
 * downloaded over HTTP, every profile is public — the admin needs to know
 * immediately, not eventually.
 */
async function securityCheck() {
  const warn = [];

  // The SQLite file: exposed only if it comes back with its real header.
  if (D.probe) {
    try {
      const r = await fetch(D.probe, { cache: 'no-store' });
      if (r.ok && (await r.text()).startsWith('SQLite format 3')) {
        warn.push({
          level: 'danger',
          t: 'Your database is downloadable',
          s: `Anyone can fetch ${D.probe} over the web and read every profile. This host `
           + `is ignoring .htaccess. Expected on PHP's built-in dev server; on real hosting `
           + `it must be fixed — check hPanel for an .htaccess / mod_rewrite setting, or `
           + `contact support. See README.md.`,
        });
      }
    } catch { /* blocked, which is the good outcome */ }
  }

  // .env: exposed if it comes back looking like KEY=value.
  if (D.env) {
    try {
      const r = await fetch('.env', { cache: 'no-store' });
      if (r.ok && /^\s*[A-Z_]{2,}\s*=/m.test(await r.text())) {
        warn.push({
          level: 'danger',
          t: 'Your .env file is readable over the web',
          s: 'It is being served as plain text. Your host is ignoring .htaccess — nothing '
           + 'in .env can be treated as private until that is fixed.',
        });
      }
    } catch { /* blocked, which is the good outcome */ }
  }

  if (D.setup) {
    warn.push({
      level: 'note',
      t: 'setup.php is still here',
      s: 'It is locked and refuses to run twice, so this is tidiness rather than danger. '
       + 'If you deploy from git, delete it from the repository — otherwise every deploy puts it back.',
    });
  }

  if (!warn.length) return;
  const box = document.getElementById('warnings');
  if (!box) return;
  const skin = {
    danger: ['⚠️', 'border-color:rgba(248,113,113,.5);background:rgba(248,113,113,.08)'],
    good:   ['✅', 'border-color:rgba(74,222,128,.45);background:rgba(74,222,128,.07)'],
    note:   ['ℹ️', ''],
  };
  warn.sort((a, b) => (a.level === 'danger' ? -1 : 0) - (b.level === 'danger' ? -1 : 0));
  box.innerHTML = warn.map(w => {
    const [icon, style] = skin[w.level] || skin.note;
    return `<div class="nudge mt14" style="${style}">
      <span class="ni">${icon}</span>
      <div class="grow"><div class="t">${esc(w.t)}</div><div class="s">${esc(w.s)}</div></div>
    </div>`;
  }).join('');
}

function render() {
  const s = D.stats;
  app.innerHTML = `
    <div id="warnings"></div>
    <div class="statstrip mt14">
      <div><div class="v">${s.people}</div><div class="k">People</div></div>
      <div><div class="v">${s.sparks}</div><div class="k">Sparks</div></div>
      <div><div class="v">${s.done}</div><div class="k">Talked</div></div>
      <div><div class="v">${s.projects}</div><div class="k">Projects</div></div>
    </div>

    <div class="sec-head"><h2>People</h2><span class="rest"></span>
      <button data-add>+ Add person</button></div>

    ${D.people.map(p => `<div class="card" style="padding:14px">
      <div class="row gap10">
        <div class="av sm" style="--h:${p.hue}">${esc(p.emoji || p.name[0] || '?')}</div>
        <div class="grow" style="min-width:0">
          <div class="row gap6">
            <b class="ellip">${esc(p.name)}</b>
            ${p.is_admin ? '<span class="pill done">admin</span>' : ''}
            ${p.active ? '' : '<span class="pill open">hidden</span>'}
          </div>
          <div class="faint tiny ellip">${esc(p.headline || 'no headline')} · ${p.tags} tags</div>
        </div>
        <button class="btn sm" data-edit="${p.id}">Edit</button>
      </div>

      <div class="linkbox">
        <code>${esc(p.link)}</code>
        <button class="btn sm" data-copy="${esc(p.link)}">Copy</button>
      </div>

      <div class="row gap6 wrapf mt8">
        <button class="btn sm ghost" data-wa="${esc(p.link)}|${esc(p.name)}">Send on WhatsApp</button>
        <button class="btn sm ghost" data-newtoken="${p.id}">New link</button>
        <button class="btn sm ghost" data-reset="${p.id}">Sign out their devices</button>
        <span class="grow"></span>
        <button class="btn sm ghost danger" data-del="${p.id}|${esc(p.name)}">Delete</button>
      </div>
    </div>`).join('')}

    <div class="sec-head"><h2>Email notifications</h2></div>
    <div class="card">
      ${D.mail
        ? `<div class="row gap10"><span class="pill done">on</span>
             <span class="small muted ellip">${esc(D.mailhost)}</span></div>
           <p class="tiny faint mt8">People with an email address and notifications switched on get
             a message whenever someone starts a spark with them.</p>
           <button class="btn full mt14" data-testmail>Send a test email</button>
           <button class="btn full mt8" data-importmail>Import addresses in bulk</button>`
        : `<div class="row gap10"><span class="pill open">off</span>
             <span class="small muted">No MAIL_HOST in .env</span></div>
           <p class="tiny faint mt8">Add the MAIL_* block from <code>.env.example</code> to your
             <code>.env</code> to switch spark notifications on.</p>`}
      <div class="row gap10 mt14 tiny faint">
        <span>${D.people.filter(p => p.email).length} of ${D.people.length} have an address</span>
      </div>
    </div>

    <div class="sec-head"><h2>The Round</h2></div>
    <div class="card">
      <form data-roundsettings>
        <label class="lbl">Answers before it opens up</label>
        <input class="field" type="number" name="threshold" min="2" max="50"
               value="${(D.rounds || {}).threshold || 6}">
        <label class="lbl mt14">Days it holds the floor</label>
        <input class="field" type="number" name="days" min="1" max="60"
               value="${(D.rounds || {}).days || 4}">
        <label class="lbl mt14">Days until the digest email</label>
        <input class="field" type="number" name="digest_days" min="1" max="90"
               value="${(D.rounds || {}).digest_days || 7}">
        <p class="tiny faint mt8">Whichever comes first — enough answers, or the clock —
          hands the floor to the next person. The digest with everyone's answers goes out later,
          so people who were slow still get counted.</p>
        <button class="btn full mt14">Save</button>
      </form>
      <div class="row gap10 mt14">
        <span class="pill ${(D.rounds || {}).cron ? 'done' : 'open'}">${(D.rounds || {}).cron ? 'cron key set' : 'no cron key'}</span>
        <span class="tiny faint grow">${(D.rounds || {}).cron
          ? 'cron.php is reachable — point a daily job at it'
          : 'Add CRON_KEY to .env, then a daily job in hPanel'}</span>
      </div>
    </div>

    <div class="sec-head"><h2>Maintenance</h2></div>
    <div class="card">
      <button class="btn full" data-pw>Change admin password</button>
      <button class="btn full mt8 danger" data-wipe>Clear all sparks &amp; activity</button>
      <p class="tiny faint mt14">“New link” invalidates the old URL and signs out their devices.
        “Sign out their devices” keeps the same URL but boots every browser currently signed in as them.</p>
    </div>`;
}

function personForm(p) {
  const v = k => esc(p ? (p[k] ?? '') : '');
  modal(`<h3>${p ? 'Edit ' + esc(p.name) : 'Add a person'}</h3>
    <form data-personform="${p ? p.id : ''}" class="mt20">
      <label class="lbl">Name</label>
      <input class="field" name="name" autofocus required maxlength="120" value="${v('name')}">
      <label class="lbl mt14">Emoji</label>
      <input class="field" name="emoji" maxlength="4" value="${v('emoji')}" placeholder="🧠">
      <label class="lbl mt14">Headline</label>
      <input class="field" name="headline" maxlength="160" value="${v('headline')}">
      <label class="lbl mt14">City</label>
      <input class="field" name="city" maxlength="60" value="${v('city')}">
      <label class="lbl mt14">Email ${D.mail ? '' : '<span class="faint">(mail not configured)</span>'}</label>
      <input class="field" type="email" name="email" maxlength="190" value="${v('email')}"
        placeholder="them@example.com">
      <p class="faint tiny mt8">Used only for spark notifications. Never shown to anyone else.</p>
      <label class="lbl mt14">Sort order</label>
      <input class="field" name="sort" type="number" value="${p ? p.sort : 999}">
      <label class="row gap10 mt14 small muted" style="cursor:pointer">
        <input type="checkbox" name="notify" ${!p || p.notify ? 'checked' : ''}> Send them spark emails
      </label>
      <label class="row gap10 mt8 small muted" style="cursor:pointer">
        <input type="checkbox" name="is_admin" ${p && p.is_admin ? 'checked' : ''}> Mark as organiser
      </label>
      <label class="row gap10 mt8 small muted" style="cursor:pointer">
        <input type="checkbox" name="active" ${!p || p.active ? 'checked' : ''}> Visible in the app
      </label>
      <button class="btn primary full mt20">Save</button>
      <button type="button" class="btn ghost full mt8" data-close>Cancel</button>
    </form>`);
}

document.addEventListener('click', async ev => {
  const t = ev.target;

  if (t.closest('[data-close]')) return close();

  const c = t.closest('[data-copy]');
  if (c) {
    try { await navigator.clipboard.writeText(c.dataset.copy); toast('Copied'); }
    catch {
      const ta = document.createElement('textarea');
      ta.value = c.dataset.copy; document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); ta.remove(); toast('Copied');
    }
    return;
  }

  const wa = t.closest('[data-wa]');
  if (wa) {
    const [link, name] = wa.dataset.wa.split('|');
    const msg = `Salam ${name.split(' ')[0]} — this is your personal link for our circle.\n`
      + `Open it once and you're in, no password:\n${link}\n\n`
      + `Your profile is already filled in from the session notes — please fix anything I got wrong, `
      + `and add one thing you noticed about someone else.`;
    window.open('https://wa.me/?text=' + encodeURIComponent(msg), '_blank', 'noopener');
    return;
  }

  if (t.closest('[data-add]')) return personForm(null);

  const ed = t.closest('[data-edit]');
  if (ed) return personForm(D.people.find(p => p.id === +ed.dataset.edit));

  const nt = t.closest('[data-newtoken]');
  if (nt) return guard(async () => {
    if (!confirm('Make a new link? The old one stops working immediately.')) return;
    await api('admin_new_token', { id: +nt.dataset.newtoken });
    await load(); toast('New link created');
  });

  const rs = t.closest('[data-reset]');
  if (rs) return guard(async () => {
    if (!confirm('Sign out every device currently signed in as this person?')) return;
    await api('admin_reset_cookies', { id: +rs.dataset.reset });
    toast('Their devices are signed out');
  });

  const dl = t.closest('[data-del]');
  if (dl) {
    const [id, name] = dl.dataset.del.split('|');
    return guard(async () => {
      if (!confirm(`Delete ${name} and everything they added? This cannot be undone.`)) return;
      await api('admin_delete_person', { id: +id });
      await load(); toast('Deleted');
    });
  }

  if (t.closest('[data-pw]')) {
    return modal(`<h3>Change admin password</h3>
      <form data-pwform class="mt20">
        <input class="field" type="password" name="password" autofocus required minlength="6"
               placeholder="New password (min 6)">
        <button class="btn primary full mt14">Save</button>
        <button type="button" class="btn ghost full mt8" data-close>Cancel</button>
      </form>`);
  }

  if (t.closest('[data-importmail]')) {
    return modal(`<h3>Import addresses</h3>
      <p class="small muted">Paste a To: line straight out of your email client —
        <code>Name &lt;a@b.com&gt;, c@d.com</code> — or one per line. Nothing is saved
        until you have seen what it matched.</p>
      <form data-mailplanform class="mt20">
        <textarea class="field" name="raw" rows="7" autofocus required
          placeholder="Zeeshan AHMED &lt;zeeshan@example.org&gt;,&#10;someone@gmail.com,"></textarea>
        <button class="btn primary full mt14">Show me the matches</button>
        <button type="button" class="btn ghost full mt8" data-close>Cancel</button>
      </form>`);
  }

  const applyBtn = t.closest('[data-mailapply]');
  if (applyBtn) return guard(async () => {
    const j = await api('admin_email_apply', { raw: applyBtn.dataset.mailapply });
    close(); await load();
    toast(`Updated ${j.updated} ${j.updated === 1 ? 'person' : 'people'}`);
  });

  if (t.closest('[data-testmail]')) {
    return modal(`<h3>Send a test email</h3>
      <p class="small muted">Proves the SMTP settings in your .env actually work.</p>
      <form data-testmailform class="mt20">
        <input class="field" type="email" name="email" autofocus required
               placeholder="where should it go?">
        <button class="btn primary full mt14">Send it</button>
        <button type="button" class="btn ghost full mt8" data-close>Cancel</button>
      </form>`);
  }

  if (t.closest('[data-wipe]')) return guard(async () => {
    if (!confirm('Delete every spark and all activity? Profiles stay.')) return;
    await api('admin_wipe_demo', {});
    await load(); toast('Cleared');
  });
});

document.addEventListener('submit', async ev => {
  const f = ev.target;
  const fd = new FormData(f);
  const val = k => (fd.get(k) || '').toString().trim();

  if (f.dataset.personform !== undefined) {
    ev.preventDefault();
    const payload = {
      name: val('name'), emoji: val('emoji'), headline: val('headline'), city: val('city'),
      email: val('email'),
      sort: +val('sort') || 0,
      notify: fd.get('notify') ? 1 : 0,
      is_admin: fd.get('is_admin') ? 1 : 0,
      active: fd.get('active') ? 1 : '0',
    };
    const id = f.dataset.personform;
    return guard(async () => {
      if (id) await api('admin_save_person', { ...payload, id: +id });
      else    await api('admin_add_person', payload);
      close(); await load(); toast('Saved');
    });
  }

  if (f.dataset.roundsettings !== undefined) {
    ev.preventDefault();
    return guard(async () => {
      await api('admin_set_rounds', {
        threshold: +val('threshold'), days: +val('days'), digest_days: +val('digest_days'),
      });
      await load(); toast('Saved');
    });
  }

  if (f.dataset.mailplanform !== undefined) {
    ev.preventDefault();
    const raw = val('raw');
    return guard(async () => {
      const p = await api('admin_email_plan', { raw });
      const row = (left, right, cls = '') =>
        `<div class="row gap10 small ${cls}" style="padding:5px 0"><span class="grow ellip">${esc(left)}</span>
         <span class="faint ellip" style="max-width:55%">${esc(right)}</span></div>`;

      let body = '';
      if (p.matched.length) {
        body += `<div class="kt" style="--c:var(--good)">Will set (${p.matched.length})</div>`
          + p.matched.map(m => row(m.name, m.email + (m.current && m.current !== m.email
              ? ' — replaces ' + m.current : ''))).join('');
      }
      if (p.ambiguous.length) {
        body += `<div class="kt mt14" style="--c:var(--build)">Too close to call — set by hand</div>`
          + p.ambiguous.map(a => row(a.email, 'could be ' + (a.candidates || []).join(' / '))).join('');
      }
      if (p.unmatched.length) {
        body += `<div class="kt mt14" style="--c:var(--life)">Nobody in the circle matches</div>`
          + p.unmatched.map(u => row(u.email, u.name || 'add them first, then re-import')).join('');
      }
      if (p.missing.length) {
        body += `<p class="tiny faint mt14">Still without an address: ${esc(p.missing.join(', '))}</p>`;
      }

      modal(`<h3>Check these first</h3>${body || '<p class="small muted mt14">Nothing usable in that text.</p>'}
        ${p.matched.length
          ? `<button class="btn primary full mt20" data-mailapply="${esc(raw)}">
               Save ${p.matched.length} ${p.matched.length === 1 ? 'address' : 'addresses'}</button>` : ''}
        <button class="btn ghost full mt8" data-close>Cancel</button>`);
    });
  }

  if (f.dataset.testmailform !== undefined) {
    ev.preventDefault();
    const btn = f.querySelector('button');
    btn.textContent = 'Sending…'; btn.disabled = true;
    return guard(async () => {
      try {
        const j = await api('admin_test_mail', { email: val('email') });
        close(); toast('Sent to ' + j.sent_to);
      } finally {
        btn.textContent = 'Send it'; btn.disabled = false;
      }
    });
  }

  if (f.dataset.pwform !== undefined) {
    ev.preventDefault();
    return guard(async () => {
      await api('admin_set_password', { password: val('password') });
      close(); toast('Password changed');
    });
  }
});

async function load() {
  const j = await api('admin_state');
  D = j;
  render();
  securityCheck();
}

load().catch(e => { app.innerHTML = `<div class="empty">${esc(e.message)}</div>`; });

})();
