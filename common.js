'use strict';
// shared helpers for admin.html and hangout.html
const BASE = window.BASE || ''; // '/index.php' when the server has no rewrite rules

// ---------- i18n ----------
const LANGS = { en: 'English', de: 'Deutsch', fr: 'Français' };
const STRINGS = {
  en: {
    userLink: 'Hangout link', adminLink: 'Admin link',
    settings: 'Settings',
    hangoutAdminPassword: 'Password', keepPassword: 'Password is set', noPassword: 'No password set', removePassword: ' Remove password (disables the hangout admin login)', saved: 'Saved',
    adminTitle: 'Hangout admin', adminPassword: 'Admin password', logIn: 'Log in', logOut: 'Log out',
    yourHangouts: 'Your hangouts', newHangout: 'New hangout', noHangouts: 'No hangouts yet.', closedSuffix: ' (closed)',
    responses: '{0} responses', copyLink: 'Copy link', copied: 'Copied', edit: 'Edit', editHangout: 'Edit hangout',
    pickEmoji: 'Pick an emoji', insertEmoji: 'Insert emoji', pickColor: 'Pick a highlight colour', noColor: 'No colour', highlightColor: 'Highlight colour',
    hasEntries: ' (has entries, tap to add another)', prevMonth: 'Previous month', nextMonth: 'Next month',
    textOnly: 'Text only', notePlaceholder: 'Time / note (e.g. 18:00)', noteLabel: 'Time or note', moveUp: 'Move up', moveDown: 'Move down',
    addTime: 'Add another time on this day', removeOption: 'Remove option', tapDays: 'Tap days in the calendar to add them.',
    logo: 'Logo', hangoutLogo: 'Hangout logo', noLogo: 'No logo. It is shown at the top of this hangout.', changeLogo: 'Change logo', uploadLogo: 'Upload logo',
    remove: 'Remove', imageError: 'Could not read that image', svgTooLarge: 'SVG too large (max 200 KB)',
    deleteResponse: 'Delete response from {0}?', delete: 'Delete', title: 'Title', description: 'Description', dates: 'Dates',
    addTextOption: '+ Add text-only option', closedCheck: ' Closed (no new responses)', save: 'Save', cancel: 'Cancel',
    confirmDelete: 'Delete this hangout and all responses?', deleteHangout: 'Delete hangout', language: 'Language', auto: 'Automatic (browser)',
    loading: 'Loading…', editResponse: 'Edit this response', total: 'Total', isClosed: 'This hangout is closed.', addAvailability: 'Add my availability',
    yourName: 'Your name', editName: 'Edit {0}', yourAvailability: 'Your availability', yes: 'Yes', maybe: 'Maybe', no: 'No', notFound: 'Hangout not found',
    openLink: 'Open the hangout link you were given to respond.',
    red: 'red', orange: 'orange', yellow: 'yellow', lime: 'lime', green: 'green', teal: 'teal', cyan: 'cyan', blue: 'blue', purple: 'purple', pink: 'pink', brown: 'brown', gray: 'gray',
  },
  de: {
    userLink: 'Hangout-Link', adminLink: 'Admin-Link',
    settings: 'Einstellungen',
    hangoutAdminPassword: 'Passwort', keepPassword: 'Passwort ist gesetzt', noPassword: 'Kein Passwort gesetzt', removePassword: ' Passwort entfernen (deaktiviert den Hangout-Admin-Login)', saved: 'Gespeichert',
    adminTitle: 'Hangout-Verwaltung', adminPassword: 'Admin-Passwort', logIn: 'Anmelden', logOut: 'Abmelden',
    yourHangouts: 'Deine Hangouts', newHangout: 'Neuer Hangout', noHangouts: 'Noch keine Hangouts.', closedSuffix: ' (geschlossen)',
    responses: '{0} Antworten', copyLink: 'Link kopieren', copied: 'Kopiert', edit: 'Bearbeiten', editHangout: 'Hangout bearbeiten',
    pickEmoji: 'Emoji auswählen', insertEmoji: 'Emoji einfügen', pickColor: 'Hervorhebungsfarbe wählen', noColor: 'Keine Farbe', highlightColor: 'Hervorhebungsfarbe',
    hasEntries: ' (hat Einträge, tippen für einen weiteren)', prevMonth: 'Vorheriger Monat', nextMonth: 'Nächster Monat',
    textOnly: 'Nur Text', notePlaceholder: 'Zeit / Notiz (z. B. 18:00)', noteLabel: 'Zeit oder Notiz', moveUp: 'Nach oben', moveDown: 'Nach unten',
    addTime: 'Weitere Zeit an diesem Tag hinzufügen', removeOption: 'Option entfernen', tapDays: 'Tippe im Kalender auf Tage, um sie hinzuzufügen.',
    logo: 'Logo', hangoutLogo: 'Hangout-Logo', noLogo: 'Kein Logo. Es wird oben auf der Hangout-Seite angezeigt.', changeLogo: 'Logo ändern', uploadLogo: 'Logo hochladen',
    remove: 'Entfernen', imageError: 'Das Bild konnte nicht gelesen werden', svgTooLarge: 'SVG zu gross (max. 200 KB)',
    deleteResponse: 'Antwort von {0} löschen?', delete: 'Löschen', title: 'Titel', description: 'Beschreibung', dates: 'Daten',
    addTextOption: '+ Nur-Text-Option hinzufügen', closedCheck: ' Geschlossen (keine neuen Antworten)', save: 'Speichern', cancel: 'Abbrechen',
    confirmDelete: 'Diesen Hangout und alle Antworten löschen?', deleteHangout: 'Hangout löschen', language: 'Sprache', auto: 'Automatisch (Browser)',
    loading: 'Lädt…', editResponse: 'Diese Antwort bearbeiten', total: 'Total', isClosed: 'Dieser Hangout ist geschlossen.', addAvailability: 'Meine Verfügbarkeit hinzufügen',
    yourName: 'Dein Name', editName: '{0} bearbeiten', yourAvailability: 'Deine Verfügbarkeit', yes: 'Ja', maybe: 'Vielleicht', no: 'Nein', notFound: 'Hangout nicht gefunden',
    openLink: 'Öffne den Hangout-Link, den du erhalten hast, um zu antworten.',
    red: 'rot', orange: 'orange', yellow: 'gelb', lime: 'limette', green: 'grün', teal: 'petrol', cyan: 'cyan', blue: 'blau', purple: 'violett', pink: 'pink', brown: 'braun', gray: 'grau',
  },
  fr: {
    userLink: 'Lien du hangout', adminLink: 'Lien admin',
    settings: 'Paramètres',
    hangoutAdminPassword: 'Mot de passe', keepPassword: 'Mot de passe défini', noPassword: 'Aucun mot de passe défini', removePassword: ' Supprimer le mot de passe (désactive la connexion admin du hangout)', saved: 'Enregistré',
    adminTitle: 'Administration Hangout', adminPassword: 'Mot de passe admin', logIn: 'Se connecter', logOut: 'Se déconnecter',
    yourHangouts: 'Vos hangouts', newHangout: 'Nouveau hangout', noHangouts: 'Aucun hangout pour l’instant.', closedSuffix: ' (fermé)',
    responses: '{0} réponses', copyLink: 'Copier le lien', copied: 'Copié', edit: 'Modifier', editHangout: 'Modifier le hangout',
    pickEmoji: 'Choisir un emoji', insertEmoji: 'Insérer un emoji', pickColor: 'Choisir une couleur de mise en évidence', noColor: 'Aucune couleur', highlightColor: 'Couleur de mise en évidence',
    hasEntries: ' (a des entrées, appuyer pour en ajouter une)', prevMonth: 'Mois précédent', nextMonth: 'Mois suivant',
    textOnly: 'Texte seul', notePlaceholder: 'Heure / note (p. ex. 18:00)', noteLabel: 'Heure ou note', moveUp: 'Monter', moveDown: 'Descendre',
    addTime: 'Ajouter un autre horaire ce jour', removeOption: 'Supprimer l’option', tapDays: 'Appuyez sur des jours du calendrier pour les ajouter.',
    logo: 'Logo', hangoutLogo: 'Logo du hangout', noLogo: 'Pas de logo. Il s’affiche en haut de ce hangout.', changeLogo: 'Changer le logo', uploadLogo: 'Téléverser un logo',
    remove: 'Retirer', imageError: 'Impossible de lire cette image', svgTooLarge: 'SVG trop volumineux (max. 200 Ko)',
    deleteResponse: 'Supprimer la réponse de {0} ?', delete: 'Supprimer', title: 'Titre', description: 'Description', dates: 'Dates',
    addTextOption: '+ Ajouter une option texte', closedCheck: ' Fermé (plus de nouvelles réponses)', save: 'Enregistrer', cancel: 'Annuler',
    confirmDelete: 'Supprimer ce hangout et toutes les réponses ?', deleteHangout: 'Supprimer le hangout', language: 'Langue', auto: 'Automatique (navigateur)',
    loading: 'Chargement…', editResponse: 'Modifier cette réponse', total: 'Total', isClosed: 'Ce hangout est fermé.', addAvailability: 'Ajouter ma disponibilité',
    yourName: 'Votre nom', editName: 'Modifier {0}', yourAvailability: 'Votre disponibilité', yes: 'Oui', maybe: 'Peut-être', no: 'Non', notFound: 'Hangout introuvable',
    openLink: 'Ouvrez le lien du hangout que vous avez reçu pour répondre.',
    red: 'rouge', orange: 'orange', yellow: 'jaune', lime: 'citron vert', green: 'vert', teal: 'sarcelle', cyan: 'cyan', blue: 'bleu', purple: 'violet', pink: 'rose', brown: 'brun', gray: 'gris',
  },
};

function detectLang() { // the browser's preferred supported language
  for (const l of navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || 'en']) {
    const base = String(l).toLowerCase().split('-')[0];
    if (LANGS[base]) return base;
  }
  return 'en';
}
const BROWSER_LANG = detectLang();
let LANG = BROWSER_LANG;
// a hangout's own language wins over the browser's; '' falls back to the browser
function useLang(l) { LANG = LANGS[l] ? l : BROWSER_LANG; document.documentElement.lang = LANG; }
useLang('');
function t(key, ...args) {
  const str = (STRINGS[LANG] && STRINGS[LANG][key]) || STRINGS.en[key] || key;
  return str.replace(/\{(\d+)\}/g, (_, i) => args[i]);
}

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

function weekdayText(o) { // "Tue"
  return o.date ? new Date(o.date + 'T00:00:00').toLocaleDateString(LANG, { weekday: 'short' }) : '';
}

// plain-text label (aria-label, admin list)
function dateWithWeekday(o) { // "Tue, 01.12.2026"
  return [weekdayText(o), dateText(o)].filter(Boolean).join(', ');
}

function optionLabel(o, weekday) {
  return [weekday ? dateWithWeekday(o) : dateText(o), o.note].filter(Boolean).join(' ');
}

// visible label: date on the first line, description on the next
function optionNode(o) {
  const d = dateWithWeekday(o);
  return el('span', {}, d && el('span', { style: 'white-space:nowrap' }, d), d && o.note ? el('br') : null, o.note);
}

const COLORS = ['red', 'orange', 'yellow', 'lime', 'green', 'teal', 'cyan', 'blue', 'purple', 'pink', 'brown', 'gray']; // keep in sync with OPTION_COLORS in index.php
const hlClass = (o) => (COLORS.includes(o.color) ? 'hl hl-' + o.color : '');

const SYMBOL = { ok: '✓', maybe: '(✓)', no: '✗' };
const hangoutUrl = (id) => location.origin + BASE + '/p/' + id;
const adminUrl = (id) => location.origin + BASE + '/admin/' + id;
