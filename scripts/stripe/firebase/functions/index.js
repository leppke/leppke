// Stripe riport lekérdező Cloud Function.
//
// A weboldalról hívható (httpsCallable "getreport"), csak az ALLOWED_EMAILS
// listában szereplő, bejelentkezett felhasználóknak. A Stripe-kulcsok a
// Secret Managerben tárolt STRIPE_ACCOUNTS titokban vannak, ugyanabban a
// formátumban, mint a Windows-os .bat-ban:
//   Hotel=rk_live_xxx;Marina=rk_live_yyy
//
// A kulcsokon szükséges jogok: Balance transactions: Read, Charges: Read,
// Events: Read, Webhook Endpoints: Read.

const {onCall, HttpsError} = require("firebase-functions/v2/https");
const {defineSecret} = require("firebase-functions/params");
const Stripe = require("stripe");

const stripeAccountsSecret = defineSecret("STRIPE_ACCOUNTS");

// Csak ezek a bejelentkezett felhasználók kérdezhetnek le — írd át a valódi
// címekre (a sajátod és a könyvelőé), majd deployold újra a functions részt.
const ALLOWED_EMAILS = [
  "lleepppp@gmail.com",
  "konyvelo@example.com",
];

// A Stripe hivatalos nulla-tizedesjegyű pénznemei. A HUF/ISK/TWD szándékosan
// NINCS köztük: hivatalosan nulla tizedesjegyűek, de a Stripe API kivételként
// 1/100 egységben (a HUF-ot "fillérben") adja az összegüket.
const ZERO_DECIMAL = new Set([
  "bif", "clp", "djf", "gnf", "jpy", "kmf", "krw", "mga",
  "pyg", "rwf", "ugx", "vnd", "vuv", "xaf", "xof", "xpf",
]);

function formatAmount(amount, currency) {
  const value = ZERO_DECIMAL.has(currency) ? amount : amount / 100;
  const formatted = value.toLocaleString("hu-HU").replace(/ /g, " ");
  return `${formatted} ${currency.toUpperCase()}`;
}

function formatDate(unixSeconds) {
  return new Date(unixSeconds * 1000).toISOString().slice(0, 16).replace("T", " ");
}

function parseAccounts(raw) {
  const accounts = [];
  for (const part of raw.split(";")) {
    const trimmed = part.trim();
    if (!trimmed) continue;
    const eq = trimmed.indexOf("=");
    if (eq < 1) {
      throw new HttpsError("failed-precondition",
          `Hibás STRIPE_ACCOUNTS elem (Név=kulcs kell): ${trimmed.slice(0, 20)}…`);
    }
    accounts.push({name: trimmed.slice(0, eq).trim(), key: trimmed.slice(eq + 1).trim()});
  }
  if (!accounts.length) {
    throw new HttpsError("failed-precondition", "A STRIPE_ACCOUNTS titok üres.");
  }
  return accounts;
}

function payerName(tx) {
  const source = tx.source;
  if (!source || typeof source !== "object") return "";
  return (source.billing_details && source.billing_details.name) || "";
}

async function fetchRows(stripe, accountName, createdAfter) {
  const rows = [];
  // A balance_transactions minden pénzmozgást tartalmaz (befizetés, díj,
  // visszatérítés, kifizetés), díj- és nettó bontással.
  for await (const tx of stripe.balanceTransactions.list(
      {created: {gte: createdAfter}, limit: 100, expand: ["data.source"]})) {
    rows.push({
      account: accountName,
      date: formatDate(tx.created),
      customer: payerName(tx),
      id: tx.id,
      amount: formatAmount(tx.amount, tx.currency),
      fee: formatAmount(tx.fee, tx.currency),
      net: formatAmount(tx.net, tx.currency),
      status: `${tx.type} / ${tx.status}`,
      description: tx.description || "",
    });
  }
  return rows;
}

async function fetchWebhookStatus(stripe, accountName, createdAfter) {
  // A Stripe a kézbesítési naplót nem adja ki API-n; amit tudunk: a végpont
  // státusza (a tartósan hibázót a Stripe letiltja), és eseményenként a
  // pending_webhooks szám (>0 = van végpont, ami még nem igazolta vissza).
  const result = {account: accountName, endpoints: [], events_total: 0, pending: []};
  try {
    for await (const ep of stripe.webhookEndpoints.list({limit: 100})) {
      result.endpoints.push({url: ep.url, status: ep.status});
    }
    for await (const ev of stripe.events.list({created: {gte: createdAfter}, limit: 100})) {
      result.events_total += 1;
      if (ev.pending_webhooks > 0 && result.pending.length < 200) {
        result.pending.push({date: formatDate(ev.created), type: ev.type, id: ev.id});
      }
    }
  } catch (err) {
    result.error = String(err.message || err).slice(0, 300);
  }
  return result;
}

exports.getreport = onCall(
    {
      region: "europe-west1",
      secrets: [stripeAccountsSecret],
      timeoutSeconds: 300,
      memory: "256MiB",
    },
    async (request) => {
      const email = request.auth && request.auth.token && request.auth.token.email;
      if (!email || !ALLOWED_EMAILS.includes(email)) {
        throw new HttpsError("permission-denied",
            "Ez a riport csak az engedélyezett felhasználóknak érhető el.");
      }

      const days = Math.min(Math.max(parseInt(request.data && request.data.days, 10) || 31, 1), 365);
      const createdAfter = Math.floor(Date.now() / 1000) - days * 86400;
      const accounts = parseAccounts(stripeAccountsSecret.value());

      const rows = [];
      const webhooks = [];
      for (const {name, key} of accounts) {
        const stripe = new Stripe(key, {maxNetworkRetries: 2});
        try {
          rows.push(...await fetchRows(stripe, name, createdAfter));
        } catch (err) {
          throw new HttpsError("internal",
              `Stripe hiba (${name}): ${String(err.message || err).slice(0, 300)}`);
        }
        webhooks.push(await fetchWebhookStatus(stripe, name, createdAfter));
      }

      rows.sort((a, b) => (a.date < b.date ? 1 : -1));

      return {
        updated: formatDate(Math.floor(Date.now() / 1000)) + " UTC",
        days,
        rows,
        webhooks,
      };
    });
