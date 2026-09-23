import { useState } from 'react';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import FormControlLabel from '@mui/material/FormControlLabel';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import apiClient from '../api/client';
import { useAuth } from '../context/AuthContext';
import { useColorMode } from '../context/ColorModeContext';

export default function Profile() {
  const { user } = useAuth();
  const { mode, toggleColorMode, isLoadingPreference, isSavingPreference, saveError } = useColorMode();
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [message, setMessage] = useState(null);
  const [saving, setSaving] = useState(false);

  const changePassword = async (event) => {
    event.preventDefault();
    if (newPassword !== confirmPassword) {
      setMessage({ severity: 'error', text: 'New passwords do not match.' });
      return;
    }
    setSaving(true);
    setMessage(null);
    try {
      await apiClient.post('/auth/password', { current_password: currentPassword, new_password: newPassword });
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
      setMessage({ severity: 'success', text: 'Password updated.' });
    } catch (error) {
      setMessage({ severity: 'error', text: error.response?.data?.error || 'Could not update password.' });
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <Typography variant="h4" component="h2" gutterBottom>My Profile</Typography>
      <Typography variant="body1" sx={{ mb: 3 }}>Signed in as {user?.username}.</Typography>
      {message && <Alert severity={message.severity} sx={{ mb: 2, maxWidth: 480 }}>{message.text}</Alert>}
      <Paper variant="outlined" sx={{ p: 2, mb: 3, maxWidth: 480 }}>
        <Typography variant="h6" component="h3" gutterBottom>Preferences</Typography>
        <FormControlLabel
          control={(
            <Switch
              checked={mode === 'dark'}
              disabled={isLoadingPreference || isSavingPreference}
              onChange={toggleColorMode}
            />
          )}
          label="Dark mode"
        />
        <Typography variant="body2" color="text.secondary">
          Use a darker color theme across authenticated portal pages.
        </Typography>
        {saveError && (
          <Alert severity="error" role="status" sx={{ mt: 2 }}>
            Could not save theme preference.
          </Alert>
        )}
      </Paper>
      <Box component="form" onSubmit={changePassword} sx={{ maxWidth: 480 }}>
        <Typography variant="h6" component="h3" gutterBottom>Change Password</Typography>
        <Stack spacing={2}>
          <TextField autoComplete="current-password" label="Current password" onChange={(event) => setCurrentPassword(event.target.value)} required type="password" value={currentPassword} />
          <TextField autoComplete="new-password" helperText="At least 8 characters" label="New password" onChange={(event) => setNewPassword(event.target.value)} required type="password" value={newPassword} />
          <TextField autoComplete="new-password" label="Confirm new password" onChange={(event) => setConfirmPassword(event.target.value)} required type="password" value={confirmPassword} />
          <Button disabled={saving} type="submit" variant="contained">{saving ? 'Updating...' : 'Update Password'}</Button>
        </Stack>
      </Box>
    </>
  );
}
