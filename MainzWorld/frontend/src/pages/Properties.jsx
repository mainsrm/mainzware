import { useEffect, useState } from 'react';
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

function parcelGisUrl(county, state, gisSources) {
  const key = `${(county || '').toLowerCase()}|${(state || '').toLowerCase()}`;
  return gisSources[key] || null;
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
  const [gisSources, setGisSources] = useState({});
  const [gisCounty, setGisCounty] = useState('');
  const [gisState, setGisState] = useState('IN');
  const [gisUrl, setGisUrl] = useState('');
  const [sourceManagerOpen, setSourceManagerOpen] = useState(false);

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
        `${source.county.toLowerCase()}|${source.state.toLowerCase()}`, source.url,
      ])));
    }).catch(() => {});
  }, [user]);

  const generateSriUrl = () => {
    const county = sourceCounty.trim();
    const state = sourceState.trim().toUpperCase();
    if (!county || !state) return;
    setSourceUrl(
      `https://sriservices.com/properties?saleId=1356&state=${encodeURIComponent(state)}&county=${encodeURIComponent(county)}&saleType=Tax%20Sale&timeFrame=All%20Future%20Sale%20Dates`,
    );
    if (!sourceLabel) setSourceLabel(`${county} County, ${state}`);
  };

  const addSource = async (event) => {
    event.preventDefault();
    try {
      await apiClient.post('/scrape-sources', { label: sourceLabel, url: sourceUrl, vendor: 'SRI' });
      const response = await apiClient.get('/scrape-sources');
      setSources(response.data);
      setSourceMessage({ severity: 'success', text: 'SRI source added. It will refresh once per day.' });
      setSourceLabel('');
      setSourceUrl('');
      setSourceCounty('');
    } catch (error) {
      setSourceMessage({ severity: 'error', text: error.response?.data?.error || 'Could not add source.' });
    }
  };

  const removeSource = async (source) => {
    if (!window.confirm(`Remove ${source.label}?`)) return;
    await apiClient.delete(`/scrape-sources/${source.id}`);
    setSources((current) => current.filter((item) => item.id !== source.id));
  };

  const addGisSource = async (event) => {
    event.preventDefault();
    try {
      await apiClient.post('/gis-sources', { county: gisCounty, state: gisState, url: gisUrl });
      const response = await apiClient.get('/gis-sources');
      setGisSources(Object.fromEntries(response.data.map((source) => [
        `${source.county.toLowerCase()}|${source.state.toLowerCase()}`, source.url,
      ])));
      setSourceMessage({ severity: 'success', text: 'GIS lookup mapping saved.' });
      setGisCounty('');
      setGisUrl('');
    } catch (error) {
      setSourceMessage({ severity: 'error', text: error.response?.data?.error || 'Could not save GIS mapping.' });
    }
  };

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

  const counties = [...new Set(baseProperties.map((property) => property.county).filter(Boolean))].sort();
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
    return copied;
  };

  const lookupParcel = async (property) => {
    const parcel = firstParcelToken(property.parcel);
    const gisUrl = parcelGisUrl(property.county, property.state, gisSources);
    // Copy first while this document still has focus, then open the GIS tab.
    let copied = false;
    if (parcel && navigator.clipboard?.writeText) {
      try {
        await navigator.clipboard.writeText(parcel);
        copied = true;
      } catch {
        copied = copyParcelForSafari(parcel);
      }
    } else if (parcel) {
      copied = copyParcelForSafari(parcel);
    }
    setSnackbar(
      parcel
        ? copied
          ? `Copied parcel ${parcel} — paste it into the county GIS search box.`
          : `Parcel ${parcel} selected — use Copy from the Safari menu if needed.`
        : 'No parcel number available for this property.',
    );
    if (gisUrl) window.open(gisUrl, '_blank', 'noopener');
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
            sx={{ mb: 1 }}
          >
            {sourceManagerOpen ? 'Hide County Sources' : 'Manage Counties'}
          </Button>
          <Collapse in={sourceManagerOpen}>
            <Box sx={{ mb: 3, p: 2, border: 1, borderColor: 'divider', borderRadius: 1 }}>
              <Typography variant="h6" component="h3" gutterBottom>SRI County Sources</Typography>
              {sourceMessage && <Alert severity={sourceMessage.severity} sx={{ mb: 2 }}>{sourceMessage.text}</Alert>}
              <Box component="form" onSubmit={addSource}>
                <Stack direction="row" flexWrap="wrap" spacing={1} useFlexGap alignItems="center">
                  <TextField size="small" label="County" value={sourceCounty} onChange={(event) => setSourceCounty(event.target.value)} />
                  <TextField size="small" label="State" value={sourceState} onChange={(event) => setSourceState(event.target.value)} sx={{ width: 90 }} />
                  <Button type="button" variant="outlined" onClick={generateSriUrl}>Generate SRI URL</Button>
                  <TextField size="small" label="Label" value={sourceLabel} onChange={(event) => setSourceLabel(event.target.value)} required sx={{ minWidth: 220 }} />
                  <Button type="submit" variant="contained" disabled={!sourceUrl}>Add Source</Button>
                </Stack>
                <TextField fullWidth size="small" label="Complete SRI URL (paste or generate)" value={sourceUrl} onChange={(event) => setSourceUrl(event.target.value)} required sx={{ mt: 1 }} />
              </Box>
              <Typography variant="h6" component="h3" gutterBottom sx={{ mt: 3 }}>
                GIS Lookup Sources
              </Typography>
              <Box component="form" onSubmit={addGisSource}>
                <Stack direction="row" flexWrap="wrap" spacing={1} useFlexGap alignItems="center">
                  <TextField size="small" label="County" value={gisCounty} onChange={(event) => setGisCounty(event.target.value)} required />
                  <TextField size="small" label="State" value={gisState} onChange={(event) => setGisState(event.target.value)} sx={{ width: 90 }} required />
                  <TextField size="small" label="GIS URL" value={gisUrl} onChange={(event) => setGisUrl(event.target.value)} required sx={{ minWidth: 280 }} />
                  <Button type="submit" variant="contained">Save GIS Source</Button>
                </Stack>
              </Box>
              <Stack spacing={0.5} sx={{ mt: 2 }}>
                {sources.map((source) => (
                  <Stack key={source.id} direction="row" spacing={1} alignItems="center">
                    <Typography variant="body2" sx={{ flexGrow: 1 }}>{source.label}</Typography>
                    <Typography variant="caption" color="text.secondary">
                      {source.last_scraped_at ? `Last refresh: ${new Date(source.last_scraped_at).toLocaleString()}` : 'Not scraped yet'}
                    </Typography>
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
                const gisUrl = parcelGisUrl(property.county, property.state, gisSources);
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
                      </Link>
                    </TableCell>
                    <TableCell>
                      {property.parcel ? (
                        <Button
                          size="small"
                          onClick={() => lookupParcel(property)}
                          title={
                            gisUrl
                              ? 'Copies the parcel number, then opens the county GIS site to paste into its search box'
                              : 'Copies the parcel number (no GIS site mapped for this county yet)'
                          }
                        >
                          Lookup Parcel
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
        autoHideDuration={4000}
        onClose={() => setSnackbar(null)}
        message={snackbar || ''}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      />
    </>
  );
}
