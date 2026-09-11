import { useEffect, useState } from 'react';
import Typography from '@mui/material/Typography';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TableSortLabel from '@mui/material/TableSortLabel';
import Paper from '@mui/material/Paper';
import Chip from '@mui/material/Chip';
import Button from '@mui/material/Button';
import Box from '@mui/material/Box';
import TextField from '@mui/material/TextField';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Alert from '@mui/material/Alert';
import CircularProgress from '@mui/material/CircularProgress';
import Dialog from '@mui/material/Dialog';
import DialogTitle from '@mui/material/DialogTitle';
import DialogContent from '@mui/material/DialogContent';
import DialogActions from '@mui/material/DialogActions';
import useMediaQuery from '@mui/material/useMediaQuery';
import { useTheme } from '@mui/material/styles';
import { useAuth } from '../context/AuthContext';
import apiClient from '../api/client';

export default function Users() {
  const { user: currentUser } = useAuth();
  const theme = useTheme();
  const fullScreenDialog = useMediaQuery(theme.breakpoints.down('sm'));
  const [users, setUsers] = useState([]);
  const [status, setStatus] = useState('loading');
  const [message, setMessage] = useState(null);

  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [role, setRole] = useState('admin');
  const [creating, setCreating] = useState(false);
  const [createUserOpen, setCreateUserOpen] = useState(false);

  const [editingUser, setEditingUser] = useState(null);
  const [editUsername, setEditUsername] = useState('');
  const [editRole, setEditRole] = useState('admin');
  const [editPassword, setEditPassword] = useState('');
  const [saving, setSaving] = useState(false);
  const [sortColumn, setSortColumn] = useState('username');
  const [sortDirection, setSortDirection] = useState('asc');

  const requestSort = (column) => {
    if (column === sortColumn) setSortDirection((direction) => direction === 'asc' ? 'desc' : 'asc');
    else {
      setSortColumn(column);
      setSortDirection('asc');
    }
  };
  const sortedUsers = [...users].sort((left, right) => {
    const comparison = String(left[sortColumn] || '').localeCompare(String(right[sortColumn] || ''), undefined, { numeric: true });
    return sortDirection === 'asc' ? comparison : -comparison;
  });
  const sortableHeader = (label, column) => (
    <TableCell sortDirection={sortColumn === column ? sortDirection : false}>
      <TableSortLabel active={sortColumn === column} aria-label={`Sort by ${label}${sortColumn === column ? `. Currently ${sortDirection}ending` : ''}`} direction={sortColumn === column ? sortDirection : 'asc'} onClick={() => requestSort(column)}>
        {label}
      </TableSortLabel>
    </TableCell>
  );

  const loadUsers = () => {
    setStatus('loading');
    apiClient
      .get('/users')
      .then((response) => {
        setUsers(response.data);
        setStatus('ready');
      })
      .catch((err) => {
        setStatus('error');
        setMessage({ severity: 'error', text: err.response?.data?.error || 'Could not load users.' });
      });
  };

  useEffect(loadUsers, []);

  const handleCreate = async (event) => {
    event.preventDefault();
    setCreating(true);
    setMessage(null);
    try {
      await apiClient.post('/users', { username, password, role });
      setUsername('');
      setPassword('');
      setMessage({ severity: 'success', text: 'User created.' });
      setCreateUserOpen(false);
      loadUsers();
    } catch (err) {
      setMessage({ severity: 'error', text: err.response?.data?.error || 'Could not create user.' });
    } finally {
      setCreating(false);
    }
  };

  const toggleActive = async (targetUser) => {
    const action = targetUser.is_active ? 'deactivate' : 'activate';
    try {
      await apiClient.post(`/users/${targetUser.id}/${action}`);
      loadUsers();
    } catch (err) {
      setMessage({ severity: 'error', text: err.response?.data?.error || `Could not ${action} user.` });
    }
  };

  const openEdit = (u) => {
    setEditingUser(u);
    setEditUsername(u.username);
    setEditRole(u.role);
    setEditPassword('');
  };

  const handleEditSave = async (event) => {
    event.preventDefault();
    setSaving(true);
    setMessage(null);
    try {
      const body = { username: editUsername, role: editRole };
      if (editPassword) body.password = editPassword;
      await apiClient.post(`/users/${editingUser.id}`, body);
      setEditingUser(null);
      setMessage({ severity: 'success', text: 'User updated.' });
      loadUsers();
    } catch (err) {
      setMessage({ severity: 'error', text: err.response?.data?.error || 'Could not update user.' });
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <Typography variant="h4" component="h2" gutterBottom>
        Users
      </Typography>

      {message && <Alert severity={message.severity} sx={{ mb: 2 }}>{message.text}</Alert>}

      <Button variant="outlined" onClick={() => setCreateUserOpen(true)} sx={{ mb: 3 }}>
        New User
      </Button>

      <Dialog fullScreen={fullScreenDialog} open={createUserOpen} onClose={() => setCreateUserOpen(false)}>
        <Box component="form" onSubmit={handleCreate}>
          <DialogTitle>New User</DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ pt: 1, minWidth: 300 }}>
              <TextField autoFocus label="Username" value={username} onChange={(e) => setUsername(e.target.value)} required />
              <TextField
                label="Password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                helperText="At least 8 characters"
              />
              <TextField select label="Role" value={role} onChange={(e) => setRole(e.target.value)}>
                <MenuItem value="admin">Admin</MenuItem>
                <MenuItem value="user">User</MenuItem>
              </TextField>
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setCreateUserOpen(false)}>Cancel</Button>
            <Button type="submit" variant="contained" disabled={creating}>
              {creating ? 'Creating...' : 'Create User'}
            </Button>
          </DialogActions>
        </Box>
      </Dialog>

      {status === 'loading' && <CircularProgress aria-label="Loading users" />}
      {status === 'ready' && (
        <TableContainer component={Paper}>
          <Table stickyHeader aria-label="Users">
            <TableHead sx={{
              '& .MuiTableCell-head': { backgroundColor: '#2f6f8f', color: '#ffffff', fontWeight: 700, whiteSpace: 'nowrap' },
              '& .MuiTableSortLabel-icon': { color: 'inherit', opacity: 1 },
              '& .MuiTableSortLabel-root.Mui-focusVisible': { outline: '3px solid #ffffff', outlineOffset: '2px' },
            }}>
              <TableRow>
                {sortableHeader('Username', 'username')}
                {sortableHeader('Role', 'role')}
                {sortableHeader('Status', 'is_active')}
                {sortableHeader('Created', 'created_at')}
                <TableCell>Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {sortedUsers.map((u) => (
                <TableRow key={u.id}>
                  <TableCell>{u.username}</TableCell>
                  <TableCell>{u.role}</TableCell>
                  <TableCell>
                    <Chip
                      label={u.is_active ? 'Active' : 'Inactive'}
                      color={u.is_active ? 'success' : 'default'}
                      size="small"
                    />
                  </TableCell>
                  <TableCell>{new Date(u.created_at).toLocaleDateString()}</TableCell>
                  <TableCell>
                    <Button
                      size="small"
                      disabled={currentUser?.id === u.id && u.is_active}
                      onClick={() => toggleActive(u)}
                    >
                      {u.is_active ? 'Deactivate' : 'Activate'}
                    </Button>
                    <Button size="small" onClick={() => openEdit(u)}>Edit</Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      <Dialog
        open={editingUser !== null}
        onClose={() => setEditingUser(null)}
        fullScreen={fullScreenDialog}
        fullWidth
      >
        <Box component="form" onSubmit={handleEditSave}>
          <DialogTitle>Edit User</DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ mt: 1 }}>
              <TextField
                label="Username"
                value={editUsername}
                onChange={(e) => setEditUsername(e.target.value)}
                required
              />
              <TextField select label="Role" value={editRole} onChange={(e) => setEditRole(e.target.value)}>
                <MenuItem value="admin">Admin</MenuItem>
                <MenuItem value="user">User</MenuItem>
              </TextField>
              <TextField
                label="New Password"
                type="password"
                value={editPassword}
                onChange={(e) => setEditPassword(e.target.value)}
                helperText="Leave blank to keep the current password"
              />
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setEditingUser(null)}>Cancel</Button>
            <Button type="submit" variant="contained" disabled={saving}>
              {saving ? 'Saving…' : 'Save'}
            </Button>
          </DialogActions>
        </Box>
      </Dialog>
    </>
  );
}
