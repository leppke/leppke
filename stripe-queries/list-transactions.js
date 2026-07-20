#!/usr/bin/env node
/**
 * Stripe tranzakciók lekérdezése parancssorból.
 *
 * Az API-kulcsot a STRIPE_API_KEY környezeti változóból olvassa — soha ne
 * írd bele a kódba, és ne tedd kliensoldali (böngészős/mobil) alkalmazásba!
 *
 * Használat:
 *   STRIPE_API_KEY=sk_test_... node list-transactions.js [opciók]
 *
 * Opciók:
 *   --type <t>     balance | charges | payment-intents | payouts | refunds
 *                  (alapértelmezés: balance)
 *   --from <d>     kezdő dátum, pl. 2026-07-01 (a nap kezdete, UTC)
 *   --to <d>       záró dátum, pl. 2026-07-20 (a nap vége, UTC)
 *   --limit <n>    lekért rekordok maximális száma (alapértelmezés: 100)
 *   --customer <id>  csak egy adott vevő (cus_...) tranzakciói
 *                    (charges és payment-intents típusnál)
 *   --json         nyers JSON kimenet táblázat helyett
 *
 * Példák:
 *   node list-transactions.js --type balance --from 2026-07-01 --to 2026-07-20
 *   node list-transactions.js --type charges --customer cus_ABC123 --limit 50
 *   node list-transactions.js --type payouts --json
 */

import Stripe from 'stripe';

const apiKey = process.env.STRIPE_API_KEY;
if (!apiKey) {
  console.error('Hiba: állítsd be a STRIPE_API_KEY környezeti változót.');
  console.error('Kulcsot itt hozhatsz létre: https://dashboard.stripe.com/apikeys');
  console.error('Teszteléshez használj sk_test_... kulcsot, éleshez lehetőleg korlátozott (rk_...) kulcsot csak olvasási joggal.');
  process.exit(1);
}

const stripe = new Stripe(apiKey);

function parseArgs(argv) {
  const opts = { type: 'balance', limit: 100, json: false };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--json') opts.json = true;
    else if (a === '--type') opts.type = argv[++i];
    else if (a === '--from') opts.from = argv[++i];
    else if (a === '--to') opts.to = argv[++i];
    else if (a === '--limit') opts.limit = Number(argv[++i]);
    else if (a === '--customer') opts.customer = argv[++i];
    else {
      console.error(`Ismeretlen opció: ${a}`);
      process.exit(1);
    }
  }
  return opts;
}

// Dátum → Unix timestamp (a Stripe "created" szűrője másodperc alapú).
function toEpoch(dateStr, endOfDay) {
  const t = Date.parse(`${dateStr}T${endOfDay ? '23:59:59' : '00:00:00'}Z`);
  if (Number.isNaN(t)) {
    console.error(`Érvénytelen dátum: ${dateStr} (várt formátum: ÉÉÉÉ-HH-NN)`);
    process.exit(1);
  }
  return Math.floor(t / 1000);
}

function createdFilter(opts) {
  if (!opts.from && !opts.to) return undefined;
  const created = {};
  if (opts.from) created.gte = toEpoch(opts.from, false);
  if (opts.to) created.lte = toEpoch(opts.to, true);
  return created;
}

function formatAmount(amount, currency) {
  // A Stripe a legkisebb pénzegységben ad összeget (HUF/JPY stb. kivétel,
  // ott nincs váltópénz — ezeket "zero-decimal" pénznemként kezeli).
  const zeroDecimal = new Set(['huf', 'jpy', 'krw', 'vnd', 'clp', 'isk', 'twd', 'ugx']);
  const value = zeroDecimal.has(currency.toLowerCase()) ? amount : amount / 100;
  return `${value.toLocaleString('hu-HU')} ${currency.toUpperCase()}`;
}

function formatDate(epoch) {
  return new Date(epoch * 1000).toISOString().replace('T', ' ').slice(0, 19);
}

// A listType leírja, melyik API-végpontot hívjuk és mit írunk ki soronként.
const LIST_TYPES = {
  balance: {
    // Minden pénzmozgás (terhelés, jóváírás, díj, visszatérítés, kifizetés) egy helyen.
    list: (params) => stripe.balanceTransactions.list(params),
    row: (t) => [t.id, formatDate(t.created), t.type, formatAmount(t.amount, t.currency), `díj: ${formatAmount(t.fee, t.currency)}`, t.status],
  },
  charges: {
    list: (params) => stripe.charges.list(params),
    supportsCustomer: true,
    row: (t) => [t.id, formatDate(t.created), formatAmount(t.amount, t.currency), t.status, t.customer ?? '-', t.description ?? ''],
  },
  'payment-intents': {
    list: (params) => stripe.paymentIntents.list(params),
    supportsCustomer: true,
    row: (t) => [t.id, formatDate(t.created), formatAmount(t.amount, t.currency), t.status, t.customer ?? '-', t.description ?? ''],
  },
  payouts: {
    list: (params) => stripe.payouts.list(params),
    row: (t) => [t.id, formatDate(t.created), formatAmount(t.amount, t.currency), t.status, `érkezés: ${formatDate(t.arrival_date)}`],
  },
  refunds: {
    list: (params) => stripe.refunds.list(params),
    row: (t) => [t.id, formatDate(t.created), formatAmount(t.amount, t.currency), t.status, t.charge ?? '-'],
  },
};

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  const listType = LIST_TYPES[opts.type];
  if (!listType) {
    console.error(`Ismeretlen típus: ${opts.type}. Választható: ${Object.keys(LIST_TYPES).join(', ')}`);
    process.exit(1);
  }
  if (opts.customer && !listType.supportsCustomer) {
    console.error(`A --customer szűrő csak charges és payment-intents típusnál használható.`);
    process.exit(1);
  }

  const params = { limit: Math.min(opts.limit, 100) };
  const created = createdFilter(opts);
  if (created) params.created = created;
  if (opts.customer) params.customer = opts.customer;

  // Az autoPagingEach a lapozást automatikusan kezeli (100-as lapokban kér),
  // így opts.limit lehet 100-nál nagyobb is.
  const results = [];
  await listType.list(params).autoPagingEach((item) => {
    results.push(item);
    return results.length < opts.limit; // false → lapozás leáll
  });

  if (opts.json) {
    console.log(JSON.stringify(results, null, 2));
  } else {
    if (results.length === 0) {
      console.log('Nincs találat a megadott szűrőkkel.');
      return;
    }
    for (const item of results) {
      console.log(listType.row(item).join('  |  '));
    }
    console.log(`\nÖsszesen: ${results.length} rekord`);
  }
}

main().catch((err) => {
  // A Stripe hibaobjektum type/code mezői segítenek a diagnózisban
  // (pl. authentication_error → rossz kulcs, rate_limit_error → túl sok kérés).
  console.error(`Stripe hiba (${err.type ?? 'ismeretlen'}): ${err.message}`);
  process.exit(1);
});
