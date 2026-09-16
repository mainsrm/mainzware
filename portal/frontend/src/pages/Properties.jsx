import { useEffect, useRef, useState } from 'react';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TableSortLabel from '@mui/material/TableSortLabel';
import Paper from '@mui/material/Paper';
import Link from '@mui/material/Link';
import Button from '@mui/material/Button';
import Snackbar from '@mui/material/Snackbar';
import Typography from '@mui/material/Typography';
import Alert from '@mui/material/Alert';
import CircularProgress from '@mui/material/CircularProgress';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import TextField from '@mui/material/TextField';
import Fab from '@mui/material/Fab';
import AddIcon from '@mui/icons-material/Add';
import SaveIcon from '@mui/icons-material/Save';
import DeleteIcon from '@mui/icons-material/Delete';
import OpenInNewIcon from '@mui/icons-material/OpenInNew';
import { visuallyHidden } from '@mui/utils';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import Box from '@mui/material/Box';
import Collapse from '@mui/material/Collapse';
import Chip from '@mui/material/Chip';
import Select from '@mui/material/Select';
import MenuItem from '@mui/material/MenuItem';
import InputLabel from '@mui/material/InputLabel';
import FormControl from '@mui/material/FormControl';
import apiClient from '../api/client';
import { useAuth } from '../context/AuthContext';

function firstParcelToken(parcel) {
  return (parcel || '').trim().split(/\s+/)[0];
}

// The scraper records states as full names ("Indiana") while GIS sources are
// saved as USPS codes ("IN"), so both sides are normalized before matching.
const STATE_CODES = {
  alabama: 'al', alaska: 'ak', arizona: 'az', arkansas: 'ar', california: 'ca',
  colorado: 'co', connecticut: 'ct', delaware: 'de', 'district of columbia': 'dc',
  florida: 'fl', georgia: 'ga', hawaii: 'hi', idaho: 'id', illinois: 'il',
  indiana: 'in', iowa: 'ia', kansas: 'ks', kentucky: 'ky', louisiana: 'la',
  maine: 'me', maryland: 'md', massachusetts: 'ma', michigan: 'mi', minnesota: 'mn',
  mississippi: 'ms', missouri: 'mo', montana: 'mt', nebraska: 'ne', nevada: 'nv',
  'new hampshire': 'nh', 'new jersey': 'nj', 'new mexico': 'nm', 'new york': 'ny',
  'north carolina': 'nc', 'north dakota': 'nd', ohio: 'oh', oklahoma: 'ok',
  oregon: 'or', pennsylvania: 'pa', 'rhode island': 'ri', 'south carolina': 'sc',
  'south dakota': 'sd', tennessee: 'tn', texas: 'tx', utah: 'ut', vermont: 'vt',
  virginia: 'va', washington: 'wa', 'west virginia': 'wv', wisconsin: 'wi', wyoming: 'wy',
};

function normalizeState(state) {
  const value = (state || '').trim().toLowerCase();
  return STATE_CODES[value] || value;
}

function gisSourceKey(county, state) {
  return `${(county || '').trim().toLowerCase()}|${normalizeState(state)}`;
}

function parcelGisUrl(county, state, gisSources) {
  return gisSources[gisSourceKey(county, state)] || null;
}

export default function Properties() {
  const { user } = useAuth();
  const [properties, setProperties] = useState([]);
  const [status, setStatus] = useState('loading');
  const [snackbar, setSnackbar] = useState(null);
  const [listEditorOpen, setListEditorOpen] = useState(false);
  const [listName, setListName] = useState('');
  const [selectedIds, setSelectedIds] = useState(new Set());
  const [savingList, setSavingList] = useState(false);
  const [lists, setLists] = useState([]);
  const [activeListId, setActiveListId] = useState(null);
  const [activeListProperties, setActiveListProperties] = useState([]);
  const [countyFilter, setCountyFilter] = useState('all');
  const [sortField, setSortField] = useState('address');
  const [sortDirection, setSortDirection] = useState('asc');
  const [sources, setSources] = useState([]);
  const [sourceLabel, setSourceLabel] = useState('');
  const [sourceUrl, setSourceUrl] = useState('');
  const [sourceCounty, setSourceCounty] = useState('');
  const [sourceState, setSourceState] = useState('IN');
  const [sourceMessage, setSourceMessage] = useState(null);
  const [savingSources, setSavingSources] = useState(false);
  const [gisSources, setGisSources] = useState({});
  const [gisUrl, setGisUrl] = useState('');
  const [sourceManagerOpen, setSourceManagerOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiClient
      .get('/properties')
      .then((response) => {
        if (!cancelled) {
          setProperties(response.data);
          setStatus('ready');
        }
      })
      .catch(() => {
        if (!cancelled) setStatus('error');
      });
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    apiClient.get('/property-lists').then((response) => setLists(response.data)).catch(() => {});
  }, []);

  useEffect(() => {
    if (user?.role === 'admin') {
      apiClient.get('/scrape-sources').then((response) => setSources(response.data)).catch(() => {});
    }
    apiClient.get('/gis-sources').then((response) => {
      setGisSources(Object.fromEntries(response.data.map((source) => [
        gisSourceKey(source.county, source.state), source.url,
      ])));
    }).catch(() => {});
  }, [user]);

  // The SRI URL and label are derived from the county/state pair so the county
  // is only ever typed once; editing them directly stays possible under Advanced.
  useEffect(() => {
    const county = sourceCounty.trim();
    const state = sourceState.trim().toUpperCase();
    if (!county || !state) return;
    setSourceUrl(
      `https://sriservices.com/properties?saleId=1356&state=${encodeURIComponent(state)}&county=${encodeURIComponent(county)}&saleType=Tax%20Sale&timeFrame=All%20Future%20Sale%20Dates`,
    );
    setSourceLabel(`${county} County, ${state}`);
  }, [sourceCounty, sourceState]);

  const refreshSourcesAndProperties = async () => {
    const [sriResponse, gisResponse, propertiesResponse] = await Promise.all([
      apiClient.get('/scrape-sources'),
      apiClient.get('/gis-sources'),
      apiClient.get('/properties'),
    ]);
    setSources(sriResponse.data);
    setGisSources(Object.fromEntries(gisResponse.data.map((source) => [
      gisSourceKey(source.county, source.state), source.url,
    ])));
    setProperties(propertiesResponse.data);
  };

  const addSources = async (event) => {
    event.preventDefault();
    const county = sourceCounty.trim();
    const state = sourceState.trim().toUpperCase();
    const sriUrl = sourceUrl.trim();
    const gis = gisUrl.trim();

    if (!county || !state) {
      setSourceMessage({ severity: 'error', text: 'Enter a county and state.' });
      return;
    }
    if (!sriUrl) {
      setSourceMessage({ severity: 'error', text: 'No SRI URL was generated — add one under Advanced.' });
      return;
    }

    const payload = {
      sri: { label: sourceLabel.trim() || `${county} County, ${state}`, url: sriUrl, vendor: 'SRI' },
    };
    if (gis) payload.gis = { county, state, url: gis };

    setSavingSources(true);
    try {
      const response = await apiClient.post('/property-sources', payload);
      await refreshSourcesAndProperties();
      const scrapeStatus = response.data.scrape_queued
        ? 'Scrape queued — status updates below as it runs.'
        : 'No scrape was queued.';
      setSourceMessage({
        severity: 'success',
        text: `${county} County, ${state} saved${gis ? ' with its GIS lookup' : ''}. ${scrapeStatus}`,
      });
      setSourceLabel('');
      setSourceUrl('');
      setSourceCounty('');
      setGisUrl('');
    } catch (error) {
      setSourceMessage({ severity: 'error', text: error.response?.data?.error || 'Could not save sources.' });
    } finally {
      setSavingSources(false);
    }
  };

  const removeSource = async (source) => {
    if (!window.confirm(`Remove ${source.label}?`)) return;
    await apiClient.delete(`/scrape-sources/${source.id}`);
    await refreshSourcesAndProperties();
  };

  const scrapeInProgress = sources.some(
    (source) => source.scrape_state === 'queued' || source.scrape_state === 'running',
  );

  // While any source is queued/running, poll its status so the UI reflects progress.
  useEffect(() => {
    if (user?.role !== 'admin' || !scrapeInProgress) return undefined;
    const interval = setInterval(() => {
      apiClient.get('/scrape-sources').then((response) => setSources(response.data)).catch(() => {});
    }, 4000);
    return () => clearInterval(interval);
  }, [scrapeInProgress, user]);

  // When a scrape finishes, pull in any newly imported properties.
  const wasScrapingRef = useRef(false);
  useEffect(() => {
    if (wasScrapingRef.current && !scrapeInProgress) {
      apiClient.get('/properties').then((response) => setProperties(response.data)).catch(() => {});
    }
    wasScrapingRef.current = scrapeInProgress;
  }, [scrapeInProgress]);

  const toggleSelected = (propertyId) => {
    setSelectedIds((current) => {
      const next = new Set(current);
      if (next.has(propertyId)) next.delete(propertyId);
      else next.add(propertyId);
      return next;
    });
  };

  const saveList = async () => {
    if (!listName.trim()) {
      setSnackbar('Enter a name for this list first.');
      return;
    }
    if (selectedIds.size === 0) {
      setSnackbar('Select at least one property for this list.');
      return;
    }

    setSavingList(true);
    try {
      const savedIds = Array.from(selectedIds);
      const response = await apiClient.post('/property-lists', {
        name: listName.trim(),
        property_ids: savedIds,
      });
      const listsResponse = await apiClient.get('/property-lists');
      setLists(listsResponse.data);
      setListName('');
      setSelectedIds(new Set(savedIds));
      setActiveListId(response.data.id);
      const savedProperties = await apiClient.get(`/property-lists/${response.data.id}`);
      setActiveListProperties(savedProperties.data);
      setListEditorOpen(false);
      setSnackbar('Custom property list saved.');
    } catch (error) {
      setSnackbar(error.response?.data?.error || 'Could not save the property list.');
    } finally {
      setSavingList(false);
    }
  };

  const openList = async (list) => {
    setListEditorOpen(false);
    setListName(list.name);
    setSelectedIds(new Set(list.property_ids.map(Number)));
    setActiveListId(list.id);
    const response = await apiClient.get(`/property-lists/${list.id}`);
    setActiveListProperties(response.data);
    setSnackbar(`Opened ${list.name} — ${list.property_count} selected properties.`);
  };

  const showAllProperties = () => {
    setActiveListId(null);
    setListEditorOpen(false);
    setSelectedIds(new Set());
    setListName('');
    setActiveListProperties([]);
  };

  const toggleListEditor = () => {
    if (activeListId !== null) {
      setActiveListId(null);
      setSelectedIds(new Set());
      setListName('');
    }
    setListEditorOpen((open) => !open);
  };

  const deleteActiveList = async () => {
    if (activeListId === null || !window.confirm(`Delete list "${listName}"?`)) return;
    try {
      await apiClient.delete(`/property-lists/${activeListId}`);
      setLists((current) => current.filter((list) => list.id !== activeListId));
      showAllProperties();
      setSnackbar('Custom property list deleted.');
    } catch (error) {
      setSnackbar(error.response?.data?.error || 'Could not delete the property list.');
    }
  };

  const baseProperties = activeListId === null
    ? properties
    : activeListProperties;

  // Live example of the expected GIS URL shape, built from what's typed so far;
  // wthgis.com is a common vendor, not a guarantee for every county.
  const gisCountySlug = sourceCounty.trim().toLowerCase().replace(/[^a-z]/g, '');
  const gisStateSlug = sourceState.trim().toLowerCase().replace(/[^a-z]/g, '');
  const gisUrlPlaceholder = gisCountySlug && gisStateSlug
    ? `https://${gisCountySlug}${gisStateSlug}.wthgis.com/`
    : 'https://fayettein.wthgis.com/';

  const sourceCounties = sources
    .map((source) => source.label?.match(/^(.+?)\s+County(?:,|$)/i)?.[1]?.trim())
    .filter(Boolean);
  const counties = [...new Set([
    ...baseProperties.map((property) => property.county).filter(Boolean),
    ...sourceCounties,
  ])].sort();
  const visibleProperties = baseProperties
    .filter((property) => countyFilter === 'all' || property.county === countyFilter)
    .sort((left, right) => {
      const leftValue = String(left[sortField] || '').toLowerCase();
      const rightValue = String(right[sortField] || '').toLowerCase();
      const comparison = leftValue.localeCompare(rightValue, undefined, { numeric: true });
      return sortDirection === 'asc' ? comparison : -comparison;
    });

  const requestSort = (field) => {
    if (field === sortField) setSortDirection((direction) => direction === 'asc' ? 'desc' : 'asc');
    else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  const sortableHeader = (label, field) => (
    <TableCell sortDirection={sortField === field ? sortDirection : false}>
      <TableSortLabel active={sortField === field} aria-label={`Sort by ${label}${sortField === field ? `. Currently ${sortDirection}ending` : ''}`} direction={sortField === field ? sortDirection : 'asc'} onClick={() => requestSort(field)}>
        {label}
      </TableSortLabel>
    </TableCell>
  );

  const copyParcelForSafari = (parcel) => {
    const previouslyFocused = document.activeElement;
    const textArea = document.createElement('textarea');
    textArea.value = parcel;
    textArea.setAttribute('readonly', '');
    textArea.style.position = 'fixed';
    textArea.style.top = '0';
    textArea.style.left = '-9999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    textArea.setSelectionRange(0, textArea.value.length);
    let copied = false;
    try {
      copied = document.execCommand('copy');
    } catch {
      copied = false;
    }
    document.body.removeChild(textArea);
    previouslyFocused?.focus?.();
    return copied;
  };

  const lookupParcel = async (property) => {
    const parcel = firstParcelToken(property.parcel);
    const countyGisUrl = parcelGisUrl(property.county, property.state, gisSources);
    if (!parcel) {
      setSnackbar('No parcel number available for this property.');
      return;
    }

    const gisNote = countyGisUrl
      ? ' — paste it into the county GIS search box.'
      : `. No GIS site is mapped for ${property.county || 'this county'} yet — ${
        user?.role === 'admin' ? 'add one under Manage Counties.' : 'ask an admin to add one.'}`;

    // Start the copy while this document still has focus. Without the clipboard
    // API the fallback must also run before the GIS tab takes focus, since
    // execCommand('copy') needs a focused document.
    const copyRequest = navigator.clipboard?.writeText
      ? navigator.clipboard.writeText(parcel)
      : null;
    let copied = copyRequest !== null ? false : copyParcelForSafari(parcel);

    // Announce before opening the tab: once the GIS tab is in the foreground a
    // live-region update in this document is no longer announced.
    setSnackbar(copyRequest !== null || copied
      ? `Copied parcel ${parcel}${gisNote}`
      : `Parcel ${parcel} selected — use your browser's Copy command${gisNote}`);

    // window.open must stay in the click gesture; awaiting first spends the
    // user activation that popup blockers require, so the tab never opens.
    if (countyGisUrl) {
      // Open blank first so we can label the tab with the parcel while it's
      // still same-origin, then sever window.opener ourselves (the manual
      // equivalent of rel=noopener) before navigating it to the GIS site.
      // The title reverts to the GIS site's own once that page loads —
      // browsers don't let us control a cross-origin page's title.
      const gisTab = window.open('', '_blank');
      if (gisTab) {
        gisTab.opener = null;
        gisTab.document.title = `Parcel ${parcel} — ${property.county || 'County'} GIS`;
        gisTab.location.href = countyGisUrl;
      }
    }

    if (copyRequest !== null) {
      try {
        await copyRequest;
      } catch {
        copied = copyParcelForSafari(parcel);
        if (!copied) {
          setSnackbar(`Parcel ${parcel} could not be copied — use your browser's Copy command${gisNote}`);
        }
      }
    }
  };

  if (status === 'loading') return <CircularProgress aria-label="Loading properties" />;
  if (status === 'error') return <Alert severity="error">Could not load properties from the API.</Alert>;

  return (
    <>
      <Typography variant="h4" component="h2" gutterBottom>
        Property Sales
      </Typography>
      {user?.role === 'admin' && (
        <>
          <Button
            variant="outlined"
            onClick={() => setSourceManagerOpen((open) => !open)}
            aria-expanded={sourceManagerOpen}
            aria-controls="county-sources-panel"
            sx={{ mb: 1 }}
          >
            {sourceManagerOpen ? 'Hide County Sources' : 'Manage Counties'}
          </Button>
          <Collapse in={sourceManagerOpen}>
            <Box id="county-sources-panel" sx={{ mb: 3, p: 2, border: 1, borderColor: 'divider', borderRadius: 1 }}>
              <Typography variant="h6" component="h3" gutterBottom>County Sources</Typography>
              {sourceMessage && <Alert severity={sourceMessage.severity} sx={{ mb: 2 }}>{sourceMessage.text}</Alert>}
              <Box component="form" onSubmit={addSources}>
                <Stack direction="row" flexWrap="wrap" spacing={1} useFlexGap alignItems="flex-start">
                  <TextField
                    size="small"
                    required
                    label="County"
                    value={sourceCounty}
                    onChange={(event) => setSourceCounty(event.target.value)}
                    disabled={savingSources}
                    helperText="Used for both the sale list and the GIS lookup"
                  />
                  <TextField size="small" required label="State" value={sourceState} onChange={(event) => setSourceState(event.target.value)} sx={{ width: 90 }} disabled={savingSources} />
                  <TextField
                    size="small"
                    label="GIS URL (optional)"
                    value={gisUrl}
                    onChange={(event) => setGisUrl(event.target.value)}
                    placeholder={gisUrlPlaceholder}
                    sx={{ minWidth: { xs: '100%', sm: 280 } }}
                    disabled={savingSources}
                    helperText={
                      gisCountySlug && gisStateSlug ? (
                        <>
                          Try:{' '}
                          <Link
                            component="button"
                            type="button"
                            onClick={() => setGisUrl(gisUrlPlaceholder)}
                            disabled={savingSources}
                            aria-label={`Use ${gisUrlPlaceholder} as the GIS URL`}
                            sx={{ font: 'inherit', verticalAlign: 'baseline' }}
                          >
                            {gisUrlPlaceholder}
                          </Link>
                        </>
                      ) : 'Enables Lookup Parcel for this county'
                    }
                  />
                  <Button type="submit" variant="contained" disabled={savingSources} sx={{ mt: 0.25 }}>
                    {savingSources ? 'Saving…' : 'Save County'}
                  </Button>
                </Stack>
                <Button
                  type="button"
                  size="small"
                  onClick={() => setAdvancedOpen((open) => !open)}
                  aria-expanded={advancedOpen}
                  aria-controls="county-advanced-fields"
                  sx={{ mt: 1 }}
                >
                  {advancedOpen ? 'Hide advanced' : 'Advanced'}
                </Button>
                <Collapse in={advancedOpen}>
                  <Box id="county-advanced-fields" sx={{ mt: 1 }}>
                    <Typography variant="body2" color="text.secondary" gutterBottom>
                      Generated from the county and state above. Edit only if this county&apos;s sale list lives elsewhere.
                    </Typography>
                    <TextField fullWidth size="small" label="Label" value={sourceLabel} onChange={(event) => setSourceLabel(event.target.value)} disabled={savingSources} />
                    <TextField fullWidth size="small" label="Complete SRI URL" value={sourceUrl} onChange={(event) => setSourceUrl(event.target.value)} sx={{ mt: 1 }} disabled={savingSources} />
                  </Box>
                </Collapse>
              </Box>
              <Stack spacing={0.5} sx={{ mt: 2 }} role="status" aria-live="polite">
                {sources.map((source) => (
                  <Stack key={source.id} direction={{ xs: 'column', sm: 'row' }} spacing={1} alignItems={{ xs: 'stretch', sm: 'center' }}>
                    <Typography variant="body2" sx={{ minWidth: 0, flex: '1 1 10rem', overflowWrap: 'anywhere' }}>{source.label}</Typography>
                    <Box sx={{ minWidth: 0, flex: '1 1 10rem', display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 0.5 }}>
                      {source.scrape_state === 'queued' && <Chip size="small" variant="outlined" label="Queued" />}
                      {source.scrape_state === 'running' && (
                        <Chip size="small" variant="outlined" color="info" icon={<CircularProgress size={14} aria-hidden="true" />} label="Scraping…" />
                      )}
                      {source.scrape_state === 'error' && <Chip size="small" variant="outlined" color="error" label="Scrape failed" />}
                      {source.scrape_state !== 'queued' && source.scrape_state !== 'running' && (
                        <Typography variant="caption" color="text.secondary" sx={{ overflowWrap: 'anywhere' }}>
                          {source.last_scraped_at ? `Last refresh: ${new Date(source.last_scraped_at).toLocaleString()}` : 'Not scraped yet'}
                        </Typography>
                      )}
                    </Box>
                    <Button size="small" color="error" onClick={() => removeSource(source)}>Remove</Button>
                  </Stack>
                ))}
              </Stack>
            </Box>
          </Collapse>
        </>
      )}
      <Stack direction="row" flexWrap="wrap" spacing={1} useFlexGap sx={{ mb: 2 }}>
        <FormControl size="small" sx={{ minWidth: 190 }}>
          <InputLabel id="county-filter-label">County</InputLabel>
          <Select
            labelId="county-filter-label"
            value={countyFilter}
            label="County"
            onChange={(event) => setCountyFilter(event.target.value)}
          >
            <MenuItem value="all">All counties</MenuItem>
            {counties.map((county) => <MenuItem key={county} value={county}>{county}</MenuItem>)}
          </Select>
        </FormControl>
        <FormControl size="small" sx={{ minWidth: 190 }}>
          <InputLabel id="property-sort-label">Sort by</InputLabel>
          <Select
            labelId="property-sort-label"
            value={sortField}
            label="Sort by"
            onChange={(event) => setSortField(event.target.value)}
          >
            <MenuItem value="address">Address</MenuItem>
            <MenuItem value="county">County</MenuItem>
            <MenuItem value="state">State</MenuItem>
            <MenuItem value="sale_status">Status</MenuItem>
            <MenuItem value="sale_group">Sale group</MenuItem>
          </Select>
        </FormControl>
        <Button
          variant="outlined"
          onClick={() => setSortDirection((direction) => direction === 'asc' ? 'desc' : 'asc')}
          aria-label={`Sort ${sortDirection === 'asc' ? 'descending' : 'ascending'}`}
        >
          {sortDirection === 'asc' ? 'A to Z' : 'Z to A'}
        </Button>
      </Stack>
      <Stack direction="row" spacing={2} alignItems="center" sx={{ mb: 2 }}>
        <Button
          variant={listEditorOpen ? 'outlined' : 'contained'}
          startIcon={<AddIcon />}
          onClick={toggleListEditor}
        >
          {listEditorOpen ? 'Close Custom List' : 'Save Custom List'}
        </Button>
        {activeListId !== null && (
          <>
            <Button variant="text" onClick={showAllProperties}>Show All Properties</Button>
            <Button color="error" startIcon={<DeleteIcon />} onClick={deleteActiveList}>Delete List</Button>
          </>
        )}
        {lists.length > 0 && (
          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
            {lists.map((list) => (
              <Chip
                key={list.id}
                label={`${list.name} (${list.property_count})`}
                onClick={() => openList(list)}
                clickable
                color={list.name === listName ? 'primary' : 'default'}
              />
            ))}
          </Stack>
        )}
      </Stack>
      <Collapse in={listEditorOpen}>
        <Box sx={{ mb: 2, p: 2, border: 1, borderColor: 'divider', borderRadius: 1 }}>
          <TextField
            label="List name"
            value={listName}
            onChange={(event) => setListName(event.target.value)}
            placeholder="Fayette properties to review"
            required
            fullWidth
            sx={{ maxWidth: 520 }}
          />
          <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
            Select properties below, then use the floating Save List button.
            Selected: {selectedIds.size}
          </Typography>
        </Box>
      </Collapse>
      {properties.length === 0 ? (
        <Typography>
          No properties scraped yet. Run Sale Address Mapper with the <code>--db</code> flag to import some.
        </Typography>
      ) : (
        <TableContainer component={Paper}>
          <Table stickyHeader aria-label="Property sales">
            <TableHead sx={{
              '& .MuiTableCell-head': { backgroundColor: '#2f6f8f', color: '#ffffff', fontWeight: 700, whiteSpace: 'nowrap' },
              '& .MuiTableSortLabel-icon': { color: 'inherit', opacity: 1 },
              '& .MuiTableSortLabel-root.Mui-focusVisible': { outline: '3px solid #ffffff', outlineOffset: '2px' },
            }}>
              <TableRow>
                {listEditorOpen && activeListId === null && <TableCell>Select</TableCell>}
                {sortableHeader('Address', 'address')}
                {sortableHeader('County / State', 'county')}
                {sortableHeader('Status', 'sale_status')}
                {sortableHeader('Sale Group', 'sale_group')}
                {activeListId !== null && sortableHeader('Listing Status', 'archived')}
                {sortableHeader('Parcel #', 'parcel')}
                <TableCell>Map</TableCell>
                <TableCell>Parcel Lookup</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {visibleProperties.map((property) => {
                const countyGisUrl = parcelGisUrl(property.county, property.state, gisSources);
                return (
                  <TableRow key={property.id}>
                    {listEditorOpen && activeListId === null && (
                      <TableCell padding="checkbox">
                        <FormControlLabel
                          label=""
                          control={(
                            <Checkbox
                              checked={selectedIds.has(property.id)}
                              onChange={() => toggleSelected(property.id)}
                              inputProps={{ 'aria-label': `Select ${property.address}` }}
                            />
                          )}
                        />
                      </TableCell>
                    )}
                    <TableCell>{property.address}</TableCell>
                    <TableCell>
                      {[property.county, property.state].filter(Boolean).join(', ') || '—'}
                    </TableCell>
                    <TableCell>{property.sale_status || '—'}</TableCell>
                    <TableCell>{property.sale_group || '—'}</TableCell>
                    {activeListId !== null && (
                      <TableCell>
                        {property.archived ? (
                          <Chip label="No longer on sale site" color="warning" size="small" />
                        ) : (
                          <Chip label="Active" color="success" size="small" />
                        )}
                      </TableCell>
                    )}
                    <TableCell>{property.parcel || '—'}</TableCell>
                    <TableCell>
                      <Link href={property.map_url} target="_blank" rel="noopener noreferrer">
                        View on Map
                        <OpenInNewIcon fontSize="inherit" aria-hidden="true" sx={{ ml: 0.5, verticalAlign: 'middle' }} />
                        <Box component="span" sx={visuallyHidden}> (opens in a new tab)</Box>
                      </Link>
                    </TableCell>
                    <TableCell>
                      {firstParcelToken(property.parcel) ? (
                        <Button
                          size="small"
                          onClick={() => lookupParcel(property)}
                          aria-label={
                            countyGisUrl
                              ? `Look up parcel ${firstParcelToken(property.parcel)} for ${property.address}: copies the parcel number and opens the county GIS site in a new tab`
                              : `Copy parcel ${firstParcelToken(property.parcel)} for ${property.address} (no GIS site mapped for this county yet)`
                          }
                        >
                          Lookup Parcel
                          {countyGisUrl && (
                            <OpenInNewIcon fontSize="inherit" aria-hidden="true" sx={{ ml: 0.5 }} />
                          )}
                        </Button>
                      ) : (
                        '—'
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </TableContainer>
      )}
      {listEditorOpen && (
        <Fab
          color="primary"
          variant="extended"
          onClick={saveList}
          disabled={savingList}
          sx={{ position: 'fixed', right: { xs: 16, sm: 32 }, bottom: 24, zIndex: 1200 }}
        >
          <SaveIcon sx={{ mr: 1 }} />
          {savingList ? 'Saving…' : `Save List (${selectedIds.size})`}
        </Fab>
      )}
      <Snackbar
        open={snackbar !== null}
        autoHideDuration={12000}
        onClose={() => setSnackbar(null)}
        message={snackbar || ''}
        action={
          <Button color="inherit" size="small" onClick={() => setSnackbar(null)}>
            Dismiss
          </Button>
        }
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      />
    </>
  );
}
