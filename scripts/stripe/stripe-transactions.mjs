#!/usr/bin/env node
// Stripe tranzakció-lekérdező script a hivatalos `stripe` npm SDK-val (Node.js 18+).
//
// Telepítés (ebben a mappában):
//   npm install
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

import Stripe from 'stripe';

const apiKey = process.env.STRIPE_API_KEY;
if (!apiKey) {
  console.error('Hiba: állítsd be a STRIPE_API_KEY környezeti változót (rk_... vagy sk_... kulcs).');
  process.exit(1);
}

const stripe = new Stripe(apiKey, { maxNetworkRetries: 2 });

const args = process.argv.slice(2);
const resource = args.find((a) => !a.startsWith('--')) ?? 'balance';

function flag(name, fallback) {
  const i = args.indexOf(`--${name}`);
  return i !== -1 && args[i + 1] ? Number(args[i + 1]) : fallback;
}

const days = flag('days', 7);
const pageLimit = Math.min(flag('limit', 100), 100);
const createdAfter = Math.floor(Date.now() / 1000) - days * 86400;

const RESOURCES = {
  balance: stripe.balanceTransactions,
  charges: stripe.charges,
  payment_intents: stripe.paymentIntents,
};

const client = RESOURCES[resource];
if (!client) {
  console.error(`Ismeretlen erőforrás: "${resource}". Választható: ${Object.keys(RESOURCES).join(', ')}`);
  process.exit(1);
}

function formatAmount(amount, currency) {
  // A Stripe a legkisebb pénzegységben ad összegeket (pl. cent); a HUF nulla tizedesjegyű.
  const zeroDecimal = ['huf', 'jpy', 'krw', 'vnd', 'clp', 'isk', 'twd', 'ugx'];
  const value = zeroDecimal.includes(currency) ? amount : amount / 100;
  return `${value} ${currency.toUpperCase()}`;
}

// Az SDK list() hívása auto-lapozó aszinkron iterátort ad vissza: a for await
// magától kéri le a következő oldalakat, amíg van adat.
const rows = [];
try {
  for await (const item of client.list({ created: { gte: createdAfter }, limit: pageLimit })) {
    rows.push(item);
  }
} catch (err) {
  console.error(`Stripe API hiba${err.statusCode ? ` (${err.statusCode})` : ''}: ${err.message}`);
  process.exit(1);
}

console.log(`${rows.length} tétel az elmúlt ${days} napból (${resource}):\n`);
for (const r of rows) {
  const date = new Date(r.created * 1000).toISOString().slice(0, 19).replace('T', ' ');
  const status = r.status ?? r.type ?? '';
  console.log(`${r.id}  ${date}  ${formatAmount(r.amount, r.currency)}  ${status}  ${r.description ?? ''}`);
}
