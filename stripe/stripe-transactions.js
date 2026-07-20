#!/usr/bin/env node
/**
 * Stripe tranzakció-lekérdező CLI.
 *
 * Függőségmentes: csak Node.js 18+ kell hozzá (beépített fetch).
 * A Stripe titkos (vagy korlátozott) API kulcsot a STRIPE_SECRET_KEY
 * környezeti változóból olvassa — soha ne írd bele a kódba!
 *
 * Használat:
 *   STRIPE_SECRET_KEY=sk_test_... node stripe-transactions.js <erőforrás> [opciók]
 *
 * Erőforrások:
 *   balance-transactions  Elszámolt pénzmozgások (díjakkal, nettó összeggel)
 *   charges               Terhelések (kártyás fizetések)
 *   payment-intents       Fizetési szándékok (modern fizetési folyamat)
 *   payouts               Kifizetések a bankszámlára
 *   refunds               Visszatérítések
 *
 * Opciók:
 *   --from YYYY-MM-DD   Ettől a naptól (created >= )
 *   --to YYYY-MM-DD     Eddig a napig, a nap végéig (created <= )
 *   --limit N           Legfeljebb ennyi rekord (alapértelmezés: 100)
 *   --all               Az összes találat lekérése lapozással (felülírja a --limit-et)
 *   --json              Nyers JSON kimenet táblázat helyett
 *   --csv FÁJL          Eredmény mentése CSV fájlba
 */

'use strict';

const fs = require('fs');

// Teszteléshez (pl. stripe-mock) átirányítható a STRIPE_API_BASE változóval.
const API_BASE = process.env.STRIPE_API_BASE || 'https://api.stripe.com/v1';
const PAGE_SIZE = 100; // a Stripe által megengedett maximum oldalméret

const RESOURCES = {
  'balance-transactions': {
    path: '/balance_transactions',
    columns: ['id', 'type', 'amount', 'fee', 'net', 'currency', 'status', 'created', 'description'],
  },
  charges: {
    path: '/charges',
    columns: ['id', 'amount', 'currency', 'status', 'paid', 'refunded', 'created', 'description'],
  },
  'payment-intents': {
    path: '/payment_intents',
    columns: ['id', 'amount', 'currency', 'status', 'created', 'description'],
  },
  payouts: {
    path: '/payouts',
    columns: ['id', 'amount', 'currency', 'status', 'arrival_date', 'created', 'description'],
  },
  refunds: {
    path: '/refunds',
    columns: ['id', 'amount', 'currency', 'status', 'reason', 'created'],
  },
};

function usage(exitCode) {
  const lines = [
    'Használat: STRIPE_SECRET_KEY=sk_... node stripe-transactions.js <erőforrás> [opciók]',
    '',
    'Erőforrások: ' + Object.keys(RESOURCES).join(', '),
    '',
    'Opciók:',
    '  --from YYYY-MM-DD   Ettől a naptól',
    '  --to YYYY-MM-DD     Eddig a napig (a nap végéig)',
    '  --limit N           Legfeljebb ennyi rekord (alapértelmezés: 100)',
    '  --all               Minden találat lekérése lapozással',
    '  --json              Nyers JSON kimenet',
    '  --csv FÁJL          Mentés CSV fájlba',
    '  --help              Ez a súgó',
  ];
  console.log(lines.join('\n'));
  process.exit(exitCode);
}

function fail(message) {
  console.error('Hiba: ' + message);
  process.exit(1);
}

function parseDate(value, endOfDay) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    fail(`érvénytelen dátum: "${value}" (elvárt formátum: YYYY-MM-DD)`);
  }
  const suffix = endOfDay ? 'T23:59:59Z' : 'T00:00:00Z';
  const ms = Date.parse(value + suffix);
  if (Number.isNaN(ms)) fail(`érvénytelen dátum: "${value}"`);
  return Math.floor(ms / 1000);
}

function parseArgs(argv) {
  const opts = { resource: null, from: null, to: null, limit: 100, all: false, json: false, csv: null };
  const args = argv.slice(2);
  if (args.length === 0 || args.includes('--help') || args.includes('-h')) usage(args.length === 0 ? 1 : 0);

  opts.resource = args.shift();
  if (!RESOURCES[opts.resource]) {
    fail(`ismeretlen erőforrás: "${opts.resource}". Választható: ${Object.keys(RESOURCES).join(', ')}`);
  }

  while (args.length > 0) {
    const arg = args.shift();
    switch (arg) {
      case '--from': opts.from = parseDate(requireValue(arg, args), false); break;
      case '--to': opts.to = parseDate(requireValue(arg, args), true); break;
      case '--limit': {
        const n = Number(requireValue(arg, args));
        if (!Number.isInteger(n) || n < 1) fail('a --limit pozitív egész szám kell legyen');
        opts.limit = n;
        break;
      }
      case '--all': opts.all = true; break;
      case '--json': opts.json = true; break;
      case '--csv': opts.csv = requireValue(arg, args); break;
      default: fail(`ismeretlen opció: "${arg}" (súgó: --help)`);
    }
  }
  return opts;
}

function requireValue(flag, args) {
  if (args.length === 0) fail(`a(z) ${flag} opcióhoz érték szükséges`);
  return args.shift();
}

async function fetchPage(apiKey, path, params) {
  const query = new URLSearchParams(params).toString();
  const response = await fetch(`${API_BASE}${path}?${query}`, {
    headers: {
      Authorization: `Bearer ${apiKey}`,
      'Stripe-Version': '2024-06-20',
    },
  });
  const body = await response.json();
  if (!response.ok) {
    const message = body && body.error ? `${body.error.type}: ${body.error.message}` : `HTTP ${response.status}`;
    fail(`a Stripe API hibát adott — ${message}`);
  }
  return body;
}

async function fetchAll(apiKey, resource, opts) {
  const results = [];
  let startingAfter = null;
  const target = opts.all ? Infinity : opts.limit;

  while (results.length < target) {
    const params = { limit: Math.min(PAGE_SIZE, target - results.length) };
    if (opts.from) params['created[gte]'] = opts.from;
    if (opts.to) params['created[lte]'] = opts.to;
    if (startingAfter) params.starting_after = startingAfter;

    const page = await fetchPage(apiKey, resource.path, params);
    results.push(...page.data);
    if (!page.has_more || page.data.length === 0) break;
    startingAfter = page.data[page.data.length - 1].id;
  }
  return opts.all ? results : results.slice(0, target);
}

function formatCell(record, column) {
  const value = record[column];
  if (value === null || value === undefined) return '';
  if (column === 'created' || column === 'arrival_date') {
    return new Date(value * 1000).toISOString().replace('T', ' ').slice(0, 19);
  }
  if (column === 'amount' || column === 'fee' || column === 'net') {
    // A Stripe a legkisebb pénzegységben ad összeget (pl. fillér, cent);
    // a HUF a Stripe-nál kétdecimális pénznemként viselkedik.
    return (value / 100).toFixed(2);
  }
  return String(value);
}

function printTable(records, columns) {
  const rows = [columns, ...records.map((r) => columns.map((c) => formatCell(r, c)))];
  const widths = columns.map((_, i) => Math.max(...rows.map((row) => row[i].length)));
  for (const row of rows) {
    console.log(row.map((cell, i) => cell.padEnd(widths[i])).join('  '));
  }
  console.log(`\nÖsszesen: ${records.length} rekord`);
}

function csvEscape(value) {
  return /[",\n]/.test(value) ? '"' + value.replace(/"/g, '""') + '"' : value;
}

function writeCsv(filePath, records, columns) {
  const lines = [columns.join(',')];
  for (const record of records) {
    lines.push(columns.map((c) => csvEscape(formatCell(record, c))).join(','));
  }
  fs.writeFileSync(filePath, lines.join('\n') + '\n', 'utf8');
  console.log(`CSV mentve: ${filePath} (${records.length} rekord)`);
}

async function main() {
  const opts = parseArgs(process.argv);

  const apiKey = process.env.STRIPE_SECRET_KEY;
  if (!apiKey) {
    fail(
      'hiányzik a STRIPE_SECRET_KEY környezeti változó.\n' +
        'A kulcsot a Stripe Dashboardon találod: https://dashboard.stripe.com/apikeys\n' +
        'Példa: STRIPE_SECRET_KEY=sk_test_... node stripe-transactions.js charges'
    );
  }
  if (!/^(sk|rk)_(test|live)_/.test(apiKey)) {
    fail('a STRIPE_SECRET_KEY nem tűnik titkos kulcsnak (sk_... vagy rk_... kezdetű kell legyen; a pk_... publikus kulcs erre nem alkalmas)');
  }

  const resource = RESOURCES[opts.resource];
  const records = await fetchAll(apiKey, resource, opts);

  if (opts.json) {
    console.log(JSON.stringify(records, null, 2));
  } else if (!opts.csv) {
    printTable(records, resource.columns);
  }
  if (opts.csv) {
    writeCsv(opts.csv, records, resource.columns);
  }
}

main().catch((err) => fail(err.message));
