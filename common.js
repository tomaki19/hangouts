'use strict';
// shared helpers for admin.html and hangout.html
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

function dateText(o) { // "01.12.2026"
  if (!o.date) return '';
  const [y, m, day] = o.date.split('-');
  return `${day}.${m}.${y}`;
}

// plain-text label (aria-label, admin list)
function optionLabel(o) {
  return [dateText(o), o.note].filter(Boolean).join(' ');
}

// visible label: date on the first line, description on the next
function optionNode(o) {
  const d = dateText(o);
  return el('span', {}, d, d && o.note ? el('br') : null, o.note);
}

const COLORS = ['red', 'orange', 'yellow', 'lime', 'green', 'teal', 'cyan', 'blue', 'purple', 'pink', 'brown', 'gray']; // keep in sync with OPTION_COLORS in index.php
const hlClass = (o) => (COLORS.includes(o.color) ? 'hl hl-' + o.color : '');

const SYMBOL = { ok: '✓', maybe: '(✓)', no: '✗' };
const hangoutUrl = (id) => location.origin + BASE + '/p/' + id;
