Awesome—let’s adapt the plugin plan to your **Case B** decision tree and to your **lean per-product data** (only `description` + `country_code`, with a global lookup keyed by `description|country_code`).

Below is a crisp, implementation-ready plan your dev can follow.

---

# WooCommerce Duties & Routing Plugin — Adapted Plan (v0.4)

*Last updated: 2025-09-05 (America/Vancouver). Incorporates Case B split-shipment decision tree and a global customs lookup keyed by `description|country_code`.*

---

## 0) What changes vs the previous plan

* **Per-product data is minimal**: only

  1. `_customs_description` (commercial description string)
  2. `_country_of_origin` (ISO-2)
* **All trade specifics** (HS, duty JSON, FTA flags, etc.) come from a **global “Customs Profile” lookup** keyed by `description|country_code`.
* **Case B logic** (V > \$800, mixed CUSMA/non-CUSMA) is **first-class**: the router compares **single vs split** costs and picks the cheapest compliant plan (with config knobs for minimum savings and operational constraints).

---

## 1) Data model

### 1.1 Product fields (minimal)

* `_customs_description` (string; e.g., “Vulcanized rubber suction pad”)
* `_country_of_origin` (ISO-2; e.g., `CA`, `CN`, `TW`)

> Variations: same fields on the variation; fallback to parent if empty.

### 1.2 Global customs lookup (“Customs Profiles”)

Create a custom table (fast + easy to maintain by CSV):

**Table:** `wp_wrd_customs_profiles`
**Unique key:** `(description_normalized, country_code, effective_from)` to allow versioning

| Column                   | Type         | Notes                                               |
| ------------------------ | ------------ | --------------------------------------------------- |
| `id`                     | BIGINT PK    |                                                     |
| `description_raw`        | TEXT         | As entered; kept for admin display                  |
| `description_normalized` | VARCHAR(255) | Lowercased, trimmed, canonical spaces (used in key) |
| `country_code`           | CHAR(2)      | ISO-2                                               |
| `hs_code`                | VARCHAR(10)  | 6–10 digits                                         |
| `us_duty_json`           | JSON         | See §1.3                                            |
| `fta_flags`              | JSON         | e.g., `["CUSMA"]` if eligible                       |
| `effective_from`         | DATE         | Defaults to today                                   |
| `effective_to`           | DATE NULL    | Optional sunset                                     |
| `notes`                  | TEXT         | Optional admin notes                                |

**Admin UI**: searchable grid, CSV import/export, JSON validator.

### 1.3 `us_duty_json` schema (MVP, % only)

Duty rates modeled by **entry channel** (`postal` vs `commercial`), not by carrier brand.

```json
{
  "postal": {
    "de_minimis_threshold_usd": 800,
    "rates": {
      "base_mfn": 3.5,
      "section_301": 25.0
    }
  },
  "commercial": {
    "de_minimis_threshold_usd": 800,
    "rates": {
      "base_mfn": 3.5,
      "section_301": 25.0
    }
  }
}
```

> Only include components that actually apply to the **given** `description|country_code`.
> Brokerage/MPF/HMF live in **Channel/Carrier Fee Tables** (global settings), not here.

### 1.4 Channel/Carrier Fee Tables (global)

A separate settings object (no per-product data needed):

* **Postal**: typical “clearance fee” you charge (often \$0–\$20)
* **Commercial – Stallion/UPS/Purolator/FedEx**:

  * Brokerage tiers (by value or type of entry)
  * **MPF** min/max rules for formal entries
  * Any disbursement/advancement fees

---

## 2) Runtime lookups

**Normalize** the per-product description at runtime and resolve a profile:

```
key = normalize(_customs_description) + '|' + _country_of_origin
profile = SELECT * FROM wp_wrd_customs_profiles WHERE description_normalized=? AND country_code=? AND NOW() BETWEEN effective_from AND COALESCE(effective_to,'2999-12-31') ORDER BY effective_from DESC LIMIT 1
```

If not found: configurable behavior

* “Fail closed” (block checkout) **or**
* “Fallback” (use conservative default duty profile + warn/log)

Cache profile results per product per request.

---

## 3) Decision engine (with Case B logic)

### 3.1 Composition & value classification

For the current cart:

* Determine each line’s **profile** (HS, duty JSON, FTA flags).
* **CUSMA-eligible?** `fta_flags` contains `"CUSMA"` AND origin is CA/US/MX.
* Aggregate:

  * `cusma_value_usd`
  * `non_cusma_value_usd`
  * `total_value_usd`
* Classify composition: `all_cusma` / `all_non_cusma` / `mixed`

### 3.2 Routing rules (top-level)

* **TW** products: prefer **Postal** (fits your rule of thumb).
* **CA** products:

  * If **sub-shipment value < \$800** → route **Stallion** (commercial)
  * If **≥ \$800** → **Purolator** (commercial)
* **CN** products:

  * If **only CN** in the order → default **Postal**
  * If **mixed** → evaluate **split vs single**

> These are **preferences/constraints** your engine respects when generating candidate scenarios.

### 3.3 Case B (V > \$800, **mixed CUSMA + non-CUSMA**) — compare **single vs split**

**Single (Courier, one shipment):**

* Entry channel: `commercial`
* Duties: `CUSMA 0%` + non-CUSMA duty per `commercial.rates`
* Fees: brokerage + MPF per your fee table
* Shipping: courier rate for combined weight/dims
* Output: `{duties, fees, shipping, total}`

**Split (Two packages):**

* **Pkg A (CUSMA)**: route **Commercial** (fast; clean 0% duty), apply brokerage for that leg
* **Pkg B (non-CUSMA)**: default **Postal** if ≤ \$2,500, else **Commercial**
* Duties: `0` on CUSMA; HTS (+ 301 if present) on non-CUSMA using that package’s entry channel
* Fees: postal fee for non-CUSMA leg + brokerage for CUSMA leg
* Shipping: two labels (commercial + postal)
* Output: sum to `{duties, fees, shipping, total}`

**Choose** the cheaper **total** that also meets operational constraints:

* `can_split_order` (warehouse capability)
* `extra_shipping_cost ≤ threshold` (config: \$ and/or % savings)
* `value_per_leg ≤ informal/formal thresholds` (e.g., default to courier if leg > \$2,500)

> **Override rule**: if **any leg > \$2,500** or **high-risk non-CUSMA**, force that leg to **Commercial**.

### 3.4 Config knobs (Admin → Routing)

* Enable **Case B split optimization** (on/off)
* **Min savings to split**: `$` amount and/or `% of order value`
* **Postal limit** for non-CUSMA leg (e.g., \$2,500)
* **Risk override** for certain origins/HS (e.g., CN high-risk → always Commercial)

---

## 4) Checkout & order persistence

### 4.1 What the customer sees

* **Shipping** (sum of chosen legs’ shipping)
* **Duties & Import Fees** (sum of all applicable duties + your configurable clearance fees)
* Optional note: “Ships in 2 parcels: CA (Courier), CN (Postal).”

### 4.2 What we save to the order (immutable)

* `_wrd_shipments` (array of legs):

  * `id`, `origin`, `entry_channel` (postal/commercial), `carrier_family`
  * `value_usd`, `weight`, `dims`
  * `duties_total`, `fees_total`, `shipping_estimate`
  * `items`: `[ { order_item_id, qty, description, country_code, hs_code, cusma_flag, duty_rates_used } ]`
* `_wrd_totals`: `{ duties_total, fees_total, shipping_total }`
* `_wrd_routing_version`: plugin ruleset version
* Order note: human-readable breakdown

---

## 5) ShipStation handoff

Pick **one** model (matching your ops):

**A) Single order, multi-shipment** (preferred)

* Export order with **two shipments** (legs), each tagged:

  * `ROUTE-COMM-PUROLATOR`, `ROUTE-POSTAL-CP`, etc.
* Warehouse prints both labels from one order.

**B) Sub-orders per leg**

* Create child orders `#1234-1` and `#1234-2` containing the respective items.
* Tag each with route.
* Clear messaging to the customer.

> Manufacturer info is **not** needed in Woo—keep that in ShipStation or your label system’s vendor registry keyed by **HS + COO** or by `description|country_code`.

---

## 6) PHP hook map & core functions

### 6.1 Hooks

* `woocommerce_cart_calculate_fees` → compute & add **Duties & Import Fees**
* `woocommerce_checkout_create_order` → persist `_wrd_shipments`, `_wrd_totals`, notes
* Product admin: custom tab with fields + lookup tester

### 6.2 Key services (class outlines)

* `CustomsProfileRepository`

  * `findByDescriptionAndCOO(description, coo): Profile|null`
  * Normalizes description; caches results
* `DutyCalculator`

  * `estimate(lineItemsGrouped, entryChannel): {duty, breakdown}`
  * Uses `us_duty_json.rates` × customs value
* `FeeCalculator`

  * Brokerage/MPF/etc. by channel and value
* `RateEstimator`

  * Shipping quotes; API or static; returns `{amount, service_hint}`
* `RoutingEngine`

  * `plan(cart): ShipmentPlan`
  * Generates **single** and **split** scenarios for **Case B**; picks best
* `Splitter`

  * Materializes legs → line allocations

### 6.3 Pseudocode (Case B core)

```php
$composition = classify_composition($cart); // all_cusma, all_non_cusma, mixed
$total_usd = order_value_usd($cart);

if ($total_usd > 800 && $composition === 'mixed' && cfg('optimize_case_b')) {
    // SINGLE: one commercial leg
    $single = scenario_single_commercial($cart);

    // SPLIT: CUSMA via Commercial, non-CUSMA via Postal (or Commercial if > threshold)
    $split = can_split_order($cart) ? scenario_split_cusma_vs_non($cart) : null;

    $chosen = pick_best($single, $split, cfg());
} else {
    $chosen = fallback_routing($cart); // existing rules for other cases
}

persist_to_session($chosen);
```

Where:

```php
function scenario_single_commercial($cart) {
  $duty = duty_for_non_cusma($cart, 'commercial');
  $fees = fees_for_value($cart_value, 'commercial');
  $ship = estimate_shipping($cart, 'commercial');
  return totalize('single_commercial', $duty, $fees, $ship);
}

function scenario_split_cusma_vs_non($cart) {
  [$cusma, $non] = split_lines($cart, by_cusma_flag());

  // CUSMA leg: commercial
  $dutyA = 0.0;
  $feesA = fees_for_value(value_usd($cusma), 'commercial');
  $shipA = estimate_shipping($cusma, 'commercial');

  // non-CUSMA leg: postal if <= threshold else commercial
  $channelB = value_usd($non) <= cfg('postal_max_informal') ? 'postal' : 'commercial';
  $dutyB = duty_for_lines($non, $channelB);
  $feesB = fees_for_value(value_usd($non), $channelB);
  $shipB = estimate_shipping($non, $channelB);

  $total = sum($dutyA+$dutyB, $feesA+$feesB, $shipA+$shipB);

  if (!split_viable($shipA+$shipB, estimate_shipping($cart, 'commercial'), cfg())) {
    return null;
  }
  return totalize('split', $dutyA+$dutyB, $feesA+$feesB, $shipA+$shipB, ['legs' => [['CUSMA','commercial'], ['NON','postal or commercial']]]);
}
```

**Config checks** in `pick_best()`:

* If `split` exists and `split.total <= single.total - cfg('min_split_savings')` → choose split; else single.

---

## 7) Admin UX

* **Product editor** (“Trade & Duties” tab):

  * `Customs Description` (text, autocomplete from profiles)
  * `Country of Origin` (select)
  * “Test Lookup” button → shows HS + duty JSON from the profile (read-only preview)
* **Customs Profiles** page:

  * CRUD table + CSV import/export
  * JSON validator & pretty-print
  * Versioning via `effective_from/to`
* **Routing & Fees** page:

  * Case B optimization toggle
  * Min savings to split (\$ / %)
  * Postal informal threshold (default \$2,500)
  * Brokerage & MPF settings per channel

---

## 8) CSV formats

### 8.1 Product bulk update

```
sku,customs_description,country_of_origin
WRD-SP-PAD,Vulcanized rubber suction pad,TW
WRD-FIBER-80M,Fiber cord for windshield urethane cutting,TW
WRD-KNIFE,Rubber-handled cutting knife,CA
```

### 8.2 Customs profile import

```
description,country_code,hs_code,fta_flags,us_duty_json,effective_from,effective_to,notes
"Vulcanized rubber suction pad","CN","5607493000","[]","{""postal"":{""de_minimis_threshold_usd"":800,""rates"":{""base_mfn"":3.5,""section_301"":25.0}},""commercial"":{""de_minimis_threshold_usd"":800,""rates"":{""base_mfn"":3.5,""section_301"":25.0}}}","2025-08-01",,"CN 301 applies"
"Vulcanized rubber suction pad","TW","5607493000","[]","{""postal"":{""de_minimis_threshold_usd"":800,""rates"":{""base_mfn"":3.5}},""commercial"":{""de_minimis_threshold_usd"":800,""rates"":{""base_mfn"":3.5}}}","2025-08-01",,
"Rubber-handled cutting knife","CA","821192","[""CUSMA""]","{""postal"":{""de_minimis_threshold_usd"":800,""rates"":{""base_mfn"":0.0}},""commercial"":{""de_minimis_threshold_usd"":800,""rates"":{""base_mfn"":0.0}}}","2025-08-01",,"Preferential"
```

---

## 9) Guardrails & edge cases

* **Description drift**: Normalize descriptions (lowercase, trim, collapse spaces). Provide an admin tool: “Map similar descriptions → canonical descriptor”.
* **Missing profile**: configurable behavior (block vs fallback). Log loudly.
* **Mixed currencies**: Always convert to USD for de minimis/fee thresholds; show store currency at checkout.
* **“Must ship alone” items**: (optional) add a per-product switch if needed later; they bypass the Case B split optimization.
* **API outages**: If rating APIs fail, use cached/static tables; persist a warning note on the order.
* **Audit trail**: Save the chosen scenario and the alternatives’ totals to the order meta for transparency.

---

## 10) Rollout plan (phased)

**P0 (2–3 sprints)**

* Product fields + profile lookup
* Duty calculation (% model)
* Hard rules (TW→Postal, CA threshold, CN alone→Postal)
* Case B optimizer (single vs split)
* Checkout fees + order snapshot

**P1**

* Channel/Carrier Fee Tables (brokerage/MPF)
* ShipStation tags & (optionally) multi-shipment export
* CSV importers + validators

**P2**

* Live carrier API quotes + caching
* Simulator (upload a CSV cart; see chosen plan)
* Discrepancy tracker (estimated vs actual label costs)

---

If you want, I can spit out:

* a **DB migration script** for `wp_wrd_customs_profiles`,
* a **WC meta-box PHP stub** for the product fields + “Test Lookup”,
* and a **Case B unit-test matrix** (inputs → expected route/cost).
