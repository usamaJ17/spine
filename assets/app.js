/* Spine — single-page front-end. Vanilla JS, hash routing, no build step. */

(() => {
'use strict';

const $  = (s, r = document) => r.querySelector(s);
const app = $('#app');

let S = {};                 // bootstrap payload
let cache = {};             // per-person detail cache
let busy = false;

/* ------------------------------------------------------------- utils */

const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const attr = o => esc(JSON.stringify(o));

/* Honorifics and common prefixes — "Muhammad Saqib" should read as Saqib,
   not Muhammad, and "Hafiz Talha Jalal" as Talha. */
const PREFIX = new Set(['muhammad', 'mohammad', 'mohammed', 'md', 'hafiz', 'syed', 'sayyed',
  'mian', 'malik', 'sheikh', 'shaikh', 'mirza', 'ch', 'chaudhry', 'chaudhary', 'raja',
  'dr', 'dr.', 'prof', 'prof.', 'engr', 'mr', 'ms', 'mrs', 'hafiza']);

function firstName(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '';
  let i = 0;
  while (i < parts.length - 1 && PREFIX.has(parts[i].toLowerCase())) i++;
  return parts[i];
}

function initials(name) {
  const p = String(name || '?').trim().split(/\s+/);
  return ((p[0] || '')[0] + (p.length > 1 ? (p[p.length - 1] || '')[0] : '')).toUpperCase();
}

function av(p, cls = '') {
  if (!p) return '';
  const inner = p.emoji ? esc(p.emoji) : esc(initials(p.name));
  return `<div class="av ${cls}" style="--h:${p.hue || 0}">${inner}</div>`;
}

function ago(iso) {
  const t = Date.parse(iso.endsWith('Z') ? iso : iso + 'Z');
  if (isNaN(t)) return '';
  const s = Math.max(1, (Date.now() - t) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return Math.floor(s / 60) + 'm ago';
  if (s < 86400) return Math.floor(s / 3600) + 'h ago';
  if (s < 604800) return Math.floor(s / 86400) + 'd ago';
  return new Date(t).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}

function toast(msg) {
  document.querySelectorAll('.toast').forEach(t => t.remove());
  const el = document.createElement('div');
  el.className = 'toast';
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 2600);
}

async function api(action, data, query) {
  const opts = data
    ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Spine': '1' }, body: JSON.stringify(data) }
    : {};
  let url = `api.php?a=${encodeURIComponent(action)}`;
  for (const [k, v] of Object.entries(query || {})) {
    url += `&${encodeURIComponent(k)}=${encodeURIComponent(v)}`;
  }
  const res = await fetch(url, opts);
  let json;
  try { json = await res.json(); } catch { throw new Error('Server sent something unreadable.'); }
  if (!json.ok) throw new Error(json.error || 'Request failed.');
  return json;
}

async function guard(fn) {
  if (busy) return;
  busy = true;
  try { await fn(); }
  catch (e) { toast(e.message || 'Something went wrong.'); }
  finally { busy = false; }
}

const go = hash => { location.hash = hash; };

/* -------------------------------------------------------------- modal */

function modal(html) {
  closeModal();
  const wrap = document.createElement('div');
  wrap.className = 'scrim';
  wrap.innerHTML = `<div class="sheet">${html}</div>`;
  wrap.addEventListener('click', ev => { if (ev.target === wrap) closeModal(); });
  document.body.appendChild(wrap);
  document.body.style.overflow = 'hidden';
  const f = wrap.querySelector('[autofocus]');
  if (f) setTimeout(() => f.focus(), 60);
  return wrap;
}
function closeModal() {
  document.querySelectorAll('.scrim').forEach(e => e.remove());
  document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

/* ------------------------------------------------------------- chrome */

const ICONS = {
  home:   '<path d="M3 10.5 12 3l9 7.5M5.5 9.5V20h13V9.5"/>',
  people: '<circle cx="9" cy="8" r="3.2"/><path d="M3 19.5c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><path d="M16.5 5.6a3 3 0 0 1 0 5.6M17.5 14.4c2.2.6 3.5 2.4 3.5 4.6"/>',
  proj:   '<rect x="3" y="7" width="18" height="13" rx="2.5"/><path d="M8.5 7V5.5A1.5 1.5 0 0 1 10 4h4a1.5 1.5 0 0 1 1.5 1.5V7"/>',
  spark:  '<path d="M13 2.5 4.5 13.5H11l-1 8L19.5 10H13z"/>',
};

function nav(route) {
  const on = r => route.startsWith(r) ? 'on' : '';
  const tab = (href, key, label, r) =>
    `<a href="#${href}" class="${on(r)}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
       stroke-linecap="round" stroke-linejoin="round">${ICONS[key]}</svg>${label}</a>`;

  $('#topnav').innerHTML = [
    ['/', 'Home'], ['/people', 'People'], ['/projects', 'Projects'], ['/sparks', 'Sparks'],
  ].map(([h, l]) => `<a href="#${h}" class="${route === h || (h !== '/' && route.startsWith(h)) ? 'on' : ''}">${l}</a>`).join('');

  $('#tabbar').innerHTML =
    tab('/', 'home', 'Home', '/') +
    tab('/people', 'people', 'People', '/people') +
    tab('/projects', 'proj', 'Projects', '/projects') +
    tab('/sparks', 'spark', 'Sparks', '/sparks');

  $('#mechip').innerHTML = S.me
    ? `<button class="me-chip" data-act="switch">${av(S.me, 'xs')}<span class="nm">${esc(firstName(S.me.name))}</span></button>`
    : '';
}

/* ---------------------------------------------------------------- gate */

function renderGate() {
  document.body.innerHTML = `<div class="gate">
    <div class="brand" style="margin-bottom:22px"><span class="brand-dot">◆</span>${esc(S.app.name)}</div>
    <h1>Who are you?</h1>
    <p class="muted">Tap your name. This browser will remember you — no password, ever.</p>
    <div class="gatelist">
      ${S.people.map(p => `<button data-pick="${p.id}">${av(p, 'lg')}<span class="n">${esc(p.name)}</span></button>`).join('')}
    </div>
    <p class="faint tiny mt20 center">Not on the list? Ask whoever runs this circle to add you.</p>
  </div>`;
  document.body.addEventListener('click', async ev => {
    const b = ev.target.closest('[data-pick]');
    if (!b) return;
    await guard(async () => {
      await api('switch', { person_id: +b.dataset.pick });
      location.reload();
    });
  });
}

/* ---------------------------------------------------------------- home */

function viewHome() {
  const me = S.me;
  const mineOpen = S.sparks.filter(s => s.status !== 'done');
  const myProfileEmpty = S.empty.some(p => p.id === me.id);

  /* --- pair of the week --------------------------------------------- */
  const pair = S.pair;
  const involvesMe = pair && (pair.a.id === me.id || pair.b.id === me.id);
  let pairHtml = '';
  if (pair) {
    const other = pair.a.id === me.id ? pair.b : pair.a;
    const shared = (pair.shared || []).flatMap(b => b.tags).slice(0, 4);
    pairHtml = `<div class="pair">
      <div class="tagline">Pair of the week</div>
      <div class="duo">
        <a class="who" href="#/p/${pair.a.id}">${av(pair.a, 'lg')}<span class="n">${esc(pair.a.name)}</span></a>
        <span class="link"></span>
        <a class="who" href="#/p/${pair.b.id}">${av(pair.b, 'lg')}<span class="n">${esc(pair.b.name)}</span></a>
      </div>
      <p class="small muted center">${involvesMe
        ? `That's you and <b>${esc(firstName(other.name))}</b>. Nobody has to decide who goes first — the week already did.`
        : 'Give them a nudge in the group chat if they miss it.'}</p>
      ${shared.length ? `<div class="chipwrap mt14" style="justify-content:center">
        ${shared.map(t => `<span class="chip k-neutral">${esc(t.label)}</span>`).join('')}</div>` : ''}
      ${involvesMe ? `<button class="btn primary full mt14" data-spark="${attr({ b_id: other.id, topic: shared[0] ? shared[0].label : '' })}">
        Start this conversation</button>` : ''}
    </div>`;
  }

  /* --- one concrete thing for YOU, always ---------------------------- */
  const best = S.people
    .filter(p => p.id !== me.id && p.overlap > 0)
    .sort((a, b) => b.overlap - a.overlap)[0];

  let nextHtml = '';
  if (!involvesMe && best) {
    nextHtml = `<div class="next mt14">
      <div class="tagline">Your closest match</div>
      <a class="row gap14 mt14" href="#/p/${best.id}">
        ${av(best, 'lg')}
        <div class="grow" style="min-width:0">
          <div style="font-weight:650;font-size:16px">${esc(best.name)}</div>
          <div class="small faint">${esc(best.why)}</div>
        </div>
      </a>
      <div class="chipwrap mt14">
        ${(best.sample || []).map(l => `<span class="chip k-neutral">${esc(l)}</span>`).join('')}
      </div>
      <button class="btn primary full mt14"
        data-spark="${attr({ b_id: best.id, topic: best.top ? best.top.label : '', tag_id: best.top ? best.top.tag_id : 0 })}">
        ⚡ Spark with ${esc(firstName(best.name))}</button>
    </div>`;
  }

  /* --- nudges --------------------------------------------------------- */
  let nudges = '';
  if (myProfileEmpty) {
    nudges += nudge('👋', 'Your profile is still empty',
      'Nobody can find a reason to talk to you yet. Two minutes fixes it.',
      `<a class="btn primary sm" href="#/p/${me.id}">Fill it in</a>`);
  } else if (!best) {
    nudges += nudge('🔭', 'Nothing overlaps yet',
      'Add a few things you are curious about — that is what makes matches appear.',
      `<a class="btn primary sm" href="#/p/${me.id}">Add them</a>`);
  }
  if (!S.stats.traits) {
    nudges += nudge('👀', 'Nobody has been seen yet',
      'Open someone and write one thing you noticed about them. It takes 15 seconds and it lands hard.',
      `<a class="btn sm" href="#/people">Pick someone</a>`);
  }

  const stat = (v, k) => `<div><div class="v">${v}</div><div class="k">${k}</div></div>`;

  return `<div class="view">
    ${pairHtml}
    ${nextHtml}
    ${nudges}

    <div class="statstrip mt14">
      ${stat(S.stats.people, 'People')}
      ${stat(S.stats.open, 'Open')}
      ${stat(S.stats.done, 'Talked')}
      ${stat(S.stats.traits, 'Traits')}
    </div>

    ${mineOpen.length ? `<div class="sec-head"><h2>Your open sparks</h2><span class="rest"></span>
        <a href="#/sparks">All</a></div>
      ${mineOpen.slice(0, 3).map(s => sparkCard(s)).join('')}` : ''}

    <div class="sec-head"><h2>What's happening</h2></div>
    <div class="card">
      ${S.feed.length ? S.feed.map(feedItem).join('')
        : `<div class="empty"><div class="big">🌱</div>Nothing yet.<br>Be the first — add a trait to someone.</div>`}
    </div>

    <a class="howlink mt20" href="#/how">
      <span class="q">?</span>
      <span class="grow"><b>What is this place for?</b>
        <span class="faint tiny">How it works, and what to actually do here — two minutes.</span></span>
      <span class="faint">→</span>
    </a>
  </div>`;
}

function nudge(icon, title, sub, action) {
  return `<div class="nudge mt14">
    <span class="ni">${icon}</span>
    <div class="grow"><div class="t">${esc(title)}</div><div class="s">${esc(sub)}</div></div>
    ${action}</div>`;
}

function feedItem(f) {
  const who = n => `<b>${esc(n)}</b>`;
  const ic = { trait: '👀', spark: '⚡', done: '✅', project: '🚀' }[f.type] || '•';
  let txt;
  if (f.type === 'trait')   txt = `${who(f.actor.name)} sees <b>${esc(f.text)}</b> in ${who(f.target.name)}`;
  else if (f.type === 'spark')   txt = `${who(f.actor.name)} wants to talk to ${who(f.target.name)} about <b>${esc(f.text)}</b>`;
  else if (f.type === 'done')    txt = `${who(f.actor.name)} and ${who(f.target.name)} actually talked about <b>${esc(f.text)}</b>`;
  else if (f.type === 'project') txt = `${who(f.actor.name)} wants to start <b>${esc(f.text)}</b>`;
  else txt = `${who(f.actor.name)} ${esc(f.text)}`;

  return `<div class="fitem">
    <div class="ic">${ic}</div>
    <div class="grow"><div class="txt">${txt}</div><div class="when">${ago(f.at)}</div></div>
  </div>`;
}

/* ------------------------------------------------------- how it works */

const HOW_KEY = 'spine_how_v1';

function viewHow() {
  const step = (n, title, body) => `<div class="step">
    <div class="num">${n}</div>
    <div class="grow"><div class="st">${title}</div><div class="sb">${body}</div></div>
  </div>`;

  const box = (kind, body) => `<div class="hbox k-${kind}">
    <div class="kt">${esc(S.kinds[kind].label)}</div>
    <p class="small">${body}</p>
  </div>`;

  return `<div class="view how">

    <div class="howhead">
      <h1>Why this exists</h1>
      <p>We all met once. Everyone in that call was worth knowing. Then a week went
         past and nothing happened — not because anybody stopped caring, but because
         nobody wants to be the one who messages first.</p>
      <p class="lead">This site exists to remove that one awkward step.
         It finds a real thing two people share, and turns it into one real conversation.</p>
    </div>

    <div class="sec-head"><h2>How it works</h2></div>

    ${step(1, 'Fix your profile', `It is already filled in from the July session — check it and
      correct whatever is wrong. Three minutes, once.`)}
    ${step(2, 'Say what you notice in other people', `Open anyone and write one thing you saw in them.
      It takes fifteen seconds and it is the single kindest thing on this site.`)}
    ${step(3, 'Turn a shared topic into a spark', `The app shows you why you two should talk.
      Tap it. That is a spark — a small, public promise that you will actually speak.`)}
    ${step(4, 'Talk, then say what came out of it', `A voice note counts. A ten-minute call counts.
      Afterwards mark it done and write one line about it — that line is what makes other people copy you.`)}

    <div class="sec-head"><h2>Your profile has four boxes</h2></div>
    <div class="card">
      ${box('good_at', `Things you could teach or help with. Your skills, but also the
        unglamorous ones — finding a flat, writing an email that gets answered.`)}
      ${box('curious', `Things you <b>want to learn</b>. This is the important one.
        A profile with only skills in it matches almost nobody — curiosity is what
        makes the connections appear.`)}
      ${box('building', `What you are actually pushing right now. This is how people
        find out they can help you.`)}
      ${box('life', `Books, football, poetry, food, faith. Friendships get built here far
        more often than they get built on job titles.`)}
    </div>

    <div class="peer mt14">
      <div class="kt k-seen_in_you">What others see in you</div>
      <p class="small">A fifth box that <b>you cannot write in</b>. Only other people can.
      Whatever they add carries their name, and you will see it on your profile.
      Most of us have no idea how we come across — this is how you find out.</p>
    </div>

    <div class="sec-head"><h2>The rest of it</h2></div>
    <div class="card">
      <div class="kindblock"><div class="kt" style="--c:var(--spark)">Sparks</div>
        <p class="small muted">Open, scheduled, or done. Every spark has a Share button that
        writes the WhatsApp message for you — this site finds the reason to talk, the
        group chat is still where you talk.</p></div>

      <div class="kindblock"><div class="kt" style="--c:var(--curious)">Projects</div>
        <p class="small muted">Anything you wish existed — your own, or something for the group.
        Write it down and other people can tap <b>I want in</b>. Nothing starts while it stays
        in your head.</p></div>

      <div class="kindblock"><div class="kt" style="--c:var(--build)">Pair of the week</div>
        <p class="small muted">Every week the site picks two people. If one of them is you,
        you do not have to decide anything — the week already decided. Just start.</p></div>
    </div>

    <div class="sec-head"><h2>Two practical things</h2></div>
    <div class="card">
      <div class="kindblock"><div class="kt" style="--c:var(--seen)">There is no password</div>
        <p class="small muted">Your personal link signs you in and this device stays signed in.
        Tap your name at the top right to switch to someone else. Keep your link to yourself —
        it is the closest thing here to a key.</p></div>

      <div class="kindblock"><div class="kt" style="--c:var(--good)">Everything is public to the group</div>
        <p class="small muted">Every trait and every spark carries the name of whoever wrote it.
        There are no phone numbers or email addresses on this site, and it does not appear
        on Google. Write like the person is reading — because they are.</p></div>
    </div>

    <div class="why mt20">
      <h3>If you only do one thing</h3>
      <p class="small muted mt8">Add one thing you noticed about one person. That is it.
      One sentence, fifteen seconds. It is the smallest thing on here and it does more
      than anything else.</p>
      <a class="btn primary full mt14" href="#/people" data-seenhow>Pick someone</a>
    </div>

    <p class="center faint tiny mt20">You can come back to this page any time — the
      <b>?</b> at the top right.</p>
  </div>`;
}

/* -------------------------------------------------------------- people */

function viewPeople() {
  const list = S.people.slice().sort((a, b) =>
    (a.id === S.me.id ? 1 : 0) - (b.id === S.me.id ? 1 : 0) || b.overlap - a.overlap);

  return `<div class="view">
    <div class="sec-head"><h2>Everyone</h2><span class="rest"></span>
      <span class="faint tiny">most in common first</span></div>
    <div class="pgrid">
      ${list.map(p => {
        const isMe = p.id === S.me.id;
        return `<a class="pcard" href="#/p/${p.id}">
          <div class="row gap10">
            ${av(p)}
            <div class="grow" style="min-width:0">
              <div class="nm ellip">${esc(p.name)}${isMe ? ' <span class="faint tiny">you</span>' : ''}</div>
              <div class="hl ellip">${esc(p.headline || 'No headline yet')}</div>
            </div>
            ${isMe ? '' : `<span class="ov ${p.overlap ? '' : 'zero'}">${p.overlap || '–'}</span>`}
          </div>
          ${!isMe && p.sample && p.sample.length ? `<div class="shared">
            <span class="faint tiny">${esc(p.why)}</span>
            <div class="chipwrap mt8">${p.sample.map(l =>
              `<span class="chip k-neutral" style="font-size:12px;padding:4px 9px">${esc(l)}</span>`).join('')}</div>
          </div>` : ''}
        </a>`;
      }).join('')}
    </div>
  </div>`;
}

/* ------------------------------------------------------------- profile */

function kindBlock(kind, items, opts) {
  const meta = S.kinds[kind];
  if (!items.length && !opts.canAdd) return '';       // no dead sections on other people

  const chips = items.map(t => `<span class="chip k-${kind}">${esc(t.label)}
      ${kind === 'seen_in_you' && t.by_name ? `<span class="by">— ${esc(firstName(t.by_name))}</span>` : ''}
      ${opts.canDelete(t) ? `<button class="x" data-deltag="${t.id}" title="Remove">×</button>` : ''}
    </span>`).join('');

  return `<div class="kindblock k-${kind}">
    <div class="kt">${esc(meta.label)}<span class="ct">${items.length || ''}</span></div>
    ${items.length ? `<div class="chipwrap">${chips}</div>`
      : `<div class="kh">${esc(meta.hint)}</div>`}
    ${opts.canAdd ? `<form class="inline-add" data-addtag="${kind}">
        <input class="field" name="label" list="vocab" autocomplete="off" maxlength="80"
               placeholder="${esc(opts.placeholder || 'Add one…')}">
        <button class="btn sm">Add</button>
      </form>` : ''}
  </div>`;
}

const PLACEHOLDERS = {
  good_at:  'e.g. grant writing',
  curious:  'e.g. how the brain stores memory',
  building: 'e.g. a compost fertiliser business',
  life:     'e.g. Urdu poetry',
};

function viewPerson(id) {
  const d = cache['p' + id];
  if (!d) return skeleton();
  const p = d.person, mine = p.id === S.me.id;

  const whyHtml = (!mine && d.overlaps.length) ? `<div class="why mt14">
      <h3>Why you two should talk</h3>
      <p class="small muted">Tap anything to turn it into a spark.</p>
      ${d.overlaps.map((b, i) => `<div class="bucket">
        <div class="bl">${esc(b.title)}</div>
        <div class="chipwrap">${b.tags.map(t => `<button class="sparkable ${i === 0 ? 'strong' : ''}"
          data-spark="${attr({ b_id: p.id, topic: t.label, tag_id: t.tag_id })}">${esc(t.label)}</button>`).join('')}</div>
      </div>`).join('')}
    </div>`
    : (!mine ? `<div class="card mt14">
        <h3 style="font-size:16px">Nothing overlaps yet</h3>
        <p class="small muted mt8">Either their profile is thin or yours is. Add what you're
        curious about and this fills up on its own.</p>
        <button class="btn primary sm mt14" data-spark="${attr({ b_id: p.id, topic: '' })}">Spark anyway</button>
      </div>` : '');

  const canDelete = t => mine || t.added_by === S.me.id;
  const own = ['good_at', 'curious', 'building', 'life'];
  const seen = d.tags.seen_in_you || [];

  /* Own profile: show how complete it is and name what is missing. */
  let meterHtml = '';
  if (mine) {
    const filled = own.filter(k => (d.tags[k] || []).length).length;
    const missing = own.find(k => !(d.tags[k] || []).length);
    meterHtml = `<div class="meter mt14">
      <div class="bar"><i style="width:${(filled / own.length) * 100}%"></i></div>
      <div class="small faint mt8">${filled} of ${own.length} sections filled${
        missing ? ` — next: <b style="color:var(--txt)">${esc(S.kinds[missing].label.toLowerCase())}</b>` : '. Nicely done.'}</div>
    </div>`;
  }

  const peerHtml = `<div class="peer mt14">
    <div class="kt k-seen_in_you">${esc(S.kinds.seen_in_you.label)}<span class="ct">${seen.length || ''}</span></div>
    ${seen.length ? `<div class="chipwrap">${seen.map(t => `<span class="chip k-seen_in_you">${esc(t.label)}
        ${t.by_name ? `<span class="by">— ${esc(firstName(t.by_name))}</span>` : ''}
        ${canDelete(t) ? `<button class="x" data-deltag="${t.id}">×</button>` : ''}</span>`).join('')}</div>`
      : `<p class="small muted">${mine
          ? 'Nothing yet. This section fills up when other people notice something in you.'
          : 'Nobody has written anything here yet. You could be the first.'}</p>`}
    ${!mine ? `<form class="inline-add" data-addtag="seen_in_you">
        <input class="field" name="label" list="vocab" autocomplete="off" maxlength="80"
               placeholder="One thing you noticed about ${esc(firstName(p.name))}…">
        <button class="btn sm primary">Add</button>
      </form>
      <p class="faint tiny mt8">They will see it, with your name on it.</p>` : ''}
  </div>`;

  return `<div class="view">
    <button class="btn ghost sm" data-back>← Back</button>

    <div class="card hero mt14" style="--h:${p.hue}">
      <div class="phead">
        ${av(p, 'xl')}
        <div class="grow" style="min-width:0">
          <h1>${esc(p.name)}</h1>
          <div class="sub">${esc(p.headline || (mine ? 'Add a one-line headline' : ''))}</div>
          ${p.city ? `<div class="faint tiny mt8">${esc(p.city)}</div>` : ''}
        </div>
      </div>
      ${mine ? `<button class="btn sm mt14" data-act="editme">Edit your header</button>` : ''}
      ${!mine ? `<button class="btn primary full mt14"
        data-spark="${attr({ b_id: p.id, topic: '' })}">⚡ Spark with ${esc(firstName(p.name))}</button>` : ''}
      ${meterHtml}
      ${mine && d.link ? `<div class="linkbox"><code>${esc(d.link)}</code>
        <button class="btn sm" data-copy="${esc(d.link)}">Copy</button></div>
        <p class="faint tiny mt8">Your private link. Open it on any device to be signed in as you.</p>` : ''}
    </div>

    ${whyHtml}

    ${peerHtml}

    <div class="card mt14">
      ${own.map(k => kindBlock(k, d.tags[k] || [], {
        canAdd: mine, canDelete, placeholder: PLACEHOLDERS[k],
      })).join('') || `<p class="small faint">${esc(firstName(p.name))} hasn't filled anything in yet.</p>`}
    </div>

    <div class="sec-head"><h2>Projects ${mine ? 'you want to start' : 'they want to start'}</h2></div>
    ${d.projects.length ? d.projects.map(pr => projCard(pr, p)).join('')
      : `<div class="card"><p class="small faint">${mine
          ? 'Anything you wish existed — personal or for the group. Someone here might join you.'
          : 'Nothing listed yet.'}</p></div>`}
    ${mine ? `<button class="btn full mt14" data-act="addproj">+ Add a project</button>` : ''}

    ${d.sparks.length ? `<div class="sec-head"><h2>Sparks</h2></div>
      ${d.sparks.map(s => sparkCard(s)).join('')}` : ''}
  </div>`;
}

/* ------------------------------------------------------------ projects */

function projCard(pr, owner) {
  const isMine = pr.person_id === S.me.id;
  const who = owner || { id: pr.person_id, name: pr.owner, emoji: pr.emoji, hue: pr.hue };
  return `<div class="proj">
    <div class="row">
      <span class="badge ${pr.kind === 'community' ? 'community' : ''}">${pr.kind === 'community' ? 'Community' : 'Personal'}</span>
      <span class="rest grow"></span>
      ${isMine ? `<button class="btn ghost sm danger" data-delproj="${pr.id}">Remove</button>` : ''}
    </div>
    <div class="t mt8">${esc(pr.title)}</div>
    ${pr.blurb ? `<div class="b">${esc(pr.blurb)}</div>` : ''}
    ${pr.looking ? `<div class="look">Looking for: ${esc(pr.looking)}</div>` : ''}
    <div class="row mt14">
      ${who.name ? `<a class="row gap6 small muted" href="#/p/${who.id}">${av(who, 'xs')}${esc(who.name)}</a>` : ''}
      <span class="grow"></span>
      ${isMine ? '' : `<button class="btn primary sm"
        data-spark="${attr({ b_id: pr.person_id, topic: pr.title, project_id: pr.id })}">I want in</button>`}
    </div>
  </div>`;
}

function viewProjects() {
  const list = S.projects;
  return `<div class="view">
    <div class="sec-head"><h2>Projects people want to start</h2></div>
    ${list.length ? list.map(pr => projCard(pr, null)).join('')
      : `<div class="empty"><div class="big">🚀</div>No projects yet.<br>
         Add the thing you wish existed — someone here may join you.</div>`}
    <button class="btn primary full mt14" data-act="addproj">+ Add a project</button>
  </div>`;
}

/* -------------------------------------------------------------- sparks */

let sparkFilter = 'mine';

function sparkCard(s) {
  const mine = s.a.id === S.me.id || s.b.id === S.me.id;
  const other = s.a.id === S.me.id ? s.b : s.a;
  return `<div class="spark ${mine ? 'mine' : ''}">
    <div class="top">
      <a href="#/p/${s.a.id}">${av(s.a, 'sm')}</a>
      <span class="faint">⚡</span>
      <a href="#/p/${s.b.id}">${av(s.b, 'sm')}</a>
      <span class="grow small muted ellip">${esc(firstName(s.a.name))} &amp; ${esc(firstName(s.b.name))}</span>
      <span class="pill ${s.status}">${s.status}</span>
    </div>
    <div class="tp">${esc(s.topic)}</div>
    ${s.message ? `<div class="msg">“${esc(s.message)}”</div>` : ''}
    ${s.outcome ? `<div class="out">✅ ${esc(s.outcome)}</div>` : ''}
    <div class="acts">
      ${mine && s.status !== 'done' ? `
        <button class="btn sm" data-status="${s.id}|scheduled">Scheduled</button>
        <button class="btn sm primary" data-done="${s.id}">We talked</button>` : ''}
      <button class="btn sm ghost" data-share="${attr({ a: s.a.name, b: s.b.name, topic: s.topic, msg: s.message })}">Share</button>
      ${mine ? `<span class="grow"></span><span class="faint tiny" style="align-self:center">${ago(s.updated)}</span>` : ''}
    </div>
  </div>`;
}

function viewSparks(all) {
  const list = (all || []).filter(s => {
    if (sparkFilter === 'mine') return s.a.id === S.me.id || s.b.id === S.me.id;
    if (sparkFilter === 'open') return s.status !== 'done';
    if (sparkFilter === 'done') return s.status === 'done';
    return true;
  });
  const seg = (k, l) => `<button class="${sparkFilter === k ? 'on' : ''}" data-filter="${k}">${l}</button>`;

  return `<div class="view">
    <div class="sec-head"><h2>Sparks</h2><span class="rest"></span>
      <span class="faint tiny">${S.stats.done} finished</span></div>
    <div class="segmented">${seg('mine', 'Mine')}${seg('open', 'Open')}${seg('done', 'Done')}${seg('all', 'All')}</div>
    <div class="mt14">
      ${list.length ? list.map(s => sparkCard(s)).join('')
        : `<div class="empty"><div class="big">⚡</div>Nothing here yet.<br>
           Open someone's profile and tap a shared topic.</div>`}
    </div>
  </div>`;
}

function skeleton() {
  return `<div class="view"><div class="skel"></div><div class="skel"></div><div class="skel"></div></div>`;
}

/* -------------------------------------------------------------- sheets */

function sparkSheet(pre) {
  const other = S.people.find(p => p.id === +pre.b_id);
  if (!other) return;
  modal(`<h3>Spark with ${esc(firstName(other.name))}</h3>
    <p class="small muted">A spark is a tiny public promise to actually talk. Keep it small —
    a voice note counts.</p>
    <form data-sparkform="${attr(pre)}" class="mt20">
      <label class="lbl">What's it about?</label>
      <input class="field" name="topic" maxlength="160" ${pre.topic ? '' : 'autofocus'}
             value="${esc(pre.topic || '')}" placeholder="e.g. how memory actually forms" required>
      <label class="lbl mt14">Say something (optional)</label>
      <textarea class="field" name="message" maxlength="600" ${pre.topic ? 'autofocus' : ''}
        placeholder="What do you want to ask, or what can you offer?"></textarea>
      <button class="btn primary full mt20">Create spark</button>
      <button type="button" class="btn ghost full mt8" data-act="close">Cancel</button>
    </form>`);
}

function sparkDone(other, topic, message) {
  const url = location.origin + location.pathname.replace(/[^/]*$/, '') + '#/sparks';
  const text = `⚡ New spark on ${S.app.name}\n${S.me.name} → ${other.name}\n“${topic}”`
    + (message ? `\n\n${message}` : '') + `\n\n${url}`;
  modal(`<div class="center"><div style="font-size:40px">⚡</div>
    <h3 class="mt8">Spark created</h3>
    <p class="small muted mt8">Now put it where the group actually lives.</p></div>
    <a class="btn primary full mt20" target="_blank" rel="noopener"
       href="https://wa.me/?text=${encodeURIComponent(text)}">Share to WhatsApp</a>
    <button class="btn full mt8" data-copy="${esc(text)}">Copy the message</button>
    <button class="btn ghost full mt8" data-act="close">Done</button>`);
}

function doneSheet(id) {
  modal(`<h3>You two actually talked?</h3>
    <p class="small muted">One line about what came out of it. This is the part other people read
    and copy.</p>
    <form data-doneform="${id}" class="mt20">
      <textarea class="field" name="outcome" maxlength="600" autofocus
        placeholder="e.g. 20-min call — he mapped out how to email professors in France"></textarea>
      <button class="btn primary full mt14">Mark as talked</button>
      <button type="button" class="btn ghost full mt8" data-act="close">Cancel</button>
    </form>`);
}

function editMeSheet() {
  const me = S.me;
  modal(`<h3>Your header</h3>
    <form data-meform class="mt20">
      <label class="lbl">Emoji (optional — replaces your initials)</label>
      <input class="field" name="emoji" maxlength="4" value="${esc(me.emoji || '')}" placeholder="🧠">
      <label class="lbl mt14">One line about you</label>
      <input class="field" name="headline" maxlength="160" autofocus value="${esc(me.headline || '')}"
        placeholder="Pharmacist turned social entrepreneur">
      <label class="lbl mt14">Where you are</label>
      <input class="field" name="city" maxlength="60" value="${esc(me.city || '')}" placeholder="Lahore">
      <button class="btn primary full mt20">Save</button>
      <button type="button" class="btn ghost full mt8" data-act="close">Cancel</button>
    </form>`);
}

function projSheet() {
  modal(`<h3>A project you wish existed</h3>
    <p class="small muted">Personal or something for the group. Saying it out loud is how it finds people.</p>
    <form data-projform class="mt20">
      <label class="lbl">Name it</label>
      <input class="field" name="title" maxlength="140" autofocus required placeholder="Weekly Arabic self-study group">
      <label class="lbl mt14">What is it, in two lines?</label>
      <textarea class="field" name="blurb" maxlength="600" placeholder="15–30 minutes a day, shared deck, one check-in a week."></textarea>
      <label class="lbl mt14">What do you need?</label>
      <input class="field" name="looking" maxlength="200" placeholder="Two people who'll actually show up">
      <label class="lbl mt14">Type</label>
      <select class="field" name="kind">
        <option value="community">For the community</option>
        <option value="personal">Personal</option>
      </select>
      <button class="btn primary full mt20">Add project</button>
      <button type="button" class="btn ghost full mt8" data-act="close">Cancel</button>
    </form>`);
}

function switchSheet() {
  modal(`<h3>Switch person</h3>
    <p class="small muted">This browser will act as whoever you pick.</p>
    <div class="gatelist mt20">
      ${S.people.map(p => `<button data-switch="${p.id}">${av(p, 'lg')}<span class="n">${esc(p.name)}</span></button>`).join('')}
    </div>
    <button class="btn ghost full mt14" data-act="signout">Sign out of this browser</button>`);
}

/* -------------------------------------------------------------- router */

async function render() {
  const r = location.hash.replace(/^#/, '') || '/';
  nav(r);
  window.scrollTo(0, 0);

  if (r.startsWith('/p/')) {
    const id = +r.slice(3);
    app.innerHTML = viewPerson(id);
    if (!cache['p' + id]) {
      try {
        cache['p' + id] = await api('person', null, { id });
        if (location.hash.replace(/^#/, '') === r) app.innerHTML = viewPerson(id);
      } catch (e) { app.innerHTML = `<div class="empty">${esc(e.message)}</div>`; }
    }
    return;
  }
  if (r === '/how') {
    try { localStorage.setItem(HOW_KEY, '1'); } catch { /* private mode */ }
    app.innerHTML = viewHow();
    return;
  }
  if (r === '/people')   { app.innerHTML = viewPeople(); return; }
  if (r === '/projects') { app.innerHTML = viewProjects(); return; }
  if (r === '/sparks') {
    app.innerHTML = viewSparks(S.allSparks);
    if (!S.allSparks) {
      const j = await api('sparks');
      S.allSparks = j.sparks; S.stats = j.stats;
      if (location.hash.replace(/^#/, '') === '/sparks') app.innerHTML = viewSparks(S.allSparks);
    }
    return;
  }
  app.innerHTML = viewHome();
}

async function refresh(hard) {
  const j = await api('bootstrap');
  Object.assign(S, j);
  if (hard) { cache = {}; S.allSparks = null; }
  await render();
}

/* --------------------------------------------------------- interactions */

document.addEventListener('click', async ev => {
  const t = ev.target;

  const copy = t.closest('[data-copy]');
  if (copy) {
    const text = copy.dataset.copy;
    try { await navigator.clipboard.writeText(text); toast('Copied'); }
    catch {
      const ta = document.createElement('textarea');
      ta.value = text; document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); ta.remove(); toast('Copied');
    }
    return;
  }

  if (t.closest('[data-back]')) {
    if (history.length > 1) history.back(); else go('/people');
    return;
  }

  const act = t.closest('[data-act]');
  if (act) {
    const a = act.dataset.act;
    if (a === 'close')   return closeModal();
    if (a === 'switch')  return switchSheet();
    if (a === 'editme')  return editMeSheet();
    if (a === 'addproj') return projSheet();
    if (a === 'signout') return guard(async () => { await api('signout', {}); location.reload(); });
  }

  const sw = t.closest('[data-switch]');
  if (sw) return guard(async () => {
    await api('switch', { person_id: +sw.dataset.switch });
    location.hash = '/';
    location.reload();
  });

  const sp = t.closest('[data-spark]');
  if (sp) return sparkSheet(JSON.parse(sp.dataset.spark));

  const dn = t.closest('[data-done]');
  if (dn) return doneSheet(+dn.dataset.done);

  const stt = t.closest('[data-status]');
  if (stt) {
    const [id, status] = stt.dataset.status.split('|');
    return guard(async () => {
      await api('spark_update', { id: +id, status });
      toast('Updated');
      await refresh(true);
    });
  }

  const del = t.closest('[data-deltag]');
  if (del) return guard(async () => {
    const id = +del.dataset.deltag;
    const j = await api('del_tag', { id });
    const key = 'p' + currentPersonId();
    if (cache[key]) cache[key].tags = j.tags;
    await render();
  });

  const dp = t.closest('[data-delproj]');
  if (dp) return guard(async () => {
    await api('del_project', { id: +dp.dataset.delproj });
    toast('Removed');
    await refresh(true);
  });

  const sh = t.closest('[data-share]');
  if (sh) {
    const d = JSON.parse(sh.dataset.share);
    const url = location.origin + location.pathname.replace(/[^/]*$/, '') + '#/sparks';
    const text = `⚡ ${S.app.name}\n${d.a} & ${d.b}\n“${d.topic}”` + (d.msg ? `\n\n${d.msg}` : '') + `\n\n${url}`;
    window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank', 'noopener');
    return;
  }

  const flt = t.closest('[data-filter]');
  if (flt) { sparkFilter = flt.dataset.filter; return render(); }
});

function currentPersonId() {
  const r = location.hash.replace(/^#/, '');
  return r.startsWith('/p/') ? +r.slice(3) : 0;
}

document.addEventListener('submit', async ev => {
  const f = ev.target;
  const fd = new FormData(f);
  const val = k => (fd.get(k) || '').toString().trim();

  if (f.dataset.addtag !== undefined) {
    ev.preventDefault();
    const label = val('label');
    if (!label) return;
    return guard(async () => {
      const pid = currentPersonId();
      const j = await api('add_tag', { person_id: pid, kind: f.dataset.addtag, label });
      cache['p' + pid].tags = j.tags;
      f.querySelector('input').value = '';
      await render();
      if (f.dataset.addtag === 'seen_in_you') toast('They will see this — nice.');
    });
  }

  if (f.dataset.sparkform !== undefined) {
    ev.preventDefault();
    const pre = JSON.parse(f.dataset.sparkform);
    const topic = val('topic'), message = val('message');
    if (!topic) return toast('What is it about?');
    return guard(async () => {
      await api('spark_create', { ...pre, topic, message });
      const other = S.people.find(p => p.id === +pre.b_id);
      await refresh(true);
      sparkDone(other, topic, message);
    });
  }

  if (f.dataset.doneform !== undefined) {
    ev.preventDefault();
    return guard(async () => {
      await api('spark_update', { id: +f.dataset.doneform, status: 'done', outcome: val('outcome') });
      closeModal(); toast('That is the whole point. Well done.');
      await refresh(true);
    });
  }

  if (f.dataset.meform !== undefined) {
    ev.preventDefault();
    return guard(async () => {
      await api('save_me', { emoji: val('emoji'), headline: val('headline'), city: val('city') });
      closeModal(); await refresh(true);
    });
  }

  if (f.dataset.projform !== undefined) {
    ev.preventDefault();
    if (!val('title')) return toast('Give it a name.');
    return guard(async () => {
      await api('add_project', {
        title: val('title'), blurb: val('blurb'), looking: val('looking'), kind: val('kind'),
      });
      closeModal(); toast('Added'); await refresh(true);
    });
  }
});

window.addEventListener('hashchange', render);

/* ---------------------------------------------------------------- boot */

(async function start() {
  try {
    const j = await api('bootstrap');
    Object.assign(S, j);
  } catch (e) {
    app.innerHTML = `<div class="empty"><div class="big">⚠️</div>${esc(e.message)}</div>`;
    return;
  }
  if (!S.me) return renderGate();
  $('#vocab').innerHTML = S.vocab.map(v => `<option value="${esc(v)}">`).join('');

  // First visit lands on the explainer instead of a screen nobody understands.
  let seenHow = true;
  try { seenHow = !!localStorage.getItem(HOW_KEY); } catch { /* private mode */ }
  if (!seenHow && !location.hash) {
    location.hash = '/how';
    return;                       // hashchange takes it from here
  }
  await render();
})();

})();
