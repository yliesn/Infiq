// Infiq — utilitaires partagés (chargé sur chaque page front)

const API = '../api';

// ─── Auth ─────────────────────────────────────────────────────────────────────

function getToken() { return localStorage.getItem('jwt'); }
function getUser()  { return JSON.parse(localStorage.getItem('user') || '{}'); }

function checkAuth() {
  if (!getToken()) { window.location.href = '../index.html'; return false; }
  return true;
}

async function apiFetch(path, options = {}) {
  const headers = { 'Content-Type': 'application/json', ...( options.headers || {}) };
  const token   = getToken();
  if (token) headers['Authorization'] = 'Bearer ' + token;

  const res  = await fetch(API + path, { ...options, headers });

  if (res.status === 401) {
    localStorage.removeItem('jwt');
    localStorage.removeItem('user');
    window.location.href = '../index.html';
    return;
  }

  const data = await res.json();
  if (!res.ok) throw new Error(data.error || 'Erreur serveur');
  return data;
}

// ─── Formatage ────────────────────────────────────────────────────────────────

function formatMoney(cents) {
  return (cents / 100).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' });
}

function formatVat(basisPoints) {
  return (basisPoints / 100).toFixed(2).replace('.', ',') + ' %';
}

function formatDate(s) {
  if (!s) return '—';
  const [y, m, d] = s.split('-');
  return `${d}/${m}/${y}`;
}

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function clientName(doc) {
  return doc.company_name || `${doc.first_name || ''} ${doc.last_name || ''}`.trim() || '—';
}

function statusBadge(type, status) {
  const labels = {
    draft:     ['secondary', 'Brouillon'],
    sent:      ['primary',   type === 'invoice' ? 'Envoyée' : 'Envoyé'],
    accepted:  ['success',   'Accepté'],
    refused:   ['danger',    'Refusé'],
    cancelled: ['dark',      'Annulé'],
    paid:      ['success',   'Payée'],
  };
  const [color, label] = labels[status] || ['secondary', status];
  return `<span class="badge bg-${color}">${label}</span>`;
}

// ─── Navigation ───────────────────────────────────────────────────────────────

function initLayout(activePage) {
  if (!checkAuth()) return;

  if (!document.getElementById('sidebar-styles')) {
    const style = document.createElement('style');
    style.id = 'sidebar-styles';
    style.textContent = `
      .sidebar { width: 64px; transition: width 0.25s ease; white-space: nowrap; }
      .sidebar:hover { width: 220px; }
      .sidebar .nav-label { opacity: 0; transition: opacity 0.15s ease; pointer-events: none; }
      .sidebar:hover .nav-label { opacity: 1; pointer-events: auto; }
      .sidebar .sidebar-icon { min-width: 1.1rem; text-align: center; font-size: 1.05rem; flex-shrink: 0; }
    `;
    document.head.appendChild(style);
  }

  const items = [
    { id: 'dashboard', href: 'dashboard.html', icon: 'bi-speedometer2', label: 'Dashboard' },
    { id: 'clients',   href: 'clients.html',   icon: 'bi-people-fill',  label: 'Clients'   },
    { id: 'quotes',    href: 'quotes.html',     icon: 'bi-file-text',    label: 'Devis'     },
    { id: 'invoices',  href: 'invoices.html',   icon: 'bi-receipt',      label: 'Factures'  },
    { id: 'services',  href: 'services.html',   icon: 'bi-grid-fill',    label: 'Services'  },
    { id: 'settings',  href: 'settings.html',   icon: 'bi-gear-fill',    label: 'Paramètres'},
  ];

  const links = items.map(item => {
    const active = item.id === activePage;
    return `<a href="${item.href}" title="${item.label}" class="nav-link d-flex align-items-center px-3 py-2 rounded ${active ? 'bg-primary text-white' : 'text-white-50 link-light'}">
      <i class="bi ${item.icon} sidebar-icon"></i><span class="nav-label ms-2">${item.label}</span>
    </a>`;
  }).join('');

  const user = getUser();

  document.getElementById('nav-placeholder').innerHTML = `
    <div class="d-flex flex-column flex-shrink-0 bg-dark text-white sidebar" style="height:100vh;position:sticky;top:0;overflow-y:auto;overflow-x:hidden;">
      <svg width="380" height="80" viewBox="0 0 500 120" xmlns="http://www.w3.org/2000/svg">
        <text x="0" y="72" font-family="Inter, Arial, sans-serif" font-size="34" fill="#3B82F6">
          &lt;/&gt;
        </text>
        <text x="85" y="72" font-family="Inter, Arial, sans-serif" font-size="54" font-weight="600" fill="#ffffff">
          INFIO
        </text>
        <rect x="88" y="82" width="140" height="4" rx="2" fill="#3B82F6"/>
        <circle cx="240" cy="84" r="3.5" fill="#3B82F6"/>
      </svg>
      <nav class="nav flex-column gap-1 p-2 flex-grow-1">${links}</nav>
      <div class="px-2 py-3 border-top border-secondary">
        <small class="nav-label text-white-50 d-block text-truncate mb-1 px-1" title="${esc(user.email || '')}">${esc(user.email || '')}</small>
        <button id="btn-logout" class="btn btn-sm btn-outline-light w-100" title="Déconnexion">
          <i class="bi bi-box-arrow-right sidebar-icon"></i><span class="nav-label ms-1">Déconnexion</span>
        </button>
      </div>
    </div>
  `;

  document.getElementById('btn-logout').addEventListener('click', () => {
    localStorage.removeItem('jwt');
    localStorage.removeItem('user');
    window.location.href = '../index.html';
  });
}

// ─── Toast ────────────────────────────────────────────────────────────────────

function toast(msg, type = 'success') {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'position-fixed bottom-0 end-0 p-3';
    container.style.zIndex = 9999;
    document.body.appendChild(container);
  }
  const id = 't' + Date.now();
  container.insertAdjacentHTML('beforeend', `
    <div id="${id}" class="toast align-items-center text-bg-${type === 'error' ? 'danger' : 'success'} border-0 show" role="alert">
      <div class="d-flex">
        <div class="toast-body">${esc(msg)}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  `);
  setTimeout(() => document.getElementById(id)?.remove(), 4000);
}

// ─── Confirm modal léger ──────────────────────────────────────────────────────

function confirmAction(msg) {
  return new Promise(resolve => {
    if (!confirm(msg)) { resolve(false); return; }
    resolve(true);
  });
}
