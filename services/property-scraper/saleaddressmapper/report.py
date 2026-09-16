from __future__ import annotations
import json
from pathlib import Path
from typing import List

from .models import Property
from .launcher import write_launcher

_TEMPLATE = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sale Property Map Links</title>
<link rel="preconnect" href="https://www.google.com">
<link rel="dns-prefetch" href="https://www.google.com">
<style>
  body { font-family: -apple-system, Segoe UI, Arial, sans-serif; margin: 1.5rem; color: #1b1b1b; }
  h1 { font-size: 1.3rem; }
  .controls { display: flex; gap: .75rem; margin-bottom: .75rem; flex-wrap: wrap; align-items: center; }
  input[type="search"] { padding: .4rem .6rem; font-size: .9rem; min-width: 240px; }
  select { padding: .4rem; font-size: .9rem; }
  table { border-collapse: collapse; width: 100%; font-size: .85rem; }
  th, td { border: 1px solid #ddd; padding: .4rem .5rem; text-align: left; vertical-align: top; }
  th { background: #f3f3f3; cursor: pointer; position: sticky; top: 0; }
  tr:nth-child(even) { background: #fafafa; }
  a.maps-link, a.parcel-link, button.refresh-btn { display: inline-block; padding: .25rem .55rem; background: #1a73e8; color: #fff;
                border-radius: 4px; text-decoration: none; font-size: .8rem; white-space: nowrap; border: none; cursor: pointer; }
  a.maps-link:hover, a.parcel-link:hover, button.refresh-btn:hover { background: #1558b0; }
  a.parcel-link { background: #6b3fa0; }
  a.parcel-link:hover { background: #55317f; }
  .count { color: #555; font-size: .85rem; margin-bottom: .5rem; }
</style>
</head>
<body>
  <h1>Sale Property Map Links</h1>
  <div class="count" id="count"></div>
  <div class="controls">
    <input type="search" id="search" placeholder="Search address, county, status...">
    <select id="statusFilter"><option value="">All statuses</option></select>
    <select id="groupFilter"><option value="">All sale groups</option></select>
    <button type="button" class="refresh-btn" id="refreshBtn" title="Reload this page to pick up a freshly regenerated report">&#8635; Refresh List</button>
  </div>
  <table id="tbl">
    <thead>
      <tr>
        <th data-key="address">Address</th>
        <th data-key="sale_group">Sale Group</th>
        <th data-key="sale_id">Sale ID</th>
        <th data-key="parcel">Parcel #</th>
        <th data-key="sale_date">Sale Date</th>
        <th data-key="status">Status</th>
        <th data-key="sale_type">Sale Type</th>
        <th>Map</th>
        <th>Parcel Lookup</th>
      </tr>
    </thead>
    <tbody id="tbody"></tbody>
  </table>

<script>
const DATA = __DATA_JSON__;

const tbody = document.getElementById('tbody');
const countEl = document.getElementById('count');
const searchEl = document.getElementById('search');
const statusEl = document.getElementById('statusFilter');
const groupEl = document.getElementById('groupFilter');
const refreshBtn = document.getElementById('refreshBtn');
// The GIS site has no working URL search param (confirmed: #SearchFor= and ?FindText= do nothing) -
// so we copy the parcel number to the clipboard and open the homepage for the user to paste + search.
const PARCEL_GIS_URL = 'https://fayettein.wthgis.com/';

function firstParcelToken(parcel) {
  return (parcel || '').trim().split(/\s+/)[0];
}

function uniqueSorted(key) {
  return [...new Set(DATA.map(d => d[key]).filter(Boolean))].sort();
}
uniqueSorted('status').forEach(s => statusEl.add(new Option(s, s)));
uniqueSorted('sale_group').forEach(g => groupEl.add(new Option(g, g)));

let sortKey = 'address';
let sortAsc = true;

function render() {
  const q = searchEl.value.trim().toLowerCase();
  const statusVal = statusEl.value;
  const groupVal = groupEl.value;

  let rows = DATA.filter(d => {
    if (statusVal && d.status !== statusVal) return false;
    if (groupVal && d.sale_group !== groupVal) return false;
    if (q && !Object.values(d).join(' ').toLowerCase().includes(q)) return false;
    return true;
  });

  rows.sort((a, b) => {
    const av = (a[sortKey] || '').toString();
    const bv = (b[sortKey] || '').toString();
    return sortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
  });

  tbody.innerHTML = rows.map(d => `
    <tr>
      <td>${d.address}</td>
      <td>${d.sale_group}</td>
      <td>${d.sale_id}</td>
      <td>${d.parcel}</td>
      <td>${d.sale_date}</td>
      <td>${d.status}</td>
      <td>${d.sale_type}</td>
      <td><a class="maps-link" href="${d.maps_url}" target="_blank" rel="noopener">View on Map</a></td>
      <td><a class="parcel-link" href="${PARCEL_GIS_URL}" data-parcel="${firstParcelToken(d.parcel)}" title="Copies the parcel number, then opens the county GIS site to paste into its search box">Lookup Parcel</a></td>
    </tr>`).join('');

  countEl.textContent = `Showing ${rows.length} of ${DATA.length} properties`;
}

document.querySelectorAll('th[data-key]').forEach(th => {
  th.addEventListener('click', () => {
    const key = th.dataset.key;
    sortAsc = sortKey === key ? !sortAsc : true;
    sortKey = key;
    render();
  });
});

searchEl.addEventListener('input', render);
statusEl.addEventListener('change', render);
groupEl.addEventListener('change', render);
refreshBtn.addEventListener('click', () => location.reload());

function copyToClipboard(text) {
  // navigator.clipboard requires a secure context and is unavailable on file:// pages,
  // so fall back to the classic textarea + execCommand trick, which works there.
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).catch(() => fallbackCopy(text));
  } else {
    fallbackCopy(text);
  }
}

function fallbackCopy(text) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.focus();
  ta.select();
  try { document.execCommand('copy'); } catch (err) { /* clipboard unavailable */ }
  document.body.removeChild(ta);
}

tbody.addEventListener('click', (e) => {
  const link = e.target.closest('a.parcel-link');
  if (!link) return;
  e.preventDefault();
  // Copy first while this document still has focus, then open the tab -
  // doing both at once (via target=_blank) can shift focus before the copy completes.
  const parcel = link.dataset.parcel;
  if (parcel) copyToClipboard(parcel);
  window.open(link.href, '_blank', 'noopener');
});

render();
</script>
</body>
</html>
"""


def render_report(properties: List[Property], output_path: str | Path) -> Path:
    output_path = Path(output_path)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    data_json = json.dumps([p.to_dict() for p in properties])
    html = _TEMPLATE.replace("__DATA_JSON__", data_json)
    output_path.write_text(html, encoding="utf-8")
    write_launcher(output_path)
    return output_path
