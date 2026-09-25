const base = '/api/v1/live-worship';

export async function request(path, options = {}) {
  const headers = new Headers(options.headers || {});
  const isForm = options.body instanceof FormData;
  if (options.body && !isForm && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
  const response = await fetch(`${base}${path}`, { credentials: 'include', ...options, headers });
  if (response.status === 204) return null;
  const contentType = response.headers.get('content-type') || '';
  const payload = contentType.includes('application/json') ? await response.json() : null;
  if (!response.ok) {
    const error = new Error(payload?.error || `Request failed (${response.status})`);
    error.status = response.status;
    throw error;
  }
  return payload;
}

export function send(method, payload) {
  return { method, body: JSON.stringify(payload) };
}

export function normalizeSong(song) {
  const rawSections = song.sections || [];
  const baseName = (name) => String(name || '')
    .replace(/\s*\((?:\d+\s*x|x\s*\d+)\)\s*$/i, '')
    .replace(/\s+\d+(?=\s|$)/, '')
    .trim();
  const counts = rawSections.reduce((result, section) => {
    const base = baseName(section.name);
    const key = base.toLocaleLowerCase();
    result[key] = (result[key] || 0) + 1;
    return result;
  }, {});
  const seen = {};
  const sections = rawSections.map((section) => {
    const base = baseName(section.name) || section.name;
    const key = String(base).toLocaleLowerCase();
    seen[key] = (seen[key] || 0) + 1;
    return { ...section, name: counts[key] > 1 ? `${base} ${seen[key]}` : base };
  });
  return {
    ...song,
    id: String(song.id),
    key: song.default_key || '',
    parts: sections,
    pages: (song.pages || []).map((page) => ({ ...page, name: `Page ${page.number}`, image: page.url })),
    updated: song.updated_at ? new Date(song.updated_at).toLocaleDateString() : '',
  };
}

export function normalizeSetlist(item) {
  return {
    ...item,
    id: String(item.id),
    serviceAt: item.service_at,
    archived: item.status === 'archived',
    songs: (item.songs || []).map(normalizeSong),
    songIds: (item.songs || []).map((song) => String(song.id)),
  };
}
