#!/usr/bin/env node
// Stripe tranzakció-lekérdező script (Node.js 18+, nincs külső függőség).
//
// Használat:
//   STRIPE_API_KEY=rk_live_... node stripe-transactions.mjs [balance|charges|payment_intents] [--days N] [--limit N]
//
// Példák:
//   STRIPE_API_KEY=rk_test_... node stripe-transactions.mjs balance --days 30
//   STRIPE_API_KEY=rk_test_... node stripe-transactions.mjs charges --limit 10
//
// A kulcsot SOHA ne írd bele a kódba és ne csomagold kliensalkalmazásba —
// mindig környezeti változóból, szerveroldalon használd.

const API_BASE = 'https://api.stripe.com/v1';

const apiKey = process.env.STRIPE_API_KEY;
if (!apiKey) {
  console.error('Hiba: állítsd be a STRIPE_API_KEY környezeti változót (rk_... vagy sk_... kulcs).');
  process.exit(1);
}

const args = process.argv.slice(2);
const resource = args.find((a) => !a.startsWith('--')) ?? 'balance';

function flag(name, fallback) {
  const i = args.indexOf(`--${name}`);
  return i !== -1 && args[i + 1] ? Number(args[i + 1]) : fallback;
}

const days = flag('days', 7);
const pageLimit = Math.min(flag('limit', 100), 100);
const createdAfter = Math.floor(Date.now() / 1000) - days * 86400;

const ENDPOINTS = {
  balance: '/balance_transactions',
  charges: '/charges',
  payment_intents: '/payment_intents',
};

const endpoint = ENDPOINTS[resource];
if (!endpoint) {
  console.error(`Ismeretlen erőforrás: "${resource}". Választható: ${Object.keys(ENDPOINTS).join(', ')}`);
  process.exit(1);
}

async function stripeGet(path, params) {
  const url = new URL(API_BASE + path);
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, String(v));
  const res = await fetch(url, {
    headers: { Authorization: `Bearer ${apiKey}` },
  });
  const body = await res.json();
  if (!res.ok) {
    throw new Error(`Stripe API hiba (${res.status}): ${body.error?.message ?? JSON.stringify(body)}`);
  }
  return body;
}

// Lapozás: a Stripe cursor-alapú lapozást használ (starting_after az utolsó elem id-je).
async function listAll(path, params) {
  const items = [];
  let startingAfter;
  for (;;) {
    const page = await stripeGet(path, {
      ...params,
      limit: pageLimit,
      ...(startingAfter ? { starting_after: startingAfter } : {}),
    });
    items.push(...page.data);
    if (!page.has_more) return items;
    startingAfter = page.data[page.data.length - 1].id;
  }
}

function formatAmount(amount, currency) {
  // A Stripe a legkisebb pénzegységben ad összegeket (pl. cent); a HUF nulla tizedesjegyű.
  const zeroDecimal = ['huf', 'jpy', 'krw', 'vnd', 'clp', 'isk', 'twd', 'ugx'];
  const value = zeroDecimal.includes(currency) ? amount : amount / 100;
  return `${value} ${currency.toUpperCase()}`;
}

const rows = await listAll(endpoint, { 'created[gte]': createdAfter });

console.log(`${rows.length} tétel az elmúlt ${days} napból (${resource}):\n`);
for (const r of rows) {
  const date = new Date(r.created * 1000).toISOString().slice(0, 19).replace('T', ' ');
  const status = r.status ?? r.type ?? '';
  console.log(`${r.id}  ${date}  ${formatAmount(r.amount, r.currency)}  ${status}  ${r.description ?? ''}`);
}
