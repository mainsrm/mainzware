import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Archive, ArrowDown, ArrowUp, AudioLines, Calendar, ChevronDown, ChevronLeft, ChevronRight, CirclePlus, Guitar, House, ImagePlus, Library, ListMusic, LockKeyhole, LogOut, Moon, Music2, Pencil, Play, Search, Settings, Sparkles, Sun, Trash2, Upload, Users, X } from 'lucide-react';
import { normalizeSetlist, normalizeSong, request, send } from './api';

const WHOLE_SONG = 'whole-song';

export default function App() {
  const [authStatus, setAuthStatus] = useState('checking');
  const [songs, setSongs] = useState([]);
  const [setlists, setSetlists] = useState([]);
  const [brand, setBrand] = useState('Live Worship');
  const [brandLogo, setBrandLogo] = useState(null);
  const [view, setView] = useState('home');
  const [query, setQuery] = useState('');
  const [song, setSong] = useState(null);
  const [viewingSetlist, setViewingSetlist] = useState(null);
  const [songReturnView, setSongReturnView] = useState('home');
  const [partId, setPartId] = useState('');
  const [role, setRole] = useState('choir');
  const [username, setUsername] = useState('');
  const [authType, setAuthType] = useState('mainzware');
  const [mode, setMode] = useState('choir');
  const [modal, setModal] = useState('');
  const [draft, setDraft] = useState(null);
  const [selectedSetlistSongIds, setSelectedSetlistSongIds] = useState([]);
  const [setlistSongQuery, setSetlistSongQuery] = useState('');
  const [songStep, setSongStep] = useState('review');
  const [importMethodsOpen, setImportMethodsOpen] = useState(false);
  const [songSource, setSongSource] = useState('manual');
  const [songEditorMode, setSongEditorMode] = useState('sections');
  const [chordProDraft, setChordProDraft] = useState('');
  const [pastedChartText, setPastedChartText] = useState('');
  const [notice, setNotice] = useState('');
  const [ocrStatus, setOcrStatus] = useState('idle');
  const ocrBusy = ['reading', 'loading'].includes(ocrStatus) || ocrStatus.startsWith('recognizing');
  const [previewImage, setPreviewImage] = useState(null);
  const [currentSetlistId, setCurrentSetlistId] = useState(null);
  const [storage, setStorage] = useState({ page_count: 0, total_bytes: 0, total_megabytes: 0 });
  const [members, setMembers] = useState([]);
  const [settingsLoading, setSettingsLoading] = useState(false);
  const [settingsError, setSettingsError] = useState('');
  const [loading, setLoading] = useState(true);
  const [connectionError, setConnectionError] = useState('');
  const [memberUsername, setMemberUsername] = useState('');
  const [memberPassword, setMemberPassword] = useState('');
  const [memberAuthType, setMemberAuthType] = useState('live_worship');
  const [memberRole, setMemberRole] = useState('choir');
  const [loginUsername, setLoginUsername] = useState('');
  const [loginPassword, setLoginPassword] = useState('');
  const [loginError, setLoginError] = useState('');
  const [profileMenuOpen, setProfileMenuOpen] = useState(false);
  const [darkMode, setDarkMode] = useState(() => localStorage.getItem('live-worship-theme') === 'dark');
  const [passwordCurrent, setPasswordCurrent] = useState('');
  const [passwordNew, setPasswordNew] = useState('');
  const [passwordConfirm, setPasswordConfirm] = useState('');
  const liveRevision = useRef(0);
  const filesRef = useRef(null);
  const profileMenuRef = useRef(null);

  useEffect(() => {
    document.title = brand;
  }, [brand]);

  useEffect(() => {
    if (authStatus === 'ready') window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
  }, [authStatus]);

  useEffect(() => {
    document.documentElement.dataset.theme = darkMode ? 'dark' : 'light';
    localStorage.setItem('live-worship-theme', darkMode ? 'dark' : 'light');
  }, [darkMode]);

  useEffect(() => {
    if (!profileMenuOpen) return undefined;
    const dismiss = (event) => {
      if (event.type === 'keydown' && event.key === 'Escape') setProfileMenuOpen(false);
      else if (event.type === 'pointerdown' && !profileMenuRef.current?.contains(event.target)) setProfileMenuOpen(false);
    };
    document.addEventListener('pointerdown', dismiss);
    document.addEventListener('keydown', dismiss);
    return () => {
      document.removeEventListener('pointerdown', dismiss);
      document.removeEventListener('keydown', dismiss);
    };
  }, [profileMenuOpen]);

  useEffect(() => {
    if (!previewImage) return undefined;
    const closeOnEscape = (event) => { if (event.key === 'Escape') setPreviewImage(null); };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [previewImage]);

  useEffect(() => {
    refreshApp().catch((error) => {
      setConnectionError(error.message || 'Could not load your Live Worship workspace.');
      setAuthStatus(error.status === 401 ? 'sign-in' : error.status === 403 ? 'not-member' : 'unavailable');
    }).finally(() => setLoading(false));
  }, []);
  useEffect(() => {
    if (authStatus !== 'ready') return undefined;
    const refreshLists = async () => {
      try {
        const [active, archived, me, settings] = await Promise.all([request('/setlists'), request('/setlists?status=archived'), request('/me'), request('/settings')]);
        setSetlists([...active, ...archived].map(normalizeSetlist));
        setRole(me.role); setUsername(me.username || ''); setAuthType(me.auth_type || 'mainzware'); setBrand(settings.display_name || 'Live Worship'); setBrandLogo(settings.logo_url || null);
      } catch (error) {
        if (error.status === 401) setAuthStatus('sign-in');
        else if (error.status === 403) setAuthStatus('not-member');
      }
    };
    const timer = setInterval(refreshLists, 30000);
    return () => clearInterval(timer);
  }, [authStatus]);
  useEffect(() => {
    if (authStatus !== 'ready') return undefined;
    const poll = async () => {
      try {
        const state = await request('/live');
        if (!state) { setCurrentSetlistId(null); return; }
        setCurrentSetlistId(String(state.setlist_id));
        if (state.revision === liveRevision.current) return;
        liveRevision.current = state.revision;
        if (!state.song_id) return;
        const selected = songs.find((item) => item.id === String(state.song_id));
        if (selected) {
          setSong(selected); setView('song');
          setPartId(state.section_id || WHOLE_SONG);
        }
      } catch { /* transient polling errors do not interrupt song display */ }
    };
    poll();
    const timer = setInterval(poll, 1800);
    return () => clearInterval(timer);
  }, [authStatus, songs]);

  const filtered = useMemo(() => songs.filter((item) => item.title.toLowerCase().includes(query.toLowerCase()) || (item.writer || '').toLowerCase().includes(query.toLowerCase())), [songs, query]);
  const filteredSetlistSongs = useMemo(() => {
    const search = setlistSongQuery.trim().toLowerCase();
    if (!search) return songs;
    return songs.filter((item) => [item.title, item.writer, item.key].some((value) => String(value || '').toLowerCase().includes(search)));
  }, [songs, setlistSongQuery]);
  const activeSetlists = setlists.filter((item) => !item.archived);
  const homeServices = [...activeSetlists].sort((a, b) => Number(Boolean(b.current)) - Number(Boolean(a.current)) || new Date(a.serviceAt) - new Date(b.serviceAt)).slice(0, 2);
  const archivedSetlists = setlists.filter((item) => item.archived);
  const showWholeSong = partId === WHOLE_SONG;
  const activePart = showWholeSong ? null : song?.parts?.find((part) => part.id === partId) || song?.parts?.[0];
  const currentSetlist = setlists.find((item) => item.id === currentSetlistId);
  const currentSongIndex = currentSetlist ? currentSetlist.songIds.indexOf(song?.id) : -1;
  const roleLabel = role === 'leader' ? 'Worship leader' : role === 'choir' ? 'Choir member' : 'Musician';
  const usernameInitial = username.trim().charAt(0).toUpperCase() || 'U';

  async function refreshApp() {
    const [me, settings, songRows, activeRows, archivedRows, live] = await Promise.all([
      request('/me'), request('/settings'), request('/songs'), request('/setlists'), request('/setlists?status=archived'), request('/live'),
    ]);
    setRole(me.role); setUsername(me.username || ''); setAuthType(me.auth_type || 'mainzware'); setMode(me.role === 'musician' ? 'musician' : 'choir'); setBrand(settings.display_name || 'Live Worship'); setBrandLogo(settings.logo_url || null);
    const normalizedSongs = songRows.map(normalizeSong);
    setSongs(normalizedSongs); setSetlists([...activeRows, ...archivedRows].map(normalizeSetlist));
    setCurrentSetlistId(live ? String(live.setlist_id) : null);
    liveRevision.current = live?.revision || 0;
    if (live?.song_id) {
      const currentSong = normalizedSongs.find((item) => item.id === String(live.song_id));
      if (currentSong) { setSong(currentSong); setPartId(live.section_id || WHOLE_SONG); }
    }
    setConnectionError(''); setAuthStatus('ready');
  }

  async function openSettings() {
    setProfileMenuOpen(false);
    setSettingsError('');
    setSettingsLoading(true);
    setModal('settings');
    try { await refreshSettings(); }
    catch (error) { setSettingsError(error.message || 'Please try again.'); }
    finally { setSettingsLoading(false); }
  }

  async function refreshSettings() {
    const [settings, memberRows, usage] = await Promise.all([request('/settings'), request('/members'), request('/storage')]);
    setBrand(settings.display_name || 'Live Worship'); setBrandLogo(settings.logo_url || null); setMembers(memberRows); setStorage(usage);
  }

  function goHome() { setView('home'); setSong(null); }
  function goCatalog() { setView('catalog'); setSong(null); }
  function goSetlists() { setView('setlists'); setSong(null); }
  function goArchive() { setView('archive'); setSong(null); }
  function viewSetlist(item) { setViewingSetlist(item); setModal('setlist-detail'); }
  async function openSetlist(item = null) {
    setDraft(item);
    setSetlistSongQuery('');
    setSelectedSetlistSongIds((item?.songIds || item?.songs?.map((song) => song.id) || []).map(String));
    setModal('setlist');
    try {
      const rows = await request('/songs');
      const catalog = rows.map(normalizeSong);
      const availableIds = new Set(catalog.map((song) => song.id));
      setSongs(catalog);
      setSelectedSetlistSongIds((current) => current.filter((id) => availableIds.has(id)));
    } catch (error) { showNotice(error.message || 'Could not load the song catalog'); }
  }
  function returnFromSong() { setView(songReturnView === 'song' ? 'home' : songReturnView); setSong(null); }
  function openSong(item, fromView = view) { if (fromView !== 'song') setSongReturnView(fromView); setSong(item); setPartId(WHOLE_SONG); setView('song'); }
  function newSong() {
    const parts = [{ id: crypto.randomUUID(), name: 'Verse 1', lyrics: '', chords: '', chord_marks: [] }];
    setDraft({ id: '', title: '', writer: '', key: '', parts, pages: [], ocrText: '' });
    setImportMethodsOpen(false); setSongStep('review'); setSongSource('manual'); setSongEditorMode('sections'); setChordProDraft(serializeChordPro(parts));
    setPastedChartText(''); setOcrStatus('idle'); setModal('song');
  }
  function editSong(item) {
    const parts = item.parts.map((part) => ({ ...part, lyrics: normalizeWhitespaceEntities(part.lyrics), chord_marks: part.chord_marks || [] }));
    setDraft({ ...item, parts, removedPageIds: [], pages: item.pages.map((page) => ({ ...page, pending: false })) });
    setImportMethodsOpen(false); setSongStep('review'); setSongSource('edit'); setSongEditorMode('chordpro'); setChordProDraft(serializeChordPro(parts));
    setPastedChartText(''); setOcrStatus('idle'); setModal('song');
  }
  function chooseSongEntry(source) {
    setSongSource(source); setSongStep(source === 'photo' ? 'photos' : 'review');
    setImportMethodsOpen(false);
    changeSongEditorMode('sections');
  }
  function changeSongEditorMode(nextMode) {
    if (nextMode === songEditorMode) return;
    if (nextMode === 'chordpro') {
      setChordProDraft(serializeChordPro(draft.parts));
      setSongEditorMode('chordpro');
      return;
    }
    const chordPro = parseChordPro(chordProDraft);
    const parsed = chordPro.isChordPro ? chordPro : parseInlineChordChart(chordProDraft) || chordPro;
    const parts = parsed.parts.length ? parsed.parts : [{ id: crypto.randomUUID(), name: 'Verse 1', lyrics: '', chords: '', chord_marks: [] }];
    setDraft((current) => ({ ...current, parts, title: current.title || parsed.title, writer: current.writer || parsed.writer, key: current.key || parsed.key }));
    setSongEditorMode('sections');
  }
  async function saveSong(event) {
    event.preventDefault();
    if (songStep === 'photos') { setSongStep('review'); return; }
    const parsedChordPro = songEditorMode === 'chordpro' ? parseChordPro(chordProDraft) : null;
    const chordPro = parsedChordPro && !parsedChordPro.isChordPro ? parseInlineChordChart(chordProDraft) || parsedChordPro : parsedChordPro;
    const sections = chordPro ? (chordPro.parts.length ? chordPro.parts : [{ id: crypto.randomUUID(), name: 'Verse 1', lyrics: '', chords: '', chord_marks: [] }]) : draft.parts;
    const payload = {
      title: draft.title || chordPro?.title || '',
      writer: draft.writer || chordPro?.writer || '',
      default_key: draft.key || chordPro?.key || '',
      sections,
      ocr_text: draft.ocrText || '',
    };
    try {
      let saved = draft.id ? await request(`/songs/${draft.id}`, send('PUT', payload)) : await request('/songs', send('POST', payload));
      if (!draft.id) setDraft((current) => ({ ...current, id: String(saved.id) }));
      for (const pageId of draft.removedPageIds || []) {
        await request(`/songs/${saved.id}/pages/${pageId}`, { method: 'DELETE' });
        setDraft((current) => ({ ...current, removedPageIds: current.removedPageIds.filter((id) => id !== pageId) }));
      }
      const uploads = draft.pages.filter((page) => page.file);
      for (const page of uploads) {
        const form = new FormData(); form.append('pages[]', page.file, page.name);
        const [stored] = await request(`/songs/${saved.id}/pages`, { method: 'POST', body: form });
        setDraft((current) => ({ ...current, pages: current.pages.map((item) => {
          if (item.file !== page.file) return item;
          const { file, ...rest } = item;
          return { ...rest, id: stored.id, name: `Page ${stored.number}`, image: stored.url };
        }) }));
      }
      await refreshSongsAndLists(); saved = normalizeSong(await request(`/songs/${saved.id}`));
      if (!draft.id) setSongReturnView('catalog');
      setSong(saved); setPartId(WHOLE_SONG); setModal(''); setView('song'); showNotice('Song saved to the catalog');
    } catch (error) { showNotice(error.message); }
  }
  async function refreshSongsAndLists() {
    const [songRows, activeRows, archivedRows] = await Promise.all([request('/songs'), request('/setlists'), request('/setlists?status=archived')]);
    setSongs(songRows.map(normalizeSong)); setSetlists([...activeRows, ...archivedRows].map(normalizeSetlist));
  }
  function showNotice(message) { setNotice(message); window.setTimeout(() => setNotice(''), 3000); }
  async function deleteSong(id) { try { await request(`/songs/${id}`, { method: 'DELETE' }); await refreshSongsAndLists(); setSong(null); setView('home'); setModal(''); } catch (error) { showNotice(error.message); } }
  async function addPages(event) {
    const selected = Array.from(event.target.files || []); if (!selected.length) return;
    setOcrStatus('reading');
    try {
      const pages = await Promise.all(selected.map(async (file) => {
        const storedFile = await jpegForUpload(file);
        const reader = new FileReader();
        const image = await new Promise((resolve, reject) => { reader.onload = () => resolve(reader.result); reader.onerror = reject; reader.readAsDataURL(storedFile); });
        return { name: file.name.replace(/\.[^.]+$/, '.jpg'), image, file: storedFile };
      }));
    setDraft((current) => ({ ...current, pages: [...(current.pages || []), ...pages] }));
      setOcrStatus('loading');
      for (let index = 0; index < pages.length; index += 1) {
        setOcrStatus(`recognizing-${index + 1}-${pages.length}`);
        const form = new FormData(); form.append('image', pages[index].file, pages[index].name);
        const result = await request('/songs/ocr', { method: 'POST', body: form });
        pages[index] = { ...pages[index], recognizedText: cleanOcrPage(result.lines) };
        const recognizedPage = pages[index];
        setDraft((current) => ({ ...current, pages: current.pages.map((page) => page.file === recognizedPage.file ? recognizedPage : page) }));
      }
      const allPages = [...(draft.pages || []), ...pages];
      const extracted = allPages.map((page) => page.recognizedText || '').filter(Boolean).join('\n\n'); const suggestions = parseChartText(extracted);
      setDraft((current) => {
        const hasLyrics = current.parts.some((part) => part.lyrics.trim());
        const parts = suggestions.parts.length ? suggestions.parts : [{ ...current.parts[0], lyrics: extracted.trim(), chords: '', chord_marks: [] }];
        return { ...current, title: current.id ? current.title : suggestions.title || current.title, pages: current.pages.map((page) => pages.find((parsed) => parsed.file === page.file) || page), parts: current.id && hasLyrics ? current.parts : parts, ocrText: extracted };
      });
      setSongEditorMode('sections');
      setOcrStatus('done');
      showNotice(suggestions.parts.some((part) => part.lyrics.trim()) ? 'Photo text is ready to review' : 'No usable lyric lines were found. The photo is attached; enter the lyrics manually or try a clearer photo.');
    } catch (error) { setOcrStatus('error'); showNotice(error?.status === 503 ? 'Photo recognition is unavailable. You can still enter the lyrics manually.' : error.message || 'Could not read these photos. You can still enter the lyrics manually.'); }
    event.target.value = '';
  }
  function removePage(index) {
    const page = draft.pages[index];
    const pages = draft.pages.filter((_, i) => i !== index);
    const extracted = !draft.id && pages.every((item) => typeof item.recognizedText === 'string') ? pages.map((item) => item.recognizedText).join('\n\n') : null;
    const suggestions = extracted === null ? null : parseChartText(extracted);
    setDraft((current) => ({ ...current, pages, ...(suggestions ? { title: suggestions.title, writer: suggestions.writer, key: suggestions.key, parts: suggestions.parts.length ? suggestions.parts : [{ ...current.parts[0], lyrics: extracted.trim(), chords: '', chord_marks: [] }], ocrText: extracted } : {}), removedPageIds: page.id && !page.file ? [...(current.removedPageIds || []), page.id] : current.removedPageIds || [] }));
  }
  function movePage(index, delta) {
    const nextIndex = index + delta;
    if (nextIndex < 0 || nextIndex >= draft.pages.length) return;
    const pages = [...draft.pages];
    [pages[index], pages[nextIndex]] = [pages[nextIndex], pages[index]];
    const extracted = !draft.id && pages.every((page) => typeof page.recognizedText === 'string') ? pages.map((page) => page.recognizedText).join('\n\n') : null;
    const suggestions = extracted === null ? null : parseChartText(extracted);
    setDraft((current) => ({ ...current, pages, ...(suggestions ? { title: suggestions.title || '', writer: suggestions.writer || '', key: suggestions.key || '', parts: suggestions.parts.length ? suggestions.parts : [{ ...current.parts[0], lyrics: extracted.trim(), chords: '', chord_marks: [] }], ocrText: extracted } : {}) }));
  }
  function addPart() { setDraft((current) => ({ ...current, parts: [...current.parts, { id: crypto.randomUUID(), name: `Verse ${current.parts.length + 1}`, lyrics: '', chords: '', chord_marks: [] }] })); }
  function updatePart(id, field, value) { setDraft((current) => ({ ...current, parts: (current.parts || []).map((part) => part.id === id ? { ...part, [field]: value } : part) })); }
  function updateLyrics(id, value) {
    setDraft((current) => {
      const parts = current.parts || [];
      const part = parts.find((item) => item.id === id);
      if (!part) return current;
      const before = Array.from(typeof part.lyrics === 'string' ? part.lyrics : '');
      const after = Array.from(value);
      let prefix = 0;
      while (prefix < before.length && prefix < after.length && before[prefix] === after[prefix]) prefix += 1;
      let suffix = 0;
      while (suffix < before.length - prefix && suffix < after.length - prefix && before[before.length - 1 - suffix] === after[after.length - 1 - suffix]) suffix += 1;
      const oldEnd = before.length - suffix;
      const delta = after.length - before.length;
      const sourceMarks = Array.isArray(part.chord_marks) ? part.chord_marks : [];
      const marks = sourceMarks.flatMap((mark) => {
        if (!mark || !Number.isInteger(mark.at) || typeof mark.chord !== 'string') return [];
        if (mark.at <= prefix) return [mark];
        if (mark.at >= oldEnd) return [{ ...mark, at: mark.at + delta }];
        return [];
      });
      return { ...current, parts: parts.map((item) => item.id === id ? { ...item, lyrics: value, chord_marks: marks } : item) };
    });
  }
  function removePart(id) { setDraft((current) => ({ ...current, parts: current.parts.filter((part) => part.id !== id) })); }
  function removeChord(part, at) { updatePart(part.id, 'chord_marks', (part.chord_marks || []).filter((mark) => mark.at !== at)); }
  function applyPastedChart() {
    const parsed = parsePastedChart(pastedChartText);
    if (!parsed.parts.length) { showNotice('Could not find song lyrics in that text. Keep the line breaks and try again.'); return; }
    setDraft((current) => ({ ...current, title: parsed.title || current.title, writer: parsed.writer || current.writer, key: parsed.key || current.key, parts: parsed.parts, ocrText: pastedChartText }));
    setChordProDraft(serializeChordPro(parsed.parts)); setSongEditorMode('sections');
    showNotice(`Imported ${parsed.parts.length} song part${parsed.parts.length === 1 ? '' : 's'}; review the chord positions`);
  }
  async function importSongTextFile(event) {
    const input = event.currentTarget;
    const file = input.files?.[0];
    if (!file) return;
    try {
      const text = await file.text();
      const chordPro = parseChordPro(text);
      const parsed = chordPro.isChordPro ? chordPro : parsePastedChart(text);
      if (!parsed.parts.length) throw new Error('Could not find song lyrics in that file.');
      setDraft((current) => ({ ...current, title: parsed.title || current.title, writer: parsed.writer || current.writer, key: parsed.key || current.key, parts: parsed.parts, ocrText: text }));
      setChordProDraft(serializeChordPro(parsed.parts)); setSongEditorMode(chordPro.isChordPro ? 'chordpro' : 'sections');
      setPastedChartText(text);
      showNotice(`Imported ${parsed.parts.length} song part${parsed.parts.length === 1 ? '' : 's'} from file`);
    } catch (error) { showNotice(error.message || 'Could not read that song file'); }
    input.value = '';
  }

  async function createSetlist(event) {
    event.preventDefault(); const form = new FormData(event.currentTarget);
    const body = { name: form.get('name'), service_at: new Date(form.get('serviceAt')).toISOString(), song_ids: selectedSetlistSongIds };
    try {
      if (draft?.id) await request(`/setlists/${draft.id}`, send('PUT', body)); else await request('/setlists', send('POST', body));
      await refreshSongsAndLists(); setDraft(null); setModal(''); showNotice('Set list saved');
    } catch (error) { showNotice(error.message); }
  }
  async function deleteSetlist(id) {
    try {
      await request(`/setlists/${id}`, { method: 'DELETE' });
      if (currentSetlistId === id) setCurrentSetlistId(null);
      await refreshSongsAndLists();
      setDraft(null); setModal(''); showNotice('Set list deleted');
    } catch (error) { showNotice(error.message); }
  }
  async function startService(item) {
    setCurrentSetlistId(item.id);
    if (role === 'leader') {
      try { await request(`/setlists/${item.id}/start`, { method: 'POST' }); }
      catch (error) { showNotice(error.message); return; }
    }
    const first = item.songs[0];
    if (first && role === 'leader') {
      try { await request(`/setlists/${item.id}/state`, send('PUT', { song_id: Number(first.id), section_id: null })); }
      catch (error) { showNotice(error.message); }
    }
    if (first) openSong(first);
  }
  async function selectPart(id) {
    setPartId(id);
    if (role !== 'leader' || !currentSetlistId || !song) return;
    try { await request(`/setlists/${currentSetlistId}/state`, send('PUT', { song_id: Number(song.id), section_id: id === WHOLE_SONG ? null : id })); }
    catch (error) { showNotice(error.message); }
  }
  async function selectSetlistSong(index) {
    const next = currentSetlist?.songs[index];
    if (!next) return;
    openSong(next);
    if (role === 'leader') {
      try { await request(`/setlists/${currentSetlist.id}/state`, send('PUT', { song_id: Number(next.id), section_id: null })); }
      catch (error) { showNotice(error.message); }
    }
  }
  async function saveBrand(event) {
    event.preventDefault();
    try { const updated = await request('/settings', send('PUT', { display_name: event.currentTarget.elements.name.value.trim() })); setBrand(updated.display_name); setBrandLogo(updated.logo_url || null); setModal(''); showNotice('Team name updated'); }
    catch (error) { showNotice(error.message); }
  }
  async function uploadLogo(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    const form = new FormData(); form.append('logo', file);
    try {
      const updated = await request('/settings/logo', { method: 'POST', body: form });
      setBrandLogo(updated.logo_url || null); showNotice('Team logo updated');
    } catch (error) { showNotice(error.message); }
    event.target.value = '';
  }
  async function removeLogo() {
    try {
      const updated = await request('/settings/logo', { method: 'DELETE' });
      setBrandLogo(updated.logo_url || null); showNotice('Team logo removed');
    } catch (error) { showNotice(error.message); }
  }
  async function addMember(event) {
    event.preventDefault();
    try {
      await request('/members', send('POST', { username: memberUsername.trim(), password: memberPassword, auth_type: memberAuthType, role: memberRole }));
      setMemberUsername(''); setMemberPassword(''); await refreshSettings(); showNotice('Member added');
    }
    catch (error) { showNotice(error.message); }
  }
  async function changeMemberRole(member, nextRole) {
    try { await request(`/members/${member.id}`, send('PUT', { role: nextRole })); await refreshSettings(); }
    catch (error) { showNotice(error.message); }
  }
  async function removeMember(member) {
    try { await request(`/members/${member.id}`, { method: 'DELETE' }); await refreshSettings(); showNotice('Member access removed'); }
    catch (error) { showNotice(error.message); }
  }

  async function signInLiveWorship(event) {
    event.preventDefault(); setLoginError(''); setLoading(true);
    try {
      await request('/auth/login', send('POST', { username: loginUsername.trim(), password: loginPassword }));
      setLoginPassword('');
      await refreshApp();
    } catch (error) {
      setLoginError(error.message || 'Could not sign in.');
      setAuthStatus(error.status === 403 ? 'not-member' : 'sign-in');
    } finally { setLoading(false); }
  }

  async function continueWithMainzWare() {
    setLoginError(''); setLoading(true);
    try { await request('/auth/mainzware', send('POST', {})); await refreshApp(); }
    catch (error) {
      setLoginError(error.status === 401 ? 'Sign in to MainzWare first, then return to Live Worship.' : error.message || 'Could not use your MainzWare session.');
      setAuthStatus('sign-in');
    } finally { setLoading(false); }
  }

  async function signOutLiveWorship() {
    try {
      await request('/auth/logout', send('POST', {}));
      setProfileMenuOpen(false); setUsername(''); setAuthType(''); setLoginPassword(''); setLoginError('');
      setAuthStatus('sign-in'); setView('home'); setSong(null);
    } catch (error) { showNotice(error.message || 'Could not sign out.'); }
  }

  async function updateAccountPassword(event) {
    event.preventDefault();
    if (passwordNew !== passwordConfirm) { showNotice('The new passwords do not match.'); return; }
    const payload = { current_password: passwordCurrent, new_password: passwordNew };
    try {
      if (authType === 'mainzware') {
        const response = await fetch('/api/v1/auth/password', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(result.error || `Request failed (${response.status})`);
      } else await request('/auth/password', send('POST', payload));
      setModal(''); setPasswordCurrent(''); setPasswordNew(''); setPasswordConfirm(''); showNotice('Password changed');
    } catch (error) { showNotice(error.message || 'Could not change password.'); }
  }

  function renderProfileMenu(menuRef) {
    return <div className="profile-menu" ref={menuRef}><button type="button" className="profile-menu-trigger" aria-label={`Profile menu for ${username}, ${roleLabel}`} aria-expanded={profileMenuOpen} aria-haspopup="menu" onClick={() => setProfileMenuOpen((open) => !open)} title={`${username} · ${roleLabel}`}><span className="profile-initial" aria-hidden="true">{usernameInitial}</span><span className="profile-menu-copy"><b>{username}</b><small>{roleLabel}</small></span></button>{profileMenuOpen && <div className="profile-menu-panel" role="menu"><div className="profile-menu-identity"><b>{username}</b><small>{roleLabel}</small></div><button type="button" role="menuitem" onClick={() => { setProfileMenuOpen(false); setModal('account-password'); }}><LockKeyhole size={15}/> Change password</button><button type="button" role="menuitem" onClick={() => { setProfileMenuOpen(false); setDarkMode((enabled) => !enabled); }}>{darkMode ? <Sun size={15}/> : <Moon size={15}/>} Turn {darkMode ? 'light' : 'dark'} mode on</button><button type="button" role="menuitem" className="profile-menu-logout" onClick={signOutLiveWorship}><LogOut size={15}/> Log out</button></div>}</div>;
  }

  if (authStatus !== 'ready') return <div className="auth-gate"><div className="auth-gate-mark"><Music2 size={21}/></div><h1>{loading ? 'Connecting to Live Worship' : authStatus === 'sign-in' ? 'Sign in to Live Worship' : authStatus === 'not-member' ? 'Ask a worship leader for access' : 'Live Worship is unavailable'}</h1><p>{loading ? 'Loading your song catalog and service plans.' : authStatus === 'sign-in' ? 'Use the Live Worship account your worship leader gave you, or continue with your MainzWare account.' : authStatus === 'not-member' ? `This account is not yet a member of ${brand}. Ask a worship leader to add it.` : connectionError || 'Could not load your Live Worship workspace.'}</p>{authStatus === 'sign-in' && <form className="live-worship-login" onSubmit={signInLiveWorship}><label className="field">Username<input autoComplete="username" value={loginUsername} onChange={(event) => setLoginUsername(event.target.value)} required/></label><label className="field">Password<input type="password" autoComplete="current-password" value={loginPassword} onChange={(event) => setLoginPassword(event.target.value)} required/></label>{loginError && <div className="login-error" role="alert">{loginError}</div>}<button className="button dark" disabled={loading}>Sign in</button><div className="login-divider"><span>OR</span></div><button className="button quiet" type="button" onClick={continueWithMainzWare} disabled={loading}>Continue with MainzWare</button><a href="/login?return=%2Flive-worship%2F">Sign in to MainzWare</a></form>}{authStatus === 'not-member' && <button className="button quiet" onClick={() => { setLoginError(''); setAuthStatus('sign-in'); }}>Use a different account</button>}{authStatus === 'unavailable' && <button className="button quiet" onClick={() => { setLoading(true); refreshApp().catch((error) => { setConnectionError(error.message || 'Could not load your Live Worship workspace.'); setAuthStatus(error.status === 401 ? 'sign-in' : error.status === 403 ? 'not-member' : 'unavailable'); }).finally(() => setLoading(false)); }}>Try again</button>}</div>;

  return <div className={`app-frame ${view === 'song' ? 'is-song-view' : ''}`}>
    <aside className="sidebar">
      <button className="brand-lockup" onClick={goHome} aria-label={`${brand} home`}>{brandLogo ? <img className="brand-logo" src={brandLogo} alt=""/> : <span className="brand-mark"><Music2 size={19}/></span>}<span><b>{brand}</b></span></button>
      <button className={`nav-link ${view === 'home' ? 'selected' : ''}`} onClick={goHome}><House size={18}/> Home</button>
      <button className={`nav-link ${view === 'catalog' ? 'selected' : ''}`} onClick={goCatalog}><Library size={18}/> Song catalog <span className="nav-count">{songs.length}</span></button>
      <button className={`nav-link ${['setlists', 'archive'].includes(view) ? 'selected' : ''}`} onClick={goSetlists}><Calendar size={18}/> Set lists <span className="nav-count">{activeSetlists.length}</span></button>
      <div className="sidebar-utilities">
        {role === 'leader' && <button type="button" className="nav-link sidebar-settings" aria-label="Team settings" title="Team settings" onClick={openSettings}><Settings size={18}/><span>Settings</span></button>}
        {renderProfileMenu(profileMenuRef)}
      </div>
      <a className="powered-by-link sidebar-powered-by" href="/" aria-label="Powered by MainzWare — visit homepage"><span>Powered by:</span><b>MainzWare</b></a>
    </aside>
    <main className="main-area">
      <header className="topbar"><div className="crumb"><span>{brand}</span><span className="crumb-slash">/</span><b>{view === 'home' ? 'Home' : view === 'catalog' ? 'Song catalog' : view === 'setlists' ? 'Set lists' : view === 'archive' ? 'Archive' : 'Song view'}</b></div><div className="top-actions"><span className="online-dot" title="Connected to Live Worship"/></div></header>
      <div className="content">
      {view === 'home' && <>
        <div className="page-heading home-heading"><div><div className="eyebrow">UPCOMING SERVICES</div><h1>Services</h1><p>{activeSetlists.length} planned</p></div></div>
        <section className="dashboard-services">
          <div className="upcoming-card">
            {homeServices.length ? homeServices.map((item) => {
              const date = new Date(item.serviceAt);
              return <button className="upcoming-service-row" key={item.id} onClick={() => viewSetlist(item)}>
                <span className="upcoming-date"><small>{date.toLocaleDateString([], { month: 'short' })}</small><b>{date.getDate()}</b><small>{date.toLocaleDateString([], { weekday: 'short' })}</small></span>
                <span className="upcoming-service-info"><b>{item.name}</b><small>{date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })} <i>·</i> {item.songs.length} {item.songs.length === 1 ? 'song' : 'songs'}</small></span>
                <span className={`upcoming-status ${item.current ? 'in-progress' : ''}`}>{item.current ? 'In progress' : 'Upcoming'}</span><ChevronRight size={19}/>
              </button>;
            }) : <div className="upcoming-empty"><b>No upcoming services</b><span>{role === 'leader' ? 'Create a set list for your next service.' : 'New services will appear here when they are planned.'}</span></div>}
            <div className="upcoming-card-footer"><button className="link-button" onClick={() => setView('setlists')}>See all services <ChevronRight size={16}/></button>{role === 'leader' && <button className="link-button" onClick={() => openSetlist()}><CirclePlus size={16}/> New</button>}</div>
          </div>
        </section>
        <section className="dashboard-tools">
          <div className="dashboard-section-head"><div><div className="eyebrow">WORSHIP WORKSPACE</div><h2>Tools for your team</h2></div></div>
          <div className="dashboard-tool-grid">
            <button className="dashboard-tool" onClick={goCatalog}><span className="dashboard-tool-icon catalog-tool"><ListMusic size={25}/></span><span className="dashboard-tool-copy"><b>Song catalog</b><small>Browse {songs.length} songs and charts</small></span><ChevronRight size={18}/></button>
            {role === 'leader' && <button className="dashboard-tool" onClick={newSong}><span className="dashboard-tool-icon add-song-tool"><CirclePlus size={25}/></span><span className="dashboard-tool-copy"><b>Add a song</b><small>Upload pages or paste a chart</small></span><ChevronRight size={18}/></button>}
            <button className="dashboard-tool" onClick={goSetlists}><span className="dashboard-tool-icon services-tool"><AudioLines size={25}/></span><span className="dashboard-tool-copy"><b>Set lists</b><small>{activeSetlists.length} planned service{activeSetlists.length === 1 ? '' : 's'}</small></span><ChevronRight size={18}/></button>
            {role === 'leader' && <button className="dashboard-tool" onClick={() => openSetlist()}><span className="dashboard-tool-icon plan-tool"><ListMusic size={24}/></span><span className="dashboard-tool-copy"><b>Create a set list</b><small>Plan an upcoming service</small></span><ChevronRight size={18}/></button>}
          </div>
        </section>
      </>}
      {view === 'catalog' && <>
        <div className="page-heading catalog-heading"><div><div className="eyebrow">SONG LIBRARY</div><h1>Your songs</h1><p>{songs.length} {songs.length === 1 ? 'song' : 'songs'} in the catalog</p></div>{role === 'leader' && <button className="button primary" onClick={newSong}><CirclePlus size={17}/> Add a song</button>}</div>
        <div className="catalog-tools"><label className="search"><Search size={17}/><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search songs"/></label></div>
        {filtered.length > 0 ? <div className="song-list">{filtered.map((item, index) => <article className="song-card" key={item.id}><button className={`song-art art-${index % 4}`} onClick={() => openSong(item)} aria-label={`Open ${item.title}`}><Music2 size={21}/></button><button className="song-info" onClick={() => openSong(item)}><b>{item.title}</b><span>{item.writer || 'Writer not listed'}</span></button><div className="song-detail"><span className="key-pill">{item.key ? `Key ${item.key}` : `${item.parts.length} parts`}</span></div><button className="row-arrow" onClick={() => openSong(item)} aria-label={`View ${item.title}`}><ChevronRight size={18}/></button></article>)}</div> : <div className="empty-card"><div className="empty-icon"><Music2 size={22}/></div><h3>{query ? 'No songs found' : 'Your song list is empty'}</h3><p>{query ? 'Try a different song title.' : 'Enter a song manually or import page photos to start your catalog.'}</p>{role === 'leader' && !query && <button className="button primary" onClick={newSong}><CirclePlus size={17}/> Add your first song</button>}</div>}
      </>}
      {view === 'setlists' && <><div className="page-heading setlists-heading"><div><div className="eyebrow">PLAN THE SERVICE</div><h1>Set lists</h1><p>Choose the songs everyone will follow during worship.</p></div><div className="setlist-heading-actions"><button className="button secondary" onClick={goArchive}><Archive size={16}/> Archive</button>{role === 'leader' && <button className="button dark" onClick={() => openSetlist()}><CirclePlus size={16}/> Create set list</button>}</div></div><div className="service-grid">{activeSetlists.length ? activeSetlists.map((item) => <ServiceCard key={item.id} item={item} songs={songs} onStart={() => startService(item)} onEdit={role === 'leader' ? () => openSetlist(item) : undefined}/>) : <div className="empty-card"><div className="empty-icon"><ListMusic/></div><h3>Nothing planned yet</h3><p>Create a set list for Sunday morning, Wednesday night, or any special service.</p>{role === 'leader' && <button className="button dark" onClick={() => openSetlist()}><CirclePlus size={16}/> Create your first set list</button>}</div>}</div></>}
      {view === 'archive' && <><button className="button quiet" onClick={goSetlists}><ChevronLeft size={16}/> Back to set lists</button><div className="page-heading"><div><div className="eyebrow">PAST SERVICES</div><h1>Archive</h1><p>Set lists are kept here after the service window ends.</p></div><span className="archive-count">{archivedSetlists.length} archived</span></div>{archivedSetlists.length ? <div className="service-grid">{archivedSetlists.map((item) => <ServiceCard key={item.id} item={item} songs={songs} archived />)}</div> : <div className="empty-card"><div className="empty-icon"><Archive/></div><h3>No archived set lists</h3><p>Completed services will appear here.</p></div>}</>}
      {view === 'song' && song && <>
        <button className="back-link" onClick={returnFromSong}><ChevronLeft size={16}/> {songReturnView === 'setlists' ? 'Back to set lists' : songReturnView === 'catalog' ? 'Back to catalog' : 'Back to services'}</button>
        <div className="song-view-head">
          <div><div className="eyebrow">NOW SINGING <span className="live-tag"><i/> LIVE</span></div><h1>{song.title}</h1><p>{song.writer || 'Writer not listed'} <span className="middot">·</span> Key <b>{song.key || '—'}</b></p></div>
          <div className="song-head-actions">
            {role === 'leader' && currentSetlist && currentSongIndex >= 0 && <div className="setlist-stepper"><button className="icon-button" onClick={() => selectSetlistSong(currentSongIndex - 1)} disabled={currentSongIndex === 0} aria-label="Previous set list song"><ChevronLeft size={16}/></button><span>{currentSongIndex + 1} of {currentSetlist.songs.length} · {currentSetlist.name}</span><button className="icon-button" onClick={() => selectSetlistSong(currentSongIndex + 1)} disabled={currentSongIndex >= currentSetlist.songs.length - 1} aria-label="Next set list song"><ChevronRight size={16}/></button></div>}
            {role === 'leader' && <button className="button quiet" onClick={() => editSong(song)}><Pencil size={15}/> Edit song</button>}
          </div>
        </div>
        <div className="song-workspace">
          <section className="lyrics-panel">
            <div className="lyrics-toolbar"><div><small>SONG PARTS</small><span>{role === 'leader' ? 'Tap a part to guide everyone' : 'Following the worship leader'}</span></div>{role !== 'leader' && <span className="following"><i/> Leader is guiding</span>}</div>
            <div className="part-tabs"><button className={`${showWholeSong ? 'current' : ''} ${role === 'leader' ? '' : 'follower'}`} onClick={() => role === 'leader' && selectPart(WHOLE_SONG)}>Whole song{showWholeSong && role !== 'leader' && <i/>}</button>{song.parts.map((part, i) => <button className={`${partId === part.id ? 'current' : ''} ${role === 'leader' ? '' : 'follower'}`} key={part.id} onClick={() => role === 'leader' && selectPart(part.id)}><span>{String(i + 1).padStart(2, '0')}</span>{part.name}{partId === part.id && role !== 'leader' && <i/>}</button>)}</div>
            <div className="lyric-sheet">
              <div className="sheet-meta"><b>{showWholeSong ? 'Whole song' : activePart?.name}</b><span>KEY {song.key || '—'}</span></div>
              {showWholeSong ? song.parts.map((part) => <section className="whole-song-part" key={part.id}><h3>{part.name}</h3>{mode === 'musician' ? <ChordLyrics part={part}/> : <ChoirLyrics text={part.lyrics}/>}</section>) : mode === 'musician' ? <ChordLyrics part={activePart}/> : <ChoirLyrics text={activePart?.lyrics}/>} 
              {role === 'leader' && <div className="leader-hint"><Sparkles size={15}/> Choose a part above to guide the choir and musicians.</div>}
            </div>
            <div className="lyrics-footer"><span>{mode === 'musician' ? 'Musician view · chords aligned to lyrics' : 'Choir view · lyrics'}</span>{role === 'leader' ? <div className="mode-toggle"><button className={mode === 'choir' ? 'selected' : ''} onClick={() => setMode('choir')}><Users size={14}/> Choir</button><button className={mode === 'musician' ? 'selected' : ''} onClick={() => setMode('musician')}><Guitar size={14}/> Musician</button></div> : <span className="role-display-note">{role === 'musician' ? 'Musician view' : 'Choir view'}</span>}</div>
          </section>
          <details className="source-panel source-details">
            <summary><span>Original paper chart</span><small>{song.pages?.length || 0} page{song.pages?.length === 1 ? '' : 's'}</small>{role === 'leader' && <span className="source-edit-hint">Edit photos</span>}</summary>
            {role === 'leader' && <button className="source-edit-button" onClick={(event) => { event.preventDefault(); editSong(song); }}><Pencil size={14}/> Edit song and photos</button>}
            {song.pages?.length ? <div className="source-pages">{song.pages.map((page, i) => <button type="button" className="source-page image-preview-trigger" key={page.id || `${page.name}-${i}`} onClick={() => setPreviewImage({ src: page.image, title: `Original chart page ${i + 1}` })} aria-label={`Enlarge original chart page ${i + 1}`}><img src={page.image} alt=""/><span>PAGE {i + 1}</span></button>)}</div> : <div className="source-empty"><ImagePlus size={21}/><span>No paper photos attached</span></div>}
            {role === 'leader' && <button className="delete-song" onClick={() => setModal('delete')}><Trash2 size={15}/> Remove song from catalog</button>}
          </details>
        </div>
      </>}
      </div>
      <footer className="main-footer"><span>MADE FOR WORSHIP, TOGETHER</span><a className="powered-by-link" href="/">Powered by: <b>MainzWare</b></a></footer>
    </main>
    {notice && <div className="toast">{notice}</div>}
    {previewImage && <div className="image-preview-scrim" onMouseDown={(event) => event.target === event.currentTarget && setPreviewImage(null)}><section className="image-preview-dialog" role="dialog" aria-modal="true" aria-label={previewImage.title}><button type="button" className="image-preview-close" onClick={() => setPreviewImage(null)} aria-label="Close image preview"><X size={20}/></button><img src={previewImage.src} alt={previewImage.title}/><span>{previewImage.title} · click outside or press Escape to close</span></section></div>}
    {modal === 'account-password' && <div className="modal-scrim" onMouseDown={(event) => event.target === event.currentTarget && setModal('')}><form className="modal-card account-password-modal" onSubmit={updateAccountPassword}><div className="modal-head"><div><div className="eyebrow">ACCOUNT SECURITY</div><h2>Change password</h2></div><button type="button" className="icon-button" onClick={() => setModal('')} aria-label="Close"><X size={18}/></button></div><label className="field">Current password<input type="password" autoComplete="current-password" value={passwordCurrent} onChange={(event) => setPasswordCurrent(event.target.value)} required/></label><label className="field">New password<input type="password" autoComplete="new-password" minLength="8" value={passwordNew} onChange={(event) => setPasswordNew(event.target.value)} required/><small>Use at least 8 characters.</small></label><label className="field">Confirm new password<input type="password" autoComplete="new-password" minLength="8" value={passwordConfirm} onChange={(event) => setPasswordConfirm(event.target.value)} required/></label><div className="modal-footer"><button type="button" className="button quiet" onClick={() => setModal('')}>Cancel</button><button className="button primary">Save password</button></div></form></div>}
    {modal === 'song' && draft && <div className="modal-scrim" onMouseDown={(event) => event.target === event.currentTarget && setModal('')}>
      <form className="modal-card song-modal" onSubmit={saveSong}>
        <div className="modal-head"><div><div className="eyebrow">{draft.id ? 'EDIT CATALOG ENTRY' : 'NEW CATALOG ENTRY'}</div><h2>{draft.id ? 'Edit song' : 'Add a song'}</h2></div><button type="button" className="icon-button" onClick={() => setModal('')} aria-label="Close"><X size={18}/></button></div>
        <button type="button" className="button quiet song-import-methods" onClick={() => setImportMethodsOpen((open) => !open)} aria-expanded={importMethodsOpen} aria-controls="song-import-method-options" disabled={ocrBusy}><ChevronDown size={16}/>Import Methods</button>
        <div id="song-import-method-options" hidden={!importMethodsOpen}>
          <div className="entry-methods">
            <button type="button" className="entry-method" onClick={() => chooseSongEntry('manual')}><span className="entry-method-icon"><Music2 size={20}/></span><b>Paste or enter lyrics</b><small>Paste lyrics or a chord chart, choose a text file, or type the song by hand.</small><span className="entry-method-action">Continue <ChevronRight size={15}/></span></button>
            <button type="button" className="entry-method" onClick={() => chooseSongEntry('photo')}><span className="entry-method-icon"><ImagePlus size={20}/></span><b>Use song photos</b><small>Choose photos of printed pages. We’ll read the lyrics so you can review them.</small><span className="entry-method-action">Choose photos <ChevronRight size={15}/></span></button>
          </div>
        </div>
        {songSource === 'photo' && <div className="song-steps"><span className={songStep === 'photos' ? 'active' : 'complete'}><b>1</b> Paper pages</span><i/><span className={songStep === 'review' ? 'active' : ''}><b>2</b> Review song</span></div>}
        {songStep === 'photos' ? <>
          <p className="intake-intro">Add the pages in reading order. We’ll capture the lyrics and any section labels for you to review.</p>
          {ocrBusy && <PhotoProcessing status={ocrStatus}/>}
          <label className={`upload-zone ${ocrBusy ? 'processing-upload' : ''}`}><input ref={filesRef} type="file" accept="image/*" multiple disabled={ocrBusy} onChange={addPages}/><span className="upload-circle"><Upload size={18}/></span><b>{ocrBusy ? 'Processing your photos…' : 'Choose song page photos'}</b><small>{ocrBusy ? 'Your lyrics will be ready to review here when processing finishes.' : 'Upload one page or several in order · JPG, PNG or HEIC'}</small><span className="button quiet">{ocrBusy ? 'Please wait…' : 'Browse photos'}</span></label>
          {draft.pages?.length > 0 && <div className="page-thumbs">{draft.pages.map((page, i) => <div className="page-thumb" key={`${page.id || page.name}-${i}`}><div className="page-thumb-top">{!draft.id && <div className="page-order-controls"><button type="button" onClick={() => movePage(i, -1)} disabled={i === 0 || ['reading', 'loading'].includes(ocrStatus) || ocrStatus.startsWith('recognizing')} aria-label={`Move page ${i + 1} earlier`}><ArrowUp size={12}/></button><button type="button" onClick={() => movePage(i, 1)} disabled={i === draft.pages.length - 1 || ['reading', 'loading'].includes(ocrStatus) || ocrStatus.startsWith('recognizing')} aria-label={`Move page ${i + 1} later`}><ArrowDown size={12}/></button></div>}<button type="button" onClick={() => removePage(i)} disabled={ocrBusy} aria-label={`Remove page ${i + 1}`}><X size={13}/></button></div><button type="button" className="page-image-preview image-preview-trigger" onClick={() => setPreviewImage({ src: page.image, title: `Song page ${i + 1}` })} aria-label={`Enlarge song page ${i + 1}`}><img src={page.image} alt=""/></button><span>Page {i + 1}</span></div>)}</div>}
          {!ocrBusy && <div className={`ocr-note ${ocrStatus === 'error' ? 'warning' : ''}`}><Sparkles size={16}/><span><b>{ocrStatus === 'idle' ? 'Turn your photos into lyrics' : ocrStatus === 'loading' ? 'Preparing your photos…' : ocrStatus.startsWith('recognizing') ? `Reading page ${ocrStatus.split('-')[1]} of ${ocrStatus.split('-')[2]}` : ocrStatus === 'done' ? 'Your lyrics are ready to review' : ocrStatus === 'error' ? 'Could not read these photos' : 'Reading photos'}</b><small>{ocrStatus === 'error' ? 'Try again, or continue and type or paste the lyrics yourself.' : 'We’ll read the lyrics and find parts like verses and choruses. Check for mistakes before saving. Chords can be added afterward.'}</small></span></div>}
          <div className="modal-footer"><button type="button" className="button quiet" onClick={() => setModal('')}>Cancel</button><button className="button primary" type="submit" disabled={ocrBusy}>{ocrBusy ? <>Reading photos… <span className="photo-processing-spinner" aria-hidden="true"/></> : <>Continue to review <ChevronRight size={16}/></>}</button></div>
        </> : <>
          <p className="intake-intro">{songEditorMode === 'chordpro' ? 'Edit the song in ChordPro. Put chords in brackets before the lyric word where they begin, and use section directives to organize the song.' : 'Review the lyrics and section names. Switch to ChordPro if you want to add or edit chords.'}</p>
          <div className="form-grid"><label className="field wide">Song title<input required value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} placeholder="Song title"/></label><label className="field">Writer<input value={draft.writer || ''} onChange={(event) => setDraft({ ...draft, writer: event.target.value })} placeholder="Writer, if known"/></label><label className="field">Key<input value={draft.key || ''} onChange={(event) => setDraft({ ...draft, key: event.target.value })} placeholder="e.g. G"/></label></div>
          <details className="paste-chart-panel" open><summary><span>Import lyrics or chord chart</span><small>Paste text, choose a file, or add song photos</small></summary><div className="paste-chart-body"><label className="field paste-chart-field">Paste lyrics or chord chart<textarea value={pastedChartText} onChange={(event) => setPastedChartText(event.target.value)} placeholder={'Paste lyrics here. Section names such as Verse 1 or Chorus will be organized automatically.'} rows="8" spellCheck="false"/></label><div className="paste-import-actions"><button className="button primary" type="button" onClick={applyPastedChart} disabled={!pastedChartText.trim()}>Save</button><span>Or</span><label className="button secondary chordpro-import">Choose a song file<input type="file" accept=".cho,.chordpro,.pro,.txt,text/plain" onChange={importSongTextFile}/></label></div><small className="paste-file-help">Text files and chord chart files are supported. For paper song pages, open Import Methods and choose “Use song photos”.</small></div></details>
          {songSource !== 'manual' && <div className="review-photo-row"><span>{draft.pages?.length ? `${draft.pages.length} paper page${draft.pages.length === 1 ? '' : 's'} attached` : 'No paper pages attached'}</span><button className="button quiet small" type="button" onClick={() => setSongStep('photos')}><ImagePlus size={15}/> Review photos</button></div>}
          <div className="ocr-note"><Sparkles size={16}/><span><b>{songEditorMode === 'chordpro' ? 'ChordPro song editor' : ocrStatus === 'done' ? 'Review lyrics and section labels' : 'Review song details'}</b><small>{songEditorMode === 'chordpro' ? 'ChordPro is an editing format; the catalog saves plain lyrics by named song section and keeps chord positions alongside them.' : 'Check the lyrics and section labels, then add the song title, writer, and key before saving.'}</small></span></div>
          <div className="song-editor-toolbar"><div className="editor-mode-toggle"><button type="button" className={songEditorMode === 'chordpro' ? 'selected' : ''} onClick={() => changeSongEditorMode('chordpro')}>ChordPro</button><button type="button" className={songEditorMode === 'sections' ? 'selected' : ''} onClick={() => changeSongEditorMode('sections')}>Song parts</button></div>{songEditorMode === 'sections' && <button className="button quiet small" type="button" onClick={addPart}><CirclePlus size={14}/> Add song part</button>}</div>
          {songEditorMode === 'chordpro' ? <label className="field chordpro-field">ChordPro<textarea value={chordProDraft} onChange={(event) => setChordProDraft(event.target.value)} placeholder={'Put chords in [brackets] just before the word where they begin.\nUse a section label to organize the song:\n\n{start_of_verse: Verse 1}\n[G]Amazing grace, how [D]sweet the sound\n{end_of_verse}\n\n{start_of_chorus: Chorus}\n[C]This is a chorus line\n{end_of_chorus}\n\nOther section types include chorus, bridge, pre_chorus, intro, and outro.'} rows="16" spellCheck="false"/></label> : <>
          <div className="modal-divider"><span>SONG PARTS</span></div>
          {draft.parts.map((part) => <div className="part-editor" key={part.id}>
            <div className="part-editor-head"><input value={part.name} onChange={(event) => updatePart(part.id, 'name', event.target.value)} aria-label="Part name" placeholder="Verse, chorus, bridge…"/><button type="button" onClick={() => removePart(part.id)} aria-label={`Remove ${part.name}`} disabled={draft.parts.length <= 1}><Trash2 size={14}/></button></div>
            <label className="field lyrics-field">Lyrics<textarea value={part.lyrics} onChange={(event) => updateLyrics(part.id, event.target.value)} placeholder="Enter or correct the lyrics for this part" rows="5"/></label>
            {part.chords && !(part.chord_marks || []).length && <div className="unplaced-chords"><b>Unplaced chord sequence</b><span>{part.chords}</span><small>Switch to ChordPro to position these chords over the lyrics.</small></div>}
            {(part.chord_marks || []).length > 0 && <div className="placed-chords"><b>Placed chords</b>{part.chord_marks.map((mark) => <button type="button" className="chord-chip" key={`${mark.at}-${mark.chord}`} onClick={() => removeChord(part, mark.at)} title={`Remove ${mark.chord}`}><span>{mark.chord}</span><X size={12}/></button>)}</div>}
          </div>)}
          </>}
          <div className="modal-footer"><button type="button" className="button quiet" onClick={() => songSource === 'manual' ? setModal('') : setSongStep('photos')}>{songSource !== 'manual' && <ChevronLeft size={16}/>}{songSource === 'manual' ? 'Cancel' : 'Back to photos'}</button><button className="button primary" type="submit">Save song</button></div>
        </>}
      </form>
    </div>}
    {modal === 'setlist-detail' && viewingSetlist && <div className="modal-scrim" onMouseDown={(event) => event.target === event.currentTarget && setModal('')}><div className="modal-card setlist-detail-modal"><div className="modal-head"><div><div className="eyebrow">SERVICE SET LIST</div><h2>{viewingSetlist.name}</h2><p className="setlist-detail-date">{new Date(viewingSetlist.serviceAt).toLocaleString([], { weekday: 'long', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' })}</p></div><button type="button" className="icon-button" onClick={() => setModal('')} aria-label="Close set list"><X size={18}/></button></div><div className="modal-divider"><span>SONGS</span><small>{viewingSetlist.songs.length} selected</small></div>{viewingSetlist.songs.length ? <div className="service-songs setlist-detail-songs">{viewingSetlist.songs.map((item, index) => <div key={item.id}><span>{String(index + 1).padStart(2, '0')}</span>{item.title}<small>KEY {item.key || '—'}</small></div>)}</div> : <div className="empty-song-options">No songs have been added to this set list yet.</div>}<div className="modal-footer">{role === 'leader' && <button type="button" className="button quiet" style={{ marginRight: 'auto' }} onClick={() => openSetlist(viewingSetlist)}><Pencil size={14}/> Edit set list</button>}{viewingSetlist.songs.length > 0 && <button type="button" className="button dark" onClick={() => { setModal(''); startService(viewingSetlist); }}><Play size={14}/> Open service</button>}</div></div></div>}
    {modal === 'setlist' && <div className="modal-scrim" onMouseDown={(event) => event.target === event.currentTarget && setModal('')}><form key={draft?.id || 'new-setlist'} className="modal-card" onSubmit={createSetlist}><div className="modal-head"><div><div className="eyebrow">WORSHIP PLANNING</div><h2>{draft?.id ? 'Edit set list' : 'Create a set list'}</h2></div><button type="button" className="icon-button" onClick={() => { setDraft(null); setModal(''); }}><X size={18}/></button></div><label className="field">Service name<input name="name" list="worship-service-names" defaultValue={draft?.name || 'Sunday a.m.'} required/><datalist id="worship-service-names"><option value="Sunday a.m."/><option value="Sunday p.m."/><option value="Wednesday p.m."/><option value="Revival service"/><option value="Impromptu service"/></datalist></label><label className="field service-date">When's the service?<input name="serviceAt" type="datetime-local" required defaultValue={draft?.serviceAt ? new Date(new Date(draft.serviceAt).getTime() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16) : new Date(Date.now() + 86400000 - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16)}/></label><div className="modal-divider"><span>CHOOSE SONGS</span><small>{selectedSetlistSongIds.length} selected · {songs.length} available</small></div>{songs.length > 0 && <label className="search select-song-search"><Search size={17}/><input type="search" aria-label="Search catalog songs" value={setlistSongQuery} onChange={(event) => setSetlistSongQuery(event.target.value)} placeholder="Search by title, writer, or key"/></label>}<div className="select-songs">{songs.length ? filteredSetlistSongs.length ? filteredSetlistSongs.map((item) => <label key={item.id}><input type="checkbox" name="songs" value={item.id} checked={selectedSetlistSongIds.includes(item.id)} onChange={(event) => setSelectedSetlistSongIds((current) => event.target.checked ? [...current, item.id] : current.filter((id) => id !== item.id))}/><span><b>{item.title}</b><small>{item.writer}</small></span><span className="key-pill"><span>KEY</span> {item.key}</span></label>) : <div className="empty-song-options">No songs match “{setlistSongQuery}”.</div> : <div className="empty-song-options">No songs in the catalog yet. Add songs first, then return here to choose them.</div>}</div><div className="archive-help"><Archive size={14}/> Automatically moves to archive 6 hours after service time.</div><div className="modal-footer">{draft?.id && <button type="button" className="button danger" style={{ marginRight: 'auto' }} onClick={() => setModal('confirm-delete-setlist')}><Trash2 size={14}/> Delete set list</button>}<button type="button" className="button quiet" onClick={() => { setDraft(null); setModal(''); }}>Cancel</button><button className="button dark">{draft?.id ? 'Save changes' : 'Create set list'}</button></div></form></div>}
    {modal === 'settings' && <div className="modal-scrim" onMouseDown={(event) => event.target === event.currentTarget && setModal('')}><div className="modal-card settings-modal"><div className="modal-head"><div><h2>Team settings</h2></div><button type="button" aria-label="Close" className="icon-button" onClick={() => setModal('')}><X size={18}/></button></div>{settingsLoading ? <p role="status">Loading team settings…</p> : settingsError ? <div role="alert"><p>Could not load team settings: {settingsError}</p><button type="button" className="button secondary" onClick={openSettings}>Try again</button></div> : <><form onSubmit={saveBrand}><label className="field">Team name<input name="name" defaultValue={brand} maxLength="80" required/></label><div className="modal-footer settings-save"><button className="button primary">Save name</button></div></form><section className="logo-settings"><div className="settings-section-title"><b>Team logo</b><span>Shown beside your team name</span></div><div className="logo-settings-row"><div className="logo-preview">{brandLogo ? <img src={brandLogo} alt="Current team logo"/> : <Music2 size={25}/>}</div><label className="button secondary logo-pick">{brandLogo ? 'Change logo' : 'Choose a logo'}<input type="file" accept="image/jpeg,image/png,image/webp" onChange={uploadLogo}/></label>{brandLogo && <button type="button" className="button text-button" onClick={removeLogo}>Remove</button>}</div></section><div className="modal-divider"><span>TEAM ACCESS</span><small>{members.filter((member) => member.active).length} people</small></div><p className="member-access-help">Create a Live Worship login for beta users, or add someone who already has a MainzWare account. Adding an existing Live Worship username again resets its password.</p><form className="member-add" onSubmit={addMember}><select value={memberAuthType} onChange={(event) => setMemberAuthType(event.target.value)} aria-label="Account type"><option value="live_worship">Live Worship login</option><option value="mainzware">MainzWare account</option></select><input value={memberUsername} onChange={(event) => setMemberUsername(event.target.value)} placeholder="Username" aria-label="New member username" required/>{memberAuthType === 'live_worship' && <input type="password" autoComplete="new-password" value={memberPassword} onChange={(event) => setMemberPassword(event.target.value)} placeholder="Initial password (8+ characters)" aria-label="Initial password" minLength="8" required/>}<select value={memberRole} onChange={(event) => setMemberRole(event.target.value)} aria-label="New member role"><option value="choir">Choir</option><option value="musician">Musician</option><option value="leader">Leader</option></select><button className="button primary"><CirclePlus size={15}/> Add person</button></form><div className="member-list">{members.filter((member) => member.active).map((member) => <div className="member-row" key={member.id}><div className="role-avatar small-avatar">{(member.username || '?').slice(0, 1).toUpperCase()}</div><b>{member.username}</b><small className="member-auth-type">{member.auth_type === 'live_worship' ? 'Live Worship login' : 'MainzWare account'}</small><select value={member.role} onChange={(event) => changeMemberRole(member, event.target.value)} aria-label={`Role for ${member.username}`}><option value="leader">Leader</option><option value="choir">Choir</option><option value="musician">Musician</option></select><button className="icon-button member-remove" onClick={() => removeMember(member)} title={`Remove ${member.username}`}><X size={14}/></button></div>)}</div><div className="storage-summary"><div className="storage-summary-head"><span>PHOTO STORAGE</span><b>{storage.total_megabytes} MB</b></div><div className="storage-meter"><i style={{ width: `${Math.min(100, storage.total_bytes / (1024 * 1024 * 1024) * 100)}%` }}/></div><small>{storage.page_count} song pages · {(storage.total_bytes / 1073741824).toFixed(3)} GB used</small></div></>}</div></div>}
    {modal === 'delete' && <div className="modal-scrim"><div className="modal-card confirm-modal"><div className="confirm-icon"><Trash2/></div><h2>Remove this song?</h2><p><b>{song?.title}</b> will be removed from the catalog and any set lists.</p><div className="modal-footer"><button className="button quiet" onClick={() => setModal('')}>Keep song</button><button className="button danger" onClick={() => { deleteSong(song.id); setModal(''); }}>Remove song</button></div></div></div>}
    {modal === 'confirm-delete-setlist' && <div className="modal-scrim"><div className="modal-card confirm-modal"><div className="confirm-icon"><Trash2/></div><h2>Delete this set list?</h2><p><b>{draft?.name}</b> and its selected songs will be removed.</p><div className="modal-footer"><button className="button quiet" onClick={() => setModal('setlist')}>Keep set list</button><button className="button danger" onClick={() => deleteSetlist(draft.id)}>Delete set list</button></div></div></div>}
  </div>;
}

function PhotoProcessing({ status }) {
  const [elapsed, setElapsed] = useState(0);
  useEffect(() => {
    const started = Date.now();
    const timer = window.setInterval(() => setElapsed(Math.floor((Date.now() - started) / 1000)), 1000);
    return () => window.clearInterval(timer);
  }, []);
  const [, page, total] = status.split('-');
  const recognizing = status.startsWith('recognizing');
  const duration = elapsed < 60 ? `${elapsed}s` : `${Math.floor(elapsed / 60)}m ${elapsed % 60}s`;
  return <div className="photo-processing">
    <div className="photo-processing-heading">
      <span className="photo-processing-spinner" aria-hidden="true"/>
      <div role="status" aria-live="polite"><b>{recognizing ? `Reading page ${page} of ${total}` : 'Preparing your photos…'}</b><small>{recognizing ? `${Number(page) - 1} of ${total} pages read` : 'Getting the images ready to read'}</small></div>
      <span className="photo-processing-time" aria-label={`Time elapsed: ${duration}`}>{duration} elapsed</span>
    </div>
    <div className="photo-processing-track" aria-hidden="true"><i/></div>
    <p>{elapsed >= 30 ? 'This is taking a little longer. We’re still waiting for the lyrics. Keep this window open; it will update when they’re ready.' : 'Reading photos can take a little time. Keep this window open and we’ll let you know when the lyrics are ready.'}</p>
  </div>;
}

function ChoirLyrics({ text }) {
  const lines = normalizeWhitespaceEntities(text || 'Lyrics have not been added for this part yet.').split('\n');
  const containerRef = useRef(null);
  const lineRefs = useRef([]);
  const [fontSizes, setFontSizes] = useState({});
  useLayoutEffect(() => {
    const fitLines = () => {
      const width = containerRef.current?.clientWidth || 0;
      if (!width) return;
      const next = {};
      lineRefs.current.forEach((line, index) => {
        if (!line || !line.textContent.trim()) return;
        line.style.fontSize = '27px';
        const measured = line.scrollWidth;
        next[index] = measured > width ? Math.max(14, Math.floor((27 * width / measured) * 10) / 10) : 27;
      });
      setFontSizes(next);
    };
    fitLines();
    const observer = new ResizeObserver(fitLines);
    if (containerRef.current) observer.observe(containerRef.current);
    return () => observer.disconnect();
  }, [lines.join('\n')]);
  return <div className="choir-lyrics" ref={containerRef}>{lines.map((line, index) => <div key={`${index}-${line}`} ref={(element) => { lineRefs.current[index] = element; }} style={fontSizes[index] ? { fontSize: `${fontSizes[index]}px` } : undefined}>{line || '\u00a0'}</div>)}</div>;
}

function ChordLyrics({ part }) {
  if (!part) return null;
  const lyrics = normalizeWhitespaceEntities(part.lyrics || 'Lyrics have not been added for this part yet.').split('\n');
  const marks = part.chord_marks || [];
  let offset = 0;
  return <div className="chord-score">
    {lyrics.map((line, index) => {
      const chars = Array.from(line);
      const lineMarks = marks.filter((mark) => mark.at >= offset && mark.at <= offset + chars.length).map((mark) => ({ ...mark, localAt: mark.at - offset }));
      const start = offset;
      offset += chars.length + 1;
      return <div className="aligned-line" key={`${index}-${line}`} style={{ '--lyric-columns': Math.max(chars.length, 1) }}>
        {lineMarks.map((mark) => <span className="aligned-chord" key={`${mark.at}-${mark.chord}`} style={{ gridColumnStart: mark.localAt + 1 }}>{mark.chord}</span>)}
        {chars.map((char, charIndex) => <span className="lyric-char" key={`${start + charIndex}-${char}`}>{char === ' ' ? '\u00a0' : char}</span>)}
        {!chars.length && <span className="lyric-char">{'\u00a0'}</span>}
      </div>;
    })}
    {!marks.length && <div className="chord-alignment-note">{part.chords ? 'These older chord notes have no saved lyric positions yet. A worship leader can place them in Edit song.' : 'No chords have been placed over these lyrics yet.'}</div>}
  </div>;
}

function parseChartText(text) {
  const lines = String(text || '').split(/\r?\n/).map((line) => line.replace(/\s+/g, ' ').replace(/^[|•·]+\s*/, '').replace(/[|~\\=]+\s*$/, '').trim()).filter(Boolean);
  const heading = /^\[?\s*(intro|verse\s*\d*|v\s*\d+|chorus|refrain|bridge|tag|ending|outro|pre[- ]?chorus)\s*\]?\s*:?$/i;
  const ignored = (line) => /^(description|difficulty|tuning|standard tuning|key|writer|words|music|lyrics|chords?|capo|written by|page)\b\s*[:\-]?/i.test(line)
    || /https?:\/\/|www\./i.test(line)
    || /ultimate\s*guitar/i.test(line)
    || /\bchords?\s+by\b/i.test(line)
    || isChordLine(line);
  const sections = [];
  const leadingLyrics = [];
  let current = null;
  let title = '';
  for (const line of lines) {
    const match = line.match(heading);
    if (match) {
      if (!current && leadingLyrics.length === 1) title = leadingLyrics[0];
      else if (!current && leadingLyrics.length) sections.push({ name: 'Verse 1', lyrics: leadingLyrics.splice(0) });
      leadingLyrics.length = 0;
      const name = match[1].replace(/^v\s*(\d+)$/i, 'Verse $1').replace(/\b\w/g, (letter) => letter.toUpperCase());
      current = { name, lyrics: [] };
      sections.push(current);
    } else if (!ignored(line)) {
      if (current) current.lyrics.push(line);
      else leadingLyrics.push(line);
    }
  }
  if (!sections.length) sections.push({ name: 'Verse 1', lyrics: leadingLyrics });
  else if (leadingLyrics.length) sections.unshift({ name: 'Verse 1', lyrics: leadingLyrics });
  // PaddleOCR can mistake decorative labels or page artifacts for section
  // headings. Do not turn those empty detections into blank editor cards.
  const populatedSections = sections.filter((section) => section.lyrics.some((line) => line.trim()));
  return {
    title,
    writer: '',
    key: '',
    parts: (populatedSections.length ? populatedSections : [{ name: 'Verse 1', lyrics: [] }]).map((section) => ({ id: crypto.randomUUID(), name: section.name, lyrics: section.lyrics.join('\n'), chords: '', chord_marks: [] })),
  };
}

function cleanOcrPage(recognizedLines) {
  return (Array.isArray(recognizedLines) ? recognizedLines : [])
    .map((line) => String(line?.text || '').replace(/[\u0000-\u001f\u007f]/g, ' ').replace(/\s+/g, ' ').trim())
    .filter((line) => line && !isChordLine(line) && !/^page\s+\d+(?:\s*\/\s*\d+)?$/i.test(line))
    .join('\n');
}

function isChordToken(value) {
  const token = String(value || '').replace(/^[^A-Za-z#♯b♭0-9]+|[^A-Za-z0-9#♯b♭/]+$/g, '').replace(/^\d+/, '');
  if (/^b[l1]$/i.test(token)) return true;
  return /^[A-G](?:#|♯|b|♭)?(?:(?:maj|min|m|sus|dim|aug|add)?\d*(?:sus\d+|add\d+|[#♯b♭]\d+)?)(?:\/[A-G](?:#|♯|b|♭)?)?$/i.test(token);
}

function isChordProToken(value) {
  const token = String(value || '').trim();
  return isChordToken(token)
    || /^N\.?C\.?$/i.test(token)
    || /^[A-G](?:#|♯|b|♭)?(?:(?:maj|min|m|sus|dim|aug|add|no|omit|alt)?\d*(?:sus\d+|add\d+)?(?:\([#♯b♭0-9,]+\))?(?:\/(?:[A-G](?:#|♯|b|♭)?\d*|\d+))?)$/i.test(token);
}

function isChordLine(line) {
  const tokens = line.trim().split(/\s+/).filter(Boolean);
  return tokens.length > 0 && tokens.length <= 16 && tokens.every(isChordToken);
}

function serializeChordPro(parts) {
  return (parts || []).map((part) => {
    const name = String(part.name || 'Verse 1').trim() || 'Verse 1';
    const lower = name.toLowerCase();
    const type = lower.startsWith('pre-chorus') || lower.startsWith('pre chorus') ? 'pre_chorus'
      : lower.startsWith('chorus') ? 'chorus'
        : lower.startsWith('bridge') ? 'bridge'
          : lower.startsWith('intro') ? 'intro'
            : lower.startsWith('outro') ? 'outro'
      : lower.startsWith('refrain') ? 'refrain'
        : lower.startsWith('tag') ? 'tag'
          : lower.startsWith('turn') ? 'turn'
            : lower.startsWith('interlude') ? 'interlude'
              : lower.startsWith('instrumental') ? 'instrumental'
              : lower.startsWith('ending') ? 'ending' : 'verse';
    const chars = Array.from(String(part.lyrics || ''));
    const marks = new Map();
    for (const mark of part.chord_marks || []) {
      if (!mark || !Number.isInteger(mark.at) || typeof mark.chord !== 'string' || !mark.chord.trim()) continue;
      const at = Math.max(0, Math.min(chars.length, mark.at));
      marks.set(at, [...(marks.get(at) || []), mark.chord.trim()]);
    }
    let lyrics = '';
    for (let at = 0; at <= chars.length; at += 1) {
      for (const chord of marks.get(at) || []) lyrics += `[${chord}]`;
      if (at < chars.length) lyrics += chars[at];
    }
    return `{start_of_${type}: ${name}}\n${lyrics}\n{end_of_${type}}`;
  }).join('\n\n');
}

function normalizeWhitespaceEntities(text) {
  return String(text || '')
    .replace(/&amp;(?=(?:nbsp|#(?:x0*a0|0*160|x0*20|0*32));)/gi, '&')
    .replace(/&(?:nbsp|#(?:x0*a0|0*160|x0*20|0*32));/gi, ' ');
}

function parseChordPro(text) {
  const metadata = { title: '', writer: '', key: '' };
  const sections = [];
  let current = null;
  let isChordPro = false;
  const createPart = (name) => {
    current = { id: crypto.randomUUID(), name: String(name || '').trim() || 'Verse 1', lyricLines: [], chord_marks: [] };
    sections.push(current);
    return current;
  };
  const sectionNames = { verse: 'Verse', chorus: 'Chorus', bridge: 'Bridge', pre_chorus: 'Pre-Chorus', intro: 'Intro', outro: 'Outro', refrain: 'Refrain', tag: 'Tag', turn: 'Turn', interlude: 'Interlude', instrumental: 'Instrumental', ending: 'Ending' };
  const addLyricLine = (part, line) => {
    const offset = part.lyricLines.reduce((size, lyric) => size + Array.from(lyric).length, 0) + part.lyricLines.length;
    const lyric = [];
    let cursor = 0;
    for (const match of line.matchAll(/\[([^\]]+)\]/g)) {
      const before = line.slice(cursor, match.index);
      lyric.push(before);
      const chord = match[1].trim();
      if (isChordProToken(chord)) part.chord_marks.push({ at: offset + lyric.reduce((size, chunk) => size + Array.from(chunk).length, 0), chord });
      else lyric.push(match[0]);
      cursor = match.index + match[0].length;
    }
    lyric.push(line.slice(cursor));
    part.lyricLines.push(lyric.join(''));
  };

  for (const rawLine of normalizeWhitespaceEntities(text).replace(/\r\n?/g, '\n').split('\n')) {
    const line = rawLine.trim();
    if (!line) { if (current) current.lyricLines.push(''); continue; }
    if (line.startsWith('#')) continue;
    const directiveMatch = line.match(/^\{\s*([^}]+)\s*\}$/);
    if (directiveMatch) {
      const directiveMatchParts = directiveMatch[1].match(/^([\w-]+)(?:\s*:\s*(.*))?$/);
      if (!directiveMatchParts) continue;
      const directive = directiveMatchParts[1].toLowerCase();
      const argument = (directiveMatchParts[2] || '').trim();
      const value = argument.replace(/^label\s*=\s*["']([^"']+)["']$/i, '$1').trim();
      if (['title', 't'].includes(directive)) { metadata.title = value; isChordPro = true; continue; }
      if (['artist', 'composer', 'lyricist', 'writer'].includes(directive)) { metadata.writer ||= value; isChordPro = true; continue; }
      if (directive === 'key') { metadata.key = value; isChordPro = true; continue; }
      if (['sov', 'soc', 'sob'].includes(directive)) {
        const type = directive === 'sov' ? 'verse' : directive === 'soc' ? 'chorus' : 'bridge';
        createPart(value || `${sectionNames[type]} ${sections.filter((part) => part.name.startsWith(sectionNames[type])).length + 1}`);
        isChordPro = true;
        continue;
      }
      if (['eov', 'eoc', 'eob'].includes(directive)) { current = null; isChordPro = true; continue; }
      const sectionMatch = directive.match(/^(start_of|end_of)_(verse|chorus|bridge|pre_chorus|intro|outro|refrain|tag|turn|interlude|instrumental|ending)$/);
      if (sectionMatch) {
        const [, action, type] = sectionMatch;
        if (action === 'end_of') current = null;
        else {
          const defaultName = sectionNames[type] || 'Verse';
          const fallbackName = `${defaultName} ${sections.filter((part) => part.name.startsWith(defaultName)).length + 1}`;
          createPart(value || fallbackName);
        }
        isChordPro = true;
        continue;
      }
      if (directive === 'chorus') {
        const chorus = [...sections].reverse().find((part) => part.name.toLowerCase().startsWith('chorus'));
        if (chorus) sections.push({ ...chorus, id: crypto.randomUUID(), name: value || chorus.name, lyricLines: [...chorus.lyricLines], chord_marks: chorus.chord_marks.map((mark) => ({ ...mark })) });
        current = null;
        isChordPro = true;
        continue;
      }
      if (/^(title|t|artist|composer|lyricist|writer|key|start_of_|end_of_|so[cbv]|eo[bcv])/.test(directive)) isChordPro = true;
      continue;
    }

    const heading = line.match(/^\[?\s*(intro|verse\s*\d*|v\s*\d+|chorus|refrain|bridge|tag|ending|outro|pre[-_ ]?chorus)\s*\]?\s*:?$/i);
    if (heading) {
      createPart(normalizePartName(heading[1].replace(/_/g, '-')));
      isChordPro = true;
      continue;
    }
    if (!current) createPart(`Verse ${sections.filter((part) => part.name.startsWith('Verse')).length + 1}`);
    const beforeMarks = current.chord_marks.length;
    addLyricLine(current, rawLine);
    if (current.chord_marks.length > beforeMarks) isChordPro = true;
  }

  const parts = sections.map((part) => ({
    id: part.id,
    name: part.name,
    lyrics: part.lyricLines.join('\n'),
    chords: '',
    chord_marks: part.chord_marks.sort((a, b) => a.at - b.at),
  }));
  return { ...metadata, parts, isChordPro };
}

function inlineSectionHeading(tokens, index) {
  const clean = (value) => String(value || '').replace(/\((?:\d+x|x\d+)\)$/i, '').replace(/^[\[({*_#]+|[\]})\:,*_#]+$/g, '');
  const first = clean(tokens[index]);
  const upper = first.toUpperCase();
  const titleCase = (value) => /^[A-Z][a-z]*(?:[-_][A-Z]?[a-z]*)*$/.test(value);
  const isHeadingCase = first === upper || titleCase(first);
  if (!isHeadingCase) return null;

  if (upper === 'PRE' && clean(tokens[index + 1])?.toUpperCase() === 'CHORUS') return { name: 'Pre-Chorus', consumed: 2 };
  if (/^PRE[-_]CHORUS$/.test(upper)) return { name: 'Pre-Chorus', consumed: 1 };
  const verse = upper.match(/^V(?:ERSE)?(\d+)$/) || (upper === 'VERSE' && /^\d+$/.test(clean(tokens[index + 1])) ? [null, clean(tokens[index + 1])] : null);
  if (verse) return { name: `Verse ${verse[1]}`, consumed: upper === 'VERSE' ? 2 : 1 };
  if (['VERSE', 'V'].includes(upper)) return { name: 'Verse', consumed: 1 };
  const simple = { CHORUS: 'Chorus', BRIDGE: 'Bridge', TURN: 'Turn', INTRO: 'Intro', OUTRO: 'Outro', TAG: 'Tag', REFRAIN: 'Refrain', ENDING: 'Ending', INTERLUDE: 'Interlude' };
  return simple[upper] ? { name: simple[upper], consumed: 1 } : null;
}

function parseInlineChordChart(text) {
  const source = normalizeWhitespaceEntities(text).trim();
  if (!source) return null;
  const sourceLines = source.split(/\r\n?/).map((line) => line.trim()).filter(Boolean);
  // Keep the line-aware parser for traditional charts with a separate chord row
  // above each lyric row; inline parsing is for chord names embedded in lyrics.
  if (sourceLines.length > 1 && sourceLines.some((line) => getPastedChordTokens(line))) return null;
  const tokens = source.match(/[^\s]+|\r?\n/g) || [];
  const headingCount = tokens.reduce((count, _token, index) => count + Number(Boolean(inlineSectionHeading(tokens, index))), 0);
  const chordCount = tokens.filter((token) => /^[A-G]/.test(token) && isChordProToken(token)).length;
  // A single labeled section is still useful input, and unlabeled inline
  // charts can be imported as a whole song when they contain several chords.
  if (!headingCount && chordCount < 3) return null;

  const parts = [];
  let current = null;
  let pendingChords = [];
  const flushUnplacedChords = () => {
    if (current && pendingChords.length && !current.lyrics) current.chords = pendingChords.join(' - ');
    pendingChords = [];
  };
  const createPart = (name) => {
    flushUnplacedChords();
    current = { id: crypto.randomUUID(), name: normalizePartName(name), lyrics: '', chords: '', chord_marks: [] };
    parts.push(current);
  };

  for (let index = 0; index < tokens.length; index += 1) {
    const token = tokens[index];
    if (/^\r?\n$/.test(token)) {
      if (current?.lyrics) current.lyrics += '\n';
      continue;
    }
    if (/^\\+$/.test(token)) continue;
    const heading = inlineSectionHeading(tokens, index);
    if (heading) {
      const attachedRepeat = String(token).match(/\(((?:\d+x|x\d+))\)$/i);
      const followingRepeat = String(tokens[index + heading.consumed] || '').match(/^\(?((?:\d+x|x\d+))\)?$/i);
      const repeat = attachedRepeat?.[1] || followingRepeat?.[1];
      createPart(repeat ? `${heading.name} (${repeat})` : heading.name);
      index += heading.consumed - 1;
      if (!attachedRepeat && followingRepeat) index += 1;
      continue;
    }
    if (/^(?:\(?\d+x\)?|x\d+)$/i.test(token.replace(/[),]/g, '')) || /^[-–—]+$/.test(token)) continue;
    if (!current) createPart(`Verse ${parts.filter((part) => part.name.startsWith('Verse')).length + 1}`);

    const slashBass = token.match(/^\/([A-G](?:#|b)?)$/);
    if (slashBass && pendingChords.length) {
      pendingChords[pendingChords.length - 1] += `/${slashBass[1]}`;
      continue;
    }
    if (/^[A-G]/.test(token) && isChordProToken(token)) {
      pendingChords.push(token.replace(/[,:;]+$/, ''));
      continue;
    }

    const prefix = current.lyrics && !current.lyrics.endsWith('\n') ? ' ' : '';
    const at = Array.from(current.lyrics + prefix).length;
    for (const chord of pendingChords) current.chord_marks.push({ at, chord });
    pendingChords = [];
    current.lyrics += prefix + token;
  }
  flushUnplacedChords();
  return { title: '', writer: '', key: '', parts };
}

function parsePastedChart(text) {
  text = normalizeWhitespaceEntities(text);
  const inlineChart = parseInlineChordChart(text);
  if (inlineChart) return inlineChart;
  const cleanLine = (line) => line.replace(/\t/g, '    ').replace(/\\+\s*$/, '').replace(/\*\*/g, '');
  const lines = text.replace(/\r\n?/g, '\n').split('\n').map(cleanLine);
  const textLines = lines.map((line) => line.trim()).filter(Boolean);
  const isSectionTitle = (line) => /^(intro|verse\s*\d*|v\s*\d+|chorus|refrain|bridge|tag|turn|interlude|instrumental|ending|outro|pre[- ]?chorus)\b/i.test(line);
  const titleLine = textLines.find((line) => /^(title|song)\s*[:\-]/i.test(line));
  const titleSource = titleLine || textLines.find((line) => !isSectionTitle(line) && !/^(key|writer|words|music|written by)\b/i.test(line) && !getPastedChordTokens(line));
  let title = titleLine ? titleLine.replace(/^(title|song)\s*[:\-]\s*/i, '').trim() : titleSource || '';
  let writer = textLines.find((line) => /^(words|music|writer|written by)\s*[:\-]/i.test(line))?.replace(/^(words|music|writer|written by)\s*[:\-]\s*/i, '').trim() || '';
  const key = textLines.find((line) => /\bkey\s*[:\-]?\s*[A-G][#b]?\s*(major|minor|maj|min|m)?\b/i.test(line))?.match(/\bkey\s*[:\-]?\s*([A-G][#b]?(?:\s*(?:major|minor|maj|min|m))?)/i)?.[1]?.replace(/\s+/g, ' ') || '';
  const byline = title.match(/^(.*?)\s+\(([^()]+)\)$/);
  if (byline) { title = byline[1].trim(); writer ||= byline[2].trim(); }

  const parts = [];
  const heading = /^\s*(intro|verse\s*\d*|v\s*\d+|chorus|refrain|bridge|tag|turn|interlude|instrumental|ending|outro|pre[- ]?chorus)\s*:\s*(.*)$/i;
  let current = null;
  const createPart = (name) => {
    current = { id: crypto.randomUUID(), name: normalizePartName(name), lyricLines: [], chord_marks: [], pendingChordRows: [], rawChordRows: [] };
    parts.push(current);
  };
  const storePendingWithoutLyrics = (part) => {
    if (part.pendingChordRows.length) {
      part.rawChordRows.push(...part.pendingChordRows.map((row) => row.text.trim()));
      part.pendingChordRows = [];
    }
  };
  const addLyrics = (part, line) => {
    const base = part.lyricLines.reduce((size, lyric) => size + Array.from(lyric).length, 0) + part.lyricLines.length;
    const lyricLength = Array.from(line).length;
    for (const row of part.pendingChordRows) {
      for (const token of row.tokens) {
        const at = base + Math.min(token.at, lyricLength);
        part.chord_marks = part.chord_marks.filter((mark) => mark.at !== at);
        part.chord_marks.push({ at, chord: token.chord });
      }
    }
    part.pendingChordRows = [];
    part.lyricLines.push(line);
  };

  let titleSkipped = false;
  for (const rawLine of lines) {
    const line = rawLine.replace(/\s+$/, '');
    const clean = line.trim();
    if (!clean || /^(title|song|key|writer|words|music|written by)\s*[:\-]/i.test(clean)) continue;
    if (!titleLine && !titleSkipped && clean === titleSource) { titleSkipped = true; continue; }
    const section = clean.match(heading);
    if (section) {
      if (current) storePendingWithoutLyrics(current);
      createPart(section[1]);
      const remainder = section[2];
      if (remainder) {
        const tokens = getPastedChordTokens(remainder, true);
        if (tokens) { current.pendingChordRows.push({ text: remainder, tokens }); }
        else addLyrics(current, remainder);
      }
      continue;
    }
    if (!current) createPart('Verse 1');
    const tokens = getPastedChordTokens(line);
    if (tokens) current.pendingChordRows.push({ text: line, tokens });
    else addLyrics(current, line);
  }
  for (const part of parts) storePendingWithoutLyrics(part);
  return {
    title,
    writer,
    key,
    parts: parts.map((part) => ({
      id: part.id,
      name: part.name,
      lyrics: part.lyricLines.join('\n'),
      chords: part.rawChordRows.join('\n'),
      chord_marks: part.chord_marks.sort((a, b) => a.at - b.at),
    })),
  };
}

function normalizePartName(name) {
  const normalized = name.replace(/^v\s*(\d+)$/i, 'Verse $1').replace(/^pre[- ]?chorus$/i, 'Pre-Chorus');
  return normalized.replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function getPastedChordTokens(line, allowSingle = false) {
  const pattern = /[A-G](?:#|b)?(?:(?:maj|min|sus|dim|aug|add|m)(?:\d+)?)?(?:\d*(?:sus\d*)?)?(?:\/[A-G](?:#|b)?)?/gi;
  const tokens = [];
  let cursor = 0;
  for (const match of line.matchAll(pattern)) {
    if (line.slice(cursor, match.index).trim()) return null;
    tokens.push({ at: Array.from(line.slice(0, match.index)).length, chord: match[0] });
    cursor = match.index + match[0].length;
  }
  if (line.slice(cursor).trim() || tokens.length < (allowSingle ? 1 : 2)) return null;
  return tokens;
}

async function jpegForUpload(file) {
  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, 2200 / Math.max(bitmap.width, bitmap.height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(bitmap.width * scale); canvas.height = Math.round(bitmap.height * scale);
  const context = canvas.getContext('2d');
  if (!context) throw new Error('This browser cannot prepare the page image.');
  context.drawImage(bitmap, 0, 0, canvas.width, canvas.height); bitmap.close();
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.88));
  if (!blob) throw new Error('This browser could not convert the page image to JPEG.');
  return new File([blob], file.name.replace(/\.[^.]+$/, '.jpg'), { type: 'image/jpeg' });
}

function ServiceCard({ item, songs, archived = false, onStart, onEdit }) {
  const songItems = item.songIds.map((id) => songs.find((song) => song.id === id)).filter(Boolean);
  return <article className="service-card"><div className="service-card-top"><span className="service-date-icon"><ListMusic size={18}/></span><span className={archived ? 'status-archived' : 'status-upcoming'}>{archived ? 'ARCHIVED' : item.current ? 'IN PROGRESS' : 'UPCOMING'}</span>{onEdit && <button className="icon-button" onClick={onEdit} disabled={archived}><Pencil size={15}/></button>}</div><h3>{item.name}</h3><p>{new Date(item.serviceAt).toLocaleString([], { weekday: 'long', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' })}</p><div className="service-songs">{songItems.length ? songItems.map((song, i) => <div key={song.id}><span>{String(i + 1).padStart(2, '0')}</span>{song.title}<small>KEY {song.key || '—'}</small></div>) : <span className="no-songs">No songs selected</span>}</div>{!archived && <div className="service-card-footer"><span>{songItems.length} songs <span>·</span> archives 6 hours after service</span><button className="button dark small" onClick={onStart}><Play size={14}/> Open service</button></div>}</article>;
}
