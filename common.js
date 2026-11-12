'use strict';
// shared helpers for admin.html and poll.html
const BASE = window.BASE || ''; // '/index.php' when the server has no rewrite rules

async function api(method, url, body) {
  const r = await fetch(BASE + url, {
    method, credentials: 'same-origin',
    headers: body ? { 'Content-Type': 'application/json' } : {},
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await r.json().catch(() => ({}));
  if (!r.ok) { const e = new Error(data.error || r.statusText); e.status = r.status; throw e; }
  return data;
}

function el(tag, attrs, ...kids) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
    else if (k === 'class') n.className = v;
    else if (v !== false && v != null) n.setAttribute(k, v === true ? '' : v);
  }
  for (const k of kids.flat()) if (k != null) n.append(k.nodeType ? k : document.createTextNode(k));
  return n;
}

function optionLabel(o) {
  let d = '';
  if (o.date) { // "01.12.2026"
    const [y, m, day] = o.date.split('-');
    d = `${day}.${m}.${y}`;
  }
  return [d, o.note].filter(Boolean).join(' · ');
}

const SYMBOL = { ok: '✓', maybe: '(✓)', no: '✗' };
const pollUrl = (id) => location.origin + BASE + '/p/' + id;
