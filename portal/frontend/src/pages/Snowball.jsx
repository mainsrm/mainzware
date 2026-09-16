import { useEffect, useMemo, useRef, useState } from 'react';
import { visuallyHidden } from '@mui/utils';
import Box from '@mui/material/Box';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Grid from '@mui/material/Grid';
import Typography from '@mui/material/Typography';
import TextField from '@mui/material/TextField';
import Button from '@mui/material/Button';
import IconButton from '@mui/material/IconButton';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Collapse from '@mui/material/Collapse';
import Alert from '@mui/material/Alert';
import Snackbar from '@mui/material/Snackbar';
import CircularProgress from '@mui/material/CircularProgress';
import DeleteIcon from '@mui/icons-material/Delete';
import AddIcon from '@mui/icons-material/Add';
import SaveIcon from '@mui/icons-material/Save';
import apiClient from '../api/client';

const EMPTY_DEBT = { name: '', balance: '', min_payment: '', apr: '' };

function currency(value) {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(
    Number.isFinite(value) ? value : 0,
  );
}

function monthLabel(yyyymm) {
  if (!yyyymm) return '—';
  const [year, month] = yyyymm.split('-').map(Number);
  return new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
}

export default function Snowball() {
  const [debts, setDebts] = useState([]);
  const [status, setStatus] = useState('loading');
  const [newDebt, setNewDebt] = useState(EMPTY_DEBT);
  const [savingNew, setSavingNew] = useState(false);
  const [extraPayment, setExtraPayment] = useState('0');
  const [projection, setProjection] = useState(null);
  const [projecting, setProjecting] = useState(false);
  const [showSchedule, setShowSchedule] = useState(false);
  const [snackbar, setSnackbar] = useState(null);
  // Rows edited but not yet saved; a projection would be computed from stale DB values,
  // so Calculate is blocked until these are saved.
  const [dirtyIds, setDirtyIds] = useState(() => new Set());
  const nameInputRef = useRef(null);

  const loadDebts = () => {
    setStatus('loading');
    apiClient
      .get('/debts')
      .then((response) => {
        setDebts(response.data);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(loadDebts, []);

  const totalBalance = useMemo(
    () => debts.reduce((sum, debt) => sum + Number(debt.balance || 0), 0),
    [debts],
  );
  const totalMinimums = useMemo(
    () => debts.reduce((sum, debt) => sum + Number(debt.min_payment || 0), 0),
    [debts],
  );

  // Concise text mirrored into a persistent live region so screen readers announce the result.
  const liveMessage = useMemo(() => {
    if (!projection) return '';
    if (!projection.payable) {
      return 'Projection ready. These minimum payments cannot cover the interest, so the debts never fully clear.';
    }
    return `Projection ready. Debt-free by ${monthLabel(projection.debt_free_month)}, ${projection.total_months} month${projection.total_months === 1 ? '' : 's'}, ${currency(projection.total_interest_paid)} total interest.`;
  }, [projection]);

  const notify = (severity, message) => setSnackbar({ severity, message });

  const validateDraft = (draft) => {
    if (!draft.name.trim()) return 'Enter a debt name.';
    if (draft.balance === '' || Number(draft.balance) < 0) return 'Enter a balance of 0 or more.';
    if (draft.min_payment === '' || Number(draft.min_payment) < 0) return 'Enter a minimum payment of 0 or more.';
    if (draft.apr !== '' && Number(draft.apr) < 0) return 'APR cannot be negative.';
    return null;
  };

  const addDebt = async (event) => {
    event.preventDefault();
    const error = validateDraft(newDebt);
    if (error) {
      notify('error', error);
      return;
    }
    setSavingNew(true);
    try {
      const response = await apiClient.post('/debts', {
        name: newDebt.name.trim(),
        balance: Number(newDebt.balance),
        min_payment: Number(newDebt.min_payment),
        apr: newDebt.apr === '' ? 0 : Number(newDebt.apr),
      });
      setDebts((current) => [...current, response.data]);
      setNewDebt(EMPTY_DEBT);
      setProjection(null);
      notify('success', 'Debt added.');
      nameInputRef.current?.focus();
    } catch (error) {
      notify('error', error?.response?.data?.error || 'Could not add that debt.');
    } finally {
      setSavingNew(false);
    }
  };

  const updateDebtField = (id, field, value) => {
    setDebts((current) => current.map((debt) => (debt.id === id ? { ...debt, [field]: value } : debt)));
    setDirtyIds((current) => new Set(current).add(id));
  };

  const saveDebt = async (debt) => {
    const error = validateDraft({
      name: String(debt.name ?? ''),
      balance: String(debt.balance ?? ''),
      min_payment: String(debt.min_payment ?? ''),
      apr: String(debt.apr ?? ''),
    });
    if (error) {
      notify('error', error);
      return;
    }
    try {
      const response = await apiClient.post(`/debts/${debt.id}`, {
        name: String(debt.name).trim(),
        balance: Number(debt.balance),
        min_payment: Number(debt.min_payment),
        apr: debt.apr === '' ? 0 : Number(debt.apr),
      });
      setDebts((current) => current.map((row) => (row.id === response.data.id ? response.data : row)));
      setDirtyIds((current) => {
        const next = new Set(current);
        next.delete(debt.id);
        return next;
      });
      setProjection(null);
      notify('success', 'Debt saved. Recalculate to refresh your projection.');
    } catch (error) {
      notify('error', error?.response?.data?.error || 'Could not save that debt.');
    }
  };

  const deleteDebt = async (id, name) => {
    if (!window.confirm(`Delete “${name || 'this debt'}”? This cannot be undone.`)) {
      return;
    }
    try {
      await apiClient.delete(`/debts/${id}`);
      setDebts((current) => current.filter((debt) => debt.id !== id));
      setDirtyIds((current) => {
        const next = new Set(current);
        next.delete(id);
        return next;
      });
      setProjection(null);
      notify('success', 'Debt removed.');
      nameInputRef.current?.focus();
    } catch (error) {
      notify('error', error?.response?.data?.error || 'Could not remove that debt.');
    }
  };

  const runProjection = async () => {
    setProjecting(true);
    try {
      const response = await apiClient.post('/debts/snowball', {
        extra_payment: extraPayment === '' ? 0 : Number(extraPayment),
      });
      setProjection(response.data);
      if (!response.data.payable) {
        notify('warning', 'These minimum payments cannot cover the interest — the debts never fully clear.');
      }
    } catch (error) {
      notify('error', error?.response?.data?.error || 'Could not run the projection.');
    } finally {
      setProjecting(false);
    }
  };

  if (status === 'loading') {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}>
        <CircularProgress aria-label="Loading debts" />
      </Box>
    );
  }

  if (status === 'error') {
    return (
      <Alert severity="error" sx={{ my: 4 }}>
        Could not load your debts. Please try again.
      </Alert>
    );
  }

  return (
    <Box component="section" aria-labelledby="snowball-heading" sx={{ py: { xs: 2, md: 4 } }}>
      <Typography id="snowball-heading" variant="h4" component="h1" gutterBottom>
        Debt Snowball
      </Typography>
      <Typography color="text.secondary" sx={{ mb: 3 }}>
        List your debts, add any extra you can pay each month, and project a payoff date. Each time a
        debt is cleared, its payment rolls into the next-smallest balance.
      </Typography>

      <Box aria-live="polite" sx={visuallyHidden}>
        {liveMessage}
      </Box>

      <Grid container spacing={3}>
        <Grid item xs={12} md={7}>
          <Paper variant="outlined" sx={{ p: 2 }}>
            <Typography variant="h6" component="h2" gutterBottom>
              Your debts
            </Typography>
            <TableContainer>
              <Table size="small" aria-label="Your debts">
                <TableHead>
                  <TableRow>
                    <TableCell scope="col">Name</TableCell>
                    <TableCell scope="col" align="right">Balance</TableCell>
                    <TableCell scope="col" align="right">Min. payment</TableCell>
                    <TableCell scope="col" align="right">APR %</TableCell>
                    <TableCell scope="col" align="right">Actions</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {debts.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={5}>
                        <Typography color="text.secondary">No debts yet. Add one below.</Typography>
                      </TableCell>
                    </TableRow>
                  )}
                  {debts.map((debt, index) => (
                    <TableRow key={debt.id}>
                      <TableCell>
                        <TextField
                          value={debt.name}
                          onChange={(event) => updateDebtField(debt.id, 'name', event.target.value)}
                          variant="standard"
                          size="small"
                          inputProps={{ 'aria-label': `Name for debt ${debt.name || index + 1}` }}
                        />
                      </TableCell>
                      <TableCell align="right">
                        <TextField
                          value={debt.balance}
                          onChange={(event) => updateDebtField(debt.id, 'balance', event.target.value)}
                          type="number"
                          variant="standard"
                          size="small"
                          inputProps={{ min: 0, step: '0.01', 'aria-label': `Balance for ${debt.name || `debt ${index + 1}`}` }}
                        />
                      </TableCell>
                      <TableCell align="right">
                        <TextField
                          value={debt.min_payment}
                          onChange={(event) => updateDebtField(debt.id, 'min_payment', event.target.value)}
                          type="number"
                          variant="standard"
                          size="small"
                          inputProps={{ min: 0, step: '0.01', 'aria-label': `Minimum payment for ${debt.name || `debt ${index + 1}`}` }}
                        />
                      </TableCell>
                      <TableCell align="right">
                        <TextField
                          value={debt.apr}
                          onChange={(event) => updateDebtField(debt.id, 'apr', event.target.value)}
                          type="number"
                          variant="standard"
                          size="small"
                          inputProps={{ min: 0, step: '0.1', 'aria-label': `APR percent for ${debt.name || `debt ${index + 1}`}` }}
                        />
                      </TableCell>
                      <TableCell align="right">
                        <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                          <IconButton
                            aria-label={`Save ${debt.name || `debt ${index + 1}`}`}
                            color="primary"
                            size="small"
                            onClick={() => saveDebt(debt)}
                          >
                            <SaveIcon fontSize="small" />
                          </IconButton>
                          <IconButton
                            aria-label={`Delete ${debt.name || `debt ${index + 1}`}`}
                            color="error"
                            size="small"
                            onClick={() => deleteDebt(debt.id, debt.name)}
                          >
                            <DeleteIcon fontSize="small" />
                          </IconButton>
                        </Stack>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </TableContainer>

            <Box component="form" onSubmit={addDebt} sx={{ mt: 3 }} aria-label="Add a debt">
              <Typography variant="subtitle1" component="h3" gutterBottom>
                Add a debt
              </Typography>
              <Grid container spacing={2} alignItems="flex-end">
                <Grid item xs={12} sm={4}>
                  <TextField
                    label="Name"
                    value={newDebt.name}
                    onChange={(event) => setNewDebt({ ...newDebt, name: event.target.value })}
                    fullWidth
                    size="small"
                    required
                    inputRef={nameInputRef}
                  />
                </Grid>
                <Grid item xs={6} sm={2}>
                  <TextField
                    label="Balance"
                    value={newDebt.balance}
                    onChange={(event) => setNewDebt({ ...newDebt, balance: event.target.value })}
                    type="number"
                    fullWidth
                    size="small"
                    inputProps={{ min: 0, step: '0.01' }}
                    required
                  />
                </Grid>
                <Grid item xs={6} sm={2}>
                  <TextField
                    label="Min. payment"
                    value={newDebt.min_payment}
                    onChange={(event) => setNewDebt({ ...newDebt, min_payment: event.target.value })}
                    type="number"
                    fullWidth
                    size="small"
                    inputProps={{ min: 0, step: '0.01' }}
                    required
                  />
                </Grid>
                <Grid item xs={6} sm={2}>
                  <TextField
                    label="APR %"
                    value={newDebt.apr}
                    onChange={(event) => setNewDebt({ ...newDebt, apr: event.target.value })}
                    type="number"
                    fullWidth
                    size="small"
                    inputProps={{ min: 0, step: '0.1' }}
                  />
                </Grid>
                <Grid item xs={6} sm={12} md="auto">
                  <Button
                    type="submit"
                    variant="contained"
                    startIcon={<AddIcon />}
                    disabled={savingNew}
                  >
                    Add
                  </Button>
                </Grid>
              </Grid>
            </Box>
          </Paper>
        </Grid>

        <Grid item xs={12} md={5}>
          <Paper variant="outlined" sx={{ p: 2 }}>
            <Typography variant="h6" component="h2" gutterBottom>
              Projection
            </Typography>
            <Stack spacing={1} sx={{ mb: 2 }}>
              <Typography variant="body2" color="text.secondary">
                Total balance: <strong>{currency(totalBalance)}</strong>
              </Typography>
              <Typography variant="body2" color="text.secondary">
                Total minimum payments: <strong>{currency(totalMinimums)}</strong>
              </Typography>
            </Stack>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} alignItems="flex-end">
              <TextField
                label="Extra monthly payment"
                value={extraPayment}
                onChange={(event) => setExtraPayment(event.target.value)}
                type="number"
                size="small"
                inputProps={{ min: 0, step: '0.01' }}
                fullWidth
              />
              <Button
                variant="contained"
                onClick={runProjection}
                disabled={projecting || debts.length === 0 || dirtyIds.size > 0}
                sx={{ whiteSpace: 'nowrap' }}
              >
                {projecting ? 'Calculating…' : 'Calculate'}
              </Button>
            </Stack>
            {dirtyIds.size > 0 && (
              <Typography variant="caption" color="warning.main" sx={{ mt: 1, display: 'block' }}>
                Save your edited debts to project an up-to-date payoff.
              </Typography>
            )}

            {projection && (
              <Box sx={{ mt: 3 }}>
                {projection.payable ? (
                  <Card variant="outlined" sx={{ bgcolor: 'success.light' }}>
                    <CardContent>
                      <Typography variant="overline" component="h3">
                        Debt-free
                      </Typography>
                      <Typography variant="h5" component="p">
                        {monthLabel(projection.debt_free_month)}
                      </Typography>
                      <Typography variant="body2">
                        {projection.total_months} month{projection.total_months === 1 ? '' : 's'} ·{' '}
                        {currency(projection.total_interest_paid)} total interest
                      </Typography>
                    </CardContent>
                  </Card>
                ) : (
                  <Alert severity="warning">
                    With these minimums the balances never fully clear — interest outpaces the
                    payments. Increase a minimum payment or add extra each month.
                  </Alert>
                )}

                <TableContainer sx={{ mt: 2 }}>
                  <Table size="small" aria-label="Payoff order">
                    <TableHead>
                      <TableRow>
                        <TableCell scope="col">Debt</TableCell>
                        <TableCell scope="col" align="right">Paid off</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {projection.payoff_order.map((entry) => (
                        <TableRow key={entry.name}>
                          <TableCell component="th" scope="row">{entry.name}</TableCell>
                          <TableCell align="right">{monthLabel(entry.calendar_month)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </TableContainer>

                <Button
                  onClick={() => setShowSchedule((open) => !open)}
                  size="small"
                  sx={{ mt: 1 }}
                  aria-expanded={showSchedule}
                  aria-controls="snowball-schedule"
                >
                  {showSchedule ? 'Hide' : 'Show'} month-by-month schedule
                </Button>
                <Collapse in={showSchedule} id="snowball-schedule">
                  <TableContainer sx={{ mt: 1, maxHeight: 360 }}>
                    <Table size="small" stickyHeader aria-label="Month-by-month schedule">
                      <TableHead>
                        <TableRow>
                          <TableCell scope="col">Month</TableCell>
                          <TableCell scope="col">Debt</TableCell>
                          <TableCell scope="col" align="right">Payment</TableCell>
                          <TableCell scope="col" align="right">Remaining</TableCell>
                        </TableRow>
                      </TableHead>
                      <TableBody>
                        {projection.monthly_breakdown.flatMap((month) =>
                          month.payments.map((payment, index) => (
                            <TableRow key={`${month.month}-${payment.debt}`}>
                              <TableCell component="th" scope="row">
                                {index === 0 ? (
                                  monthLabel(month.calendar_month)
                                ) : (
                                  <Box component="span" sx={visuallyHidden}>
                                    {monthLabel(month.calendar_month)}
                                  </Box>
                                )}
                              </TableCell>
                              <TableCell>{payment.debt}</TableCell>
                              <TableCell align="right">{currency(payment.payment)}</TableCell>
                              <TableCell align="right">{currency(payment.remaining_balance)}</TableCell>
                            </TableRow>
                          )),
                        )}
                      </TableBody>
                    </Table>
                  </TableContainer>
                </Collapse>
              </Box>
            )}
          </Paper>
        </Grid>
      </Grid>

      <Snackbar
        open={Boolean(snackbar)}
        autoHideDuration={4000}
        onClose={() => setSnackbar(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        {snackbar ? (
          <Alert severity={snackbar.severity} onClose={() => setSnackbar(null)} sx={{ width: '100%' }}>
            {snackbar.message}
          </Alert>
        ) : undefined}
      </Snackbar>
    </Box>
  );
}
