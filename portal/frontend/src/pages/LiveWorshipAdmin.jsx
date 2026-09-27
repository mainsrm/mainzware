import { useCallback, useEffect, useState } from 'react';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import CircularProgress from '@mui/material/CircularProgress';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Divider from '@mui/material/Divider';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import apiClient from '../api/client';

const ADMIN_BASE = '/live-worship/admin';

function errorMessage(error, fallback) {
  return error.response?.data?.error || fallback;
}

function formatDate(value) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

function statusColor(state) {
  if (state === 'active' || state === 'approved') return 'success';
  if (state === 'failed' || state === 'rejected') return 'error';
  if (state === 'pending' || state === 'provisioning' || state === 'deprovisioning') return 'warning';
  return 'default';
}

function MetricCard({ label, value, detail }) {
  return (
    <Card variant="outlined" sx={{ flex: '1 1 180px', minWidth: 170 }}>
      <CardContent>
        <Typography color="text.secondary" variant="overline">{label}</Typography>
        <Typography variant="h4" sx={{ mt: 0.5 }}>{value ?? '—'}</Typography>
        {detail && <Typography color="text.secondary" variant="body2">{detail}</Typography>}
      </CardContent>
    </Card>
  );
}

export default function LiveWorshipAdmin() {
  const [overview, setOverview] = useState(null);
  const [tenants, setTenants] = useState([]);
  const [reviews, setReviews] = useState([]);
  const [loginActivity, setLoginActivity] = useState([]);
  const [status, setStatus] = useState('loading');
  const [message, setMessage] = useState(null);
  const [selectedTenant, setSelectedTenant] = useState(null);
  const [tenantLoading, setTenantLoading] = useState(false);
  const [reviewDialog, setReviewDialog] = useState(null);
  const [reviewNote, setReviewNote] = useState('');
  const [masterSongId, setMasterSongId] = useState('');
  const [savingReview, setSavingReview] = useState(false);
  const [supportSession, setSupportSession] = useState(null);
  const [supportBusy, setSupportBusy] = useState(false);

  const loadData = useCallback(async () => {
    setStatus('loading');
    setMessage(null);
    try {
      const [overviewResponse, tenantsResponse, reviewsResponse, supportResponse, loginResponse] = await Promise.all([
        apiClient.get(`${ADMIN_BASE}/overview`),
        apiClient.get(`${ADMIN_BASE}/tenants`),
        apiClient.get(`${ADMIN_BASE}/catalog-review`, { params: { review_state: 'pending' } }),
        apiClient.get(`${ADMIN_BASE}/support-sessions/current`),
        apiClient.get(`${ADMIN_BASE}/login-activity`, { params: { limit: 100 } }),
      ]);
      setOverview(overviewResponse.data);
      setTenants(tenantsResponse.data);
      setReviews(reviewsResponse.data);
      setSupportSession(supportResponse.data);
      setLoginActivity(loginResponse.data);
      setStatus('ready');
    } catch (error) {
      setStatus('error');
      setMessage({ severity: 'error', text: errorMessage(error, 'Could not load Live Worship administration data.') });
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const openTenant = async (tenant) => {
    setTenantLoading(true);
    setMessage(null);
    try {
      const response = await apiClient.get(`${ADMIN_BASE}/tenants/${tenant.id}`);
      setSelectedTenant(response.data);
    } catch (error) {
      setMessage({ severity: 'error', text: errorMessage(error, 'Could not load tenant details.') });
    } finally {
      setTenantLoading(false);
    }
  };

  const startSupportSession = async () => {
    if (!selectedTenant || selectedTenant.provisioning_state !== 'active') return;
    setSupportBusy(true);
    setMessage(null);
    try {
      const response = await apiClient.post(`${ADMIN_BASE}/support-sessions`, {
        tenant_id: selectedTenant.id,
        duration_minutes: 15,
        reason: 'Tenant troubleshooting',
      });
      setSupportSession(response.data);
      setMessage({ severity: 'success', text: `Read-only support session started for ${selectedTenant.display_name}.` });
    } catch (error) {
      setMessage({ severity: 'error', text: errorMessage(error, 'Could not start the support session.') });
    } finally {
      setSupportBusy(false);
    }
  };

  const endSupportSession = async () => {
    if (!supportSession) return;
    setSupportBusy(true);
    try {
      await apiClient.delete(`${ADMIN_BASE}/support-sessions/${supportSession.id}`);
      setSupportSession(null);
      setMessage({ severity: 'success', text: 'Support session ended.' });
    } catch (error) {
      setMessage({ severity: 'error', text: errorMessage(error, 'Could not end the support session.') });
    } finally {
      setSupportBusy(false);
    }
  };

  const openReview = (review, action) => {
    setReviewDialog({ review, action });
    setReviewNote('');
    setMasterSongId('');
  };

  const submitReview = async (event) => {
    event.preventDefault();
    if (!reviewDialog) return;
    setSavingReview(true);
    setMessage(null);
    const { review, action } = reviewDialog;
    const body = { note: reviewNote };
    if (action === 'duplicate' && masterSongId.trim()) body.master_song_id = masterSongId.trim();
    try {
      await apiClient.post(`${ADMIN_BASE}/catalog-review/${review.id}/${action}`, body);
      setReviewDialog(null);
      setMessage({ severity: 'success', text: `Catalog review ${action}d.` });
      await loadData();
    } catch (error) {
      setMessage({ severity: 'error', text: errorMessage(error, `Could not ${action} this catalog review.`) });
    } finally {
      setSavingReview(false);
    }
  };

  const tenantCounts = overview?.tenant_counts || {};
  const jobCounts = overview?.job_counts || {};
  const dialogAction = reviewDialog?.action;

  return (
    <Box>
      <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} gap={2} sx={{ mb: 3 }}>
        <Box>
          <Typography variant="h4" component="h1">Live Worship administration</Typography>
          <Typography color="text.secondary">Platform-wide tenant health and master catalog review.</Typography>
        </Box>
        <Button variant="outlined" onClick={loadData} disabled={status === 'loading'}>
          {status === 'loading' ? 'Refreshing…' : 'Refresh'}
        </Button>
      </Stack>

      {message && <Alert severity={message.severity} sx={{ mb: 3 }}>{message.text}</Alert>}
      {supportSession && (
        <Alert severity="warning" sx={{ mb: 3 }} action={(
          <Stack direction={{ xs: 'column', sm: 'row' }} gap={1} alignItems={{ sm: 'center' }}>
            <Button color="inherit" size="small" component="a" href={supportSession.launch_path} target="_blank" rel="noreferrer">Open workspace</Button>
            <Button color="inherit" size="small" onClick={endSupportSession} disabled={supportBusy}>End session</Button>
          </Stack>
        )}>
          Read-only support session: {supportSession.display_name}. Expires {formatDate(supportSession.expires_on)}.
        </Alert>
      )}
      {status === 'loading' && !overview && <CircularProgress aria-label="Loading Live Worship administration" />}
      {status === 'error' && !overview && <Button onClick={loadData}>Try again</Button>}

      {overview && (
        <>
          <Stack direction={{ xs: 'column', sm: 'row' }} flexWrap="wrap" gap={2} sx={{ mb: 4 }}>
            <MetricCard label="Active teams" value={tenantCounts.active_tenants} />
            <MetricCard label="Provisioning" value={tenantCounts.provisioning_tenants} detail={`${jobCounts.provisioning_jobs || 0} queued or running jobs`} />
            <MetricCard label="Deprovisioning" value={tenantCounts.deprovisioning_tenants} detail={`${jobCounts.deprovisioning_jobs || 0} queued or running jobs`} />
            <MetricCard label="Failed jobs" value={jobCounts.failed_jobs} />
            <MetricCard label="Catalog reviews" value={overview.pending_catalog_review_count} detail="Pending administrator decisions" />
          </Stack>

          <Typography variant="h5" component="h2" gutterBottom>Plan feature matrix</Typography>
          <Typography color="text.secondary" variant="body2" sx={{ mb: 1 }}>Current seeded entitlements are visible here while the final product and billing model is tested.</Typography>
          <TableContainer component={Paper} variant="outlined" sx={{ mb: 4 }}>
            <Table size="small" aria-label="Live Worship plan feature matrix">
              <TableHead><TableRow><TableCell>Plan</TableCell><TableCell>Teams</TableCell><TableCell>Included features</TableCell></TableRow></TableHead>
              <TableBody>
                {(overview.plans || []).map((plan) => (
                  <TableRow key={plan.code}>
                    <TableCell><Typography fontWeight={600}>{plan.display_name}</Typography><Typography color="text.secondary" variant="caption">{plan.code}</Typography></TableCell>
                    <TableCell>{plan.tenant_count}</TableCell>
                    <TableCell><Stack direction="row" gap={0.5} flexWrap="wrap">{(overview.plan_features?.[plan.code] || []).map((feature) => <Chip key={feature.code} size="small" label={feature.display_name} />)}</Stack></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>

          <Typography variant="h5" component="h2" gutterBottom>User login activity</Typography>
          <Typography color="text.secondary" variant="body2" sx={{ mb: 1 }}>Successful sign-ins, failed sign-ins, team context selections, and sign-outs. Passwords and session tokens are never stored.</Typography>
          <TableContainer component={Paper} variant="outlined" sx={{ mb: 4 }}>
            <Table size="small" aria-label="Live Worship user login activity">
              <TableHead><TableRow><TableCell>User</TableCell><TableCell>Team</TableCell><TableCell>Event</TableCell><TableCell>Result</TableCell><TableCell>Client</TableCell><TableCell>When</TableCell></TableRow></TableHead>
              <TableBody>
                {loginActivity.map((event) => (
                  <TableRow key={event.id}>
                    <TableCell>{event.username || 'Unknown account'}</TableCell>
                    <TableCell>{event.tenant_name || 'Platform login'}</TableCell>
                    <TableCell>{event.event_type.replace('_', ' ')}</TableCell>
                    <TableCell><Chip size="small" color={event.outcome === 'success' ? 'success' : 'error'} label={event.outcome} /></TableCell>
                    <TableCell>{event.client_kind}</TableCell>
                    <TableCell>{formatDate(event.occurred_on)}</TableCell>
                  </TableRow>
                ))}
                {loginActivity.length === 0 && <TableRow><TableCell colSpan={6}>No login activity recorded yet.</TableCell></TableRow>}
              </TableBody>
            </Table>
          </TableContainer>

          <Typography variant="h5" component="h2" gutterBottom>Tenants</Typography>
          <TableContainer component={Paper} variant="outlined" sx={{ mb: 4 }}>
            <Table size="small" aria-label="Live Worship tenants">
              <TableHead>
                <TableRow>
                  <TableCell>Team</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell>Plan</TableCell>
                  <TableCell>Members</TableCell>
                  <TableCell>Reviews</TableCell>
                  <TableCell>Updated</TableCell>
                  <TableCell> </TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {tenants.map((tenant) => (
                  <TableRow key={tenant.id} hover>
                    <TableCell>
                      <Typography fontWeight={600}>{tenant.display_name}</Typography>
                      <Typography color="text.secondary" variant="caption">/live-worship/{tenant.slug}</Typography>
                    </TableCell>
                    <TableCell><Chip size="small" color={statusColor(tenant.provisioning_state)} label={tenant.provisioning_state} /></TableCell>
                    <TableCell>{tenant.subscription?.plan_name || '—'}</TableCell>
                    <TableCell>{tenant.members?.active ?? 0} active / {tenant.members?.total ?? 0}</TableCell>
                    <TableCell>{tenant.pending_catalog_reviews}</TableCell>
                    <TableCell>{formatDate(tenant.updated_on)}</TableCell>
                    <TableCell><Button size="small" onClick={() => openTenant(tenant)}>Details</Button></TableCell>
                  </TableRow>
                ))}
                {tenants.length === 0 && <TableRow><TableCell colSpan={7}>No tenants found.</TableCell></TableRow>}
              </TableBody>
            </Table>
          </TableContainer>

          <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} gap={1} sx={{ mb: 1 }}>
            <Box>
              <Typography variant="h5" component="h2">Catalog review queue</Typography>
              <Typography color="text.secondary" variant="body2">Tenant-created songs remain isolated until an administrator decides whether they belong in the master catalog.</Typography>
            </Box>
            {overview.pending_catalog_review_count > 0 && <Chip color="warning" label={`${overview.pending_catalog_review_count} pending`} />}
          </Stack>
          <TableContainer component={Paper} variant="outlined">
            <Table size="small" aria-label="Pending Live Worship catalog reviews">
              <TableHead>
                <TableRow>
                  <TableCell>Song</TableCell>
                  <TableCell>Tenant</TableCell>
                  <TableCell>Submitted</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell>Actions</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {reviews.map((review) => (
                  <TableRow key={review.id} hover>
                    <TableCell>
                      <Typography fontWeight={600}>{review.title || 'Untitled song'}</Typography>
                      <Typography color="text.secondary" variant="caption">{review.writer || 'Writer not supplied'}</Typography>
                    </TableCell>
                    <TableCell>{review.tenant_name || review.slug}</TableCell>
                    <TableCell>{formatDate(review.submitted_on)}</TableCell>
                    <TableCell><Chip size="small" color={statusColor(review.review_state)} label={review.review_state} /></TableCell>
                    <TableCell>
                      <Stack direction="row" flexWrap="wrap" gap={0.5}>
                        <Button size="small" color="success" onClick={() => openReview(review, 'approve')}>Approve</Button>
                        <Button size="small" color="error" onClick={() => openReview(review, 'reject')}>Reject</Button>
                        <Button size="small" onClick={() => openReview(review, 'duplicate')}>Duplicate</Button>
                      </Stack>
                    </TableCell>
                  </TableRow>
                ))}
                {reviews.length === 0 && <TableRow><TableCell colSpan={5}>No pending catalog reviews.</TableCell></TableRow>}
              </TableBody>
            </Table>
          </TableContainer>
        </>
      )}

      <Dialog open={Boolean(selectedTenant)} onClose={() => setSelectedTenant(null)} fullWidth maxWidth="md">
        <DialogTitle>{selectedTenant?.display_name || 'Tenant details'}</DialogTitle>
        <DialogContent dividers>
          {tenantLoading && <CircularProgress aria-label="Loading tenant details" />}
          {selectedTenant && (
            <Stack spacing={2}>
              <Stack direction={{ xs: 'column', sm: 'row' }} gap={1} flexWrap="wrap">
                <Chip color={statusColor(selectedTenant.provisioning_state)} label={selectedTenant.provisioning_state} />
                <Chip label={selectedTenant.subscription?.plan_name || 'No plan'} />
                <Chip label={`${selectedTenant.members?.active ?? 0} active members`} />
              </Stack>
              <Typography variant="body2"><strong>URL:</strong> /live-worship/{selectedTenant.slug}</Typography>
              <Typography variant="body2"><strong>Schema:</strong> {selectedTenant.schema_name}</Typography>
              <Typography variant="body2"><strong>Last updated:</strong> {formatDate(selectedTenant.updated_on)}</Typography>
              {selectedTenant.provisioning_state === 'active' && (!supportSession || supportSession.tenant_id !== selectedTenant.id) && (
                <Alert severity="info" action={<Button color="inherit" size="small" onClick={startSupportSession} disabled={supportBusy}>Start session</Button>}>
                  Open this tenant in a time-limited read-only support session for troubleshooting.
                </Alert>
              )}
              <Divider />
              <Typography variant="h6">Entitlements</Typography>
              <Stack direction="row" gap={1} flexWrap="wrap">
                {(selectedTenant.features || []).map((feature) => <Chip key={feature.code} size="small" color={feature.entitled ? 'success' : 'default'} label={`${feature.display_name}: ${feature.entitled ? 'enabled' : 'inactive'}`} />)}
                {(selectedTenant.features || []).length === 0 && <Typography color="text.secondary">No feature entitlements recorded.</Typography>}
              </Stack>
              <Typography variant="h6">Members</Typography>
              <Table size="small" aria-label="Tenant members">
                <TableHead><TableRow><TableCell>Name</TableCell><TableCell>Role</TableCell><TableCell>Status</TableCell></TableRow></TableHead>
                <TableBody>
                  {(selectedTenant.members || []).map((member) => <TableRow key={member.id}><TableCell>{member.display_name || member.external_subject || member.actor_id}</TableCell><TableCell>{member.role}</TableCell><TableCell>{member.active ? 'Active' : 'Inactive'}</TableCell></TableRow>)}
                </TableBody>
              </Table>
            </Stack>
          )}
        </DialogContent>
        <DialogActions>
          {supportSession && selectedTenant && supportSession.tenant_id === selectedTenant.id && <Button color="warning" onClick={endSupportSession} disabled={supportBusy}>End support session</Button>}
          <Button onClick={() => setSelectedTenant(null)}>Close</Button>
        </DialogActions>
      </Dialog>

      <Dialog open={Boolean(reviewDialog)} onClose={() => !savingReview && setReviewDialog(null)} fullWidth maxWidth="sm">
        <Box component="form" onSubmit={submitReview}>
          <DialogTitle>{dialogAction ? `${dialogAction[0].toUpperCase()}${dialogAction.slice(1)} catalog song` : 'Catalog review'}</DialogTitle>
          <DialogContent>
            <Typography sx={{ mb: 2 }}><strong>{reviewDialog?.review.title}</strong>{reviewDialog?.review.writer ? ` — ${reviewDialog.review.writer}` : ''}</Typography>
            {dialogAction === 'duplicate' && <TextField label="Matching master song ID (optional)" value={masterSongId} onChange={(event) => setMasterSongId(event.target.value)} fullWidth helperText="Leave blank to match by title and writer." sx={{ mb: 2 }} />}
            <TextField label="Review note" value={reviewNote} onChange={(event) => setReviewNote(event.target.value)} fullWidth multiline minRows={3} inputProps={{ maxLength: 2000 }} helperText="Optional note saved with the audit decision." />
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setReviewDialog(null)} disabled={savingReview}>Cancel</Button>
            <Button type="submit" variant="contained" color={dialogAction === 'reject' ? 'error' : 'primary'} disabled={savingReview}>{savingReview ? 'Saving…' : 'Save decision'}</Button>
          </DialogActions>
        </Box>
      </Dialog>
    </Box>
  );
}
