import { useEffect, useState } from 'react';
import axios from 'axios';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import Alert from '@mui/material/Alert';
import Stack from '@mui/material/Stack';
import CircularProgress from '@mui/material/CircularProgress';
import apiClient from '../api/client';
import BudgetVariance from '../components/BudgetVariance';
import TransactionTable from '../components/TransactionTable';
import { useAuth } from '../context/AuthContext';
import { Link as RouterLink } from 'react-router-dom';
import TextField from '@mui/material/TextField';
import MenuItem from '@mui/material/MenuItem';
import Box from '@mui/material/Box';
import Dialog from '@mui/material/Dialog';
import DialogTitle from '@mui/material/DialogTitle';
import DialogContent from '@mui/material/DialogContent';
import DialogActions from '@mui/material/DialogActions';
import IconButton from '@mui/material/IconButton';
import DeleteIcon from '@mui/icons-material/Delete';
import EditIcon from '@mui/icons-material/Edit';
import Chip from '@mui/material/Chip';

export default function Budget() {
  const { user } = useAuth();
  const [transactions, setTransactions] = useState([]);
  const [status, setStatus] = useState('ready');
  const [uploadMessage, setUploadMessage] = useState(null);
  const [uploading, setUploading] = useState(false);
  const [budgets, setBudgets] = useState([]);
  const [budgetId, setBudgetId] = useState('');
  const [newBudgetName, setNewBudgetName] = useState('');
  const [shareUsername, setShareUsername] = useState('');
  const [sharePermission, setSharePermission] = useState('viewer');
  const [shareEditingMemberId, setShareEditingMemberId] = useState(null);
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const [categories, setCategories] = useState([]);
  const [categoryEditorOpen, setCategoryEditorOpen] = useState(false);
  const [categoryName, setCategoryName] = useState('');
  const [parentCategoryId, setParentCategoryId] = useState('');
  const [createBudgetOpen, setCreateBudgetOpen] = useState(false);
  const [editBudgetOpen, setEditBudgetOpen] = useState(false);
  const [editBudgetName, setEditBudgetName] = useState('');
  const [shareBudgetOpen, setShareBudgetOpen] = useState(false);
  const [budgetMembers, setBudgetMembers] = useState([]);
  const [shareableUsers, setShareableUsers] = useState([]);
  const [sharingDetailsLoading, setSharingDetailsLoading] = useState(false);
  const selectedBudget = budgets.find((budget) => String(budget.id) === String(budgetId));
  const canWrite = selectedBudget?.permission === 'owner' || selectedBudget?.permission === 'editor';
  const sharingMembers = Array.isArray(budgetMembers) ? budgetMembers : [];
  const sharedWithCount = sharingMembers.filter((member) => Number(member.id) !== Number(user?.id)).length;
  const editingExistingShare = sharingMembers.some(
    (member) => Number(member.id) !== Number(user?.id) && member.username === shareUsername,
  );

  const loadTransactions = () => {
    setStatus('loading');
    apiClient
      .get('/budget/transactions', { params: { budget_id: budgetId } })
      .then((response) => {
        setTransactions(response.data);
        if (response.data.length > 0) {
          setMonth(String(response.data[0].transaction_date).slice(0, 7));
        }
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  const loadBudgets = () => {
    if (!user) {
      setBudgets([]);
      setBudgetId('');
      return;
    }
    apiClient.get('/budget/budgets').then((response) => {
      setBudgets(response.data);
      if (!budgetId && response.data.length > 0) setBudgetId(String(response.data[0].id));
    });
  };

  const loadCategories = () => {
    if (!budgetId) return;
    apiClient.get('/budget/categories', { params: { budget_id: budgetId } }).then((response) => setCategories(response.data));
  };

  useEffect(loadBudgets, [user]);
  useEffect(() => {
    if (budgetId) {
      loadTransactions();
    } else {
      setStatus('ready');
      setTransactions([]);
    }
  }, [budgetId]);
  useEffect(loadCategories, [budgetId]);
  useEffect(() => {
    if (selectedBudget?.permission === 'owner') {
      loadSharingDetails();
    } else {
      setBudgetMembers([]);
      setShareableUsers([]);
    }
  }, [budgetId, selectedBudget?.permission]);

  const createCategory = async (event) => {
    event.preventDefault();
    await apiClient.post('/budget/categories', { name: categoryName, parent_category_id: parentCategoryId || null });
    setCategoryName('');
    setParentCategoryId('');
    loadCategories();
  };


  const createBudget = async (event) => {
    event.preventDefault();
    const response = await apiClient.post('/budget/budgets', { name: newBudgetName });
    setNewBudgetName('');
    await loadBudgets();
    setBudgetId(String(response.data.id));
    setCreateBudgetOpen(false);
  };

  const resetShareDialog = () => {
    setShareUsername('');
    setSharePermission('viewer');
    setShareEditingMemberId(null);
  };

  const shareBudget = async (event) => {
    event.preventDefault();
    await apiClient.post('/budget/share', {
      budget_id: Number(budgetId),
      username: shareUsername,
      permission: sharePermission,
    });
    resetShareDialog();
    await loadSharingDetails();
    setUploadMessage({ severity: 'success', text: shareEditingMemberId ? 'Share access updated.' : 'Budget shared.' });
  };

  const unshareBudgetMember = async (member) => {
    if (!member || Number(member.id) === Number(user?.id)) return;
    if (!window.confirm(`Remove ${member.username}'s access to this budget?`)) return;
    await apiClient.delete(`/budget/budgets/${budgetId}/members/${member.id}`);
    if (Number(shareEditingMemberId) === Number(member.id)) {
      resetShareDialog();
    }
    await loadSharingDetails();
    setUploadMessage({ severity: 'success', text: `${member.username} no longer has access to this budget.` });
  };

  const loadSharingDetails = async () => {
    if (!budgetId) return;
    setSharingDetailsLoading(true);
    try {
      const response = await apiClient.get(`/budget/budgets/${budgetId}/members`);
      setBudgetMembers(Array.isArray(response.data.members) ? response.data.members : []);
      setShareableUsers(Array.isArray(response.data.available_users) ? response.data.available_users : []);
    } finally {
      setSharingDetailsLoading(false);
    }
  };

  const renameBudget = async (event) => {
    event.preventDefault();
    await apiClient.post(`/budget/budgets/${budgetId}`, { name: editBudgetName });
    await loadBudgets();
    setEditBudgetOpen(false);
    setUploadMessage({ severity: 'success', text: 'Budget name updated.' });
  };

  const deleteBudget = async (budget) => {
    if (!budget || budget.permission !== 'owner') return;
    if (!window.confirm(`Delete budget "${budget.name}" and all of its transactions?`)) return;
    await apiClient.delete(`/budget/budgets/${budget.id}`);
    const remaining = budgets.filter((item) => item.id !== budget.id);
    setBudgets(remaining);
    setBudgetId(remaining.length > 0 ? String(remaining[0].id) : '');
  };

  const handleFileChange = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = ''; // allow re-selecting the same file
    if (!file) return;

    setUploading(true);
    setUploadMessage(null);

    const formData = new FormData();
    formData.append('file', file);

    try {
      // Uses plain axios (not the shared JSON apiClient) so the browser can set the
      // multipart/form-data boundary itself.
      const response = await axios.post('/api/v1/budget/import', formData);
      const importedCount = Number(response.data?.imported ?? response.data?.inserted ?? 0);
      const skippedDuplicateCount = Number(response.data?.skipped_duplicates ?? 0);
      const duplicateMessage = skippedDuplicateCount > 0
        ? ` Skipped ${skippedDuplicateCount} duplicate${skippedDuplicateCount === 1 ? '' : 's'}.`
        : '';
      setUploadMessage({ severity: 'success', text: `Imported ${importedCount} transactions.${duplicateMessage}` });
      loadTransactions();
    } catch (error) {
      const text = error.response?.data?.error || 'Import failed.';
      setUploadMessage({ severity: 'error', text });
    } finally {
      setUploading(false);
    }
  };

  return (
    <>
      <Typography variant="h4" component="h2" gutterBottom>
        What Da Money
      </Typography>

      {user && (
        <Stack direction="row" spacing={2} useFlexGap flexWrap="wrap" alignItems="flex-start" sx={{ mb: 2 }}>
          <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1, alignItems: 'flex-start' }}>
            <TextField
              select
              label="Budget"
              value={budgetId}
              onChange={(event) => setBudgetId(event.target.value)}
              SelectProps={{
                renderValue: (value) => {
                  const current = budgets.find((budget) => String(budget.id) === String(value));
                  return current ? `${current.name} (${current.permission})` : '';
                },
              }}
              sx={{ minWidth: 220 }}
            >
              {budgets.map((budget) => (
                <MenuItem key={budget.id} value={String(budget.id)} sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}>
                  <span>{budget.name} ({budget.permission})</span>
                  {budget.permission === 'owner' && (
                    <IconButton
                      size="small"
                      color="error"
                      aria-label={`Delete ${budget.name}`}
                      title={`Delete ${budget.name}`}
                      onMouseDown={(event) => event.stopPropagation()}
                      onClick={(event) => {
                        event.stopPropagation();
                        deleteBudget(budget);
                      }}
                    >
                      <DeleteIcon fontSize="small" />
                    </IconButton>
                  )}
                </MenuItem>
              ))}
            </TextField>
            <Button variant="outlined" onClick={() => setCreateBudgetOpen(true)}>Create New Budget</Button>
          </Box>
          {selectedBudget?.permission === 'owner' && (
            <>
              <Button variant="outlined" onClick={() => {
                setEditBudgetName(selectedBudget.name);
                setEditBudgetOpen(true);
              }}>
                Edit Budget
              </Button>
              <Button variant="outlined" onClick={() => {
                resetShareDialog();
                setShareBudgetOpen(true);
                loadSharingDetails();
              }}>
                Share Budget
              </Button>
              <Chip
                aria-label={sharingDetailsLoading ? 'Loading sharing details' : sharedWithCount > 0 ? `Shared with ${sharedWithCount} user${sharedWithCount === 1 ? '' : 's'}` : 'Not shared'}
                label={sharingDetailsLoading ? 'Checking sharing...' : sharedWithCount > 0 ? `Shared with ${sharedWithCount}` : 'Not shared'}
                size="small"
                color={sharedWithCount > 0 ? 'primary' : 'default'}
                variant="outlined"
              />
            </>
          )}
        </Stack>
      )}

      <Dialog open={createBudgetOpen} onClose={() => setCreateBudgetOpen(false)}>
        <Box component="form" onSubmit={createBudget}>
          <DialogTitle>Create New Budget</DialogTitle>
          <DialogContent>
            <TextField autoFocus fullWidth label="Budget name" value={newBudgetName} onChange={(event) => setNewBudgetName(event.target.value)} required sx={{ mt: 1 }} />
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setCreateBudgetOpen(false)}>Cancel</Button>
            <Button type="submit" variant="contained">Create</Button>
          </DialogActions>
        </Box>
      </Dialog>

      <Dialog fullWidth maxWidth="xs" open={editBudgetOpen} onClose={() => setEditBudgetOpen(false)}>
        <Box component="form" onSubmit={renameBudget}>
          <DialogTitle>Edit Budget</DialogTitle>
          <DialogContent>
            <TextField autoFocus fullWidth label="Budget name" value={editBudgetName} onChange={(event) => setEditBudgetName(event.target.value)} required sx={{ mt: 1 }} />
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setEditBudgetOpen(false)}>Cancel</Button>
            <Button type="submit" variant="contained">Save</Button>
          </DialogActions>
        </Box>
      </Dialog>

      <Dialog fullWidth maxWidth="sm" open={shareBudgetOpen} onClose={() => setShareBudgetOpen(false)}>
        <Box component="form" onSubmit={shareBudget}>
          <DialogTitle>{shareEditingMemberId ? 'Edit share access' : 'Share Budget'}: {selectedBudget?.name}</DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ mt: 1 }}>
              <Typography color="text.secondary">
                Manage sharing for {selectedBudget?.name}.
              </Typography>
              <Typography component="h3" variant="subtitle1">Shared with</Typography>
              {sharingDetailsLoading ? (
                <CircularProgress size={24} aria-label="Loading shared budget members" />
              ) : (
                <Stack component="ul" spacing={1} sx={{ listStyle: 'none', m: 0, p: 0 }}>
                  {sharingMembers.length === 0 ? (
                    <Typography color="text.secondary">No one else is currently shared with this budget.</Typography>
                  ) : (
                    sharingMembers.map((member) => (
                      <Stack component="li" direction="row" justifyContent="space-between" alignItems="center" key={member.id}>
                        <Typography>{member.username}</Typography>
                        <Stack direction="row" alignItems="center" spacing={0.5}>
                          <Chip label={member.permission} size="small" />
                          {Number(member.id) !== Number(user?.id) && (
                            <>
                              <IconButton
                                aria-label={`Edit ${member.username} sharing access`}
                                onClick={() => {
                                  setShareEditingMemberId(member.id);
                                  setShareUsername(member.username);
                                  setSharePermission(member.permission);
                                }}
                                size="small"
                                title={`Edit ${member.username} access`}
                              >
                                <EditIcon fontSize="small" />
                              </IconButton>
                              <IconButton
                                aria-label={`Remove ${member.username} sharing access`}
                                color="error"
                                onClick={() => unshareBudgetMember(member)}
                                size="small"
                                title={`Remove ${member.username} access`}
                              >
                                <DeleteIcon fontSize="small" />
                              </IconButton>
                            </>
                          )}
                        </Stack>
                      </Stack>
                    ))
                  )}
                </Stack>
              )}
              <TextField select autoFocus label="Share with user" value={shareUsername} onChange={(event) => setShareUsername(event.target.value)} required>
                <MenuItem value="" disabled>{shareEditingMemberId ? 'Select a user to update' : 'Select a user'}</MenuItem>
                {(Array.isArray(shareableUsers) ? shareableUsers : []).map((shareableUser) => (
                  <MenuItem key={shareableUser.id} value={shareableUser.username}>{shareableUser.username}</MenuItem>
                ))}
                {shareEditingMemberId && shareUsername && !shareableUsers.some((user) => user.username === shareUsername) && (
                  <MenuItem value={shareUsername}>{shareUsername}</MenuItem>
                )}
              </TextField>
              <TextField select label="Access" value={sharePermission} onChange={(event) => setSharePermission(event.target.value)}>
                <MenuItem value="viewer">Viewer</MenuItem>
                <MenuItem value="editor">Editor</MenuItem>
              </TextField>
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => {
              setShareBudgetOpen(false);
              resetShareDialog();
            }}>Cancel</Button>
            <Button type="submit" variant="contained" disabled={!shareUsername}>
              {editingExistingShare ? 'Update Access' : 'Share'}
            </Button>
          </DialogActions>
        </Box>
      </Dialog>

      <Typography variant="h6" component="h3" gutterBottom>
        What Da Money Does
      </Typography>
      {budgetId && (
        <Stack direction="row" spacing={2} alignItems="center" sx={{ mb: 2 }}>
          <TextField
            label="Budget month"
            type="month"
            value={month}
            onChange={(event) => setMonth(event.target.value)}
            InputLabelProps={{ shrink: true }}
          />
          <Typography variant="body2" color="text.secondary">
            Enter category targets, then save each row. Actuals come from imported transactions.
          </Typography>
        </Stack>
      )}
      {budgetId && <BudgetVariance budgetId={budgetId} month={month} canWrite={canWrite} />}

      {canWrite && (
        <Box component="form" onSubmit={createCategory} sx={{ mb: 3 }}>
          <Button size="small" onClick={() => setCategoryEditorOpen((open) => !open)}>
            {categoryEditorOpen ? 'Hide category manager' : 'Manage categories'}
          </Button>
          {categoryEditorOpen && (
            <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" sx={{ mt: 1 }}>
              <TextField size="small" label="New category" value={categoryName} onChange={(event) => setCategoryName(event.target.value)} required />
              <TextField select size="small" label="Parent category" value={parentCategoryId} onChange={(event) => setParentCategoryId(event.target.value)} sx={{ minWidth: 190 }}>
                <MenuItem value="">No parent</MenuItem>
                {categories.filter((category) => !category.parent_category_id).map((category) => (
                  <MenuItem key={category.id} value={String(category.id)}>{category.name}</MenuItem>
                ))}
              </TextField>
              <Button type="submit" variant="outlined">Add category</Button>
            </Stack>
          )}
        </Box>
      )}

      <Stack direction="row" flexWrap="wrap" spacing={2} alignItems="center" sx={{ mb: 2 }}>
        {user && canWrite ? (
          <>
            <Stack spacing={1} alignItems="flex-start">
              <Button variant="contained" component="label" disabled={uploading}>
                {uploading ? 'Importing…' : 'Import CSV / Excel'}
                <input
                  type="file"
                  hidden
                  accept=".csv,.xlsx,.xls"
                  onChange={handleFileChange}
                />
              </Button>
              <Typography variant="caption" color="text.secondary" sx={{ maxWidth: 500 }}>
                Accepted headers are flexible: Date/Posting Date + Description/Memo + Amount, or Debit/Credit. Optional columns include Merchant/Payee, Account, Category, Reference, and Balance.
              </Typography>
            </Stack>
            {uploading && <CircularProgress size={24} aria-label="Importing file" />}
          </>
        ) : user ? (
          <Alert severity="info">This budget is view-only. Ask the owner for editor access to import transactions.</Alert>
        ) : (
          <Alert severity="info">
            <RouterLink to="/login" state={{ from: '/what-da-money' }}>Log in</RouterLink> to import transactions.
          </Alert>
        )}
      </Stack>

      {uploadMessage && <Alert severity={uploadMessage.severity} sx={{ mb: 2 }}>{uploadMessage.text}</Alert>}

      {status === 'loading' && <CircularProgress aria-label="Loading transactions" />}
      {status === 'error' && <Alert severity="error">Could not load transactions from the API.</Alert>}
      {status === 'ready' && transactions.length === 0 && (
        <Typography>No transactions yet — import a CSV or Excel file to get started.</Typography>
      )}
      {status === 'ready' && transactions.length > 0 && (
        <TransactionTable
          transactions={transactions}
          categories={categories}
          canWrite={canWrite}
          onChanged={async () => {
            loadTransactions();
            loadCategories();
          }}
        />
      )}
    </>
  );
}
