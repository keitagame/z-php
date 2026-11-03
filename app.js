(() => {
const $ = (s) => document.querySelector(s);
const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const URL_RE = /https?:\/\/[\w\-.~:/?#[\]@!$&'()*+,;=%]+/g;
const RX_SET      = ['👍', '😂', '😮', '😢', '🔥', '🎉', '🙏', '👀'];
// URL末尾に付いた句読点・閉じ括弧はリンクに含めない
const trimUrl = (u) => {
  for (;;) {
    const c = u.slice(-1);
    if (/[.,;:!?'"\]]/.test(c) || (c === ')' && (u.match(/\)/g) || []).length > (u.match(/\(/g) || []).length)) u = u.slice(0, -1);
    else return u;
  }
};
const linkText = (u) => { const t = u.replace(/^https?:\/\//, ''); return t.length > 32 ? t.slice(0, 31) + '…' : t; };
const richText = (s) => esc(s)
  .replace(/(^|\s)@([A-Za-z0-9_]{3,15})/g, '$1<a href="#/profile/$2">@$2</a>')
  .replace(/(^|\s)#([\p{L}\p{N}_]+)/gu, (m, p, t) => `${p}<a href="#/search/${encodeURIComponent('#' + t)}">#${t}</a>`);
const emojify = (html, em) => em && Object.keys(em).length
  ? html.replace(/(<[^>]*>)|:([A-Za-z0-9_]{1,40}):/g, (m, tag, n) => tag ? tag : (em[n] || em[n.toLowerCase()]) ? `<img class="cemoji" src="${esc(em[n] || em[n.toLowerCase()])}" alt=":${n}:" title=":${n}:" loading="lazy" referrerpolicy="no-referrer">` : m)
  : html;
const rich = (s, em) => {
  s = String(s); let out = '', i = 0;
  for (const m of s.matchAll(URL_RE)) {
    const u = trimUrl(m[0]);
    out += richText(s.slice(i, m.index)) + `<a href="${esc(u)}" target="_blank" rel="noopener noreferrer nofollow ugc">${esc(linkText(u))}</a>`;
    i = m.index + u.length;
  }
  return emojify(out + richText(s.slice(i)), em);
};
const hue = (s) => Math.abs([...s].reduce((a, c) => (a * 31 + c.charCodeAt(0)) | 0, 7)) % 360;
const imgUrl = (f) => f;
const av = (u) => u.avatar ? `<img src="${esc(imgUrl(u.avatar))}" alt="" loading="lazy">` : `<span class="ph" style="background:hsl(${hue(u.handle)} 55% 45%)">${esc([...u.name][0] || '?')}</span>`;
// アイコンデコレーション(アイコンに重ねる透過画像)
const decoImg = (u) => u.deco ? `<img class="deco" src="${esc(imgUrl(u.deco))}" alt="" aria-hidden="true" loading="lazy">` : '';
const avd = (u) => av(u) + decoImg(u);
const ago = (ts) => { const d = Math.floor(Date.now() / 1000) - ts;
  if (d < 60) return 'たった今'; if (d < 3600) return Math.floor(d / 60) + '分前';
  if (d < 86400) return Math.floor(d / 3600) + '時間前'; if (d < 604800) return Math.floor(d / 86400) + '日前';
  return new Date(ts * 1000).toLocaleDateString('ja-JP'); };

/* ---------- リンクプレビュー(OGP) ---------- */
const OGP = new Map(), ogpBusy = new Set(); // url -> {title,desc,image,site} | null
const firstUrl = (s) => { const m = String(s || '').match(URL_RE); return m ? trimUrl(m[0]) : ''; };
const ogpCard = (u) => { const o = OGP.get(u); if (!o) return '';
  return `<a class="ogp" href="${esc(u)}" target="_blank" rel="noopener noreferrer nofollow ugc">${o.image ? `<img src="${esc(o.image)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : ''}<div class="ogp-body"><div class="ogp-site">${esc(o.site)}</div><div class="ogp-title">${esc(o.title)}</div>${o.desc ? `<div class="ogp-desc">${esc(o.desc)}</div>` : ''}</div></a>`; };
const ogpSlot = (body) => { const u = firstUrl(body); return u ? `<div class="ogp-slot" data-url="${esc(u)}">${ogpCard(u)}</div>` : ''; };
async function hydrateOgp() {
  const todo = [...new Set([...document.querySelectorAll('.ogp-slot')].map((s) => s.dataset.url))].filter((u) => !OGP.has(u) && !ogpBusy.has(u));
  todo.forEach((u) => ogpBusy.add(u));
  const worker = async () => {
    for (let u; (u = todo.shift());) {
      try { OGP.set(u, (await api('ogp', { q: { url: u } })).ogp || null); } catch (_) { OGP.set(u, null); }
      ogpBusy.delete(u);
      document.querySelectorAll('.ogp-slot').forEach((s) => { if (s.dataset.url === u && !s.firstChild) s.innerHTML = ogpCard(u); });
    }
  };
  await Promise.all([worker(), worker(), worker()]);
}

/* ---------- 上部プログレスバー ---------- */
const Prog = (() => {
  const wrap = document.getElementById('topbar'), bar = wrap && wrap.firstElementChild;
  let n = 0, v = 0, tick = 0, fin = 0, hide = 0;
  const paint = () => { if (bar) bar.style.transform = `scaleX(${v})`; };
  const start = () => {
    n++; clearTimeout(fin);
    if (!wrap || tick) return;
    clearTimeout(hide);
    wrap.style.transition = bar.style.transition = 'none';   // 0%に即リセット
    v = 0; paint(); wrap.style.opacity = 1;
    void wrap.offsetWidth;
    wrap.style.transition = bar.style.transition = '';
    v = 0.08; paint();
    tick = setInterval(() => { v += (0.92 - v) * 0.04; paint(); }, 200); // 待っている間じわじわ進む
  };
  const finish = () => {
    if (n > 0) return;
    clearInterval(tick); tick = 0;
    v = 1; paint();
    hide = setTimeout(() => { wrap.style.opacity = 0; }, 250);
  };
  const end = () => {
    if (n > 0) n--;
    if (!wrap || !tick) return;
    v += (0.92 - v) * 0.2; paint();                          // 1件完了するごとに前進
    if (n === 0) fin = setTimeout(finish, 150);              // 直列処理の継ぎ目でちらつかないよう少し待つ
  };
  // Promiseの開始〜完了をバーに反映する(結果はそのまま返す)
  const track = (p) => { start(); p.then(end, end); return p; };
  return { track };
})();

/* ===== ℤ : API レイヤー (api.php) ===== */
const sh = (h) => h || '';
let CSRF = '';
async function apiCore(a, { method = 'GET', body = {}, q = {} } = {}) {
  let url = 'api.php?a=' + encodeURIComponent(a);
  const opt = { method, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } };
  if (method === 'POST') { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  else for (const [k, v] of Object.entries(q)) if (v !== undefined && v !== null) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
  let r;
  try { r = await fetch(url, opt); } catch (_) { throw new Error('サーバーに接続できません'); }
  const d = await r.json().catch(() => ({ error: 'サーバーエラーが発生しました' }));
  if (!r.ok) throw new Error(d.error || 'エラー');
  return d;
}
// 通信を伴う操作もバーに反映。me/ogpは常時走るので除外
function api(a, o) { const r = apiCore(a, o); return (a === 'me' || a === 'ogp') ? r : Prog.track(r); }

let ME = null, seq = 0, searchTimer;
const S = { q: '', tabs: { home: 'all', search: 'all', notif: 'all', prof: 'posts' }, editing: false, badges: null, parent: null, quote: null, pending: '', media: { dlg: [], rep: [] }, repFor: null };
let EMO = null, mediaSeq = 0;                       // カスタム絵文字キャッシュ / 添付の連番
const TA = { dlg: '#dlg-text', rep: '#rep-text' };
const MAX_IMG_MB = 10, MAX_VIDEO_MB = 50, MAX_IMGS = 4, MAX_DECO_MB = 3, MAX_BADGES = 5;
const emoImg = (name, url) => `<img class="cemoji" src="${esc(url)}" alt=":${esc(name)}:" title=":${esc(name)}:" loading="lazy">`;
async function loadEmojis(force) {
  if (EMO && !force) return EMO;
  try { EMO = (await apiCore('emoji_list')).emojis; } catch (_) { EMO = EMO || []; }
  return EMO;
}
const app = $('#app'), dlg = $('#dlg');

/* ---------- リアクション選択ポップアップ ---------- */
const rxPick = document.createElement('div'); rxPick.id = 'rx-pick'; rxPick.hidden = true; document.body.appendChild(rxPick);
// リポスト / 引用 メニュー
const rtMenu = document.createElement('div'); rtMenu.id = 'rt-menu'; rtMenu.hidden = true; document.body.appendChild(rtMenu);
const closePicker = () => { rxPick.hidden = true; rxPick.dataset.id = ''; rtMenu.hidden = true; rtMenu.dataset.id = ''; };
function placeAt(el, btn) {
  const r = btn.getBoundingClientRect(), w = el.offsetWidth, h = el.offsetHeight;
  el.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
  el.style.top = (r.top - h - 8 >= 8 ? r.top - h - 8 : r.bottom + 8) + 'px';
}
const placePicker = (btn) => placeAt(rxPick, btn);
function openRt(btn, id) {
  closePicker();
  const on = btn.dataset.on === '1';
  rtMenu.innerHTML = `<button data-act="rt" data-id="${id}"><i class="fa-solid fa-retweet"></i>${on ? 'リポストを取り消す' : 'リポスト'}</button>`
    + `<button data-act="quote" data-id="${id}"><i class="fa-solid fa-quote-left"></i>引用</button>`;
  rtMenu.hidden = false; rtMenu.dataset.id = id; placeAt(rtMenu, btn);
}
function openPicker(btn, id) {
  closePicker();
  const mine = new Set((btn.dataset.my || '').split(' ').filter(Boolean));
  const paint = () => {
    rxPick.innerHTML = RX_SET.map((x) => `<button class="${mine.has(x) ? 'on' : ''}" data-act="react" data-id="${id}" data-emoji="${x}" aria-label="${x}">${x}</button>`).join('')
      + (EMO && EMO.length ? '<i class="rx-sep"></i>' + EMO.map((e) => { const k = ':' + e.name + ':';
        return `<button class="${mine.has(k) ? 'on' : ''}" data-act="react" data-id="${id}" data-emoji="${esc(k)}" aria-label="${esc(k)}" title="${esc(k)}">${emoImg(e.name, e.url)}</button>`; }).join('') : '');
  };
  paint(); rxPick.hidden = false; rxPick.dataset.id = id; placePicker(btn);
  if (!EMO) loadEmojis().then(() => { if (!rxPick.hidden && rxPick.dataset.id === String(id) && EMO.length) { paint(); placePicker(btn); } });
}

const post = (a, body) => api(a, { method: 'POST', body });

/* ---------- 部品 ---------- */
const head = (title, sub, back) => `<div class="view-header">${back ? `<a class="back" href="${back}" aria-label="戻る"><i class="fa-solid fa-arrow-left"></i></a>` : ''}<div><h1>${title}</h1>${sub ? `<div class="sub">${sub}</div>` : ''}</div></div>`;
const tabs = (g, items) => `<div class="tabs">${items.map(([k, l]) => `<button data-tabgroup="${g}" data-tab="${k}" class="${S.tabs[g] === k ? 'active' : ''}">${l}</button>`).join('')}</div>`;
const empty = (icon, title, text) => `<div class="empty"><i class="${icon}"></i><h2>${title}</h2><p>${text}</p></div>`;
const list = (ps, e) => ps.length ? ps.map(postHtml).join('') : e;

const rxFace = (x, em) => { const m = /^:([a-z0-9_]{2,32}):$/.exec(x); return m && em && em[m[1]] ? emoImg(m[1], em[m[1]]) : esc(x); };
// バッジ(好きな絵文字を最大5個)
const badgesHtml = (u, cls = '') => (u.badges && u.badges.length)
  ? `<span class="ubadges ${cls}" aria-label="バッジ">${u.badges.map((b) => `<span class="ubadge" title="${esc(b.e)}">${b.url ? emoImg(b.e.slice(1, -1), b.url) : esc(b.e)}</span>`).join('')}</span>` : '';
const EMOJI_RE = (() => { try { return new RegExp('\\p{Extended_Pictographic}|\\p{Regional_Indicator}|^[#*0-9]\\uFE0F?\\u20E3$', 'u'); } catch (_) { return /[\u00A9\u00AE\u203C-\u3299\uD83C-\uDBFF]/; } })();
const graphemes = (s) => (window.Intl && Intl.Segmenter) ? [...new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(s)].map((x) => x.segment) : Array.from(s);
const BADGE_PICK = '😀 😃 😄 😁 😆 🥹 😅 😂 🤣 😊 😇 🙂 😉 😍 🥰 😘 😋 😛 😜 🤪 🤗 🤔 😎 🤓 🥳 😏 😴 😭 😤 😡 🥺 😱 🤯 🫠 🙈 💀 👻 👽 🤖 💩 😺 👍 👏 🙌 🙏 💪 ✌️ 🤞 🤘 👀 🧠 ❤️ 🧡 💛 💚 💙 💜 🖤 🤍 💖 💯 💢 💥 ✨ 🔥 🌈 ⭐ 🌟 ⚡ ❄️ ☀️ 🌙 🌸 🌻 🌹 🍀 🌱 🌳 🍎 🍓 🍑 🍕 🍔 🍟 🍣 🍜 🍙 🍰 🍩 🍺 ☕ 🧋 🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐸 🐵 🐔 🐧 🐦 🦉 🦄 🐝 🦋 🐢 🐙 🐬 🐳 🦈 ⚽ 🏀 🎮 🎧 🎸 🎹 🎨 📚 💻 📱 🚀 ✈️ 🚗 🚲 🏠 🏆 👑 💎 🎁 🎉 🎂 🔮 🧩 🎲 🪐 🌍 🗻'.split(' ');
const mediaHtml = (p) => { const ms = p.media || []; if (!ms.length) return '';
  if (ms[0].kind === 'video') { const v = ms[0];
    return `<div class="media vid"><video src="${esc(v.url)}#t=0.1" controls playsinline preload="metadata"${v.w && v.h ? ` style="aspect-ratio:${v.w}/${v.h}"` : ''}></video></div>`; }
  return `<div class="media m${ms.length}">${ms.map((m) => `<button type="button" class="mi" data-act="lightbox" data-src="${esc(m.url)}" aria-label="画像を拡大"><img src="${esc(m.url)}" alt="" loading="lazy"${ms.length === 1 && m.w && m.h ? ` width="${m.w}" height="${m.h}"` : ''}></button>`).join('')}</div>`; };
const rxChips = (p) => { const ks = Object.keys(p.rx || {}).sort((a, b) => p.rx[b] - p.rx[a]); if (!ks.length) return '';
  return `<div class="rx-list">${ks.map((x) => `<button class="rx-chip ${(p.myrx || []).includes(x) ? 'on' : ''}" data-act="react" data-id="${p.id}" data-emoji="${esc(x)}" aria-label="${esc(x)}でリアクション"><span>${rxFace(x, p.emojis)}</span><span class="n">${p.rx[x]}</span></button>`).join('')}</div>`; };
const PCACHE = new Map();   // 表示したポストを覚えておく(引用ダイアログ用)
const quoteCard = (q, link = true) => {
  if (!q) return '';
  if (q.deleted) return '<div class="quote gone">このポストは削除されました</div>';
  const m = (q.media || [])[0];
  const media = m ? (m.kind === 'video' ? '<div class="quote-note"><i class="fa-solid fa-video"></i> 動画</div>'
    : `<div class="quote-img"><img src="${esc(m.url)}" alt="" loading="lazy"></div>`) : '';
  const inner = `<div class="quote-h"><span class="qav">${av(q)}</span><span class="qn">${esc(q.name)}</span><span class="qu">@${sh(q.handle)}</span><span class="qt">· ${ago(q.ts)}</span></div>`
    + (q.body ? `<div class="quote-b">${emojify(esc(q.body), q.emojis)}</div>` : '') + media;
  return link ? `<a class="quote" href="#/post/${q.id}">${inner}</a>` : `<div class="quote">${inner}</div>`;
};
function postHtml(p) {
  PCACHE.set(p.id, p); if (PCACHE.size > 2000) PCACHE.delete(PCACHE.keys().next().value);
  return `<article class="post" data-id="${p.id}"><a class="avatar" href="#/profile/${p.handle}">${avd(p)}</a>
    <div class="content">
      ${p.rtBy ? `<div class="rt-by"><i class="fa-solid fa-retweet"></i>${p.rtBy.mine ? 'あなた' : `<a href="#/profile/${p.rtBy.handle}">${esc(p.rtBy.name)}</a>さん`}がリポスト</div>` : ''}
      <div class="header"><a class="name" href="#/profile/${p.handle}">${esc(p.name)}</a>${badgesHtml(p)}<span class="username">@${sh(p.handle)}</span><a class="time" href="#/post/${p.id}">· ${ago(p.ts)}</a>
        ${p.mine ? `<button class="btn-del" data-act="del" data-id="${p.id}" aria-label="削除"><i class="fa-regular fa-trash-can"></i></button>` : ''}</div>
      ${p.body ? `<p class="text">${rich(p.body, p.emojis)}</p>` : ''}${mediaHtml(p)}${quoteCard(p.quote)}${ogpSlot(p.body)}${rxChips(p)}
      <div class="actions">
        <button class="reply" data-act="reply" data-id="${p.id}" aria-label="返信"><i class="fa-regular fa-comment"></i><span>${p.rep}</span></button>
        <button class="repost ${p.reposted ? 'on' : ''}" data-act="rt-open" data-id="${p.id}" data-on="${p.reposted ? 1 : 0}" aria-haspopup="menu" aria-label="リポスト・引用"><i class="fa-solid fa-retweet"></i><span>${p.rt + (p.qt || 0)}</span></button>
        <button class="like ${p.liked ? 'on' : ''}" data-act="like" data-id="${p.id}" aria-label="いいね"><i class="fa-${p.liked ? 'solid' : 'regular'} fa-heart"></i><span>${p.likes}</span></button>
        <button class="react ${(p.myrx || []).length ? 'on' : ''}" data-act="react-open" data-id="${p.id}" data-my="${esc((p.myrx || []).join(' '))}" aria-label="リアクションを選ぶ"><i class="fa-regular fa-face-smile"></i></button>
        <button class="bookmark ${p.bookmarked ? 'on' : ''}" data-act="bm" data-id="${p.id}" aria-label="ブックマーク"><i class="fa-${p.bookmarked ? 'solid' : 'regular'} fa-bookmark"></i></button>
        <button class="share" data-act="share" data-id="${p.id}" aria-label="共有"><i class="fa-solid fa-arrow-up-from-bracket"></i></button>
      </div></div></article>`;
}
const anonBanner = () => `<div class="row anon-banner"><div class="grow"><b>ℤ へようこそ</b><div class="trend-meta">ログインすると、ポスト・返信・いいね・フォローができます。</div></div><a class="btn blue" href="#/login">ログイン</a><a class="btn" href="#/signup">新規登録</a></div>`;
const VIEWERS_MAX = 12;
const viewersHtml = (d) => {
  const vs = d.viewers || [], more = vs.length - VIEWERS_MAX;
  return `<div class="viewers"><span class="vlabel"><i class="fa-regular fa-eye"></i> 閲覧</span>`
    + (vs.length ? `<div class="vlist">${vs.slice(0, VIEWERS_MAX).map((u) => `<a class="vw" href="#/profile/${u.handle}" title="${esc(u.name)}" aria-label="${esc(u.name)}">${av(u)}</a>`).join('')}</div>${more > 0 ? `<span class="vmore">+${more}</span>` : ''}<span class="vcount">${vs.length}人</span>`
      : '<span class="vcount">まだ閲覧者はいません</span>')
    + (d.canRecord ? `<button class="vtoggle" data-act="noview">${d.noview ? '閲覧の記録を再開' : '閲覧を記録しない'}</button>` : '') + '</div>';
};
const followBtn = (u) => (!ME || u.mine) ? '' : `<button class="btn ${u.following || u.is_following ? '' : 'dark'}" data-act="follow" data-handle="${u.handle}">${u.following || u.is_following ? 'フォロー中' : 'フォロー'}</button>`;
const userRow = (u) => `<div class="row"><a class="avatar lg" href="#/profile/${u.handle}">${avd(u)}</a>
  <div class="grow"><a href="#/profile/${u.handle}"><div class="nm">${esc(u.name)}${badgesHtml(u)}</div><div class="hd">@${sh(u.handle)}</div></a><div class="bio">${rich(u.bio || '')}</div></div>${followBtn(u)}</div>`;

/* ---------- 投稿フォーム: 画像・動画・カスタム絵文字 ---------- */
const toolsBar = (t) => `<span class="c-tools"><input type="file" id="file-media-${t}" data-media="${t}" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime" multiple hidden>
  <button type="button" class="c-btn" data-act="attach" data-t="${t}" aria-label="画像・動画を追加" title="画像・動画を追加"><i class="fa-regular fa-image"></i></button>
  <button type="button" class="c-btn" data-act="emo-toggle" data-t="${t}" aria-label="カスタム絵文字" title="カスタム絵文字"><i class="fa-regular fa-face-smile"></i></button></span>`;
const toolsExtra = (t) => `<div class="emo-panel" id="emo-${t}" hidden></div><div class="media-prev" id="mp-${t}">${prevHtml(t)}</div>`;
function prevHtml(t) {
  return (S.media[t] || []).map((it) => `<div class="mp-item ${it.state === 'err' ? 'err' : ''}" data-k="${it.key}">
    ${it.kind === 'video' ? `<video src="${it.local}#t=0.1" muted playsinline preload="metadata"></video>` : `<img src="${it.local}" alt="">`}
    ${it.kind === 'video' ? '<span class="mp-vid"><i class="fa-solid fa-play"></i></span>' : ''}
    ${it.state === 'up' ? `<div class="mp-prog"><span style="width:${it.pct}%"></span></div>` : ''}
    ${it.state === 'err' ? `<div class="mp-err" title="${esc(it.msg || '')}"><i class="fa-solid fa-triangle-exclamation"></i></div>` : ''}
    <button type="button" class="mp-rm" data-act="media-rm" data-t="${t}" data-k="${it.key}" aria-label="削除"><i class="fa-solid fa-xmark"></i></button></div>`).join('');
}
function renderPrev(t) { const el = $('#mp-' + t); if (el) { el.innerHTML = prevHtml(t); el.dataset.n = S.media[t].length; } }
function clearMedia(t) {
  (S.media[t] || []).forEach((it) => { if (it.xhr) it.xhr.abort(); URL.revokeObjectURL(it.local); });
  S.media[t] = []; renderPrev(t);
}
const mediaIds = (t) => {
  const l = S.media[t];
  if (l.some((x) => x.state === 'up')) throw new Error('アップロードが終わるまでお待ちください');
  if (l.some((x) => x.state === 'err')) throw new Error('アップロードに失敗したメディアを取り除いてください');
  return l.map((x) => x.id);
};
const probeVideo = (src) => new Promise((res) => {
  const v = document.createElement('video'); v.preload = 'metadata'; v.muted = true;
  const done = (r) => { clearTimeout(tm); v.removeAttribute('src'); v.load(); res(r); };
  const tm = setTimeout(() => done(null), 4000);
  v.onloadedmetadata = () => done(v.videoWidth && v.videoHeight ? { w: v.videoWidth, h: v.videoHeight } : null);
  v.onerror = () => done(null); v.src = src;
});
function addFiles(t, files) {
  const list = S.media[t];
  for (const file of files) {
    const vid = /^video\/(mp4|webm|quicktime)$/.test(file.type), img = /^image\/(jpeg|png|gif|webp)$/.test(file.type);
    if (!vid && !img) { toast('対応していない形式です: ' + file.name); continue; }
    if (vid && list.length) { toast('動画は画像と一緒に添付できません'); continue; }
    if (img && list.some((x) => x.kind === 'video')) { toast('動画と画像は一緒に添付できません'); continue; }
    if (list.length >= (vid ? 1 : MAX_IMGS)) { toast('画像は' + MAX_IMGS + '枚までです'); continue; }
    if (file.size > (vid ? MAX_VIDEO_MB : MAX_IMG_MB) * 1048576) { toast(`${file.name} は大きすぎます(${vid ? '動画' + MAX_VIDEO_MB : '画像' + MAX_IMG_MB}MBまで)`); continue; }
    const it = { key: ++mediaSeq, file, kind: vid ? 'video' : 'image', local: URL.createObjectURL(file), state: 'up', pct: 0 };
    list.push(it); startUpload(t, it);
  }
  renderPrev(t);
}
async function startUpload(t, it) {
  const fd = new FormData(); fd.append('file', it.file, it.file.name);
  if (it.kind === 'video') { const d = await probeVideo(it.local); if (it.gone) return; if (d) { fd.append('w', d.w); fd.append('h', d.h); } }
  const x = new XMLHttpRequest(); it.xhr = x;
  x.open('POST', 'api.php?a=upload_media'); x.setRequestHeader('X-CSRF-Token', CSRF);
  x.upload.onprogress = (e) => {
    if (!e.lengthComputable) return;
    it.pct = Math.round(e.loaded / e.total * 100);
    const bar = document.querySelector(`.mp-item[data-k="${it.key}"] .mp-prog span`); if (bar) bar.style.width = it.pct + '%';
  };
  const fail = (msg) => { it.xhr = null; it.state = 'err'; it.msg = msg; toast(msg); renderPrev(t); };
  x.onload = () => {
    let d = {}; try { d = JSON.parse(x.responseText); } catch (_) {}
    if (x.status >= 200 && x.status < 300 && d.id) { it.xhr = null; it.state = 'done'; it.id = d.id; renderPrev(t); }
    else fail(d.error || 'アップロードに失敗しました');
  };
  x.onerror = () => fail('サーバーに接続できません');
  x.send(fd);
}
async function renderEmoPanel(t) {
  const el = $('#emo-' + t); if (!el) return;
  const em = await loadEmojis();
  el.innerHTML = em.length
    ? em.map((e) => `<button type="button" data-act="emo-ins" data-t="${t}" data-name="${esc(e.name)}" title=":${esc(e.name)}:" aria-label=":${esc(e.name)}:">${emoImg(e.name, e.url)}</button>`).join('')
    : '<span class="emo-none">カスタム絵文字はまだありません。</span>';
  el.insertAdjacentHTML('beforeend', '<div class="emo-foot"><button type="button" class="linklike" data-act="emo-manage">追加・管理</button></div>');
}
function insertAtCursor(ta, str) {
  if (ta.value.length + str.length > ta.maxLength) { toast('文字数の上限を超えます'); return; }
  const a = ta.selectionStart ?? ta.value.length, z = ta.selectionEnd ?? a;
  ta.setRangeText(str, a, z, 'end'); ta.focus(); ta.dispatchEvent(new Event('input', { bubbles: true }));
}

/* ---------- 認証画面 ---------- */
function authView(mode) {
  const err = '<div id="auth-err" class="note bad" hidden></div>';
  if (mode === 'login') return `<div class="auth"><h1>ℤ</h1><h2>ログイン</h2><form id="auth-form" data-mode="login"><input name="handle" placeholder="ユーザー名" autocomplete="username" autocapitalize="off" required><input name="password" type="password" placeholder="パスワード" autocomplete="current-password" required>${err}<button class="btn blue">ログイン</button></form>
    <p>アカウントをお持ちでない方は <a href="#/signup">新規登録</a></p><p><a href="#/home">ログインせずに閲覧する</a></p></div>`;
  return `<div class="auth"><h1>ℤ</h1><h2>アカウントを作成</h2><p>自由に話せる場所へようこそ。</p>
    <form id="auth-form" data-mode="signup"><input name="handle" placeholder="ユーザー名(半角英数字と _ の3〜15文字)" pattern="[A-Za-z0-9_]{3,15}" maxlength="15" autocomplete="username" autocapitalize="off" required><input name="name" placeholder="表示名(省略するとユーザー名)" maxlength="30"><input name="password" type="password" placeholder="パスワード(8文字以上)" minlength="8" autocomplete="new-password" required>${err}<button class="btn blue">登録して始める</button></form><p><a href="#/login">ログインへ戻る</a></p><p><a href="#/home">ログインせずに閲覧する</a></p></div>`;
}

/* ---------- 各ページ ---------- */
const searchTabs = () => S.q.trim() ? tabs('search', [['all', 'すべて'], ['users', 'ユーザー'], ['posts', 'ポスト']]) : '';
async function searchResults() {
  const q = S.q.trim(), t = S.tabs.search;
  if (!q) {
    const d = await api('trends');
    return '<h2 class="section-title">トレンド</h2>' + (d.trends.length ? d.trends.map((x) => `<a class="row" href="#/search/${encodeURIComponent(x.q || x.tag)}"><div class="grow"><div class="trend-meta">${esc(x.label || 'トレンド')}</div><div class="trend-topic">${esc(x.tag)}</div><div class="trend-meta">${x.count}件のポスト</div></div></a>`).join('') : '<div class="row trend-meta">まだトレンドはありません</div>')
      + (d.users.length ? '<h2 class="section-title">おすすめユーザー</h2>' + d.users.map(userRow).join('') : '');
  }
  const d = await api('search', { q: { q } });
  let out = '';
  if (t !== 'posts') out += d.users.slice(0, t === 'all' ? 3 : 99).map(userRow).join('');
  if (t !== 'users') out += d.posts.map(postHtml).join('');
  return out || empty('fa-solid fa-magnifying-glass', `「${esc(q)}」の結果はありません`, '別のキーワードで検索してみてください');
}
const loadSearch = async () => { const r = $('#sr'); if (r) { const h = await searchResults(); if ($('#sr')) { $('#sr').innerHTML = h; hydrateOgp(); observeSeen(); } } };

const V = {
  login: (a) => ({ title: 'ログイン', html: authView('login', a) }),
  signup: (a) => ({ title: '新規登録', html: authView('signup', a) }),

  async home() {
    const d = await api('timeline', { q: { scope: ME ? S.tabs.home : 'all' } });
    return { title: 'ホーム', html: head('ホーム') + (ME ? tabs('home', [['all', 'すべて'], ['following', 'フォロー中']]) : anonBanner())
      + list(d.posts, empty('fa-regular fa-comments', 'ポストがありません', !ME ? 'ログインして最初のポストをしてみましょう' : S.tabs.home === 'following' ? 'ユーザーをフォローするとここに表示されます' : '最初のポストをしてみましょう')) };
  },
  search() {
    return { title: '検索', html: `<div class="view-header"><label class="search-field"><i class="fa-solid fa-magnifying-glass"></i><input id="sv-input" type="search" placeholder="検索" value="${esc(S.q)}" aria-label="検索" autocomplete="off"></label></div><div id="st">${searchTabs()}</div><div id="sr"></div>`, after: loadSearch };
  },
  async post(id) {
    const d = await api('thread', { q: { id } });
    const p = d.post;
    const parentLink = p.parent_id ? `<a class="reply-to" href="#/post/${p.parent_id}"><i class="fa-solid fa-turn-up"></i> 返信元のポストを見る</a>` : '';
    if (S.repFor !== p.id) { clearMedia('rep'); S.repFor = p.id; }
    const box = !ME ? '<div class="row trend-meta">返信するには <a href="#/login">ログイン</a> してください</div>' : `<div class="reply-box"><span class="avatar">${avd(ME)}</span><div class="grow">
      <textarea id="rep-text" maxlength="500" placeholder="@${sh(p.handle)} さんに返信" aria-label="返信"></textarea>
      ${toolsExtra('rep')}
      <div class="foot">${toolsBar('rep')}<span id="rep-count">500</span><button class="btn blue" data-act="rep-send" data-id="${p.id}">返信</button></div></div></div>`;
    return { title: 'ポスト', html: head('ポスト', '', p.parent_id ? `#/post/${p.parent_id}` : '#/home') + parentLink + postHtml(p) + (ME ? viewersHtml(d) : '') + box
      + (d.replies.length ? d.replies.map(postHtml).join('') : '<div class="row trend-meta">まだ返信はありません</div>') };
  },
  async emojis() {
    const d = await api('emoji_list'); EMO = d.emojis;
    const form = `<div class="emoji-add"><div class="ea-row"><input id="emo-name" placeholder="名前(半角英数字と _ 、2〜32文字)" maxlength="32" autocomplete="off" autocapitalize="off">
      <input type="file" id="emo-file" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
      <img id="emo-prev" class="cemoji-lg" alt="" hidden>
      <button type="button" class="btn" data-act="emo-pick">画像を選ぶ</button>
      <button type="button" class="btn blue" data-act="emo-add">追加</button></div>
      <small>投稿やリアクションで <code>:名前:</code> として使えます。画像は128pxに縮小されます(アニメGIFは256px・512KB以下ならそのまま)。1人${50}個まで。</small></div>`;
    const grid = d.emojis.length ? `<div class="emoji-grid">${d.emojis.map((e) => `<div class="emoji-item"><img class="cemoji-lg" src="${esc(e.url)}" alt=":${esc(e.name)}:" loading="lazy"><div class="en">:${esc(e.name)}:</div><div class="eb">@${esc(e.by)}</div>${e.mine ? `<button class="btn-del" data-act="emo-del" data-name="${esc(e.name)}" aria-label="削除"><i class="fa-regular fa-trash-can"></i></button>` : ''}</div>`).join('')}</div>`
      : empty('fa-regular fa-face-smile', 'カスタム絵文字はまだありません', '最初の絵文字を追加してみましょう');
    return { title: 'カスタム絵文字', html: head('カスタム絵文字', d.emojis.length + '個', '#/home') + form + grid };
  },
  async followers(h) { return followList(h, 'followers'); },
  async following(h) { return followList(h, 'following'); },
  async notifications() {
    const d = await api('notifs');
    const icon = { quote: 'fa-solid fa-quote-left', react: 'fa-regular fa-face-smile', like: 'fa-solid fa-heart', repost: 'fa-solid fa-retweet', follow: 'fa-solid fa-user-plus', reply: 'fa-regular fa-comment', mention: 'fa-solid fa-at' };
    const verb = { quote: 'さんがあなたのポストを引用しました', like: 'があなたのポストにいいねしました', repost: 'があなたのポストをリポストしました', follow: 'さんにフォローされました', reply: 'さんが返信しました', mention: 'さんがあなたをメンションしました' };
    const items = d.items.filter((n) => S.tabs.notif === 'all' || n.type === 'reply' || n.type === 'mention').map((n) =>
      `<div class="row notif ${n.is_read ? '' : 'unread'}"><div class="ico ${n.type === 'mention' ? 'reply' : n.type}"><i class="${icon[n.type]}"></i></div>
       <div class="grow"><div class="who"><a class="avatar" href="#/profile/${n.handle}">${avd(n)}</a></div>
       <div><a href="#/profile/${n.handle}"><b>${esc(n.name)}</b></a>${n.type === 'react' ? `があなたのポストに${rxFace(n.emoji, n.emojis)}でリアクションしました` : verb[n.type]}　<span class="when">${ago(n.ts)}</span></div>
       ${n.excerpt ? `<a class="excerpt" href="#/post/${n.post_id}">${esc(n.excerpt)}</a>` : ''}</div></div>`).join('');
    return { title: '通知', html: head('通知') + tabs('notif', [['all', 'すべて'], ['mentions', 'メンション']]) + (items || empty('fa-regular fa-bell', '通知はありません', 'いいねや返信がここに表示されます')), after: () => setBadge(0) };
  },
  async messages(h) {
    if (!h) {
      const d = await api('convos');
      const rows = d.items.map((c) => `<a class="row convo" href="#/messages/${c.handle}"><span class="avatar lg">${avd(c)}</span><div class="grow"><div><span class="nm">${esc(c.name)}</span> <span class="hd">@${sh(c.handle)}</span></div><div class="last">${c.mine ? 'あなた: ' : ''}${esc(c.body)}</div></div><span class="t">${ago(c.ts)}</span></a>`).join('');
      return { title: 'メッセージ', html: head('メッセージ') + (rows || empty('fa-regular fa-envelope', 'メッセージはありません', 'プロフィールからメッセージを送れます')) };
    }
    const d = await api('dm', { q: { handle: h } });
    const msgs = d.msgs.map((m) => `<div class="bubble ${m.mine ? 'me' : 'them'}">${esc(m.body)}</div><div class="bubble-time ${m.mine ? 'me' : ''}">${ago(m.ts)}</div>`).join('');
    return { title: d.user.name + ' とのメッセージ', html: head(esc(d.user.name), '@' + sh(d.user.handle), '#/messages') + `<div class="chat">${msgs}</div>
      <div class="compose"><input id="dm-in" data-handle="${d.user.handle}" maxlength="1000" placeholder="メッセージを入力" autocomplete="off" aria-label="メッセージ"><button data-act="dm-send" aria-label="送信"><i class="fa-solid fa-paper-plane"></i></button></div>`,
      after() { window.scrollTo(0, document.body.scrollHeight); const i = $('#dm-in'); if (i) i.focus(); } };
  },
  async bookmarks() {
    const d = await api('bookmarks');
    return { title: 'ブックマーク', html: head('ブックマーク', '@' + sh(ME.handle)) + list(d.posts, empty('fa-regular fa-bookmark', 'ブックマークはまだありません', 'ポストを保存すると、ここに表示されます')) };
  },
  async profile(arg) {
    const h = arg || ME.handle;
    const d = await api('user', { q: { handle: h, tab: S.tabs.prof } });
    const u = d.user;
    const btn = !ME ? '' : u.mine ? '<button class="btn" data-act="edit">プロフィールを編集</button>' : `<span class="btns"><a class="btn" href="#/messages/${u.handle}" aria-label="メッセージ"><i class="fa-regular fa-envelope"></i></a> ${followBtn(u)}</span>`;
    if (u.mine && S.editing && !S.badges) S.badges = (u.badges || []).map((b) => ({ ...b }));
    const form = u.mine && S.editing ? `<div class="edit-form">${imgEdit(u)}${badgeEdit()}<label>名前<input id="ed-name" maxlength="30" value="${esc(u.name)}"></label><label>自己紹介<textarea id="ed-bio" rows="3" maxlength="160">${esc(u.bio)}</textarea></label><div class="btns"><button class="btn" data-act="edit-cancel">キャンセル</button><button class="btn blue" data-act="edit-save">保存</button></div></div>` : '';
    return { title: u.name, html: head(esc(u.name), u.posts + '件のポスト', '#/home')
      + `<div class="banner" id="prof-banner" data-handle="${u.handle}"${u.banner ? ` style="background-image:url(${esc(imgUrl(u.banner))})"` : ''}></div><div class="prof"><div class="prof-top"><span class="avatar xl" id="prof-av" data-handle="${u.handle}">${avd(u)}</span>${btn}</div>
        <h2>${esc(u.name)}</h2><div class="hd">@${sh(u.handle)}</div>${badgesHtml(u, 'lg')}<div class="bio">${rich(u.bio)}</div>
        <div class="meta"><a class="meta-link" href="#/following/${u.handle}"><b>${u.following}</b> フォロー中</a><a class="meta-link" href="#/followers/${u.handle}"><b>${u.followers}</b> フォロワー</a></div></div>`
      + form + tabs('prof', [['posts', 'ポスト'], ['replies', '返信']].concat(u.mine ? [['likes', 'いいね']] : []))
      + list(d.posts, empty('fa-regular fa-face-smile', 'まだ何もありません', 'ここに表示できるポストはありません')),
      after: () => { if (u.mine && S.editing) paintBadgeEd(); } };
  },
};

/* ---------- フォロワー / フォロー中 一覧 ---------- */
async function followList(h, kind) {
  if (!h) h = ME.handle;
  const d = await api('follows', { q: { handle: h, kind } });
  const t = (k, l) => `<button data-go="#/${k}/${d.user.handle}" class="${kind === k ? 'active' : ''}">${l}</button>`;
  return { title: d.user.name + (kind === 'followers' ? ' のフォロワー' : ' のフォロー中'),
    html: head(esc(d.user.name), '@' + sh(d.user.handle), '#/profile/' + d.user.handle)
      + `<div class="tabs">${t('followers', 'フォロワー')}${t('following', 'フォロー中')}</div>`
      + (d.users.length ? d.users.map(userRow).join('')
        : empty('fa-regular fa-user', kind === 'followers' ? 'フォロワーはまだいません' : 'フォロー中のユーザーはいません', '')) };
}

/* ---------- ルーター ---------- */
function render(fresh) { return Prog.track(renderCore(fresh)); }
async function renderCore(fresh) {
  const parts = location.hash.replace(/^#\/?/, '').split('/');
  let name = parts[0] || 'home';
  const arg = decodeURIComponent(parts.slice(1).join('/'));
  const guest = ['login', 'signup'].includes(name);
  // ログインしなくても見られるのは ホーム / 検索 / ポスト / プロフィール / フォロー一覧。それ以外はログインが必要
  const needsMe = ['notifications', 'messages', 'bookmarks', 'emojis'].includes(name)
    || (['profile', 'followers', 'following'].includes(name) && !arg);
  if (!ME && needsMe) { location.hash = '#/login'; return; }
  if (ME && guest) { location.hash = '#/home'; return; }
  if (!V[name]) name = 'home';
  document.body.classList.toggle('guest', !ME && guest);   // ログイン/登録画面(サイドバーなし)
  document.body.classList.toggle('anon', !ME);             // 未ログインの閲覧中(ログイン導線を表示)
  document.body.dataset.route = name;
  if (fresh) { S.editing = false; S.badges = null; window.scrollTo(0, 0); if (name === 'search') S.q = arg; }
  const my = ++seq;
  let v;
  try { v = await V[name](arg); }
  catch (e) { v = { title: 'エラー', html: head('エラー', '', '#/home') + empty('fa-regular fa-face-frown', '表示できません', esc(e.message)) }; }
  if (my !== seq) return;
  closePicker();
  app.innerHTML = v.html;
  document.title = v.title + ' / ℤ';
  document.querySelectorAll('.sidebar .nav-item[data-route]').forEach((a) =>
    a.classList.toggle('active', a.dataset.route === name && (name !== 'profile' || !arg || (ME && arg === ME.handle))));
  if (v.after) v.after();
  hydrateOgp(); observeSeen();
}
const rerender = async () => { const y = window.scrollY; await render(false); window.scrollTo(0, y); };

// SNS 共有用リンク (/p/ID) を共有シート or クリップボードへ。OGP は api.php?a=share が返す
async function sharePost(id) {
  const url = location.origin + location.pathname.replace(/[^/]*$/, '') + 'p/' + encodeURIComponent(id);
  const pc = PCACHE.get(Number(id));
  if (navigator.share) {
    try { await navigator.share({ title: pc ? `${pc.name} (@${pc.handle}) さんのポスト` : 'ポスト', url }); return; }
    catch (e) { if (e && e.name === 'AbortError') return; }
  }
  try { await navigator.clipboard.writeText(url); }
  catch (e) {
    const t = document.createElement('textarea'); t.value = url; t.style.cssText = 'position:fixed;opacity:0';
    document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove();
  }
  toast('リンクをコピーしました');
}
function toast(msg) {
  const old = $('.toast'); if (old) old.remove();
  const el = document.createElement('div'); el.className = 'toast'; el.textContent = msg;
  document.body.appendChild(el); setTimeout(() => el.remove(), 2200);
}
/* ---------- アイコン・バナー ---------- */
function imgEdit(u) {
  const row = (k, l) => `<div class="img-ed-row"><span class="grow">${l}</span><input type="file" id="file-${k}" data-kind="${k}" accept="image/*" hidden><button type="button" class="btn" data-act="pick" data-kind="${k}">変更</button><button type="button" class="btn" id="del-${k}" data-act="img-del" data-kind="${k}"${u[k] ? '' : ' hidden'}>削除</button></div>`;
  const deco = `<div class="img-ed-row"><span class="avatar deco-prev" id="deco-prev">${avd(u)}</span><span class="grow">アイコンデコレーション</span><input type="file" id="file-deco" data-kind="deco" accept="image/png,image/webp,image/gif" hidden><button type="button" class="btn" data-act="pick" data-kind="deco">変更</button><button type="button" class="btn" id="del-deco" data-act="img-del" data-kind="deco"${u.deco ? '' : ' hidden'}>削除</button></div>`;
  return `<div class="img-edit">${row('avatar', 'アイコン画像')}${deco}${row('banner', 'バナー画像')}<small>画像は JPEG / PNG / WebP / GIF。デコレーションは周りが透明な PNG / WebP / GIF(アイコンの外側に少しはみ出して重なります)</small></div>`;
}
async function cropToBlob(file, w, h) {
  let bmp;
  try { bmp = await createImageBitmap(file); } catch (_) { throw new Error('この画像は読み込めません'); }
  const c = document.createElement('canvas'); c.width = w; c.height = h;
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h);
  ctx.imageSmoothingQuality = 'high';
  const s = Math.max(w / bmp.width, h / bmp.height), dw = bmp.width * s, dh = bmp.height * s;
  ctx.drawImage(bmp, (w - dw) / 2, (h - dh) / 2, dw, dh);
  if (bmp.close) bmp.close();
  return new Promise((res, rej) => c.toBlob((b) => b ? res(b) : rej(new Error('画像の変換に失敗しました')), 'image/jpeg', 0.88));
}
async function postForm(a, fd) {
  let r;
  try { r = await Prog.track(fetch('api.php?a=' + a, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF }, body: fd })); }
  catch (_) { throw new Error('サーバーに接続できません'); }
  const d = await r.json().catch(() => ({ error: 'サーバーエラーが発生しました' }));
  if (!r.ok) throw new Error(d.error || 'エラー');
  return d;
}
async function uploadImg(kind, file) {
  const blob = await cropToBlob(file, ...(kind === 'avatar' ? [400, 400] : [1500, 500]));
  const fd = new FormData(); fd.append('kind', kind); fd.append('file', blob, kind + '.jpg');
  return postForm('upload_image', fd);
}
async function uploadDeco(file) {
  if (!/^image\/(png|webp|gif)$/.test(file.type)) throw new Error('透過に対応した PNG / WebP / GIF を選んでください');
  if (file.size > MAX_DECO_MB * 1048576) throw new Error(`画像は${MAX_DECO_MB}MB以下にしてください`);
  const fd = new FormData(); fd.append('file', file, file.name);
  return postForm('upload_deco', fd);
}
const badgeEdit = () => `<div class="badge-edit"><div class="be-head"><b>バッジ</b><span id="be-count"></span></div>
  <div id="be-list" class="be-list"></div>
  <div class="be-add"><input id="be-in" placeholder="好きな絵文字を入力・貼り付け" maxlength="40" autocomplete="off" aria-label="バッジにする絵文字">
    <button type="button" class="btn" data-act="be-add">追加</button>
    <button type="button" class="c-btn" data-act="be-toggle" aria-label="絵文字を選ぶ" title="絵文字を選ぶ"><i class="fa-regular fa-face-smile"></i></button></div>
  <div class="emo-panel be-panel" id="be-panel" hidden></div>
  <small>プロフィールやポストの名前の横に最大${MAX_BADGES}個まで表示されます。カスタム絵文字は <code>:名前:</code> でも追加できます。</small></div>`;
function paintBadgeEd() {
  const l = $('#be-list'); if (!l) return;
  const b = S.badges || [];
  l.innerHTML = b.length ? b.map((x, i) => `<button type="button" class="be-chip" data-act="be-rm" data-i="${i}" title="取り除く" aria-label="${esc(x.e)}を取り除く">${x.url ? emoImg(x.e.slice(1, -1), x.url) : esc(x.e)}<i class="fa-solid fa-xmark"></i></button>`).join('')
    : '<span class="emo-none">まだありません</span>';
  const c = $('#be-count'); if (c) c.textContent = `${b.length} / ${MAX_BADGES}`;
}
function addBadge(o) {
  if (!S.badges) S.badges = [];
  if (S.badges.some((x) => x.e === o.e)) { toast('すでに追加されています'); return false; }
  if (S.badges.length >= MAX_BADGES) { toast(`バッジは${MAX_BADGES}個までです`); return false; }
  S.badges.push(o); paintBadgeEd(); return true;
}
async function renderBadgePanel() {
  const el = $('#be-panel'); if (!el) return;
  const em = await loadEmojis();
  el.innerHTML = BADGE_PICK.map((x) => `<button type="button" data-act="be-pick" data-e="${x}" aria-label="${x}">${x}</button>`).join('')
    + (em.length ? '<i class="rx-sep"></i>' + em.map((e) => `<button type="button" data-act="be-pick" data-e=":${esc(e.name)}:" data-url="${esc(e.url)}" title=":${esc(e.name)}:" aria-label=":${esc(e.name)}:">${emoImg(e.name, e.url)}</button>`).join('') : '');
}
// 画面を作り直さず(編集中の入力を保ったまま)表示中のアイコン・バナーを更新する
function applyImages() {
  if (!ME) return;
  fillMe();
  document.querySelectorAll(`a.avatar[href="#/profile/${ME.handle}"]`).forEach((a) => { a.innerHTML = avd(ME); });
  const pa = $('#prof-av'), pb = $('#prof-banner');
  if (pa && pa.dataset.handle === ME.handle) pa.innerHTML = avd(ME);
  const dp = $('#deco-prev'); if (dp) dp.innerHTML = avd(ME);
  if (pb && pb.dataset.handle === ME.handle) pb.style.backgroundImage = ME.banner ? `url(${imgUrl(ME.banner)})` : '';
  ['avatar', 'banner', 'deco'].forEach((k) => { const d = $('#del-' + k); if (d) d.hidden = !ME[k]; });
}

function setBadge(n) { const b = $('#badge'); if (b) { b.hidden = !n; b.textContent = n > 99 ? '99+' : n; } }
function fillMe() { $('#me-name').textContent = ME.name; $('#me-handle').textContent = '@' + sh(ME.handle); $('#me-av').innerHTML = avd(ME); }
async function boot() {
  const d = await api('me'); CSRF = d.csrf; ME = d.user;
  if (ME) { fillMe(); setBadge(d.unread); }
  document.body.classList.toggle('anon', !ME);
  SEEN.clear();
  loadTrends();
}
async function loadTrends() {
  try {
    const d = await api('trends');
    $('#trend-list').innerHTML = d.trends.length ? d.trends.map((x) => `<a href="#/search/${encodeURIComponent(x.q || x.tag)}" class="card-item"><div><div class="meta">${esc(x.label || 'トレンド')}</div><div class="topic">${esc(x.tag)}</div><div class="count">${x.count}件のポスト</div></div></a>`).join('')
      : '<div class="card-item"><div class="meta">ハッシュタグ付きのポストがまだありません</div></div>';
  } catch (_) {}
}
function openCompose(parent, quote) {
  const qp = quote ? PCACHE.get(Number(quote)) : null;
  if (quote && !qp) { toast('引用元のポストを読み込めませんでした'); return; }
  S.parent = parent || null; S.quote = qp ? qp.id : null;
  $('#dlg-title').textContent = qp ? 'ポストを引用' : parent ? '返信する' : 'ポストする';
  $('#dlg-quote').innerHTML = qp ? quoteCard(qp, false) : '';
  $('#dlg-text').placeholder = qp ? 'コメントを追加' : 'いまどうしてる？';
  $('#dlg-text').value = ''; $('#dlg-count').textContent = 500;
  clearMedia('dlg'); const ep = $('#emo-dlg'); if (ep) ep.hidden = true;
  dlg.showModal(); $('#dlg-text').focus();
}
async function sendDm() {
  const i = $('#dm-in'); if (!i || !i.value.trim()) return;
  await post('dm_send', { handle: i.dataset.handle, body: i.value.trim() });
  await render(false);
}

/* ---------- 画面に表示したポストを閲覧履歴に入れる ---------- */
// ポストが画面の半分以上(背の高いポストは画面の半分以上を占めた時)、1秒以上見えていたら「見た」とみなし、まとめて送信する
const SEEN = new Set(), seenQ = new Set(), seenTimers = new Map();
let seenFlushT = 0;
function flushSeen() {
  clearTimeout(seenFlushT); seenFlushT = 0;
  if (!ME || !seenQ.size) return;
  const ids = [...seenQ].slice(0, 50); ids.forEach((i) => seenQ.delete(i));
  fetch('api.php?a=view_seen', { method: 'POST', credentials: 'same-origin', keepalive: true,
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ ids }) }).catch(() => {});
  if (seenQ.size) seenFlushT = setTimeout(flushSeen, 1500);
}
const seenVisible = (e) => e.isIntersecting && (e.intersectionRatio >= 0.5 || e.intersectionRect.height >= window.innerHeight * 0.5);
const seenIO = 'IntersectionObserver' in window ? new IntersectionObserver((es) => {
  for (const e of es) {
    const el = e.target;
    if (seenVisible(e)) {
      if (seenTimers.has(el)) continue;
      seenTimers.set(el, setTimeout(() => {
        seenTimers.delete(el); seenIO.unobserve(el);
        const id = +el.dataset.id;
        if (ME && id && !SEEN.has(id)) { SEEN.add(id); seenQ.add(id); if (!seenFlushT) seenFlushT = setTimeout(flushSeen, 2000); }
      }, 1000));
    } else if (seenTimers.has(el)) { clearTimeout(seenTimers.get(el)); seenTimers.delete(el); }
  }
}, { threshold: [0, 0.25, 0.5, 0.75, 1] }) : null;
function observeSeen() {
  if (!seenIO) return;
  seenIO.disconnect(); seenTimers.forEach(clearTimeout); seenTimers.clear();   // 描画し直したDOMに付け替える
  if (ME) document.querySelectorAll('#app article.post[data-id]').forEach((el) => seenIO.observe(el));
}
document.addEventListener('visibilitychange', () => { if (document.hidden) flushSeen(); });

/* ---------- イベント ---------- */
document.addEventListener('click', async (e) => {
  if (!e.target.closest('#rx-pick, #rt-menu, [data-act="react-open"], [data-act="rt-open"]')) closePicker();
  if (e.target.closest('#lb')) { $('#lb').close(); return; }
  const tb = e.target.closest('[data-tab]');
  if (tb) { S.tabs[tb.dataset.tabgroup] = tb.dataset.tab; rerender(); return; }
  if (e.target.closest('[data-go]')) { location.hash = e.target.closest('[data-go]').dataset.go; return; }
  // ポストの余白・本文など、リンクやボタン以外をクリックしたら詳細(返信欄)を開く
  const art = e.target.closest('article.post[data-id]');
  if (art && !e.target.closest('a, button, input, textarea, label, video, .media') && !String(window.getSelection()).trim()) {
    const h = '#/post/' + art.dataset.id;
    if (location.hash !== h) location.hash = h;
    return;
  }
  const b = e.target.closest('[data-act]'); if (!b) return;
  if (b.tagName === 'A') e.preventDefault();
  const { act, id, handle } = b.dataset;
  if (!ME && act !== 'lightbox' && act !== 'share') { toast('ログインが必要です'); location.hash = '#/login'; return; }
  try {
    switch (act) {
      case 'share': await sharePost(id); break;
      case 'like': await post('like', { id }); rerender(); break;
      case 'react-open': if (!rxPick.hidden && rxPick.dataset.id === id) closePicker(); else openPicker(b, id); break;
      case 'react': closePicker(); await post('react', { id, emoji: b.dataset.emoji }); rerender(); break;
      case 'noview': await post('noview'); SEEN.clear(); rerender(); break;
      case 'rt-open': if (!rtMenu.hidden && rtMenu.dataset.id === id) closePicker(); else openRt(b, id); break;
      case 'rt': closePicker(); await post('repost', { id }); rerender(); break;
      case 'quote': closePicker(); openCompose(null, id); break;
      case 'bm': { const d = await post('bookmark', { id }); toast(d.on ? 'ブックマークに追加しました' : 'ブックマークを解除しました'); rerender(); break; }
      case 'follow': await post('follow', { handle }); rerender(); loadTrends(); break;
      case 'del': if (confirm('このポストを削除しますか？')) { await post('delete_post', { id }); rerender(); } break;
      case 'compose': openCompose(); break;
      case 'reply': openCompose(id); break;
      case 'rep-send': {
        const body = $('#rep-text').value.trim(), media = mediaIds('rep'); if (!body && !media.length) return;
        await post('post', { body, parent_id: id, media });
        clearMedia('rep'); toast('返信しました'); rerender(); loadTrends(); break;
      }
      case 'dlg-close': dlg.close(); break;
      case 'dlg-send': {
        const body = $('#dlg-text').value.trim(), media = mediaIds('dlg'); if (!body && !media.length) return;
        await post('post', { body, parent_id: S.parent, quote_id: S.quote, media });
        clearMedia('dlg'); dlg.close(); toast('ポストしました'); rerender(); loadTrends(); break;
      }
      case 'attach': { const i = $('#file-media-' + b.dataset.t); if (i) i.click(); break; }
      case 'media-rm': {
        const t = b.dataset.t, l = S.media[t], i = l.findIndex((x) => String(x.key) === b.dataset.k); if (i < 0) break;
        const it = l.splice(i, 1)[0]; it.gone = true; if (it.xhr) it.xhr.abort(); URL.revokeObjectURL(it.local);
        if (it.state === 'done') apiCore('delete_media', { method: 'POST', body: { id: it.id } }).catch(() => {});
        renderPrev(t); break;
      }
      case 'emo-toggle': { const el = $('#emo-' + b.dataset.t); if (!el) break; el.hidden = !el.hidden; if (!el.hidden) await renderEmoPanel(b.dataset.t); break; }
      case 'emo-ins': { const ta = $(TA[b.dataset.t]); if (ta) insertAtCursor(ta, ':' + b.dataset.name + ':'); break; }
      case 'emo-manage': if (dlg.open) dlg.close(); location.hash = '#/emojis'; break;
      case 'lightbox': { $('#lb-img').src = b.dataset.src; $('#lb').showModal(); break; }
      case 'emo-pick': { const i = $('#emo-file'); if (i) i.click(); break; }
      case 'emo-add': {
        const name = $('#emo-name').value.trim().toLowerCase(), file = ($('#emo-file').files || [])[0];
        if (!/^[a-z0-9_]{2,32}$/.test(name)) throw new Error('名前は半角英数字と _ の2〜32文字にしてください');
        if (!file) throw new Error('画像を選んでください');
        const fd = new FormData(); fd.append('name', name); fd.append('file', file, file.name);
        await postForm('emoji_add', fd); EMO = null; toast(':' + name + ': を追加しました'); rerender(); break;
      }
      case 'emo-del': if (confirm(':' + b.dataset.name + ': を削除しますか？\n(この絵文字のリアクションも消えます)')) { await post('emoji_delete', { name: b.dataset.name }); EMO = null; toast('削除しました'); rerender(); } break;
      case 'be-add': {
        const inp = $('#be-in'), v = inp.value.trim(); if (!v) break;
        const cm = /^:([A-Za-z0-9_]{2,32}):$/.exec(v);
        if (cm) {
          const hit = (await loadEmojis()).find((x) => x.name === cm[1].toLowerCase());
          if (!hit) throw new Error('そのカスタム絵文字は見つかりません');
          if (addBadge({ e: ':' + hit.name + ':', url: hit.url })) inp.value = '';
          break;
        }
        const gs = graphemes(v).filter((g) => g.trim() && EMOJI_RE.test(g));
        if (!gs.length) throw new Error('絵文字を入力してください');
        for (const g of gs) if (!addBadge({ e: g })) break;
        inp.value = ''; break;
      }
      case 'be-rm': if (S.badges) { S.badges.splice(+b.dataset.i, 1); paintBadgeEd(); } break;
      case 'be-toggle': { const el = $('#be-panel'); if (!el) break; el.hidden = !el.hidden; if (!el.hidden) await renderBadgePanel(); break; }
      case 'be-pick': addBadge(b.dataset.url ? { e: b.dataset.e, url: b.dataset.url } : { e: b.dataset.e }); break;
      case 'dm-send': await sendDm(); break;
      case 'edit': S.editing = true; S.badges = null; rerender(); break;
      case 'edit-cancel': S.editing = false; S.badges = null; rerender(); break;
      case 'pick': { const i = $('#file-' + b.dataset.kind); if (i) i.click(); break; }
      case 'img-del': {
        const kind = b.dataset.kind;
        if (!confirm(kind === 'avatar' ? 'アイコンを削除しますか？' : kind === 'deco' ? 'デコレーションを外しますか？' : 'バナーを削除しますか？')) break;
        await post(kind === 'deco' ? 'remove_deco' : 'remove_image', { kind }); ME[kind] = null; applyImages(); toast('削除しました'); break;
      }
      case 'edit-save': {
        const d = await post('profile', Object.assign({ name: $('#ed-name').value, bio: $('#ed-bio').value }, S.badges ? { badges: S.badges.map((x) => x.e) } : {}));
        Object.assign(ME, { name: d.name, bio: d.bio, avatar: d.avatar, banner: d.banner, deco: d.deco, badges: d.badges }); fillMe(); S.editing = false; S.badges = null; rerender(); toast('プロフィールを更新しました'); break;
      }
      case 'logout': await post('logout'); ME = null; await boot(); location.hash = '#/login'; render(true); break;
    }
  } catch (x) { toast(x.message); }
});

document.addEventListener('change', async (e) => {
  const mi = e.target.closest('input[type=file][data-media]');
  if (mi) { const fl = [...mi.files]; mi.value = ''; if (fl.length) addFiles(mi.dataset.media, fl); return; }
  const ef = e.target.closest('#emo-file');
  if (ef) {
    const f = ef.files[0], pv = $('#emo-prev'); if (!f) return;
    pv.src = URL.createObjectURL(f); pv.hidden = false;
    const nm = $('#emo-name'); if (!nm.value) nm.value = f.name.replace(/\.[^.]+$/, '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 32);
    return;
  }
  const i = e.target.closest('input[type=file][data-kind]'); if (!i || !i.files[0]) return;
  const kind = i.dataset.kind, file = i.files[0]; i.value = '';
  try { toast('アップロード中…'); const d = await (kind === 'deco' ? uploadDeco(file) : uploadImg(kind, file)); ME[kind] = d[kind]; applyImages(); toast(kind === 'avatar' ? 'アイコンを更新しました' : kind === 'deco' ? 'デコレーションを更新しました' : 'バナーを更新しました'); }
  catch (x) { toast(x.message); }
});

document.addEventListener('submit', async (e) => {
  const f = e.target.closest('#auth-form'); if (!f) return;
  e.preventDefault();
  const d = Object.fromEntries(new FormData(f)), err = $('#auth-err'), btn = f.querySelector('button');
  btn.disabled = true; err.hidden = true;
  try {
    await post(f.dataset.mode === 'signup' ? 'signup' : 'login', d);
    await boot(); location.hash = '#/home'; render(true);
  } catch (x) { err.hidden = false; err.textContent = x.message; }
  btn.disabled = false;
});

document.addEventListener('input', (e) => {
  if (e.target.id === 'dlg-text') $('#dlg-count').textContent = 500 - e.target.value.length;
  if (e.target.id === 'rep-text') $('#rep-count').textContent = 500 - e.target.value.length;
  if (e.target.id === 'sv-input') {
    S.q = e.target.value;
    history.replaceState(null, '', '#/search' + (S.q ? '/' + encodeURIComponent(S.q) : ''));
    $('#st').innerHTML = searchTabs();
    clearTimeout(searchTimer); searchTimer = setTimeout(loadSearch, 250);
  }
});

document.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter' || e.isComposing) return;
  if (e.target.id === 'rep-text' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); const b = $('[data-act="rep-send"]'); if (b) b.click(); return; }
  if (e.target.id === 'dm-in') { e.preventDefault(); sendDm().catch((x) => toast(x.message)); }
  if (e.target.id === 'be-in') { e.preventDefault(); const bb = $('[data-act="be-add"]'); if (bb) bb.click(); }
  if (e.target.id === 'side-search') {
    const v = e.target.value.trim();
    location.hash = '#/search' + (v ? '/' + encodeURIComponent(v) : ''); e.target.value = '';
  }
});

// OGP画像の読み込み失敗時は画像だけ取り除く
document.addEventListener('error', (e) => { const i = e.target; if (i.tagName === 'IMG' && i.classList.contains('cemoji')) { i.replaceWith(document.createTextNode(i.alt)); return; }
   if (i.tagName === 'IMG' && i.closest('.ogp')) i.remove(); }, true);

window.addEventListener('hashchange', () => { closePicker(); render(true); });
window.addEventListener('scroll', closePicker, { passive: true });
window.addEventListener('resize', closePicker);
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePicker(); });
setInterval(() => { if (ME) api('me').then((d) => setBadge(d.unread)).catch(() => {}); }, 60000);
$('#dlg-bar').innerHTML = toolsBar('dlg'); $('#dlg-extra').innerHTML = toolsExtra('dlg');
boot().catch(() => {}).then(() => render(true));

// PWA: Service Worker 登録
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => { navigator.serviceWorker.register('sw.js').catch(() => {}); });
}
})();
